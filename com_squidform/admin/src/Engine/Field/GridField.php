<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * A grid or matrix (0.8.1): questions down the side ("rows"), answers across
 * the top ("columns"). "cell" says what each cell holds:
 *
 *   radio     one column per row          {"food": "good", "service": "poor"}
 *   checkbox  any columns per row         {"food": ["lunch", "dinner"]}
 *   text      a short answer per cell     {"mon": {"start": "09:00", "end": "17:00"}}
 *   select    a dropdown per cell         {"alice": {"role": "lead"}}
 *
 * Rows and columns are strings or {"value", "label"} objects. Dropdowns take
 * their choices from the column's "choices", else the field's "choices".
 *
 * It's a real <table>: each cell's control is named by its row and column
 * headings (aria-labelledby), radio groups run along a row, and the table
 * scrolls sideways on its own when it's wider than the screen.
 *
 * "required": every row needs an answer. A text cell holds up to 255 characters.
 */
final class GridField extends AbstractField
{
    public const CELLS = ['radio', 'checkbox', 'text', 'select'];

    /** @return list<array{value: string, label: string, choices?: list<array{value: string, label: string}>}> */
    public static function items(mixed $list): array
    {
        $out = [];

        foreach (\is_array($list) ? $list : [] as $it) {
            if (\is_scalar($it)) {
                $out[] = ['value' => (string) $it, 'label' => (string) $it];
            } elseif (\is_array($it) && isset($it['value'])) {
                $o = ['value' => (string) $it['value'], 'label' => (string) ($it['label'] ?? $it['value'])];

                if (isset($it['choices'])) {
                    $o['choices'] = self::items($it['choices']);
                }

                $out[] = $o;
            }
        }

        return $out;
    }

    public static function cell(array $field): string
    {
        return \in_array($field['cell'] ?? 'radio', self::CELLS, true) ? (string) ($field['cell'] ?? 'radio') : 'radio';
    }

    /** @return list<array{value: string, label: string}> */
    private static function choicesFor(array $field, array $col): array
    {
        return $col['choices'] ?? self::items($field['choices'] ?? []);
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $cell   = self::cell($field);
        $rows   = self::items($field['rows'] ?? []);
        $cols   = self::items($field['columns'] ?? []);
        $vals   = \is_array($value) ? $value : [];
        $err    = $rc->error($key) !== null;
        $locked = isset($rc->locked[$key]) || !empty($field['readonly']);
        $db     = self::describedBy($field, $rc);

        $html = '<div class="table-responsive sf-grid-wrap" tabindex="0" role="group" aria-labelledby="' . $id . '-group-legend">'
            . '<table class="table table-sm align-middle sf-grid-table sf-grid-' . $cell . '">'
            . '<thead><tr><td></td>';

        foreach ($cols as $ci => $c) {
            $html .= '<th scope="col" id="' . $id . '-c' . $ci . '">' . self::e($c['label']) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $ri => $r) {
            $rv    = $vals[$r['value']] ?? null;
            $rid   = $id . '-r' . $ri;
            $rbad  = $err && !empty($field['rules']['required']) && self::rowEmpty($rv);
            $html .= '<tr' . ($rbad ? ' class="sf-grid-missing"' : '') . '><th scope="row" id="' . $rid . '">' . self::e($r['label']) . '</th>';

            foreach ($cols as $ci => $c) {
                $cid   = $id . '-' . $ri . '-' . $ci;
                $named = ' id="' . $cid . '" aria-labelledby="' . $rid . ' ' . $id . '-c' . $ci . '"'
                    . ($db !== '' ? ' aria-describedby="' . $db . '"' : '') . ($rbad ? ' aria-invalid="true"' : '');

                switch ($cell) {
                    case 'radio':
                        $ctl = '<input class="form-check-input" type="radio" name="' . self::e($name . '[' . $r['value'] . ']') . '" value="' . self::e($c['value']) . '"' . $named
                            . ((string) $rv === $c['value'] ? ' checked' : '') . ($locked ? ' disabled' : '') . '>';
                        break;

                    case 'checkbox':
                        $ctl = '<input class="form-check-input" type="checkbox" name="' . self::e($name . '[' . $r['value'] . '][]') . '" value="' . self::e($c['value']) . '"' . $named
                            . (\in_array($c['value'], array_map('strval', (array) $rv), true) ? ' checked' : '') . ($locked ? ' disabled' : '') . '>';
                        break;

                    case 'select':
                        $cur = (string) ((\is_array($rv) ? $rv : [])[$c['value']] ?? '');
                        $ctl = '<select class="form-select form-select-sm" name="' . self::e($name . '[' . $r['value'] . '][' . $c['value'] . ']') . '"' . $named . ($locked ? ' disabled' : '') . '>'
                            . '<option value="">' . self::e($field['empty_label'] ?? '—') . '</option>';

                        foreach (self::choicesFor($field, $c) as $o) {
                            $ctl .= '<option value="' . self::e($o['value']) . '"' . ($o['value'] === $cur ? ' selected' : '') . '>' . self::e($o['label']) . '</option>';
                        }

                        $ctl .= '</select>';
                        break;

                    default:
                        $cur = (string) ((\is_array($rv) ? $rv : [])[$c['value']] ?? '');
                        $ctl = '<input class="form-control form-control-sm" type="text" maxlength="255" name="' . self::e($name . '[' . $r['value'] . '][' . $c['value'] . ']') . '"'
                            . $named . ' value="' . self::e($cur) . '"' . ($locked ? ' readonly' : '') . '>';
                }

                $html .= '<td>' . $ctl . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        // A locked grid still sends its value.
        if ($locked) {
            foreach ($vals as $rk => $rv) {
                foreach ((array) $rv as $ck => $cv) {
                    $n = $cell === 'radio' ? $name . '[' . $rk . ']' : ($cell === 'checkbox' ? $name . '[' . $rk . '][]' : $name . '[' . $rk . '][' . $ck . ']');
                    $html .= '<input type="hidden" name="' . self::e($n) . '" value="' . self::e($cv) . '">';
                }
            }
        }

        return str_replace('<legend class="form-label fs-6">', '<legend class="form-label fs-6" id="' . $id . '-group-legend">', self::wrap($field, $rc, $html, true, 'sf-grid-field'));
    }

    private static function rowEmpty(mixed $rv): bool
    {
        if (\is_array($rv)) {
            return array_filter($rv, static fn ($v) => $v !== '' && $v !== []) === [];
        }

        return $rv === null || $rv === '';
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $raw  = \is_array($raw) ? $raw : [];
        $cell = self::cell($field);
        $cols = self::items($field['columns'] ?? []);
        $cv   = array_column($cols, 'value');
        $out  = [];

        foreach (self::items($field['rows'] ?? []) as $r) {
            $in = $raw[$r['value']] ?? null;

            if ($cell === 'radio') {
                if (\is_scalar($in) && \in_array((string) $in, $cv, true)) {
                    $out[$r['value']] = (string) $in;
                }
                continue;
            }

            if ($cell === 'checkbox') {
                $picked = array_values(array_intersect($cv, array_map('strval', array_filter((array) $in, 'is_scalar'))));

                if ($picked) {
                    $out[$r['value']] = $picked;
                }
                continue;
            }

            $row = [];

            foreach ($cols as $c) {
                $v = \is_array($in) && \is_scalar($in[$c['value']] ?? null) ? trim((string) $in[$c['value']]) : '';

                if ($cell === 'select' && $v !== '' && !\in_array($v, array_column(self::choicesFor($field, $c), 'value'), true)) {
                    $v = '';
                }

                if ($v !== '') {
                    $row[$c['value']] = mb_substr($v, 0, 255);
                }
            }

            if ($row) {
                $out[$r['value']] = $row;
            }
        }

        return $out;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        if (empty($field['rules']['required'])) {
            return [];
        }

        $vals    = \is_array($value) ? $value : [];
        $missing = [];

        foreach (self::items($field['rows'] ?? []) as $r) {
            if (self::rowEmpty($vals[$r['value']] ?? null)) {
                $missing[] = $r['label'];
            }
        }

        if (!$missing) {
            return [];
        }

        $label = (string) ($field['label'] ?? $field['key']);

        return [$vc->path($field['key']) => \count($missing) === \count(self::items($field['rows'] ?? []))
            ? Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)
            : Text::sprintf('COM_SQUIDFORM_ERR_GRID_ROWS', $label, implode(', ', $missing))];
    }

    /**
     * As text, one row per entry: "Food: Good", "Food: Lunch, Dinner",
     * "Monday: Start 09:00, End 17:00".
     *
     * @return list<string>
     */
    public static function lines(array $field, array $vals): array
    {
        $cols  = self::items($field['columns'] ?? []);
        $clab  = array_column($cols, 'label', 'value');
        $cell  = self::cell($field);
        $lines = [];

        foreach (self::items($field['rows'] ?? []) as $r) {
            $rv = $vals[$r['value']] ?? null;

            if (self::rowEmpty($rv)) {
                continue;
            }

            if ($cell === 'radio') {
                $txt = $clab[(string) $rv] ?? (string) $rv;
            } elseif ($cell === 'checkbox') {
                $txt = implode(', ', array_map(static fn ($v) => $clab[$v] ?? $v, (array) $rv));
            } else {
                $parts = [];

                foreach ($cols as $c) {
                    if (isset($rv[$c['value']])) {
                        $v = (string) $rv[$c['value']];

                        if ($cell === 'select') {
                            $v = array_column(self::choicesFor($field, $c), 'label', 'value')[$v] ?? $v;
                        }

                        $parts[] = $c['label'] . ' ' . $v;
                    }
                }

                $txt = implode(', ', $parts);
            }

            $lines[] = $r['label'] . ': ' . $txt;
        }

        return $lines;
    }
}
