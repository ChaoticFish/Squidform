<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Builds option lists for select, radios and checkboxes.
 *
 * Sources (exactly one per field):
 *   "static": ["A", "B"]  or  [{"value": "a", "label": "A"}]
 *   "table":  {"name": "#__orgs", "value": "id", "label": "title",
 *              "where": {"all": [{"col": "published", "op": "eq", "value": 1}]},
 *              "order": "title", "limit": 500}
 *   "file":   {"path": "offices.json"}   (JSON, CSV or TXT in the options folder)
 *   "form":   {"alias": "event-categories", "value": "f_key", "label": "f_label",
 *              "where": {"all": [{"field": "f_on", "op": "eq", "value": true}]}, "order": "f_label asc"}
 *             (1.0.3) another form's finished answers. That form must list each
 *             field used here in its settings.shared_fields.
 *   "addon":  "tidetable.categories"     (1.0.3) a list an add-on offers (onSquidformOptionSources)
 *
 * Cascading: add "filter_by": {"field": "f_parent", "key": "parent_col"} to
 * table or file sources. Only rows whose column/key equals the parent field's
 * value are offered, and submitted values are checked against that same list.
 *
 * Tables must be on the read allow-list in the component options. A few
 * Joomla tables that hold secrets can never be read, whatever the list says.
 */
final class Options
{
    /** Tables that are never readable through SquidForm (prefix-less names). */
    public const BLOCKED_TABLES = [
        'users', 'session', 'user_keys', 'user_mfa', 'webauthn_credentials',
        'user_profiles', 'privacy_consents', 'privacy_requests', 'action_logs',
        'mail_templates', 'extensions', 'user_usergroup_map', 'usergroups',
        'viewlevels', 'assets', 'fields_values', 'history', 'user_notes',
        'messages', 'messages_cfg', 'contact_details', 'api_tokens',
    ];

    /** Table name prefixes that are never readable (SquidForm's own data, etc.). */
    public const BLOCKED_PREFIXES = ['squidform_'];

    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /** @var array<string, list<array{value: string, label: string}>> */
    private static array $cache = [];

    /**
     * @param   array<string, mixed>  $field
     * @param   mixed                 $parentValue  Current value of the filter_by parent, if any.
     *
     * @return  list<array{value: string, label: string}>
     */
    public static function resolve(array $field, mixed $parentValue = null, ?Placeholders $ph = null): array
    {
        $opts = $field['options'] ?? [];
        $ck   = md5(json_encode([$field['key'] ?? '', $opts, $parentValue]));

        if (isset(self::$cache[$ck])) {
            return self::$cache[$ck];
        }

        if (isset($opts['static'])) {
            $list = self::fromList((array) $opts['static']);
        } elseif (isset($opts['table'])) {
            $list = self::fromTable((array) $opts['table'], $parentValue, $ph);
        } elseif (isset($opts['file'])) {
            $list = self::fromFile((array) $opts['file'], $parentValue);
        } elseif (isset($opts['user_field'])) {
            // 0.14.0: a Joomla user field's own choices (the list, not anyone's answer).
            $list = UserFacts::options((string) $opts['user_field']);
        } elseif (isset($opts['form'])) {
            $list = self::fromForm((array) $opts['form']);
        } elseif (isset($opts['addon'])) {
            $list = Addons::options((string) $opts['addon']);
        } else {
            $list = [];
        }

        return self::$cache[$ck] = $list;
    }

    /**
     * value => label for every option, ignoring any cascade filter.
     *
     * @param   array<string, mixed>  $field
     *
     * @return  array<string, string>
     */
    public static function labelMap(array $field): array
    {
        $opts = $field['options'] ?? [];

        // For a cascaded source, read the whole list (no parent filter) so any stored value maps.
        foreach (['table', 'file'] as $src) {
            if (isset($opts[$src]['filter_by'])) {
                unset($opts[$src]['filter_by']);
            }
        }

        $map = [];

        foreach (self::resolve(['key' => ($field['key'] ?? '') . '#all', 'options' => $opts]) as $o) {
            $map[$o['value']] = $o['label'];
        }

        return $map;
    }

    /** True when a field's options depend on another field. */
    public static function parentOf(array $field): ?string
    {
        $o = $field['options'] ?? [];

        return $o['table']['filter_by']['field'] ?? $o['file']['filter_by']['field'] ?? null;
    }

    /** Operators a "form" source's where may use, as the reader spells them. */
    private const FORM_OPS = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    /**
     * 1.0.3: choices from another form's finished (sent or approved), unarchived,
     * non-test answers, through the add-on reader. The other form opts in by
     * listing the fields others may use in settings.shared_fields; anything else
     * gives no choices (and a log line), so a builder can't show another form's
     * private answers by naming them.
     *
     * @param   array<string, mixed>  $cfg
     *
     * @return  list<array{value: string, label: string}>
     */
    private static function fromForm(array $cfg): array
    {
        $alias = (string) ($cfg['alias'] ?? '');
        $api   = $alias !== '' ? \SquidForm\Component\SquidForm\Administrator\Api\Submissions::form($alias) : null;

        if ($api === null) {
            Addons::log('a form takes its choices from the form "' . $alias . '", which doesn\'t exist.');

            return [];
        }

        $shared = array_map('strval', (array) $api->schema()->setting('shared_fields', []));
        $value  = (string) ($cfg['value'] ?? '');
        $label  = (string) ($cfg['label'] ?? $value);
        $need   = [$value, $label];
        $where  = [];

        foreach ((array) ($cfg['where']['all'] ?? []) as $c) {
            $c = (array) $c;

            if (!isset(self::FORM_OPS[$c['op'] ?? 'eq'])) {
                return [];
            }

            $need[]  = (string) ($c['field'] ?? '');
            $where[] = [(string) ($c['field'] ?? ''), self::FORM_OPS[$c['op'] ?? 'eq'], $c['value'] ?? null];
        }

        $order = [];

        if (isset($cfg['order']) && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(asc|desc))?$/i', trim((string) $cfg['order']), $m)) {
            $need[]  = $m[1];
            $order[] = [$m[1], strtolower($m[2] ?? 'asc')];
        }

        if ($missing = array_diff(array_unique($need), $shared)) {
            Addons::log('the form "' . $alias . '" doesn\'t share ' . implode(', ', $missing) . ' (its settings.shared_fields), so another form can\'t use it for choices.');

            return [];
        }

        try {
            $rows = $api->find(['where' => $where, 'order' => $order ?: [['created', 'asc']], 'limit' => max(1, min(1000, (int) ($cfg['limit'] ?? 500)))]);
        } catch (\Throwable $e) {
            Addons::log('choices from the form "' . $alias . '" failed: ' . $e->getMessage());

            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($rows as $r) {
            $v = $r['data'][$value] ?? '';
            $l = $r['data'][$label] ?? $v;
            $v = \is_array($v) ? implode(', ', $v) : (string) $v;
            $l = \is_array($l) ? implode(', ', $l) : (string) $l;

            if ($v === '' || isset($seen[$v])) {
                continue;
            }

            $seen[$v] = true;
            $out[]    = ['value' => $v, 'label' => $l === '' ? $v : $l];
        }

        return $out;
    }

    /**
     * @param   array<int|string, mixed>  $items
     *
     * @return  list<array{value: string, label: string}>
     */
    private static function fromList(array $items): array
    {
        $out = [];

        foreach ($items as $k => $item) {
            if (\is_array($item)) {
                $v = (string) ($item['value'] ?? $item['label'] ?? '');
                $l = (string) ($item['label'] ?? $v);
            } elseif (\is_string($k) && !is_numeric($k)) {
                // {"a": "Label A"} object form
                $v = $k;
                $l = (string) $item;
            } else {
                $v = $l = (string) $item;
            }

            $out[] = ['value' => $v, 'label' => $l];
        }

        return $out;
    }

    /**
     * @param   array<string, mixed>  $cfg
     *
     * @return  list<array{value: string, label: string}>
     */
    private static function fromTable(array $cfg, mixed $parentValue, ?Placeholders $ph): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $table = self::allowedTable((string) $cfg['name'], 'read');
        $cols  = array_change_key_case($db->getTableColumns($table, true), CASE_LOWER);

        $col = static function (string $name) use ($cols): string {
            if (!preg_match(self::IDENT, $name) || !isset($cols[strtolower($name)])) {
                throw new \RuntimeException('Unknown column "' . $name . '" in option source.');
            }

            return $name;
        };

        $query = $db->createQuery()
            ->select([$db->quoteName($col((string) $cfg['value']), 'value'), $db->quoteName($col((string) $cfg['label']), 'label')])
            ->from($db->quoteName($table));

        if (!empty($cfg['where'])) {
            $i   = 0;
            $sql = self::whereSql((array) $cfg['where'], $query, $db, $col, $ph, $i);

            if ($sql !== '') {
                $query->where($sql);
            }
        }

        if (!empty($cfg['filter_by'])) {
            $fc = $col((string) ($cfg['filter_by']['column'] ?? $cfg['filter_by']['key'] ?? ''));

            if ($parentValue === null || $parentValue === '' || \is_array($parentValue)) {
                return [];
            }

            $pv = (string) $parentValue;
            $query->where($db->quoteName($fc) . ' = :sfparent')->bind(':sfparent', $pv);
        }

        $order = trim((string) ($cfg['order'] ?? $cfg['label']));
        $dir   = 'ASC';

        if (preg_match('/^(\S+)\s+(asc|desc)$/i', $order, $m)) {
            [$order, $dir] = [$m[1], strtoupper($m[2])];
        }

        $query->order($db->quoteName($col($order)) . ' ' . $dir)
            ->setLimit(min(5000, max(1, (int) ($cfg['limit'] ?? 1000))));

        $rows = $db->setQuery($query)->loadAssocList();

        return array_map(static fn ($r) => ['value' => (string) $r['value'], 'label' => (string) $r['label']], $rows);
    }

    /**
     * Turn a {all|any|not / col op value} tree into SQL with bound parameters.
     *
     * @param   array<string, mixed>  $cond
     */
    public static function whereSql(array $cond, $query, DatabaseInterface $db, callable $col, ?Placeholders $ph, int &$i, int $depth = 0): string
    {
        if ($depth > 8) {
            throw new \RuntimeException('Option filter is nested too deeply.');
        }

        foreach (['all' => ' AND ', 'any' => ' OR '] as $group => $glue) {
            if (isset($cond[$group])) {
                $parts = [];

                foreach ((array) $cond[$group] as $c) {
                    $s = self::whereSql((array) $c, $query, $db, $col, $ph, $i, $depth + 1);

                    if ($s !== '') {
                        $parts[] = $s;
                    }
                }

                return $parts ? '(' . implode($glue, $parts) . ')' : '';
            }
        }

        if (isset($cond['not'])) {
            $s = self::whereSql((array) $cond['not'], $query, $db, $col, $ph, $i, $depth + 1);

            return $s !== '' ? 'NOT ' . $s : '';
        }

        $name  = $db->quoteName($col((string) ($cond['col'] ?? '')));
        $op    = (string) ($cond['op'] ?? 'eq');
        $value = $cond['value'] ?? null;

        if (\is_string($value) && $ph) {
            $value = $ph->replace($value, 'text');
        }

        $bind = static function ($v) use ($query, &$i): string {
            $p = ':sfw' . $i++;
            $query->bind($p, $v, \is_int($v) ? ParameterType::INTEGER : ParameterType::STRING);

            return $p;
        };

        switch ($op) {
            case 'eq':   return $name . ' = ' . $bind($value);
            case 'neq':  return $name . ' <> ' . $bind($value);
            case 'gt':   return $name . ' > ' . $bind($value);
            case 'gte':  return $name . ' >= ' . $bind($value);
            case 'lt':   return $name . ' < ' . $bind($value);
            case 'lte':  return $name . ' <= ' . $bind($value);
            case 'like': return $name . ' LIKE ' . $bind('%' . $value . '%');
            case 'empty':  return '(' . $name . ' IS NULL OR ' . $name . " = '')";
            case 'filled': return '(' . $name . ' IS NOT NULL AND ' . $name . " <> '')";
            case 'in':
            case 'nin':
                $vals = array_values((array) $value);

                if (!$vals) {
                    return $op === 'in' ? '1 = 0' : '';
                }

                $ps = [];

                foreach ($vals as $v) {
                    $ps[] = $bind($v);
                }

                return $name . ($op === 'in' ? ' IN (' : ' NOT IN (') . implode(',', $ps) . ')';
        }

        throw new \RuntimeException('Unknown operator "' . $op . '" in option filter.');
    }

    /**
     * @param   array<string, mixed>  $cfg
     *
     * @return  list<array{value: string, label: string}>
     */
    private static function fromFile(array $cfg, mixed $parentValue): array
    {
        $path = self::optionsFile((string) $cfg['path']);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $raw  = (string) file_get_contents($path);
        $rows = [];

        if ($ext === 'json') {
            $data = json_decode($raw, true);

            if (!\is_array($data)) {
                throw new \RuntimeException('Option file is not valid JSON: ' . basename($path));
            }

            foreach ($data as $k => $item) {
                $rows[] = \is_array($item) ? $item : (\is_string($k) && !is_numeric($k) ? ['value' => $k, 'label' => $item] : ['value' => $item, 'label' => $item]);
            }
        } elseif ($ext === 'csv') {
            $fh     = fopen('php://memory', 'r+');
            fwrite($fh, $raw);
            rewind($fh);
            $header = fgetcsv($fh, null, ',', '"', '\\');

            if (!$header) {
                return [];
            }

            $header = array_map(static fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \t")), $header);

            while (($line = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
                if ($line === [null]) {
                    continue;
                }

                $rows[] = array_combine($header, array_pad(\array_slice($line, 0, \count($header)), \count($header), ''));
            }

            fclose($fh);
        } else {
            foreach (preg_split('/\R/', $raw) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                [$v, $l] = array_pad(explode('|', $line, 2), 2, null);
                $rows[]  = ['value' => trim($v), 'label' => trim($l ?? $v)];
            }
        }

        if (!empty($cfg['filter_by'])) {
            $key = (string) ($cfg['filter_by']['key'] ?? $cfg['filter_by']['column'] ?? '');

            if ($parentValue === null || $parentValue === '' || \is_array($parentValue)) {
                return [];
            }

            $rows = array_filter($rows, static fn ($r) => (string) ($r[$key] ?? '') === (string) $parentValue);
        }

        return array_values(array_map(static fn ($r) => [
            'value' => (string) ($r['value'] ?? $r['label'] ?? ''),
            'label' => (string) ($r['label'] ?? $r['value'] ?? ''),
        ], $rows));
    }

    /**
     * Resolve a table name and check it against the allow-list for 'read' or 'write'.
     *
     * @throws \RuntimeException
     */
    public static function allowedTable(string $name, string $mode): string
    {
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $prefix = $db->getPrefix();
        $name   = trim($name);
        $short  = str_starts_with($name, '#__') ? substr($name, 3) : (str_starts_with($name, $prefix) ? substr($name, \strlen($prefix)) : $name);

        if (!preg_match(self::IDENT, $short)) {
            throw new \RuntimeException('Invalid table name.');
        }

        $lower = strtolower($short);

        if (\in_array($lower, self::BLOCKED_TABLES, true)
            || array_filter(self::BLOCKED_PREFIXES, static fn ($p) => str_starts_with($lower, $p))) {
            throw new \RuntimeException('The table "#__' . $short . '" can never be used by SquidForm.');
        }

        // Writing outside SquidForm's own data tables (<prefix>_sfd_*) needs an
        // explicit Options switch, off by default, as well as the allow-list.
        if ($mode === 'write' && !str_starts_with($lower, 'sfd_')
            && !(int) ComponentHelper::getParams('com_squidform')->get('allow_external_writes', 0)) {
            throw new \RuntimeException('Writing to "#__' . $short . '" is not allowed: only SquidForm data tables (sfd_) can be written unless "Allow writing outside SquidForm data tables" is on (Options).');
        }

        $param   = $mode === 'write' ? 'write_tables' : 'read_tables';
        $allowed = preg_split('/[\s,]+/', (string) ComponentHelper::getParams('com_squidform')->get($param, ''), -1, PREG_SPLIT_NO_EMPTY);
        $allowed = array_map(static fn ($t) => strtolower(str_starts_with($t, '#__') ? substr($t, 3) : (str_starts_with($t, $prefix) ? substr($t, \strlen($prefix)) : $t)), $allowed);

        if (!\in_array(strtolower($short), $allowed, true)) {
            throw new \RuntimeException('The table "#__' . $short . '" is not on the SquidForm ' . $mode . ' allow-list (Options).');
        }

        return '#__' . $short;
    }

    /**
     * Resolve an option file inside the options folder, refusing anything outside it.
     */
    public static function optionsFile(string $relative): string
    {
        $folder = trim((string) ComponentHelper::getParams('com_squidform')->get('options_folder', 'images/squidform/options'), '/');
        $base   = realpath(JPATH_ROOT . '/' . $folder);

        if ($base === false) {
            throw new \RuntimeException('The options folder "' . $folder . '" does not exist.');
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (!\in_array($ext, ['json', 'csv', 'txt'], true)) {
            throw new \RuntimeException('Option files must be .json, .csv or .txt.');
        }

        $full = realpath($base . '/' . ltrim($relative, '/'));

        if ($full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            throw new \RuntimeException('Option file not found in the options folder: ' . $relative);
        }

        return $full;
    }
}
