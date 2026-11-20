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
 * Choice fields: select, radios, checkboxes (multi), checkbox (single yes/no).
 *
 * Submitted values must be one of the offered options. For cascading lists the
 * offered options are recomputed from the parent value on the server, so a
 * tampered request cannot post a value the list would not show.
 */
final class ChoiceField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $type   = $field['type'];
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $bad    = $rc->error($key) !== null;
        $locked = isset($rc->locked[$key]);

        // "readonly" does nothing on select/checkbox/radio, so a locked choice is
        // rendered disabled (really unchangeable, announced as unavailable) and
        // its value is carried in hidden inputs. The server re-applies it anyway.
        $carry = '';

        if ($locked) {
            foreach (\is_array($value) ? $value : [$value] as $v) {
                $carry .= '<input type="hidden" name="' . self::e($name) . (\in_array($type, ['checkboxes', 'multiselect'], true) ? '[]' : '') . '" value="' . self::e($v) . '">';
            }
        }

        $attrs = self::controlAttrs($field, $rc);

        if ($locked) {
            $attrs = str_replace(' readonly aria-readonly="true"', ' disabled', $attrs);
        }

        if ($type === 'checkbox') {
            $checked = ((string) $value === '1') ? ' checked' : '';
            $control = '<div class="form-check">'
                . '<input type="hidden" name="' . self::e($name) . '" value="">'
                . '<input class="form-check-input' . ($bad ? ' is-invalid' : '') . '" type="checkbox" value="1" id="' . $id . '" name="' . self::e($name) . '"'
                . $checked . $attrs . '>' . $carry
                . '<label class="form-check-label" for="' . $id . '">' . self::labelText($field) . '</label></div>';

            // Single checkbox carries its own label, so wrap without a second one.
            return '<div class="sf-field mb-3 sf-type-checkbox" data-sf-key="' . self::e($key) . '"'
                . (!empty($field['show_if']) ? ' data-sf-show-if="' . self::e(json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::forBrowser($field['show_if']))) . '"' : '') . '>'
                . $control
                . (!empty($field['help']) ? '<div id="' . $id . '-help" class="form-text">' . self::e($field['help']) . '</div>' : '')
                . ($bad ? '<div id="' . $id . '-error" class="invalid-feedback d-block" role="alert">' . self::e($rc->error($key)) . '</div>' : '')
                . '</div>';
        }

        $parent  = Options::parentOf($field);
        $options = $this->safeOptions($field, $parent !== null ? $rc->lookup($parent) : null, $rc);
        $sel     = array_map('strval', \is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]));
        $parentA = $parent !== null ? ' data-sf-parent="' . self::e($parent) . '" data-sf-field="' . self::e($key) . '"' : '';

        // Choices with a number of places (0.10.0): full ones can't be picked.
        $full = [];

        foreach ($options as &$o) {
            $n = $rc->capacity[$key][$o['value']] ?? null;

            if ($n === null) {
                continue;
            }

            if ($n <= 0) {
                $full[$o['value']] = true;
                $o['label'] .= ' ' . Text::_('COM_SQUIDFORM_CHOICE_FULL');
            } elseif (!empty($field['show_left'])) {
                $o['label'] .= ' ' . Text::plural('COM_SQUIDFORM_CHOICE_LEFT', $n);
            }
        }

        unset($o);
        $sel = array_values(array_filter($sel, static fn ($v) => !isset($full[$v])));

        // A dropdown that allows several choices (0.8.0).
        if ($type === 'multiselect') {
            $size = max(3, min(8, \count($options)));
            $hint = '<div class="form-text sf-multi-hint">' . self::e(Text::_('COM_SQUIDFORM_MULTISELECT_HINT')) . '</div>';
            $html = $carry . '<input type="hidden" name="' . self::e($name) . '[]" value="">'
                . '<select multiple size="' . $size . '" class="form-select' . (!empty($field['striped']) ? ' sf-striped' : '') . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . self::e($name) . '[]"'
                . $attrs . $parentA . '>';

            foreach ($options as $o) {
                $html .= '<option value="' . self::e($o['value']) . '"' . (\in_array($o['value'], $sel, true) ? ' selected' : '') . (isset($full[$o['value']]) ? ' disabled' : '') . '>'
                    . self::e($o['label']) . '</option>';
            }

            return self::wrap($field, $rc, $html . '</select>' . $hint . self::choiceCount($field, $rc, \count($sel)));
        }

        if ($type === 'select') {
            // Type to search (0.12.1): the script turns it into a combobox; without the script it stays a plain dropdown.
            $search = !empty($field['search']) && !$locked ? ' data-sf-search="1"' : '';
            $html   = $carry . '<select class="form-select' . (!empty($field['striped']) ? ' sf-striped' : '') . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . self::e($name) . '"'
                . $attrs . $parentA . $search . '>'
                . '<option value="">' . self::e($field['empty_label'] ?? Text::_('COM_SQUIDFORM_SELECT')) . '</option>';

            foreach ($options as $o) {
                $html .= '<option value="' . self::e($o['value']) . '"' . (\in_array($o['value'], $sel, true) ? ' selected' : '') . (isset($full[$o['value']]) ? ' disabled' : '') . '>'
                    . self::e($o['label']) . '</option>';
            }

            return self::wrap($field, $rc, $html . '</select>');
        }

        // radios / checkboxes as a fieldset group
        $multi  = $type === 'checkboxes';
        $inType = $multi ? 'checkbox' : 'radio';
        $inline = !empty($field['inline']) ? ' form-check-inline' : '';
        $db     = self::describedBy($field, $rc);
        // 0.13.1: 2 or 3 columns, filled down then across; one on phones.
        $cols   = (int) ($field['columns'] ?? 1);
        $html   = '<div class="sf-choices' . ($cols >= 2 && $cols <= 12 && $inline === '' ? ' sf-cols sf-cols-' . $cols : '') . '"' . $parentA . '>' . $carry;

        if ($multi) {
            // Ensures the key is posted even when nothing is ticked.
            $html .= '<input type="hidden" name="' . self::e($name) . '[]" value="">';
        }

        foreach ($options as $i => $o) {
            $oid   = $id . '-' . $i;
            $html .= '<div class="form-check' . $inline . '">'
                . '<input class="form-check-input' . ($bad ? ' is-invalid' : '') . '" type="' . $inType . '" id="' . $oid . '"'
                . ' name="' . self::e($name) . ($multi ? '[]' : '') . '" value="' . self::e($o['value']) . '"'
                . (\in_array($o['value'], $sel, true) ? ' checked' : '')
                . ($db !== '' ? ' aria-describedby="' . $db . '"' : '')
                . ($bad ? ' aria-invalid="true"' : '')
                . (!$multi && self::required($field) ? ' required' : '')
                . ($locked || isset($full[$o['value']]) ? ' disabled' : '') . '>'
                . '<label class="form-check-label" for="' . $oid . '">' . self::e($o['label']) . '</label></div>';
        }

        return self::wrap($field, $rc, $html . '</div>' . ($multi ? self::choiceCount($field, $rc, \count($sel)) : ''), true);
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        if (\in_array($field['type'], ['checkboxes', 'multiselect'], true)) {
            $vals = array_values(array_filter(array_map(
                static fn ($v) => \is_scalar($v) ? trim((string) $v) : '',
                \is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw])
            ), static fn ($v) => $v !== ''));

            return array_values(array_unique($vals));
        }

        if ($field['type'] === 'checkbox') {
            // Hidden "" plus checkbox "1" may both arrive; any "1" wins.
            $vals = \is_array($raw) ? $raw : [$raw];

            return \in_array('1', array_map('strval', $vals), true) ? '1' : '';
        }

        return \is_scalar($raw) ? trim((string) $raw) : '';
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $rules = $field['rules'] ?? [];

        if (self::isEmpty($value)) {
            return !empty($rules['required']) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        if ($field['type'] === 'checkbox') {
            return [];
        }

        $parent  = Options::parentOf($field);
        $pv      = $parent !== null ? (\array_key_exists($parent, $scope) ? $scope[$parent] : ($vc->data[$parent] ?? null)) : null;
        $allowed = array_column(Options::resolve($field, $pv, $vc->placeholders), 'value');

        foreach ((array) $value as $v) {
            if (!\in_array((string) $v, $allowed, true)) {
                return [$path => Text::sprintf('COM_SQUIDFORM_ERR_OPTION', $label)];
            }
        }

        if (\in_array($field['type'], ['checkboxes', 'multiselect'], true)) {
            $n = \count((array) $value);

            if (isset($rules['min_selected']) && $n < (int) $rules['min_selected']) {
                return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN_SELECTED', $label, (int) $rules['min_selected'])];
            }

            if (isset($rules['max_selected']) && $n > (int) $rules['max_selected']) {
                return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX_SELECTED', $label, (int) $rules['max_selected'])];
            }
        }

        return [];
    }

    /**
     * Options for display; a broken source shows an inline notice instead of a fatal error.
     *
     * @return list<array{value: string, label: string}>
     */
    private function safeOptions(array $field, mixed $parentValue, RenderContext $rc): array
    {
        try {
            return Options::resolve($field, $parentValue, $rc->placeholders);
        } catch (\Throwable $e) {
            // Never show table or path details to visitors; the reason goes to the Joomla log.
            \Joomla\CMS\Log\Log::add('SquidForm options for "' . ($field['key'] ?? '?') . '": ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'squidform');

            return [['value' => '', 'label' => Text::_('COM_SQUIDFORM_OPTIONS_ERROR')]];
        }
    }
}
