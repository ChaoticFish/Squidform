<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionException;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Options;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;

\defined('_JEXEC') or die;

/**
 * Read data (0.10.0): read rows from a table (or database view) on the read
 * allow-list, when a page opens or is sent.
 *
 *   "table":   "#__mytable"
 *   "columns": ["id", "title", "c.title"]      (c. = a joined table)
 *   "join":    [{"table": "#__categories", "as": "c", "on": ["catid", "id"], "type": "left"}]
 *   "where":   {"all": [{"col": "email", "op": "eq", "value": "{data:f_email}"}]}
 *   "order":   "title asc",  "limit": 100
 *   "mode":    "one" (a row), "all" (rows) or "count" (how many)
 *   "fill":    {"f_title": "title", "f_items": {"f_name": "title"}}
 *
 * Values in "where" may use placeholders ({data:…}, {user:id}, {url:id});
 * they are always bound as parameters, never written into the SQL. Table
 * and column names come from the allow-list and the table itself. There is
 * no way to write SQL here: anything more complex goes in a database view,
 * allow-listed like a table.
 *
 * Results: {action:KEY.column} (the row, or the first of all rows),
 * {action:KEY.count}, and "fill" copies columns into fields (a repeating
 * group gets one row per row read). When a page opens, only empty fields
 * are filled, so nothing a person typed is overwritten. Finding nothing is
 * a "fail", so later actions can use "when … failed".
 */
final class ReadDataAction implements ActionInterface
{
    private const IDENT = '/^(?:([a-z][a-z0-9_]{0,15})\.)?([A-Za-z_][A-Za-z0-9_]{0,63})$/';

    public function run(array $config, Context $ctx): ActionResult
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $main = Options::allowedTable((string) ($config['table'] ?? ''), 'read');
        } catch (\RuntimeException $e) {
            throw new ActionException($e->getMessage());
        }

        // Every table's real columns, by alias ("t" = the main table).
        $tables = ['t' => $main];
        $cols   = ['t' => array_change_key_case($db->getTableColumns($main, true), CASE_LOWER)];
        $query  = $db->createQuery()->from($db->quoteName($main, 't'));

        $col = static function (string $ref) use (&$cols): string {
            if (!preg_match(self::IDENT, trim($ref), $m)) {
                throw new ActionException('"' . $ref . '" isn’t a column name.');
            }

            $alias = $m[1] !== '' ? $m[1] : 't';

            if (!isset($cols[$alias])) {
                throw new ActionException('"' . $ref . '": there’s no joined table "' . $alias . '".');
            }

            if (!isset($cols[$alias][strtolower($m[2])])) {
                throw new ActionException('The table has no column "' . $ref . '".');
            }

            return $alias . '.' . $m[2];
        };

        foreach (\array_slice((array) ($config['join'] ?? []), 0, 3) as $j) {
            $alias = (string) ($j['as'] ?? '');

            if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $alias) || isset($tables[$alias])) {
                throw new ActionException('Each joined table needs its own short name ("as"), such as "c".');
            }

            try {
                $name = Options::allowedTable((string) ($j['table'] ?? ''), 'read');
            } catch (\RuntimeException $e) {
                throw new ActionException($e->getMessage());
            }

            $on = (array) ($j['on'] ?? []);

            if (\count($on) !== 2) {
                throw new ActionException('A join needs "on": [a column of the tables before it, a column of "' . $alias . '"].');
            }

            $left           = $col((string) $on[0]);
            $tables[$alias] = $name;
            $cols[$alias]   = array_change_key_case($db->getTableColumns($name, true), CASE_LOWER);
            $right          = $col($alias . '.' . preg_replace('/^' . preg_quote($alias, '/') . '\./', '', (string) $on[1]));
            $type           = ($j['type'] ?? 'left') === 'inner' ? 'innerJoin' : 'leftJoin';

            $query->$type($db->quoteName($name, $alias) . ' ON ' . $db->quoteName($left) . ' = ' . $db->quoteName($right));
        }

        $mode = (string) ($config['mode'] ?? 'one');

        if ($mode === 'count') {
            $query->select('COUNT(*)');
        } else {
            $list = array_values(array_filter(array_map('strval', (array) ($config['columns'] ?? []))));

            if (!$list || \count($list) > 50) {
                throw new ActionException('List the columns to read (1 to 50).');
            }

            $query->select(array_map(static fn ($c) => $db->quoteName($col($c), $c), $list));
        }

        $ph = Placeholders::forContext($ctx);

        if (!empty($config['where']) && \is_array($config['where'])) {
            $i   = 0;
            $sql = Options::whereSql($config['where'], $query, $db, $col, $ph, $i);

            if ($sql !== '') {
                $query->where($sql);
            }
        }

        if ($mode !== 'count') {
            $order = trim((string) ($config['order'] ?? ''));

            if ($order !== '') {
                $dir = 'ASC';

                if (preg_match('/^(\S+)\s+(asc|desc)$/i', $order, $m)) {
                    [$order, $dir] = [$m[1], strtoupper($m[2])];
                }

                $query->order($db->quoteName($col($order)) . ' ' . $dir);
            }

            $query->setLimit($mode === 'one' ? 1 : min(500, max(1, (int) ($config['limit'] ?? 100))));
        }

        $db->setQuery($query);

        if ($mode === 'count') {
            $n = (int) $db->loadResult();
            $this->fill($config, $ctx, ['count' => $n], null);

            return ActionResult::success($n, $n . ' found');
        }

        $rows = $db->loadAssocList() ?: [];

        if ($mode === 'one') {
            $row = $rows[0] ?? null;

            if ($row === null) {
                return ActionResult::fail('nothing found', null, true);
            }

            $this->fill($config, $ctx, $row, null);

            return ActionResult::success($row, '1 row');
        }

        $this->fill($config, $ctx, $rows[0] ?? [], $rows);

        return $rows ? ActionResult::success($rows, \count($rows) . ' rows') : ActionResult::fail('nothing found', [], true);
    }

    /**
     * Copy columns into fields. A repeating group takes every row (mode "all").
     *
     * @param array<string, mixed>           $row
     * @param list<array<string, mixed>>|null $rows
     */
    private function fill(array $config, Context $ctx, array $row, ?array $rows): void
    {
        foreach ((array) ($config['fill'] ?? []) as $key => $from) {
            $key   = (string) $key;
            $field = $ctx->schema->field($key);

            if (!$field || $ctx->schema->parentOf($key) !== null) {
                continue;
            }

            // On load, never over what the person already has.
            if ($ctx->event === 'load' && \array_key_exists($key, $ctx->data) && $ctx->data[$key] !== '' && $ctx->data[$key] !== null && $ctx->data[$key] !== []) {
                continue;
            }

            if (($field['type'] ?? '') === 'repeater') {
                if (!\is_array($from)) {
                    continue;
                }

                $out = [];

                foreach (\array_slice($rows ?? ($row ? [$row] : []), 0, (int) ($field['max'] ?? 50)) as $r) {
                    $line = [];

                    foreach ($from as $child => $c) {
                        $line[(string) $child] = self::text($r[(string) $c] ?? '');
                    }

                    $out[] = $line;
                }

                $ctx->data[$key] = $out;
                continue;
            }

            if (!\is_string($from) || !\array_key_exists($from, $row)) {
                continue;
            }

            $v = $row[$from];

            if (\in_array($field['type'] ?? '', ['checkboxes', 'multiselect', 'imagechoice'], true)) {
                $ctx->data[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn ($x) => $x !== ''));
            } else {
                $ctx->data[$key] = self::text($v);
            }
        }
    }

    private static function text(mixed $v): string
    {
        return $v === null ? '' : mb_substr((string) $v, 0, 65535);
    }
}
