<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Places;

\defined('_JEXEC') or die;

/**
 * Phone with country code (0.9.2): a country list showing each dialling
 * code ("United Kingdom (+44)"), then the number. "default_country" picks
 * the starting country (default US). The number may have spaces, dashes,
 * dots and brackets; a leading 0 (trunk prefix) is dropped. Checked by the
 * international rule (E.164: at most 15 digits with the country code, at
 * least 4 for the number), not each country's own patterns.
 *
 * Stored as {"country": "GB", "number": "2079460000", "e164": "+442079460000"}.
 */
final class PhoneField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key     = $field['key'];
        $id      = $rc->id($key);
        $name    = $rc->name($key);
        $v       = \is_array($value) ? $value : [];
        $country = (string) ($v['country'] ?? ($field['default_country'] ?? 'US'));
        $bad     = $rc->error($key) !== null;
        $db      = self::describedBy($field, $rc);
        $req     = self::required($field) ? ' required aria-required="true"' : '';
        $opts    = '';

        foreach (Places::COUNTRIES as $code => $label) {
            if (isset(Places::DIAL[$code])) {
                $opts .= '<option value="' . $code . '"' . ($code === $country ? ' selected' : '') . '>' . self::e($label . ' (+' . Places::DIAL[$code] . ')') . '</option>';
            }
        }

        $html = '<div class="row g-2 sf-phone">'
            . '<div class="col-12 col-sm-5"><label class="form-label small mb-1" for="' . $id . '-country">' . self::e(Text::_('COM_SQUIDFORM_PHONE_COUNTRY')) . '</label>'
            . '<select class="form-select" id="' . $id . '-country" name="' . self::e($name . '[country]') . '" autocomplete="tel-country-code"' . ($db !== '' ? ' aria-describedby="' . $db . '"' : '') . '>' . $opts . '</select></div>'
            . '<div class="col-12 col-sm-7"><label class="form-label small mb-1" for="' . $id . '-number">' . self::e(Text::_('COM_SQUIDFORM_PHONE_NUMBER')) . '</label>'
            . '<input type="tel" class="form-control' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '-number" name="' . self::e($name . '[number]') . '" value="' . self::e((string) ($v['number'] ?? '')) . '"'
            . ' autocomplete="tel-national" inputmode="tel" maxlength="25"' . $req . ($bad ? ' aria-invalid="true"' : '') . ($db !== '' ? ' aria-describedby="' . $db . '"' : '') . '></div>'
            . '</div>';

        return self::wrap($field, $rc, $html, true, 'sf-phone-field');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $raw     = \is_array($raw) ? $raw : [];
        $country = \is_string($raw['country'] ?? null) && isset(Places::DIAL[$raw['country']]) ? $raw['country'] : (string) ($field['default_country'] ?? 'US');
        $typed   = \is_string($raw['number'] ?? null) ? trim($raw['number']) : '';
        $digits  = (string) preg_replace('/\D+/', '', $typed);

        if ($digits === '') {
            return $typed === '' ? [] : ['country' => $country, 'number' => $typed, 'e164' => ''];
        }

        // Typed with the country code ("+44 20…" or "0044 20…"): take it off.
        $dial = Places::DIAL[$country] ?? '';

        if (preg_match('/^(\+|00)/', $typed) && $dial !== '' && str_starts_with(ltrim($digits, '0'), $dial)) {
            $digits = substr(ltrim($digits, '0'), \strlen($dial));
        }

        $digits = ltrim($digits, '0');

        return ['country' => $country, 'number' => $digits, 'e164' => '+' . $dial . $digits];
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $v     = \is_array($value) ? $value : [];

        if ($v === []) {
            return self::required($field) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        $n = \strlen((string) ($v['number'] ?? ''));

        if (($v['e164'] ?? '') === '' || $n < 4 || \strlen($v['e164']) - 1 > 15 || !ctype_digit((string) $v['number'])) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_PHONE', $label)];
        }

        return [];
    }

    /** "+44 2079460000 (United Kingdom)" */
    public static function format(array $v): string
    {
        if (empty($v['e164'])) {
            return (string) ($v['number'] ?? '');
        }

        return '+' . (Places::DIAL[$v['country']] ?? '') . ' ' . $v['number'] . ' (' . (Places::COUNTRIES[$v['country']] ?? $v['country']) . ')';
    }
}
