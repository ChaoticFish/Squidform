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
 * Shared markup and rules for built-in field types.
 *
 * Accessibility (WCAG 2.1 AA):
 * - every control has a real <label for> (or <fieldset><legend> for groups)
 * - help and error text are linked with aria-describedby
 * - invalid controls carry aria-invalid="true"
 * - required fields show a visible marker plus aria-required / required
 */
abstract class AbstractField implements FieldTypeInterface
{
    /** Type → class name, where the type's own name is on blockers' lists. */
    public const TYPE_CLASS = ['consent' => 'agree'];

    public function storesValue(): bool
    {
        return true;
    }

    protected static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    protected static function required(array $field): bool
    {
        return !empty($field['rules']['required']);
    }

    /** Space-separated ids for aria-describedby, or '' */
    protected static function describedBy(array $field, RenderContext $rc): string
    {
        $ids = [];

        if (!empty($field['help'])) {
            $ids[] = $rc->id($field['key']) . '-help';
        }

        if ($rc->error($field['key']) !== null) {
            $ids[] = $rc->id($field['key']) . '-error';
        }

        if (self::choiceLimit($field) !== null) {
            $ids[] = $rc->id($field['key']) . '-choicecount';
        }

        return implode(' ', $ids);
    }

    /**
     * 0.14.0: [min, max] when people pick several and there's a limit (max 0 = none).
     *
     * @return array{0: int, 1: int}|null
     */
    protected static function choiceLimit(array $field): ?array
    {
        $type  = (string) ($field['type'] ?? '');
        $multi = \in_array($type, ['checkboxes', 'multiselect'], true) || $type === 'imagechoice' && !empty($field['multiple']);
        $min   = (int) ($field['rules']['min_selected'] ?? 0);
        $max   = (int) ($field['rules']['max_selected'] ?? 0);

        return $multi && ($min > 0 || $max > 0) ? [$min, $max] : null;
    }

    /** What the choice counter says for n chosen. */
    public static function choiceCountText(int $min, int $max, int $n): string
    {
        return match (true) {
            $n < $min              => Text::sprintf('COM_SQUIDFORM_CHOICES_AT_LEAST', $min - $n),
            $max > 0 && $n > $max  => Text::sprintf('COM_SQUIDFORM_CHOICES_OVER', $n - $max, $max),
            $max > 0 && $n === $max => Text::sprintf('COM_SQUIDFORM_CHOICES_LIMIT', $max),
            $max > 0               => Text::sprintf('COM_SQUIDFORM_CHOICES_CAN', $max - $n),
            default                => Text::_('COM_SQUIDFORM_CHOICES_ENOUGH'),
        };
    }

    /**
     * The counter under a field with a choice limit: right without JavaScript
     * (worked out from what's chosen now), kept up to date by the script, and
     * read out politely as people tick.
     */
    protected static function choiceCount(array $field, RenderContext $rc, int $n): string
    {
        $lim = self::choiceLimit($field);

        if ($lim === null) {
            return '';
        }

        [$min, $max] = $lim;
        $t = static fn (string $k) => self::e(Text::_($k));

        return '<div class="form-text sf-choice-count" id="' . $rc->id($field['key']) . '-choicecount" aria-live="polite" data-sf-choice-count="1"'
            . ' data-sf-min="' . $min . '" data-sf-max="' . $max . '"'
            . ' data-sf-t-least="' . $t('COM_SQUIDFORM_CHOICES_AT_LEAST') . '" data-sf-t-over="' . $t('COM_SQUIDFORM_CHOICES_OVER') . '"'
            . ' data-sf-t-limit="' . $t('COM_SQUIDFORM_CHOICES_LIMIT') . '" data-sf-t-can="' . $t('COM_SQUIDFORM_CHOICES_CAN') . '" data-sf-t-enough="' . $t('COM_SQUIDFORM_CHOICES_ENOUGH') . '">'
            . self::e(self::choiceCountText($min, $max, $n)) . '</div>';
    }

    protected static function labelText(array $field): string
    {
        $out = self::e($field['label'] ?? $field['key']);

        if (self::required($field)) {
            $out .= ' <span class="sf-req" aria-hidden="true">*</span>'
                . '<span class="visually-hidden"> ' . self::e(Text::_('COM_SQUIDFORM_REQUIRED')) . '</span>';
        }

        return $out;
    }

    /**
     * Wrap a control with its label, help and error, plus the data attributes
     * the browser script uses for show/hide.
     *
     * @param   bool  $group  true = <fieldset><legend> (radios, checkboxes, repeater)
     */
    protected static function wrap(array $field, RenderContext $rc, string $control, bool $group = false, string $extraClass = ''): string
    {
        $key   = $field['key'];
        $id    = $rc->id($key);
        $error = $rc->error($key);
        $attrs = ' data-sf-key="' . self::e($key) . '"';

        if (!empty($field['show_if'])) {
            $attrs .= ' data-sf-show-if="' . self::e(json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::forBrowser($field['show_if']))) . '"';
        }

        // 0.13.1: no "consent", "cookie" and the like in class names: ad
        // blockers' cookie-banner lists (Brave Shields) hide such elements.
        $class = 'sf-field mb-3 sf-type-' . self::e(self::TYPE_CLASS[$field['type']] ?? $field['type']) . ($extraClass ? ' ' . $extraClass : '');
        $help  = !empty($field['help'])
            ? '<div id="' . $id . '-help" class="form-text">' . self::e($field['help']) . '</div>'
            : '';
        $err   = $error !== null
            ? '<div id="' . $id . '-error" class="invalid-feedback d-block" role="alert">' . self::e($error) . '</div>'
            : '';

        // 0.13.2: "Line up with a field in parts": room for the small part
        // labels (Country, Number) that a neighbour has, so the boxes line up.
        // Hidden from screen readers; not a <label>.
        if (!empty($field['align_parts'])) {
            $control = '<div class="sf-align-spacer" aria-hidden="true"></div>' . $control;
        }

        if ($group) {
            return '<fieldset class="' . $class . '"' . $attrs . ' id="' . $id . '-group"'
                . ($help || $err ? ' aria-describedby="' . trim(($help ? $id . '-help ' : '') . ($err ? $id . '-error' : '')) . '"' : '') . '>'
                . '<legend class="form-label fs-6">' . self::labelText($field) . '</legend>'
                . $control . $help . $err . '</fieldset>';
        }

        return '<div class="' . $class . '"' . $attrs . '>'
            . '<label class="form-label" for="' . $id . '">' . self::labelText($field) . '</label>'
            . $control . $help . $err . '</div>';
    }

    /** Common aria/required attributes for a single control. */
    protected static function controlAttrs(array $field, RenderContext $rc): string
    {
        $out = '';
        $db  = self::describedBy($field, $rc);

        if ($db !== '') {
            $out .= ' aria-describedby="' . $db . '"';
        }

        if ($rc->error($field['key']) !== null) {
            $out .= ' aria-invalid="true"';
        }

        if (self::required($field)) {
            $out .= ' required aria-required="true"';
        }

        if (isset($rc->locked[$field['key']]) || !empty($field['readonly'])) {
            $out .= ' readonly aria-readonly="true"';
        }

        if (!empty($field['placeholder'])) {
            $out .= ' placeholder="' . self::e($field['placeholder']) . '"';
        }

        if (!empty($field['autocomplete'])) {
            $out .= ' autocomplete="' . self::e($field['autocomplete']) . '"';
        }

        return $out;
    }

    protected static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /**
     * Rules shared by text-like fields: required, min_length, max_length, pattern.
     *
     * @return array<string, string>
     */
    protected static function textRules(array $field, mixed $value, ValidateContext $vc): array
    {
        $rules = $field['rules'] ?? [];
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);

        if (self::isEmpty($value)) {
            return !empty($rules['required']) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        $len = mb_strlen((string) $value);

        if (isset($rules['min_length']) && $len < (int) $rules['min_length']) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN_LENGTH', $label, (int) $rules['min_length'])];
        }

        if (isset($rules['max_length']) && $len > (int) $rules['max_length']) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX_LENGTH', $label, (int) $rules['max_length'])];
        }

        if (!empty($rules['pattern'])) {
            if (@preg_match(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::regex((string) $rules['pattern'], true), (string) $value) !== 1) {
                $msg = $rules['pattern_message'] ?? Text::sprintf('COM_SQUIDFORM_ERR_PATTERN', $label);

                return [$path => (string) $msg];
            }
        }

        return [];
    }
}
