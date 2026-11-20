<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Image choice (0.9.2): pick a picture. "choices" is a list of
 * {"value", "label", "image", "alt"}; "image" is a path on the site (for
 * example images/forms/desk-a.jpg, as the Media Manager shows it). "alt"
 * describes the picture (leave it empty when the label says it all).
 * "multiple": true lets people pick several (rules.min_selected,
 * rules.max_selected). Real radio buttons or tick boxes, so the keyboard and
 * screen readers work as usual; the label is always shown with the picture.
 */
final class ImageChoiceField extends AbstractField
{
    /** @return list<array{value: string, label: string, image: string, alt: string}> */
    public static function choices(array $field): array
    {
        $out = [];

        foreach (\is_array($field['choices'] ?? null) ? $field['choices'] : [] as $c) {
            if (\is_array($c) && isset($c['value'])) {
                $out[] = ['value' => (string) $c['value'], 'label' => (string) ($c['label'] ?? $c['value']), 'image' => self::cleanPath((string) ($c['image'] ?? '')), 'alt' => (string) ($c['alt'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * The Media Manager (0.10.0) adds "#joomlaImage://…?width=…" to what it
     * picks; only the path before it is used.
     */
    public static function cleanPath(string $p): string
    {
        $p = trim($p);
        $h = strpos($p, '#');

        return ltrim($h === false ? $p : substr($p, 0, $h), '/');
    }

    /** A site path: no scheme, no "//", no "..", only ordinary path characters. */
    public static function safePath(string $p): bool
    {
        return $p !== '' && preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./ %()-]*$#', $p) && !str_contains($p, '..') && !str_contains($p, '//');
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key   = $field['key'];
        $id    = $rc->id($key);
        $name  = $rc->name($key);
        $multi = !empty($field['multiple']);
        $sel   = array_map('strval', \is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]));
        $bad   = $rc->error($key) !== null;
        $db    = self::describedBy($field, $rc);
        $root  = rtrim(Uri::root(true), '/') . '/';
        $html  = $multi ? '<input type="hidden" name="' . self::e($name . '[]') . '" value="">' : '';
        $html .= '<div class="sf-imgchoice row g-2">';

        foreach (self::choices($field) as $i => $c) {
            $cid   = $id . '-' . $i;
            $left  = $rc->capacity[$key][$c['value']] ?? null;
            $isFull = $left !== null && $left <= 0;
            $note  = $isFull ? ' ' . Text::_('COM_SQUIDFORM_CHOICE_FULL') : ($left !== null && !empty($field['show_left']) ? ' ' . Text::plural('COM_SQUIDFORM_CHOICE_LEFT', $left) : '');
            $img   = self::safePath($c['image']) ? '<img class="sf-imgchoice-img" src="' . self::e($root . ltrim($c['image'], '/')) . '" alt="' . self::e($c['alt']) . '" loading="lazy">' : '';
            $html .= '<div class="col-6 col-sm-4 col-md-3">'
                . '<input class="btn-check sf-imgchoice-input" type="' . ($multi ? 'checkbox' : 'radio') . '" id="' . $cid . '" name="' . self::e($name . ($multi ? '[]' : '')) . '" value="' . self::e($c['value']) . '"'
                . (\in_array($c['value'], $sel, true) && !$isFull ? ' checked' : '') . ($bad ? ' aria-invalid="true"' : '') . ($db !== '' ? ' aria-describedby="' . $db . '"' : '')
                . (!$multi && self::required($field) ? ' required' : '') . (isset($rc->locked[$key]) || $isFull ? ' disabled' : '') . '>'
                . '<label class="sf-imgchoice-card card h-100" for="' . $cid . '">' . $img
                . '<span class="card-body p-2 text-center sf-imgchoice-label">' . self::e($c['label'] . $note) . '</span></label>'
                . '</div>';
        }

        return self::wrap($field, $rc, $html . '</div>' . self::choiceCount($field, $rc, \count($sel)), true, 'sf-imgchoice-field');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $valid = array_column(self::choices($field), 'value');

        if (!empty($field['multiple'])) {
            $vals = array_map(static fn ($v) => \is_scalar($v) ? (string) $v : '', \is_array($raw) ? $raw : [$raw]);

            return array_values(array_unique(array_intersect($vals, $valid)));
        }

        $v = \is_scalar($raw) ? (string) $raw : '';

        return \in_array($v, $valid, true) ? $v : '';
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $rules = $field['rules'] ?? [];
        $n     = \is_array($value) ? \count($value) : ($value === '' || $value === null ? 0 : 1);

        if ($n === 0) {
            return !empty($rules['required']) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        if (!empty($field['multiple'])) {
            if (isset($rules['min_selected']) && $n < (int) $rules['min_selected']) {
                return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN_SELECTED', $label, (int) $rules['min_selected'])];
            }

            if (isset($rules['max_selected']) && $n > (int) $rules['max_selected']) {
                return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX_SELECTED', $label, (int) $rules['max_selected'])];
            }
        }

        return [];
    }

    public static function format(array $field, mixed $value): string
    {
        $labels = array_column(self::choices($field), 'label', 'value');

        return implode(', ', array_map(static fn ($v) => $labels[$v] ?? $v, (array) $value));
    }
}
