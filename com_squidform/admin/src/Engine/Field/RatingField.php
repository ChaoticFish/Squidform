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
 * A rating or scale (0.8.0): 1 to "max" (2–10, default 5) as a row of
 * buttons, with optional words at each end ("low_label", "high_label").
 * They are native radio buttons, so the arrow keys and screen readers work
 * as usual. Stores the number.
 */
final class RatingField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $max    = self::max($field);
        $bad    = $rc->error($key) !== null;
        $locked = isset($rc->locked[$key]);
        $sel    = \is_scalar($value) ? (string) $value : '';
        $db     = self::describedBy($field, $rc);
        $low    = (string) ($field['low_label'] ?? '');
        $high   = (string) ($field['high_label'] ?? '');
        $ends   = $low !== '' || $high !== '' ? $id . '-ends' : '';
        $html   = '';

        if ($locked && $sel !== '') {
            $html .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($sel) . '">';
        }

        $html .= '<div class="sf-rating btn-group flex-wrap" role="presentation">';

        for ($n = 1; $n <= $max; $n++) {
            $oid   = $id . '-' . $n;
            $html .= '<input type="radio" class="btn-check" id="' . $oid . '" name="' . self::e($name) . '" value="' . $n . '" autocomplete="off"'
                . ($sel === (string) $n ? ' checked' : '')
                . (trim($db . ' ' . $ends) !== '' ? ' aria-describedby="' . trim($db . ' ' . $ends) . '"' : '')
                . ($bad ? ' aria-invalid="true"' : '')
                . (self::required($field) ? ' required' : '')
                . ($locked ? ' disabled' : '') . '>'
                . '<label class="btn btn-outline-primary" for="' . $oid . '">' . $n
                . '<span class="visually-hidden"> ' . self::e(Text::sprintf('COM_SQUIDFORM_RATING_OF_SR', $n, $max)) . '</span></label>';
        }

        $html .= '</div>';

        if ($ends) {
            $html .= '<div class="sf-rating-ends d-flex justify-content-between small text-body-secondary" id="' . $ends . '">'
                . '<span>' . self::e($low !== '' ? '1 = ' . $low : '') . '</span><span>' . self::e($high !== '' ? $max . ' = ' . $high : '') . '</span></div>';
        }

        return self::wrap($field, $rc, $html, true);
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $v = \is_scalar($raw) ? trim((string) $raw) : '';

        return ctype_digit($v) && (int) $v >= 1 && (int) $v <= self::max($field) ? (string) (int) $v : '';
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        if (self::isEmpty($value) && !empty($field['rules']['required'])) {
            return [$vc->path($field['key']) => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', (string) ($field['label'] ?? $field['key']))];
        }

        return [];
    }

    private static function max(array $field): int
    {
        $m = (int) ($field['max'] ?? 5);

        return $m >= 2 && $m <= 10 ? $m : 5;
    }
}
