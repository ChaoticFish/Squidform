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
 * A postal address (0.8.1), "format": "us" or "intl".
 *
 * US:   line1, line2, city, state (a list: states, DC, territories, AA/AE/AP), zip (12345 or 12345-6789)
 * intl: line1, line2, city, region, postal, country (ISO 3166 list; "default_country": "GB")
 *
 * "line2": false drops the second line. Needed when required or once any
 * part is filled in: line1, city, and state + zip (US) or country (intl).
 */
final class AddressField extends PartsField
{
    public static function isUs(array $field): bool
    {
        return ($field['format'] ?? 'us') === 'us' || (!\in_array($field['format'] ?? 'us', ['us', 'intl'], true) && self::custom($field) === null);
    }

    /** 0.13.2: a format an add-on supplied (onSquidformAddressFormats), or null. */
    public static function custom(array $field): ?array
    {
        $f = (string) ($field['format'] ?? 'us');

        return \in_array($f, ['us', 'intl'], true) ? null : \SquidForm\Component\SquidForm\Administrator\Engine\AddressFormats::get($f);
    }

    protected function parts(array $field): array
    {
        if (($custom = self::custom($field)) !== null) {
            $l = \is_array($field['part_labels'] ?? null) ? $field['part_labels'] : [];

            return array_map(static fn ($p) => ['label' => (string) ($l[$p['key']] ?? $p['label'])] + $p, array_merge(...$custom['rows']));
        }

        $l   = \is_array($field['part_labels'] ?? null) ? $field['part_labels'] : [];
        $lab = static fn (string $k, string $lang) => (string) ($l[$k] ?? Text::_($lang));
        $out = [['key' => 'line1', 'label' => $lab('line1', 'COM_SQUIDFORM_ADDR_LINE1'), 'width' => 12, 'autocomplete' => 'address-line1', 'required' => true]];

        if (($field['line2'] ?? true) !== false) {
            $out[] = ['key' => 'line2', 'label' => $lab('line2', 'COM_SQUIDFORM_ADDR_LINE2'), 'width' => 12, 'autocomplete' => 'address-line2', 'required' => false];
        }

        if (self::isUs($field)) {
            $out[] = ['key' => 'city', 'label' => $lab('city', 'COM_SQUIDFORM_ADDR_CITY'), 'width' => 6, 'autocomplete' => 'address-level2', 'required' => true];
            $out[] = ['key' => 'state', 'label' => $lab('state', 'COM_SQUIDFORM_ADDR_STATE'), 'width' => 3, 'autocomplete' => 'address-level1', 'required' => true, 'options' => Places::US_STATES];
            $out[] = ['key' => 'zip', 'label' => $lab('zip', 'COM_SQUIDFORM_ADDR_ZIP'), 'width' => 3, 'autocomplete' => 'postal-code', 'required' => true, 'max' => 10, 'inputmode' => 'numeric'];

            return $out;
        }

        $out[] = ['key' => 'city', 'label' => $lab('city', 'COM_SQUIDFORM_ADDR_TOWN'), 'width' => 6, 'autocomplete' => 'address-level2', 'required' => true];
        $out[] = ['key' => 'region', 'label' => $lab('region', 'COM_SQUIDFORM_ADDR_REGION'), 'width' => 6, 'autocomplete' => 'address-level1', 'required' => false];
        $out[] = ['key' => 'postal', 'label' => $lab('postal', 'COM_SQUIDFORM_ADDR_POSTAL'), 'width' => 4, 'autocomplete' => 'postal-code', 'required' => false, 'max' => 20];
        $out[] = ['key' => 'country', 'label' => $lab('country', 'COM_SQUIDFORM_ADDR_COUNTRY'), 'width' => 8, 'autocomplete' => 'country', 'required' => true, 'options' => Places::COUNTRIES];

        return $out;
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        if (self::custom($field) !== null) {
            return parent::render($field, $value, $rc);
        }

        // An empty international address starts on its default country.
        if (!self::isUs($field) && (!\is_array($value) || !isset($value['country'])) && !empty($field['default_country'])) {
            $value = (\is_array($value) ? $value : []) + ['country' => (string) $field['default_country']];
        }

        return parent::render($field, $value, $rc);
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $out = parent::normalize($field, $raw);

        // The default country alone isn't an answer.
        if (\count($out) === 1 && isset($out['country']) && $out['country'] === ($field['default_country'] ?? null)) {
            return [];
        }

        return $out;
    }

    protected function tidy(array $part, string $value): string
    {
        return $part['key'] === 'postal' ? mb_strtoupper($value) : $value;
    }

    protected function partProblem(array $field, array $part, string $value): ?string
    {
        if (isset($part['pattern'])) {
            return @preg_match('/^(?:' . str_replace('/', '\/', $part['pattern']) . ')$/u', $value) === 1 ? null : \Joomla\CMS\Language\Text::sprintf('COM_SQUIDFORM_ERR_PART_FORMAT', $part['label']);
        }

        if (self::custom($field) === null && $part['key'] === 'zip' && !preg_match('/^\d{5}(-\d{4})?$/', $value)) {
            return Text::_('COM_SQUIDFORM_ERR_ZIP');
        }

        return null;
    }

    /** One line: "1 Main St, Apt 2, Martinsburg, WV 25401" */
    public static function format(array $field, array $v, string $glue = ', '): string
    {
        if (($custom = self::custom($field)) !== null) {
            return \SquidForm\Component\SquidForm\Administrator\Engine\AddressFormats::display($custom, $v, $glue);
        }

        $lines = array_filter([(string) ($v['line1'] ?? ''), (string) ($v['line2'] ?? '')]);

        if (self::isUs($field)) {
            $last = trim(implode(', ', array_filter([(string) ($v['city'] ?? ''), trim(($v['state'] ?? '') . ' ' . ($v['zip'] ?? ''))])));
            $lines[] = $last;
        } else {
            $lines[] = trim(implode(', ', array_filter([(string) ($v['city'] ?? ''), trim(($v['region'] ?? '') . ' ' . ($v['postal'] ?? ''))])));
            $lines[] = Places::COUNTRIES[$v['country'] ?? ''] ?? (string) ($v['country'] ?? '');
        }

        return implode($glue, array_filter($lines, static fn ($s) => $s !== ''));
    }
}
