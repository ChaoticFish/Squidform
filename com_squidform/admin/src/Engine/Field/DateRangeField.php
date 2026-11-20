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
 * Date range (0.9.2): "From" and "To", the browser's date pickers.
 * rules.min / rules.max (YYYY-MM-DD) bound both; "max_days" caps the length
 * (counting both ends). "To" can't be before "From". Stored as
 * {"from": "2026-11-02", "to": "2026-11-06"}.
 */
final class DateRangeField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key  = $field['key'];
        $id   = $rc->id($key);
        $name = $rc->name($key);
        $v    = \is_array($value) ? $value : [];
        $bad  = $rc->error($key) !== null;
        $db   = self::describedBy($field, $rc);
        $mm   = '';

        foreach (['min', 'max'] as $r) {
            if (isset($field['rules'][$r])) {
                $mm .= ' ' . $r . '="' . self::e(\SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($field['rules'][$r], 'date', $r)) . '"';
            }
        }

        $mm .= \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::attrs($field, $rc);

        $html = '<div class="row g-2 sf-daterange">';

        foreach (['from' => 'COM_SQUIDFORM_RANGE_FROM', 'to' => 'COM_SQUIDFORM_RANGE_TO'] as $part => $lang) {
            $pid   = $id . '-' . $part;
            $html .= '<div class="col-12 col-sm-6">'
                . '<label class="form-label small mb-1" for="' . $pid . '">' . self::e((string) ($field['part_labels'][$part] ?? Text::_($lang))) . '</label>'
                . '<input type="date" class="form-control' . ($bad ? ' is-invalid' : '') . '" id="' . $pid . '" name="' . self::e($name . '[' . $part . ']') . '"'
                . ' value="' . self::e((string) ($v[$part] ?? '')) . '"' . $mm
                . (self::required($field) ? ' required aria-required="true"' : '') . ($bad ? ' aria-invalid="true"' : '')
                . ($db !== '' ? ' aria-describedby="' . $db . '"' : '')
                . ($part === 'to' ? ' data-sf-range-to="' . $id . '-from"' : '') . '></div>';
        }

        return self::wrap($field, $rc, $html . '</div>', true, 'sf-daterange-field');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $out = [];

        foreach (['from', 'to'] as $p) {
            $d = \is_array($raw) && \is_string($raw[$p] ?? null) ? trim($raw[$p]) : '';

            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                $out[$p] = $d;
            }
        }

        return $out;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $v     = \is_array($value) ? $value : [];
        $rules = $field['rules'] ?? [];

        if ($v === []) {
            return self::required($field) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        if (!isset($v['from'], $v['to'])) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_RANGE_BOTH', $label)];
        }

        if ($v['to'] < $v['from']) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_RANGE_ORDER', $label)];
        }

        $min = \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules['min'] ?? null, 'date', 'min');
        $max = \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules['max'] ?? null, 'date', 'max');

        if ($min !== null && $v['from'] < $min) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN', $label, $min)];
        }

        if ($max !== null && $v['to'] > $max) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX', $label, $max)];
        }

        $days = (int) (new \DateTimeImmutable($v['from']))->diff(new \DateTimeImmutable($v['to']))->days + 1;

        if (isset($field['max_days']) && $days > (int) $field['max_days']) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_RANGE_DAYS', $label, (int) $field['max_days'])];
        }

        if (isset($field['min_days']) && $days < (int) $field['min_days']) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_RANGE_MIN_DAYS', $label, (int) $field['min_days'])];
        }

        // Weekdays (both ends) and "after another date" (the start), 0.13.0.
        $other = (string) ($rules['after']['field'] ?? '');
        $ol    = $other !== '' ? (string) ($vc->schema->field($other)['label'] ?? $other) : '';

        foreach ([$v['from'], $v['to']] as $i => $d) {
            $msg = \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::check($i === 0 ? $field : ['rules' => array_diff_key($rules, ['after' => 1])] + $field, $d, $label, $vc->data, $ol);

            if ($msg !== null) {
                return [$path => $msg];
            }
        }

        return [];
    }

    /** "2026-11-02 to 2026-11-06" */
    public static function format(array $v): string
    {
        return isset($v['from'], $v['to']) ? Text::sprintf('COM_SQUIDFORM_RANGE_SHOW', $v['from'], $v['to']) : (string) ($v['from'] ?? $v['to'] ?? '');
    }
}
