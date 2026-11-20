<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Formula;

\defined('_JEXEC') or die;

/**
 * Calculation (0.10.0): a number worked out from other answers with a
 * formula, such as {f_qty} * {f_price}. People see it update as they type
 * (a read-only box), but the browser's figure is never trusted: the server
 * works it out again from the answers and saves its own result.
 *
 * "formula" (see Formula), "decimals" (0–10; empty = as it comes),
 * "prefix" ("$"), "suffix" ("km"), "thousands" (true: 1,234.50).
 * Inside a repeating group it works per row, from that row's fields.
 */
final class CalcField extends AbstractField
{
    /** @var array<string, array|null> formula text => tree (null = doesn't parse) */
    private static array $trees = [];

    public static function tree(array $field): ?array
    {
        $f = (string) ($field['formula'] ?? '');

        if (!\array_key_exists($f, self::$trees)) {
            try {
                self::$trees[$f] = Formula::parse($f);
            } catch (\InvalidArgumentException) {
                self::$trees[$f] = null;
            }
        }

        return self::$trees[$f];
    }

    /** @var \WeakMap<\SquidForm\Component\SquidForm\Administrator\Engine\Schema, array<string, true>>|null */
    private static ?\WeakMap $used = null;

    /**
     * Keys the browser reads: in show/hide rules, "show parts by" and
     * calculations that it works out (named values are walked too, as they
     * may feed one another).
     */
    public static function browserNeeds(\SquidForm\Component\SquidForm\Administrator\Engine\Schema $schema, string $key): bool
    {
        self::$used ??= new \WeakMap();

        if (!isset(self::$used[$schema])) {
            $keys = [];
            $walk = static function (array $fields) use (&$walk, &$keys): void {
                foreach ($fields as $f) {
                    if (!\is_array($f)) {
                        continue;
                    }

                    if (\is_array($f['show_if'] ?? null)) {
                        foreach (\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::referencedFields($f['show_if']) as $k) {
                            $keys[$k] = true;
                        }
                    }

                    if (\is_string($f['parts_by'] ?? null)) {
                        $keys[$f['parts_by']] = true;
                    }

                    if (($f['type'] ?? '') === 'calc' && ($tree = self::tree($f)) !== null) {
                        foreach (\SquidForm\Component\SquidForm\Administrator\Engine\Formula::refs($tree) as $ref) {
                            $keys[$ref[0]] = true;
                        }
                    }

                    $walk(\is_array($f['fields'] ?? null) ? $f['fields'] : []);
                }
            };

            for ($p = 0; $p < $schema->pageCount(); $p++) {
                $walk((array) ($schema->page($p)['fields'] ?? []));
            }

            self::$used[$schema] = $keys;
        }

        return isset(self::$used[$schema][$key]);
    }

    /** 0.13.1: the formula is age(…, …): shown as "34 years, 2 months and 5 days". */
    public static function isAge(array $field): bool
    {
        $t = self::tree($field);

        return \is_array($t) && ($t[0] ?? '') === 'fn' && ($t[1] ?? '') === 'age';
    }

    /** 340205 → "34 years, 2 months and 5 days"; parts that are 0 are left out. */
    public static function ageText(int $n): string
    {
        $parts = [];

        foreach ([[intdiv($n, 10000), 'YEARS'], [intdiv($n % 10000, 100), 'MONTHS'], [$n % 100, 'DAYS']] as [$v, $unit]) {
            if ($v > 0) {
                $parts[] = Text::plural('COM_SQUIDFORM_AGE_' . $unit, $v);
            }
        }

        if (!$parts) {
            return Text::plural('COM_SQUIDFORM_AGE_DAYS', 0);
        }

        $last = array_pop($parts);

        return $parts ? Text::sprintf('COM_SQUIDFORM_AGE_JOIN', implode(', ', $parts), $last) : $last;
    }

    public static function decimals(array $field): ?int
    {
        return isset($field['decimals']) && \is_int($field['decimals']) ? max(0, min(10, $field['decimals'])) : null;
    }

    /** "$1,234.50 km" */
    public static function format(array $field, mixed $v): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '';
        }

        if (self::isAge($field)) {
            return self::ageText((int) $v);
        }

        $d   = self::decimals($field);
        $num = (float) $v;

        if ($d === null) {
            $parts = explode('.', Formula::store($num, null));
            $d     = isset($parts[1]) ? \strlen($parts[1]) : 0;
        }

        $s = number_format($num, $d, '.', !empty($field['thousands']) ? ',' : '');

        if ($s === '-0' || preg_match('/^-0\.0+$/', $s)) {
            $s = ltrim($s, '-');
        }

        $pre = (string) ($field['prefix'] ?? '');
        $suf = (string) ($field['suffix'] ?? '');

        return ($num < 0 && $pre !== '' ? '-' . $pre . ltrim($s, '-') : $pre . $s) . ($suf !== '' ? ' ' . $suf : '');
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key  = $field['key'];
        $id   = $rc->id($key);
        $tree = self::tree($field);
        $db   = self::describedBy($field, $rc);

        // 0.13.0: a named value is worked out the same way but not shown. It
        // goes to the browser only when a show/hide rule or another
        // calculation there needs it; otherwise only the server knows it.
        if (!empty($field['named'])) {
            if (!self::browserNeeds($rc->schema, $key)) {
                return '';
            }

            return '<span class="sf-named" data-sf-key="' . self::e($key) . '">'
                . '<input type="hidden" id="' . $id . '" data-sf-calc="' . self::e(json_encode($tree)) . '"'
                . (self::decimals($field) !== null ? ' data-sf-decimals="' . self::decimals($field) . '"' : '') . '>'
                . '<input type="hidden" name="' . self::e($rc->name($key)) . '" value="' . self::e((string) $value) . '" data-sf-calc-value></span>';
        }

        $html = '<input type="text" readonly class="form-control sf-calc-out" id="' . $id . '" value="' . self::e(self::format($field, $value)) . '"'
            . ($db !== '' ? ' aria-describedby="' . $db . '"' : '')
            . ' data-sf-calc="' . self::e(json_encode($tree)) . '"'
            . (self::decimals($field) !== null ? ' data-sf-decimals="' . self::decimals($field) . '"' : '')
            . (!empty($field['prefix']) ? ' data-sf-prefix="' . self::e($field['prefix']) . '"' : '')
            . (!empty($field['suffix']) ? ' data-sf-suffix="' . self::e($field['suffix']) . '"' : '')
            . (!empty($field['thousands']) ? ' data-sf-thousands="1"' : '')
            . (self::isAge($field) ? ' data-sf-age="1"' : '') . '>'
            . '<input type="hidden" name="' . self::e($rc->name($key)) . '" value="' . self::e((string) $value) . '" data-sf-calc-value>';

        return self::wrap($field, $rc, $html, false, 'sf-calc');
    }

    /** What the browser posts is ignored: the server works it out (Calc::apply). */
    public function normalize(array $field, mixed $raw): mixed
    {
        return '';
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        return [];
    }
}
