<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Data tables (0.14.0): a read-only look at every form's own tables, for
 * people with Admin on SquidForm, and a tidy-up of what's left over.
 *
 * Read only: the list of tables (with rows and size), each table's columns,
 * its rows (50 a page) and a CSV of it. "Hide what's typed" answers show as
 * dots here too. No editing and no SQL box.
 *
 * Tidy-up, each behind a typed confirmation and written to Activity:
 *   - a retired column (its field was removed from the form) can be dropped
 *   - a SquidForm table no form owns (sfd_/sfa_ with no registry row, or a
 *     registry row whose form is gone) can be dropped
 *   - a registry row whose table is missing can be forgotten
 *
 * Every table and column name comes from the registry or the database's
 * own list, is checked against a strict pattern and the SquidForm prefixes,
 * and is quoted; nothing typed by a person ever becomes part of SQL.
 */
final class DataTables
{
    public const PER_PAGE = 50;

    /** Most rows one CSV holds. */
    public const CSV_MOST = 50000;

    private const NAME = '/^[A-Za-z0-9_]{1,64}$/';

    /**
     * Every registered table, plus SquidForm tables nobody owns.
     *
     * @return list<object> {id, form_id, form_title, form_alias, form_state, kind, field_key, table_name, exists, rows, bytes, columns, retired, orphan}
     */
    public static function all(): array
    {
        $db    = SubmissionStore::db();
        $real  = self::realTables();
        $rows  = $db->setQuery(
            $db->createQuery()
                ->select(['t.id', 't.form_id', 't.kind', 't.field_key', 't.table_name', $db->quoteName('f.title', 'form_title'), $db->quoteName('f.alias', 'form_alias'), $db->quoteName('f.state', 'form_state')])
                ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__squidform_data_columns') . ' c WHERE c.table_id = t.id) AS n_columns')
                ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__squidform_data_columns') . ' c WHERE c.table_id = t.id AND c.retired = 1) AS n_retired')
                ->from($db->quoteName('#__squidform_data_tables', 't'))
                ->join('LEFT', $db->quoteName('#__squidform_forms', 'f'), 'f.id = t.form_id')
                ->order('f.title, t.form_id, t.id')
        )->loadObjectList();
        $known = [];
        $out   = [];

        foreach ($rows as $r) {
            $name             = strtolower(FormData::real((string) $r->table_name));
            $known[$name]     = true;
            $r->id            = (int) $r->id;
            $r->form_id       = (int) $r->form_id;
            $r->columns       = (int) $r->n_columns;
            $r->retired       = (int) $r->n_retired;
            $r->exists        = isset($real[$name]);
            $r->rows          = $real[$name]['rows'] ?? 0;
            $r->bytes         = $real[$name]['bytes'] ?? 0;
            $r->orphan        = $r->form_title === null;
            $out[]            = $r;
        }

        foreach ($real as $name => $info) {
            if (!isset($known[$name])) {
                $out[] = (object) [
                    'id' => 0, 'form_id' => 0, 'form_title' => null, 'form_alias' => null, 'form_state' => null,
                    'kind' => '', 'field_key' => '', 'table_name' => substr($info['name'], \strlen(FormData::prefix())),
                    'exists' => true, 'rows' => $info['rows'], 'bytes' => $info['bytes'], 'columns' => 0, 'retired' => 0, 'orphan' => true,
                ];
            }
        }

        return $out;
    }

    /** One registered table, or an unowned one by its name without the prefix. */
    public static function find(int $id, string $name = ''): ?object
    {
        foreach (self::all() as $t) {
            if ($id > 0 ? $t->id === $id : ($t->id === 0 && $t->table_name === $name)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * The table's columns as the database has them, with what SquidForm
     * knows about each.
     *
     * @return list<object> {name, type, field_key, col_class, retired, registry_id, system}
     */
    public static function columns(object $t): array
    {
        if (!$t->exists) {
            return [];
        }

        $db   = SubmissionStore::db();
        $reg  = [];

        if ($t->id > 0) {
            foreach ($db->setQuery(
                $db->createQuery()->select(['id', 'field_key', 'column_name', 'col_class', 'retired'])->from($db->quoteName('#__squidform_data_columns'))
                    ->where('table_id = :t')->bind(':t', $t->id, ParameterType::INTEGER)
            )->loadObjectList() as $c) {
                $reg[strtolower((string) $c->column_name)] = $c;
            }
        }

        $out = [];

        foreach ($db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName(self::realName($t)))->loadObjectList() as $c) {
            $r     = $reg[strtolower((string) $c->Field)] ?? null;
            $out[] = (object) [
                'name'        => (string) $c->Field,
                'type'        => (string) $c->Type,
                'field_key'   => $r ? (string) $r->field_key : '',
                'col_class'   => $r ? (string) $r->col_class : '',
                'retired'     => $r ? (int) $r->retired === 1 : false,
                'registry_id' => $r ? (int) $r->id : 0,
                'system'      => $r === null,
            ];
        }

        return $out;
    }

    /**
     * Rows of a table, oldest first, with "hide what's typed" answers as dots.
     *
     * @return list<array<string, string>>
     */
    public static function rows(object $t, int $start = 0, int $limit = self::PER_PAGE): array
    {
        if (!$t->exists) {
            return [];
        }

        $db    = SubmissionStore::db();
        $cols  = self::columns($t);
        $mask  = self::masked($t, $cols);
        $order = $cols ? $db->quoteName($cols[0]->name) : '1';
        $out   = [];

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName(self::realName($t)) . ' ORDER BY ' . $order, max(0, $start), max(1, $limit))->loadAssocList() as $row) {
            $line = [];

            foreach ($row as $k => $v) {
                $line[$k] = isset($mask[strtolower($k)]) && $v !== null && $v !== '' ? '••••••' : ($v === null ? '' : (string) $v);
            }

            $out[] = $line;
        }

        return $out;
    }

    /** A CSV of the whole table (formula guard on every cell). */
    public static function csv(object $t): string
    {
        $cols = self::columns($t);
        $out  = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn ($c) => Exporter::cell($c->name), $cols), ',', '"', '', "\r\n");

        foreach (self::rows($t, 0, self::CSV_MOST) as $row) {
            fputcsv($out, array_map(static fn ($v) => Exporter::cell((string) $v), array_values($row)), ',', '"', '', "\r\n");
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        self::log((int) $t->form_id, 'table_export', ['table' => $t->table_name, 'count' => min((int) $t->rows, self::CSV_MOST)]);

        return $csv;
    }

    // ----------------------------------------------------------- tidy-up

    /** Drop a retired column. The typed name must match. */
    public static function dropColumn(object $t, string $column, string $typed): bool
    {
        $col = null;

        foreach (self::columns($t) as $c) {
            if ($c->name === $column && $c->retired && $c->registry_id > 0) {
                $col = $c;
            }
        }

        if ($col === null || $typed !== $col->name || !preg_match(self::NAME, $col->name)) {
            return false;
        }

        $db = SubmissionStore::db();
        $db->setQuery('ALTER TABLE ' . $db->quoteName(self::realName($t)) . ' DROP COLUMN ' . $db->quoteName($col->name))->execute();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_data_columns'))->where('id = :id')->bind(':id', $col->registry_id, ParameterType::INTEGER))->execute();
        FormData::forget((int) $t->form_id);
        self::log((int) $t->form_id, 'drop_column', ['table' => $t->table_name, 'column' => $col->name, 'field' => $col->field_key, 'rows' => (int) $t->rows]);

        return true;
    }

    /** Drop a table no form owns. The typed name (without the prefix) must match. */
    public static function dropTable(object $t, string $typed): bool
    {
        if (!$t->orphan || !$t->exists || $typed !== $t->table_name) {
            return false;
        }

        $db = SubmissionStore::db();
        $db->setQuery('DROP TABLE ' . $db->quoteName(self::realName($t)))->execute();
        self::unregister($t);
        self::log(0, 'drop_table', ['table' => $t->table_name, 'rows' => (int) $t->rows]);

        return true;
    }

    /** Forget a registry row whose table is missing. */
    public static function forgetTable(object $t): bool
    {
        if ($t->exists || $t->id <= 0) {
            return false;
        }

        self::unregister($t);
        self::log((int) $t->form_id, 'forget_table', ['table' => $t->table_name]);

        return true;
    }

    /** What the tidy-up could do: counts for the screen's summary. */
    public static function leftovers(array $tables): array
    {
        $n = ['columns' => 0, 'orphans' => 0, 'missing' => 0];

        foreach ($tables as $t) {
            $n['columns'] += $t->exists ? $t->retired : 0;
            $n['orphans'] += $t->orphan && $t->exists ? 1 : 0;
            $n['missing'] += !$t->exists ? 1 : 0;
        }

        return $n;
    }

    // ----------------------------------------------------------- inside

    /**
     * SquidForm's own data tables as the database lists them: lower-case
     * full name => {name, rows, bytes}. Only <prefix>sfd_ and <prefix>sfa_.
     *
     * @return array<string, array{name: string, rows: int, bytes: int}>
     */
    private static function realTables(): array
    {
        $db     = SubmissionStore::db();
        $prefix = FormData::prefix();
        $out    = [];

        foreach (['sfd_', 'sfa_'] as $kind) {
            $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix . $kind) . '%';
            $rows = $db->setQuery(
                $db->createQuery()->select(['TABLE_NAME', 'TABLE_ROWS', 'DATA_LENGTH', 'INDEX_LENGTH'])->from('information_schema.TABLES')
                    ->where('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME LIKE :l')->bind(':l', $like)
            )->loadObjectList();

            foreach ($rows as $r) {
                $name = (string) $r->TABLE_NAME;

                if (!preg_match(self::NAME, $name)) {
                    continue;
                }

                // TABLE_ROWS is an estimate for InnoDB; count for real (these tables are small).
                $count = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($name))->loadResult();
                $out[strtolower($name)] = ['name' => $name, 'rows' => $count, 'bytes' => (int) $r->DATA_LENGTH + (int) $r->INDEX_LENGTH];
            }
        }

        return $out;
    }

    /** The real name, checked again right before it's used in SQL. */
    private static function realName(object $t): string
    {
        $name = FormData::real((string) $t->table_name);

        if (!preg_match(self::NAME, $name) || !preg_match('/^sf[da]_/', (string) $t->table_name)) {
            throw new \RuntimeException('Not a SquidForm data table.');
        }

        return $name;
    }

    /** Lower-case column names holding "hide what's typed" answers (any version of the form). */
    private static function masked(object $t, array $cols): array
    {
        if ((int) $t->form_id <= 0) {
            return [];
        }

        $db     = SubmissionStore::db();
        $hidden = [];

        foreach ($db->setQuery(
            $db->createQuery()->select('schema_json')->from($db->quoteName('#__squidform_form_versions'))->where('form_id = :f')->bind(':f', $t->form_id, ParameterType::INTEGER)
        )->loadColumn() as $json) {
            $def  = json_decode((string) $json, true) ?: [];
            $walk = function (array $list) use (&$walk, &$hidden): void {
                foreach ($list as $f) {
                    if (!\is_array($f)) {
                        continue;
                    }

                    if (!empty($f['hide_input']) && isset($f['key'])) {
                        $hidden[(string) $f['key']] = true;
                    }

                    if (!empty($f['fields']) && \is_array($f['fields'])) {
                        $walk($f['fields']);
                    }
                }
            };

            foreach ((array) ($def['pages'] ?? []) as $p) {
                $walk((array) ($p['fields'] ?? []));
            }
        }

        $out = [];

        foreach ($cols as $c) {
            if ($c->field_key !== '' && isset($hidden[$c->field_key])) {
                $out[strtolower($c->name)] = true;
            }
        }

        return $out;
    }

    private static function unregister(object $t): void
    {
        if ($t->id <= 0) {
            return;
        }

        $db = SubmissionStore::db();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_data_columns'))->where('table_id = :t')->bind(':t', $t->id, ParameterType::INTEGER))->execute();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_data_tables'))->where('id = :t')->bind(':t', $t->id, ParameterType::INTEGER))->execute();
        FormData::forget((int) $t->form_id);
    }

    private static function log(int $formId, string $action, array $diff): void
    {
        Audit::log(null, $formId, (int) Factory::getApplication()->getIdentity()->id, $action, $diff);
    }
}
