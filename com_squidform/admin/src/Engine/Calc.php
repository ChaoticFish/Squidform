<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use SquidForm\Component\SquidForm\Administrator\Engine\Field\CalcField;

\defined('_JEXEC') or die;

/**
 * Works out every Calculation field (0.10.0) from the answers, on the
 * server. Calculations may use other calculations; the definition check
 * refuses loops, so as many passes as there are calculations settle them.
 */
final class Calc
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    public static function apply(Schema $schema, array $data, ?int $upToPage = null): array
    {
        $top  = [];
        $kids = []; // repeater key => list of calc children

        $last = $upToPage ?? $schema->pageCount() - 1;

        for ($p = 0; $p <= $last && $p < $schema->pageCount(); $p++) {
            foreach ($schema->pageFields($p) as $f) {
                if (($f['type'] ?? '') === 'calc') {
                    $top[] = $f;
                }

                if (($f['type'] ?? '') === 'repeater') {
                    foreach ($f['fields'] ?? [] as $c) {
                        if (($c['type'] ?? '') === 'calc') {
                            $kids[$f['key']][] = $c;
                        }
                    }
                }
            }
        }

        if (!$top && !$kids) {
            return $data;
        }

        $rows = static function (string $k) use (&$data, $schema): ?array {
            return ($schema->field($k)['type'] ?? '') === 'repeater' ? array_values(\is_array($data[$k] ?? null) ? $data[$k] : []) : null;
        };

        $passes = \count($top) + array_sum(array_map('count', $kids)) + 1;

        for ($i = 0; $i < $passes; $i++) {
            foreach ($kids as $rk => $calcs) {
                if (!\is_array($data[$rk] ?? null)) {
                    continue;
                }

                foreach ($data[$rk] as $ri => $row) {
                    foreach ($calcs as $c) {
                        $tree = CalcField::tree($c);
                        $get  = static fn (string $k) => \is_array($row) && \array_key_exists($k, $row) ? $row[$k] : ($data[$k] ?? null);
                        $data[$rk][$ri][$c['key']] = $tree ? Formula::store(Formula::evaluate($tree, $get, $rows), CalcField::decimals($c)) : '';
                        $row = $data[$rk][$ri];
                    }
                }
            }

            foreach ($top as $c) {
                $tree             = CalcField::tree($c);
                $get              = static fn (string $k) => $data[$k] ?? null;
                $data[$c['key']]  = $tree ? Formula::store(Formula::evaluate($tree, $get, $rows), CalcField::decimals($c)) : '';
            }
        }

        return $data;
    }

    /**
     * Definition problems with the calculations: unknown fields, fields on a
     * later page, a repeating group's fields used outside sum() and the like,
     * and calculations that use each other in a loop.
     *
     * @param  list<array<string, mixed>>                      $calcs  each with "_page" and "_parent"
     * @param  array<string, array{page: int, parent: ?string}> $fieldKeys
     * @param  array<string, string>                            $types  key => type
     *
     * @return string[]
     */
    public static function problems(array $calcs, array $fieldKeys, array $types): array
    {
        $errors = [];
        $uses   = []; // calc key => calc keys it reads

        foreach ($calcs as $c) {
            $where = 'pages[' . $c['_page'] . '] ' . $c['key'] . '.formula';

            try {
                $tree = Formula::parse((string) ($c['formula'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                $errors[] = $where . ': ' . $e->getMessage() . '.';
                continue;
            }

            if ($p = Formula::rowProblem($tree)) {
                $errors[] = $where . ': ' . $p . '.';
            }

            $uses[$c['key']] = [];

            foreach (Formula::refs($tree) as $ref) {
                $key  = $ref[\count($ref) - 1];
                $info = $fieldKeys[$key] ?? null;
                $name = '{' . implode('.', $ref) . '}';

                if ($info === null) {
                    $errors[] = $where . ': ' . $name . ' isn’t a field on this form.';
                    continue;
                }

                if (\in_array($types[$key] ?? '', Schema::DISPLAY_TYPES, true)) {
                    $errors[] = $where . ': ' . $name . ' is a ' . $types[$key] . ', which has no value.';
                    continue;
                }

                if (\count($ref) === 2) {
                    if (($types[$ref[0]] ?? '') !== 'repeater' || $info['parent'] !== $ref[0]) {
                        $errors[] = $where . ': ' . $name . ': "' . $key . '" isn’t a field of the repeating group "' . $ref[0] . '".';
                        continue;
                    }

                    $group = $fieldKeys[$ref[0]];

                    if ($group['page'] > $c['_page']) {
                        $errors[] = $where . ': ' . $name . ' is on a later page, so its value isn’t known yet.';
                    }
                } else {
                    if ($info['parent'] !== null && $info['parent'] !== $c['_parent']) {
                        $errors[] = $where . ': ' . $name . ' is in the repeating group "' . $info['parent'] . '": write {' . $info['parent'] . '.' . $key . '} inside sum(), avg(), min(), max() or count().';
                        continue;
                    }

                    if ($info['page'] > $c['_page']) {
                        $errors[] = $where . ': ' . $name . ' is on a later page, so its value isn’t known yet.';
                    }
                }

                if ($key === $c['key']) {
                    $errors[] = $where . ': a calculation can’t use itself.';
                } elseif (($types[$key] ?? '') === 'calc') {
                    $uses[$c['key']][] = $key;
                }
            }
        }

        // Loops: a uses b uses a.
        $state = [];
        $visit = static function (string $k, array $path) use (&$visit, &$state, $uses, &$errors): void {
            if (($state[$k] ?? 0) === 2) {
                return;
            }

            if (($state[$k] ?? 0) === 1) {
                $loop     = \array_slice($path, (int) array_search($k, $path, true));
                $errors[] = 'Calculations use each other in a loop: ' . implode(' → ', array_merge($loop, [$k])) . '.';

                return;
            }

            $state[$k] = 1;

            foreach ($uses[$k] ?? [] as $n) {
                $visit($n, array_merge($path, [$k]));
            }

            $state[$k] = 2;
        };

        foreach (array_keys($uses) as $k) {
            $visit($k, []);
        }

        return array_values(array_unique($errors));
    }
}
