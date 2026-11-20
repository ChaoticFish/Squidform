<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Options;

\defined('_JEXEC') or die;

/**
 * Look-up as you type (0.8.1): a text box that suggests matches from a long
 * list, the same "options" as a dropdown (a list, a file or a table, with
 * filter_by for cascades). Stores the chosen option's value.
 *
 * Accessible combobox (ARIA 1.2 pattern): the box is role="combobox", the
 * suggestions a role="listbox" moved through with the arrow keys, and how
 * many matched is announced. Without JavaScript it's a plain text box: the
 * server takes what was typed when it matches a label exactly (ignoring
 * case), so the field still works.
 *
 * "min_chars" (1–5, default 2) before suggestions start. "allow_other": true
 * keeps an answer that isn't in the list (stored as typed).
 */
final class LookupField extends AbstractField
{
    public const MAX_SUGGESTIONS = 20;

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $val    = \is_scalar($value) ? (string) $value : '';
        $label  = $val;
        $err    = $rc->error($key) !== null;
        $parent = Options::parentOf($field);

        if ($val !== '') {
            try {
                $label = Options::labelMap($field)[$val] ?? $val;
            } catch (\Throwable) {
                // the source is unavailable: show the stored value
            }
        }

        $cls  = 'form-control sf-lookup-input' . ($err ? ' is-invalid' : '');
        $html = '<div class="sf-lookup position-relative" data-sf-lookup="' . self::e($key) . '"'
            . ' data-sf-min="' . max(1, min(5, (int) ($field['min_chars'] ?? 2))) . '"'
            . (!empty($field['allow_other']) ? ' data-sf-other="1"' : '')
            . ($parent !== null ? ' data-sf-parent="' . self::e($parent) . '"' : '') . '>'
            . '<input type="text" class="' . $cls . '" id="' . $id . '" name="' . self::e($name . '[label]') . '" value="' . self::e($label) . '"'
            . ' autocomplete="off" spellcheck="false" maxlength="255"' . self::controlAttrs($field, $rc)
            . ' data-sf-lookup-input>'
            . '<input type="hidden" name="' . self::e($name . '[value]') . '" value="' . self::e($val) . '" data-sf-lookup-value>'
            . '<ul class="sf-lookup-list list-unstyled" id="' . $id . '-list" role="listbox" aria-label="' . self::e(Text::sprintf('COM_SQUIDFORM_LOOKUP_LIST', (string) ($field['label'] ?? $key))) . '" hidden></ul>'
            . '<div class="visually-hidden" aria-live="polite" data-sf-lookup-live></div>'
            . '</div>';

        return self::wrap($field, $rc, $html);
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        if (\is_scalar($raw)) {
            $raw = ['value' => (string) $raw];
        }

        $raw   = \is_array($raw) ? $raw : [];
        $value = \is_scalar($raw['value'] ?? null) ? trim((string) $raw['value']) : '';
        $label = \is_scalar($raw['label'] ?? null) ? trim(preg_replace('/\s+/u', ' ', (string) $raw['label']) ?? '') : '';

        if ($value !== '' || $label === '') {
            return mb_substr($value, 0, 255);
        }

        // Typed but not picked (or no JavaScript): an exact label wins.
        try {
            foreach (Options::labelMap($field) as $v => $l) {
                if (mb_strtolower($l) === mb_strtolower($label)) {
                    return (string) $v;
                }
            }
        } catch (\Throwable) {
            // checked again in validate()
        }

        return mb_substr($label, 0, 255);
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);

        if (self::isEmpty($value)) {
            return self::required($field) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        if (!empty($field['allow_other'])) {
            return [];
        }

        $parent  = Options::parentOf($field);
        $pv      = $parent !== null ? (\array_key_exists($parent, $scope) ? $scope[$parent] : ($vc->data[$parent] ?? null)) : null;
        $allowed = array_column(Options::resolve($field, $pv, $vc->placeholders), 'value');

        return \in_array((string) $value, $allowed, true) ? [] : [$path => Text::sprintf('COM_SQUIDFORM_ERR_LOOKUP', $label)];
    }

    /**
     * Suggestions for what's typed: labels that start with it first, then
     * labels that contain it, at most MAX_SUGGESTIONS.
     *
     * @param list<array{value: string, label: string}> $options
     * @return list<array{value: string, label: string}>
     */
    public static function match(array $options, string $q): array
    {
        $q = mb_strtolower(trim($q));

        if ($q === '') {
            return [];
        }

        $start = $inside = [];

        foreach ($options as $o) {
            $pos = mb_strpos(mb_strtolower($o['label']), $q);

            if ($pos === 0) {
                $start[] = $o;
            } elseif ($pos !== false) {
                $inside[] = $o;
            }

            if (\count($start) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return \array_slice(array_merge($start, $inside), 0, self::MAX_SUGGESTIONS);
    }
}
