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
 * Slider (0.9.2): a number picked on a scale, the browser's own range control
 * (arrow keys, Home and End work). "min" (default 0), "max" (100), "step"
 * (1), "default" (the middle), "unit" shown after the number ("%", "km"),
 * and words for each end ("low_label", "high_label"). The value shows beside
 * it and is read out as it moves. A slider always has a value, so it isn't
 * "required".
 */
final class SliderField extends AbstractField
{
    /** @return array{0: float, 1: float, 2: float} min, max, step */
    public static function range(array $field): array
    {
        $min  = is_numeric($field['min'] ?? null) ? (float) $field['min'] : 0.0;
        $max  = is_numeric($field['max'] ?? null) ? (float) $field['max'] : 100.0;
        $step = is_numeric($field['step'] ?? null) && (float) $field['step'] > 0 ? (float) $field['step'] : 1.0;

        return [$min, $max > $min ? $max : $min + 1, $step];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        [$min, $max, $step] = self::range($field);
        $key  = $field['key'];
        $id   = $rc->id($key);
        $name = $rc->name($key);
        $def  = is_numeric($field['default'] ?? null) ? (float) $field['default'] : $min + round(($max - $min) / 2 / $step) * $step;
        $val  = is_numeric($value) ? (float) $value : $def;
        $unit = (string) ($field['unit'] ?? '');
        $low  = (string) ($field['low_label'] ?? '');
        $high = (string) ($field['high_label'] ?? '');
        $db   = trim(self::describedBy($field, $rc) . ($low !== '' || $high !== '' ? ' ' . $id . '-ends' : ''));

        $html = '<div class="d-flex align-items-center gap-3 sf-slider">'
            . '<input type="range" class="form-range" id="' . $id . '" name="' . self::e($name) . '"'
            . ' min="' . self::num($min) . '" max="' . self::num($max) . '" step="' . self::num($step) . '" value="' . self::num($val) . '"'
            . ($unit !== '' ? ' aria-valuetext="' . self::e(self::num($val) . ' ' . $unit) . '" data-sf-unit="' . self::e($unit) . '"' : '')
            . ($db !== '' ? ' aria-describedby="' . $db . '"' : '')
            . (isset($rc->locked[$key]) || !empty($field['readonly']) ? ' disabled' : '') . '>'
            . '<output class="sf-slider-value fw-semibold" for="' . $id . '" id="' . $id . '-out">' . self::e(self::num($val) . ($unit !== '' ? ' ' . $unit : '')) . '</output>'
            . '</div>';

        if ($low !== '' || $high !== '') {
            $html .= '<div class="d-flex justify-content-between small text-body-secondary sf-slider-ends" id="' . $id . '-ends">'
                . '<span>' . self::e($low !== '' ? self::num($min) . ' = ' . $low : '') . '</span><span>' . self::e($high !== '' ? self::num($max) . ' = ' . $high : '') . '</span></div>';
        }

        return self::wrap($field, $rc, $html);
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        if (!is_numeric($raw)) {
            return '';
        }

        [$min, $max, $step] = self::range($field);
        $v = max($min, min($max, (float) $raw));
        $v = $min + round(($v - $min) / $step) * $step;

        return self::num(min($max, $v));
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        return [];
    }

    public static function format(array $field, mixed $v): string
    {
        $unit = (string) ($field['unit'] ?? '');

        return (string) $v . ($unit !== '' ? ' ' . $unit : '');
    }
}
