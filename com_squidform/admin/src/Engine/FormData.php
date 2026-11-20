<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Per-form data tables (0.4.0). Each form's answers live only in its own tables:
 *
 *   <prefix>_sfd_<alias>              one row per submission, one column per field
 *   <prefix>_sfd_<alias>__<repeater>  one row per repeater row
 *   <prefix>_sfd_<alias>__history     edit history (old → new values)
 *
 * Archived forms' tables are renamed sfd_ → sfa_ (same length). Names are
 * fixed when a table is created and kept in #__squidform_data_tables; columns
 * in #__squidform_data_columns. Columns are never dropped or renamed: a field
 * removed from the form keeps its column, and a field whose type changes to a
 * different kind of value gets a new column.
 *
 * DDL (CREATE/ALTER/RENAME/DROP) is never run inside a transaction: MySQL
 * would commit the transaction. Callers run ensure() before starting one.
 */
final class FormData
{
    /** System columns that a field key may not take. */
    private const RESERVED = ['id', 'submission_id', 'row_index'];

    /** @var array<int, self> */
    private static array $cache = [];

    /** @var array<string, object> kind|key => table row (id, kind, field_key, table_name) */
    private array $tables = [];

    /** @var array<int, list<object>> table id => columns (field_key, column_name, col_class, retired) */
    private array $columns = [];

    private function __construct(public readonly int $formId)
    {
        $this->reload();
    }

    public static function forForm(int $formId): self
    {
        return self::$cache[$formId] ??= new self($formId);
    }

    public static function forget(?int $formId = null): void
    {
        if ($formId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$formId]);
        }
    }

    private static function db(): DatabaseInterface
    {
        return SubmissionStore::db();
    }

    /** The site's table prefix with exactly one trailing underscore. */
    public static function prefix(): string
    {
        $p = (string) self::db()->getPrefix();

        return str_ends_with($p, '_') ? $p : $p . '_';
    }

    /** Full real table name for a registry name such as "sfd_visitor_request". */
    public static function real(string $name): string
    {
        return self::prefix() . $name;
    }

    private function reload(): void
    {
        $db = self::db();
        $this->tables  = [];
        $this->columns = [];

        $formId = $this->formId; // bind() takes a reference; never pass a (readonly) property
        $rows   = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__squidform_data_tables'))
                ->where('form_id = :f')->bind(':f', $formId, ParameterType::INTEGER)
        )->loadObjectList();

        foreach ($rows as $t) {
            $this->tables[$t->kind . '|' . $t->field_key] = $t;
        }

        if ($rows) {
            $ids  = array_map(static fn ($t) => (int) $t->id, $rows);
            $cols = $db->setQuery(
                $db->createQuery()->select('*')->from($db->quoteName('#__squidform_data_columns'))
                    ->whereIn($db->quoteName('table_id'), $ids)->order('id')
            )->loadObjectList();

            foreach ($cols as $c) {
                $this->columns[(int) $c->table_id][] = $c;
            }
        }
    }

    // ---------------------------------------------------------------- names

    public function hasTables(): bool
    {
        return isset($this->tables['main|']);
    }

    /** Real name of the main table, or null before the first save. */
    public function mainTable(): ?string
    {
        return isset($this->tables['main|']) ? self::real($this->tables['main|']->table_name) : null;
    }

    public function historyTable(): ?string
    {
        return isset($this->tables['history|']) ? self::real($this->tables['history|']->table_name) : null;
    }

    public function repeaterTable(string $key): ?string
    {
        return isset($this->tables['repeater|' . $key]) ? self::real($this->tables['repeater|' . $key]->table_name) : null;
    }

    /** @return list<object> registry rows of all this form's tables */
    public function tables(): array
    {
        return array_values($this->tables);
    }

    /** @return list<object> columns of one table (by kind/key) */
    public function columnsOf(string $kind, string $key = ''): array
    {
        $t = $this->tables[$kind . '|' . $key] ?? null;

        return $t ? ($this->columns[(int) $t->id] ?? []) : [];
    }

    /** Column holding $field's values in the main or a repeater table. */
    public function column(array $field, string $repeater = ''): ?string
    {
        $class = self::classOf((string) $field['type']);
        $cols  = $this->columnsOf($repeater === '' ? 'main' : 'repeater', $repeater);
        $any   = null;

        foreach ($cols as $c) {
            if ($c->field_key === $field['key']) {
                if ($c->col_class === $class) {
                    return $c->column_name;
                }

                $any ??= $c->column_name;
            }
        }

        return $any;
    }

    /** Kind of storage for a field type. */
    public static function classOf(string $type): string
    {
        return match ($type) {
            'text', 'email', 'tel', 'url', 'hidden', 'select', 'radios', 'time', 'datetime', 'lookup' => 'text',
            // signature, grid, name and address (0.8.1) are objects, uploads (0.9.0) lists of file ids: 'json'
            'textarea', 'editor' => 'longtext',
            'number', 'rating', 'slider', 'calc' => 'number',
            'multiselect', 'imagechoice' => 'list',
            'date'     => 'date',
            'checkbox' => 'bool',
            'checkboxes' => 'list',
            'user'     => 'user',
            default    => 'json',
        };
    }

    private static function sqlType(string $class): string
    {
        return match ($class) {
            'text'     => 'TEXT NULL',
            'longtext', 'json' => 'MEDIUMTEXT NULL',
            'number'   => 'DECIMAL(65,15) NULL',
            'date'     => 'DATE NULL',
            'bool'     => 'TINYINT(1) NULL',
            'list'     => 'TEXT NULL',
            'user'     => 'INT UNSIGNED NULL',
        };
    }

    /**
     * Pick a free registry name "sfd_<alias><suffix>" that fits MySQL's
     * 64-character limit with the site prefix; long names are shortened and
     * get the form id.
     */
    private function newName(string $alias, string $suffix): string
    {
        $db    = self::db();
        $alias = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $alias) ?: 'form');
        $alias = trim($alias, '_') ?: 'form';
        $room  = 64 - \strlen(self::prefix()) - \strlen('sfd_');

        if (\strlen($suffix) > 24) {
            $suffix = '__r' . substr(md5($suffix), 0, 8);
        }

        $name = 'sfd_' . $alias . $suffix;

        if (\strlen($alias . $suffix) > $room) {
            $tail = '_' . $this->formId;
            $name = 'sfd_' . rtrim(substr($alias, 0, max(1, $room - \strlen($suffix) - \strlen($tail))), '_') . $tail . $suffix;
        }

        $existing = array_map('strtolower', $db->getTableList());

        for ($n = 2; \in_array(strtolower(self::real($name)), $existing, true) || \in_array(strtolower(self::real('sfa_' . substr($name, 4))), $existing, true) || $this->registered($name); $n++) {
            $name = substr($name, 0, 60 - \strlen(self::prefix())) . '_' . $n;
        }

        return $name;
    }

    private function registered(string $name): bool
    {
        $db = self::db();

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_data_tables'))
                ->where('table_name = :n')->bind(':n', $name)
        )->loadResult() > 0;
    }

    // --------------------------------------------------------------- schema

    /**
     * Create the form's tables and any missing columns for this definition.
     * Never call inside a transaction.
     */
    public function ensure(Schema $schema, string $alias): void
    {
        $changed = false;

        if (!isset($this->tables['main|'])) {
            $this->createTable('main', '', $this->newName($alias, ''), $alias);
            $changed = true;
        }

        if (!isset($this->tables['history|'])) {
            $this->createTable('history', '', $this->newName($alias, '__history'), $alias);
            $changed = true;
        }

        if ($changed) {
            $this->reload();
        }

        foreach ($schema->valueFields() as $f) {
            if ($f['type'] === 'repeater') {
                if (!isset($this->tables['repeater|' . $f['key']])) {
                    $this->createTable('repeater', $f['key'], $this->newName($alias, '__' . strtolower($f['key'])), $alias);
                    $this->reload();
                }

                foreach ($f['fields'] ?? [] as $c) {
                    if (FieldRegistry::get($c['type'])->storesValue()) {
                        $this->ensureColumn('repeater', $f['key'], $c);
                    }
                }

                continue;
            }

            $this->ensureColumn('main', '', $f);
        }
    }

    private function createTable(string $kind, string $key, string $name, string $alias): void
    {
        $db   = self::db();
        $real = $db->quoteName(self::real($name));
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $sql = match ($kind) {
            'main' => 'CREATE TABLE IF NOT EXISTS ' . $real . ' (`submission_id` int unsigned NOT NULL, PRIMARY KEY (`submission_id`))' . $tail,
            'repeater' => 'CREATE TABLE IF NOT EXISTS ' . $real . ' (`id` int unsigned NOT NULL AUTO_INCREMENT, `submission_id` int unsigned NOT NULL,'
                . ' `row_index` int unsigned NOT NULL, PRIMARY KEY (`id`), KEY `idx_submission` (`submission_id`, `row_index`))' . $tail,
            'history' => 'CREATE TABLE IF NOT EXISTS ' . $real . ' (`id` int unsigned NOT NULL AUTO_INCREMENT, `submission_id` int unsigned NOT NULL,'
                . ' `user_id` int unsigned NOT NULL DEFAULT 0, `created` datetime NOT NULL, `action` varchar(32) NOT NULL,'
                . ' `diff_json` mediumtext, PRIMARY KEY (`id`), KEY `idx_submission` (`submission_id`))' . $tail,
        };

        $db->setQuery($sql)->execute();

        $row = (object) [
            'form_id'    => $this->formId,
            'kind'       => $kind,
            'field_key'  => $key,
            'table_name' => $name,
            'created'    => Factory::getDate()->toSql(),
        ];
        $db->insertObject('#__squidform_data_tables', $row, 'id');
    }

    /** @param array<string, mixed> $field */
    private function ensureColumn(string $kind, string $key, array $field): void
    {
        $class = self::classOf((string) $field['type']);
        $cols  = $this->columnsOf($kind, $key);

        foreach ($cols as $c) {
            if ($c->field_key === $field['key'] && $c->col_class === $class) {
                if ((int) $c->retired) {
                    $this->setRetired((int) $c->id, 0);
                }

                return;
            }
        }

        $name = $field['key'];

        if (\in_array(strtolower($name), self::RESERVED, true)) {
            $name .= '_f';
        }

        $taken = array_map(static fn ($c) => strtolower($c->column_name), $cols);

        if (\in_array(strtolower($name), $taken, true)) {
            // Same key, different kind of value: a new column; the old one is kept.
            $name = substr($field['key'], 0, 50) . '__' . $class;

            for ($n = 2; \in_array(strtolower($name), $taken, true); $n++) {
                $name = substr($field['key'], 0, 46) . '__' . $class . $n;
            }
        }

        $t  = $this->tables[$kind . '|' . $key];
        $db = self::db();
        $db->setQuery('ALTER TABLE ' . $db->quoteName(self::real($t->table_name)) . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . self::sqlType($class))->execute();

        $row = (object) [
            'table_id'    => (int) $t->id,
            'field_key'   => (string) $field['key'],
            'column_name' => $name,
            'col_class'   => $class,
            'retired'     => 0,
            'created'     => Factory::getDate()->toSql(),
        ];
        $db->insertObject('#__squidform_data_columns', $row, 'id');
        $this->columns[(int) $t->id][] = $row;
    }

    /**
     * Mark columns whose field is not in the live definition as retired (shown
     * as such in the admin); un-retire those that are back.
     */
    public function markRetired(Schema $live): void
    {
        $liveCols = [];

        foreach ($live->valueFields() as $f) {
            if ($f['type'] === 'repeater') {
                foreach ($f['fields'] ?? [] as $c) {
                    $col = $this->column($c, $f['key']);

                    if ($col !== null) {
                        $liveCols['repeater|' . $f['key'] . '|' . $col] = true;
                    }
                }
            } elseif (($col = $this->column($f)) !== null) {
                $liveCols['main||' . $col] = true;
            }
        }

        foreach ($this->tables as $k => $t) {
            foreach ($this->columns[(int) $t->id] ?? [] as $c) {
                $retired = isset($liveCols[$k . '|' . $c->column_name]) ? 0 : 1;

                if ((int) $c->retired !== $retired && isset($c->id)) {
                    $this->setRetired((int) $c->id, $retired);
                    $c->retired = $retired;
                }
            }
        }
    }

    private function setRetired(int $id, int $flag): void
    {
        $db = self::db();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__squidform_data_columns'))->set('retired = ' . $flag)->where('id = ' . $id)
        )->execute();
    }

    // ----------------------------------------------------------------- data

    /**
     * Write one submission's answers (replaces any existing rows for it).
     * Safe inside a transaction (DML only). ensure() must have run for $schema.
     *
     * @param  array<string, mixed>  $data
     */
    public function write(int $submissionId, Schema $schema, array $data): void
    {
        $db   = self::db();
        $main = $this->mainTable() ?? throw new \RuntimeException('Form ' . $this->formId . ' has no data table.');

        $this->deleteRows($submissionId, false);

        $row = ['submission_id' => $submissionId];

        foreach ($schema->valueFields() as $f) {
            if ($f['type'] === 'repeater') {
                $table = $this->repeaterTable($f['key']);

                if (!$table || !\is_array($data[$f['key']] ?? null)) {
                    continue;
                }

                foreach (array_values($data[$f['key']]) as $i => $r) {
                    $rr = ['submission_id' => $submissionId, 'row_index' => $i];

                    foreach ($f['fields'] ?? [] as $c) {
                        $col = $this->column($c, $f['key']);

                        if ($col !== null && \is_array($r) && \array_key_exists($c['key'], $r)) {
                            $rr[$col] = self::toDb(self::classOf($c['type']), $r[$c['key']]);
                        }
                    }

                    self::insert($table, $rr);
                }

                continue;
            }

            $col = $this->column($f);

            if ($col !== null && \array_key_exists($f['key'], $data)) {
                $row[$col] = self::toDb(self::classOf($f['type']), $data[$f['key']]);
            }
        }

        self::insert($main, $row);
    }

    /** @param array<string, mixed> $row */
    private static function insert(string $table, array $row): void
    {
        $db   = self::db();
        $cols = array_map([$db, 'quoteName'], array_keys($row));
        $vals = array_map(static fn ($v) => $v === null ? 'NULL' : $db->quote((string) $v), array_values($row));

        $db->setQuery('INSERT INTO ' . $db->quoteName($table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')')->execute();
    }

    /**
     * Read one submission's answers in the shape the engine uses.
     *
     * @return array<string, mixed>
     */
    public function read(int $submissionId, ?Schema $schema): array
    {
        return $this->readMany([$submissionId], static fn () => $schema)[$submissionId] ?? [];
    }

    /**
     * @param  int[]                     $ids
     * @param  callable(int): ?Schema    $schemaFor  submission id => its definition version
     *
     * @return array<int, array<string, mixed>>
     */
    public function readMany(array $ids, callable $schemaFor): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids || !$this->hasTables()) {
            return [];
        }

        $db   = self::db();
        $main = [];

        foreach ($db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName($this->mainTable()))->whereIn($db->quoteName('submission_id'), $ids)
        )->loadAssocList() as $r) {
            $main[(int) $r['submission_id']] = $r;
        }

        $reps = [];

        foreach ($this->tables as $k => $t) {
            if ($t->kind !== 'repeater') {
                continue;
            }

            foreach ($db->setQuery(
                $db->createQuery()->select('*')->from($db->quoteName(self::real($t->table_name)))
                    ->whereIn($db->quoteName('submission_id'), $ids)->order('submission_id, row_index')
            )->loadAssocList() as $r) {
                $reps[$t->field_key][(int) $r['submission_id']][] = $r;
            }
        }

        $out = [];

        foreach ($ids as $id) {
            $schema = $schemaFor($id);
            $r      = $main[$id] ?? null;
            $data   = [];

            if ($r === null || !$schema) {
                $out[$id] = $data;
                continue;
            }

            foreach ($schema->valueFields() as $f) {
                if ($f['type'] === 'repeater') {
                    $rows = [];

                    foreach ($reps[$f['key']][$id] ?? [] as $rr) {
                        $row = [];

                        foreach ($f['fields'] ?? [] as $c) {
                            $col = $this->column($c, $f['key']);

                            if ($col !== null && \array_key_exists($col, $rr) && $rr[$col] !== null) {
                                $row[$c['key']] = self::fromDb(self::classOf($c['type']), $rr[$col]);
                            }
                        }

                        $rows[] = $row;
                    }

                    $data[$f['key']] = $rows;

                    continue;
                }

                $col = $this->column($f);

                if ($col !== null && \array_key_exists($col, $r) && $r[$col] !== null) {
                    $data[$f['key']] = self::fromDb(self::classOf($f['type']), $r[$col]);
                }
            }

            $out[$id] = $data;
        }

        return $out;
    }

    /** Remove a submission's answers (and, when $history, its edit history). */
    public function deleteRows(int $submissionId, bool $history): void
    {
        $db = self::db();

        foreach ($this->tables as $t) {
            if ($t->kind === 'history' && !$history) {
                continue;
            }

            $db->setQuery(
                $db->createQuery()->delete($db->quoteName(self::real($t->table_name)))
                    ->where('submission_id = :s')->bind(':s', $submissionId, ParameterType::INTEGER)
            )->execute();
        }
    }

    public static function toDb(string $class, mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        // phpcs:disable PHPCompatibility.Operators.RemovedTernaryAssociativity -- separate match arms, not chained ternaries
        return match ($class) {
            'number' => (\is_scalar($v) && is_numeric(trim((string) $v))) ? trim((string) $v) : null,
            'date'   => (\is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null,
            'bool'   => ((string) (\is_array($v) ? '' : $v)) === '1' ? '1' : '0',
            'user'   => (\is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0) ? (string) (int) $v : null,
            'list'   => json_encode(array_values(array_map('strval', \is_array($v) ? $v : ($v === '' ? [] : [$v]))), JSON_UNESCAPED_UNICODE),
            'json'   => json_encode($v, JSON_UNESCAPED_UNICODE),
            default  => \is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v,
        };
        // phpcs:enable
    }

    public static function fromDb(string $class, mixed $raw): mixed
    {
        return match ($class) {
            'number' => str_contains((string) $raw, '.') ? (rtrim(rtrim((string) $raw, '0'), '.') ?: '0') : (string) $raw,
            'date'   => substr((string) $raw, 0, 10),
            'bool'   => (string) $raw === '1' ? '1' : '',
            'user'   => $raw === null ? '' : (string) (int) $raw,
            'list'   => \is_array($d = json_decode((string) $raw, true)) ? array_values(array_map('strval', $d)) : [],
            'json'   => json_decode((string) $raw, true),
            default  => (string) $raw,
        };
    }

    // ------------------------------------------------------------- history

    /** @param array<string, mixed>|null $diff */
    public function historyAdd(int $submissionId, int $userId, string $action, ?array $diff, ?string $created = null): void
    {
        $t = $this->historyTable();

        if (!$t) {
            return;
        }

        self::insert($t, [
            'submission_id' => $submissionId,
            'user_id'       => $userId,
            'created'       => $created ?? Factory::getDate()->toSql(),
            'action'        => substr($action, 0, 32),
            'diff_json'     => $diff !== null ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @return list<object> action, created, user_name, diff_json */
    public function history(int $submissionId): array
    {
        $t = $this->historyTable();

        if (!$t) {
            return [];
        }

        $db = self::db();

        return $db->setQuery(
            $db->createQuery()->select(['h.id', 'h.action', 'h.created', 'h.diff_json', $db->quoteName('u.name', 'user_name')])
                ->from($db->quoteName($t, 'h'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = h.user_id')
                ->where('h.submission_id = :s')->order('h.id')
                ->bind(':s', $submissionId, ParameterType::INTEGER)
        )->loadObjectList();
    }

    // -------------------------------------------------------------- search

    /**
     * Search condition over the form's text answers (main table alias $alias,
     * plus every repeater table), for a LIKE pattern bound as $param.
     */
    public function searchSql(string $alias, string $param): string
    {
        $db    = self::db();
        $parts = [];

        foreach ($this->columnsOf('main') as $c) {
            if (\in_array($c->col_class, ['text', 'longtext', 'list'], true)) {
                $parts[] = $alias . '.' . $db->quoteName($c->column_name) . ' LIKE ' . $param;
            }
        }

        $n = 0;

        foreach ($this->tables as $t) {
            if ($t->kind !== 'repeater') {
                continue;
            }

            $cols = array_filter($this->columns[(int) $t->id] ?? [], static fn ($c) => \in_array($c->col_class, ['text', 'longtext', 'list'], true));

            if (!$cols) {
                continue;
            }

            $n++;
            $ra   = 'sr' . $n;
            $ors  = array_map(static fn ($c) => $ra . '.' . $db->quoteName($c->column_name) . ' LIKE ' . $param, $cols);
            $parts[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName(self::real($t->table_name)) . ' ' . $ra
                . ' WHERE ' . $ra . '.submission_id = ' . $alias . '.submission_id AND (' . implode(' OR ', $ors) . '))';
        }

        return $parts ? '(' . implode(' OR ', $parts) . ')' : '0 = 1';
    }

    // ------------------------------------------------------ archive / purge

    /** Rename every table of the form between sfd_ (live) and sfa_ (archived). */
    public function rename(bool $archive): void
    {
        $db   = self::db();
        $from = $archive ? 'sfd_' : 'sfa_';
        $to   = $archive ? 'sfa_' : 'sfd_';

        foreach ($this->tables as $t) {
            if (!str_starts_with($t->table_name, $from)) {
                continue;
            }

            $new = $to . substr($t->table_name, 4);
            $db->setQuery('RENAME TABLE ' . $db->quoteName(self::real($t->table_name)) . ' TO ' . $db->quoteName(self::real($new)))->execute();
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_data_tables'))
                    ->set('table_name = :n')->where('id = :id')
                    ->bind(':n', $new)->bind(':id', $t->id, ParameterType::INTEGER)
            )->execute();
        }

        $this->reload();
    }

    /**
     * Drop every table of the form and its registry rows (permanent delete of
     * an archived form).
     *
     * @return array{tables: string[], rows: int}
     */
    public function drop(): array
    {
        $db    = self::db();
        $names = [];
        $rows  = 0;

        foreach ($this->tables as $t) {
            $real = self::real($t->table_name);

            try {
                if ($t->kind === 'main') {
                    $rows = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($real))->loadResult();
                }

                $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($real))->execute();
            } catch (\Throwable) {
                // already gone
            }

            $names[] = $real;
            $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_data_columns'))->where('table_id = ' . (int) $t->id))->execute();
            $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_data_tables'))->where('id = ' . (int) $t->id))->execute();
        }

        $this->reload();

        return ['tables' => $names, 'rows' => $rows];
    }

    /** Make sure the form's tables exist for its live definition (outside transactions). */
    public static function ensureLive(int $formId, Schema $schema, string $alias): self
    {
        $fd = self::forForm($formId);
        $fd->ensure($schema, $alias);

        return $fd;
    }
}
