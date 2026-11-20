<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * List settings for the submissions viewer: columns, filters, sort and page
 * size. Three layers, first one set wins:
 *
 *   1. the menu item's settings
 *   2. the form definition's "viewer" block
 *   3. built-in defaults
 *
 * Column and filter names are top-level field keys or these submission
 * details: uid (the short reference, 0.6.0), id, created, modified, status, submitted_by. A field key wins if a
 * field happens to use one of those names.
 *
 *   "viewer": { "columns": ["id", "f_name", "f_site", "status", "created"],
 *               "filters": ["status", "f_site"], "sort": "created desc", "per_page": 20 }
 */
final class ViewerConfig
{
    public const META = ['uid', 'id', 'created', 'modified', 'status', 'submitted_by'];

    /** Details that can be filters (created = a from/to date range). */
    public const META_FILTERS = ['status', 'created'];

    /** Field types that can be filters (choose from their options, or yes/no). */
    public const FILTER_TYPES = ['select', 'radios', 'checkboxes', 'checkbox', 'multiselect', 'rating', 'lookup'];

    /** Field types that can be columns (repeaters show a row count). */
    public const COLUMN_TYPES = ['text', 'textarea', 'email', 'number', 'tel', 'url', 'date', 'hidden', 'select', 'radios', 'checkboxes', 'checkbox', 'user', 'repeater',
        'multiselect', 'time', 'datetime', 'rating', 'editor', 'signature', 'grid', 'lookup', 'name', 'address', 'upload', 'ranking', 'slider', 'daterange', 'phone', 'consent', 'imagechoice', 'calc'];

    /** Field types that can be sorted (one value per submission). */
    public const SORT_TYPES = ['text', 'email', 'number', 'tel', 'url', 'date', 'hidden', 'select', 'radios', 'checkbox', 'time', 'datetime', 'rating', 'lookup', 'slider', 'calc'];

    /**
     * Check a "viewer" block. $top = top-level field key => type.
     *
     * @param  array<string, string>  $top
     *
     * @return string[]
     */
    public static function validate(mixed $v, array $top): array
    {
        if (!\is_array($v) || ($v !== [] && array_is_list($v))) {
            return ['"viewer" must be an object.'];
        }

        $errors = [];

        foreach (['columns', 'filters'] as $list) {
            if (!isset($v[$list])) {
                continue;
            }

            if (!\is_array($v[$list]) || !array_is_list($v[$list])) {
                $errors[] = 'viewer.' . $list . ' must be a list of names.';
                continue;
            }

            foreach ($v[$list] as $name) {
                // Columns may be {"name": "...", "heading": "..."} to set the heading.
                if ($list === 'columns' && \is_array($name) && \is_string($name['name'] ?? null)) {
                    if (isset($name['heading']) && !\is_string($name['heading'])) {
                        $errors[] = 'viewer.columns: "heading" must be text.';
                    }

                    $name = $name['name'];
                }

                if (!\is_string($name)) {
                    $errors[] = 'viewer.' . $list . ': names must be text' . ($list === 'columns' ? ' or {"name", "heading"}' : '') . '.';
                    continue;
                }

                $ok = $list === 'columns'
                    ? (isset($top[$name]) ? \in_array($top[$name], self::COLUMN_TYPES, true) : \in_array($name, self::META, true))
                    : (isset($top[$name]) ? \in_array($top[$name], self::FILTER_TYPES, true) : \in_array($name, self::META_FILTERS, true));

                if (!$ok) {
                    $errors[] = 'viewer.' . $list . ': "' . $name . '" is not a field or detail that can be used here.';
                }
            }
        }

        if (isset($v['sort'])) {
            [$col, $dir] = self::splitSort((string) $v['sort']);

            if (!\is_string($v['sort']) || $dir === null || !self::sortable($col, $top)) {
                $errors[] = 'viewer.sort must be "<column> asc" or "<column> desc" with a sortable column.';
            }
        }

        if (isset($v['per_page']) && (!\is_int($v['per_page']) || $v['per_page'] < 5 || $v['per_page'] > 100)) {
            $errors[] = 'viewer.per_page must be a whole number from 5 to 100.';
        }

        return $errors;
    }

    /**
     * Effective settings for a form, with the menu item's settings on top.
     *
     * @return array{columns: list<array>, filters: list<array>, sort: string, dir: string, per_page: int}
     *         columns/filters items: ['name' => ..., 'kind' => 'meta'|'field', 'field' => array|null]
     */
    public static function resolve(Schema $schema, ?Registry $menu = null): array
    {
        $top = [];

        foreach ($schema->pages() as $i => $p) {
            foreach ($schema->pageFields($i) as $f) {
                $top[$f['key']] = $f;
            }
        }

        $types = array_map(static fn ($f) => (string) $f['type'], $top);
        $form  = $schema->viewer();
        $pick  = static function (string $name) use ($menu, $form) {
            $m = $menu ? $menu->get($name) : null;

            if (\is_array($m) && array_filter($m, 'strlen')) {
                return array_values(array_filter($m, 'strlen'));
            }

            if (\is_string($m) && $m !== '' && !\in_array($name, ['columns', 'filters'], true)) {
                return $m;
            }

            return $form[$name] ?? null;
        };

        // Columns: [{name, heading}] from the menu item / module (stored as JSON),
        // a plain list of names (0.3.0 menu items, form JSON), or objects.
        $cols = self::columnList($menu ? $menu->get('columns') : null) ?? self::columnList($form['columns'] ?? null);

        if ($cols === null) {
            $cols = ['uid'];

            foreach ($top as $k => $f) {
                if (\count($cols) >= 4) {
                    break;
                }

                if (\in_array($f['type'], self::COLUMN_TYPES, true) && $f['type'] !== 'repeater' && $f['type'] !== 'hidden') {
                    $cols[] = $k;
                }
            }

            $cols[] = 'status';
            $cols[] = 'created';
            $cols   = array_map(static fn ($c) => ['name' => $c, 'heading' => ''], $cols);
        }

        $columns = [];

        foreach ($cols as $cc) {
            $c = $cc['name'];
            $h = trim((string) $cc['heading']);

            if (isset($top[$c]) && \in_array($types[$c], self::COLUMN_TYPES, true)) {
                $columns[] = ['name' => $c, 'kind' => 'field', 'field' => $top[$c], 'heading' => $h];
            } elseif (!isset($top[$c]) && \in_array($c, self::META, true)) {
                $columns[] = ['name' => $c, 'kind' => 'meta', 'field' => null, 'heading' => $h];
            }
        }

        // Filters
        $flt     = $pick('filters');
        $flt     = \is_array($flt) ? $flt : ['status'];
        $filters = [];

        foreach ($flt as $c) {
            if (isset($top[$c]) && \in_array($types[$c], self::FILTER_TYPES, true)) {
                $filters[] = ['name' => $c, 'kind' => 'field', 'field' => $top[$c]];
            } elseif (!isset($top[$c]) && \in_array($c, self::META_FILTERS, true)) {
                $filters[] = ['name' => $c, 'kind' => 'meta', 'field' => null];
            }
        }

        // Sort
        $sortCol = $menu ? (string) $menu->get('sort', '') : '';
        $sortDir = $menu ? (string) $menu->get('sort_dir', '') : '';

        if ($sortCol === '' && \is_string($form['sort'] ?? null)) {
            [$sortCol, $fd] = self::splitSort($form['sort']);
            $sortDir = $sortDir ?: (string) $fd;
        }

        if (!self::sortable($sortCol, $types)) {
            $sortCol = 'created';
        }

        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $per = $menu ? (int) $menu->get('per_page', 0) : 0;
        $per = $per ?: (int) ($form['per_page'] ?? 20);

        return ['columns' => $columns, 'filters' => $filters, 'sort' => $sortCol, 'dir' => $sortDir, 'per_page' => max(5, min(100, $per))];
    }

    /**
     * Normalise a column setting to [{name, heading}], or null when unset.
     *
     * @return list<array{name: string, heading: string}>|null
     */
    public static function columnList(mixed $raw): ?array
    {
        if (\is_string($raw)) {
            $raw = trim($raw);

            if ($raw === '' || $raw === '[]') {
                return null;
            }

            $raw = json_decode($raw, true);
        }

        if (\is_object($raw)) {
            $raw = (array) $raw;
        }

        if (!\is_array($raw)) {
            return null;
        }

        $out = [];

        foreach ($raw as $c) {
            if (\is_object($c)) {
                $c = (array) $c;
            }

            if (\is_string($c) && $c !== '') {
                $out[] = ['name' => $c, 'heading' => ''];
            } elseif (\is_array($c) && \is_string($c['name'] ?? null) && $c['name'] !== '') {
                $out[] = ['name' => $c['name'], 'heading' => mb_substr((string) ($c['heading'] ?? ''), 0, 100)];
            }
        }

        return $out ?: null;
    }

    /** @param array<string, string> $types top-level key => type */
    public static function sortable(string $col, array $types): bool
    {
        if (isset($types[$col])) {
            return \in_array($types[$col], self::SORT_TYPES, true);
        }

        return \in_array($col, ['id', 'created', 'modified', 'status'], true);
    }

    /** @return array{0: string, 1: ?string} */
    private static function splitSort(string $s): array
    {
        $parts = preg_split('/\s+/', trim($s));
        $dir   = strtolower($parts[1] ?? 'asc');

        return [(string) ($parts[0] ?? ''), \in_array($dir, ['asc', 'desc'], true) && \count($parts) <= 2 ? $dir : null];
    }
}
