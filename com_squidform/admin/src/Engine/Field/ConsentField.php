<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Html;

\defined('_JEXEC') or die;

/**
 * Consent (0.9.2): terms in a scrollable box, then an "I agree" tick box.
 * "terms" is HTML (the allowlist), "agree_label" the tick box's words
 * (default "I have read and agree to the terms above"). Required unless
 * "rules.required" is false.
 *
 * No forced scrolling: the box can be read with the keyboard (it's
 * focusable) and zooms, and ticking is never blocked. What's kept is
 * {"agreed": true, "at": "…Z", "terms_sha256": "…"}: a fingerprint of the
 * exact terms shown, which together with the submission's form version
 * proves which text was agreed to.
 */
final class ConsentField extends AbstractField
{
    public static function terms(array $field): string
    {
        return Html::clean((string) ($field['terms'] ?? ''));
    }

    private static function on(array $field): bool
    {
        return ($field['rules']['required'] ?? true) !== false;
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $agreed = \is_array($value) && !empty($value['agreed']);
        $bad    = $rc->error($key) !== null;
        $height = max(6, min(40, (int) ($field['height'] ?? 12)));
        $db     = self::describedBy($field, $rc);
        $req    = self::on($field);

        $html = '<div class="sf-agree-terms" id="' . $id . '-terms" tabindex="0" role="region" aria-label="' . self::e(Text::sprintf('COM_SQUIDFORM_CONSENT_TERMS', (string) ($field['label'] ?? $key))) . '"'
            . ' style="max-height:' . $height . 'em">' . self::terms($field) . '</div>'
            . '<div class="form-check mt-2">'
            . '<input type="hidden" name="' . self::e($name) . '" value="">'
            . '<input class="form-check-input' . ($bad ? ' is-invalid' : '') . '" type="checkbox" id="' . $id . '" name="' . self::e($name) . '" value="1"'
            . ($agreed ? ' checked' : '') . ($req ? ' required aria-required="true"' : '') . ($bad ? ' aria-invalid="true"' : '')
            . ' aria-describedby="' . trim($id . '-terms ' . $db) . '"' . (isset($rc->locked[$key]) || !empty($field['readonly']) ? ' disabled' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . self::e((string) ($field['agree_label'] ?? Text::_('COM_SQUIDFORM_CONSENT_AGREE')))
            . ($req ? ' <span class="sf-req" aria-hidden="true">*</span><span class="visually-hidden"> ' . self::e(Text::_('COM_SQUIDFORM_REQUIRED')) . '</span>' : '') . '</label>'
            . '</div>';

        return self::wrap($field, $rc, $html, true, 'sf-agree');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        // Only a tick: an answer is always made here, never taken from the request.
        $vals = \is_array($raw) && array_is_list($raw) ? $raw : [\is_scalar($raw) ? $raw : ''];

        if (!\in_array('1', array_map('strval', $vals), true)) {
            return [];
        }

        return ['agreed' => true, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'terms_sha256' => hash('sha256', self::terms($field))];
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        if (self::on($field) && (!\is_array($value) || empty($value['agreed']))) {
            return [$vc->path($field['key']) => Text::sprintf('COM_SQUIDFORM_ERR_CONSENT', (string) ($field['label'] ?? $field['key']))];
        }

        return [];
    }

    public static function format(array $v): string
    {
        return !empty($v['agreed']) ? Text::sprintf('COM_SQUIDFORM_CONSENT_GIVEN', str_replace(['T', 'Z'], [' ', ' UTC'], substr((string) ($v['at'] ?? ''), 0, 16) . 'Z')) : '';
    }
}
