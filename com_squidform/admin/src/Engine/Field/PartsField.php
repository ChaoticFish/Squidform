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
 * A field made of named parts (0.8.1): a name, an address. Drawn as a
 * <fieldset> whose <legend> is the field's label; each part is a labelled
 * box on the 12-column grid with its own autocomplete token. Stored as one
 * JSON object, {"first": "Pat", "last": "Example"}.
 *
 * One error for the whole field ("Address: City is required."), and every
 * part that caused it is marked invalid.
 */
abstract class PartsField extends AbstractField
{
    /**
     * The parts this field shows, in order.
     *
     * @return list<array{key: string, label: string, width: int, autocomplete: string, required: bool, options?: array<string, string>, empty?: string, max?: int, inputmode?: string}>
     */
    abstract protected function parts(array $field): array;

    /** A problem with a part's value that isn't "missing" (a ZIP that isn't a ZIP), or null. */
    protected function partProblem(array $field, array $part, string $value): ?string
    {
        return null;
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $vals   = \is_array($value) ? $value : [];
        $hasErr = $rc->error($key) !== null;
        $locked = isset($rc->locked[$key]) || !empty($field['readonly']);
        $html   = '<div class="row g-2 sf-parts">';

        foreach ($this->parts($field) as $p) {
            $pid  = $id . '-' . $p['key'];
            $pv   = (string) ($vals[$p['key']] ?? '');
            $req  = $p['required'] && self::required($field);
            $bad  = $hasErr && (($req && $pv === '') || ($pv !== '' && $this->partProblem($field, $p, $pv) !== null));
            $db   = self::describedBy($field, $rc);
            $attr = ' id="' . $pid . '" name="' . self::e($name . '[' . $p['key'] . ']') . '"'
                . (($field['autocomplete'] ?? '') === 'off' ? ' autocomplete="off"' : ($p['autocomplete'] !== '' ? ' autocomplete="' . $p['autocomplete'] . '"' : ''))
                . ($req ? ' required aria-required="true"' : '')
                . ($bad ? ' aria-invalid="true"' : '')
                . ($db !== '' ? ' aria-describedby="' . $db . '"' : '');

            if (isset($p['options'])) {
                $ctl = '<select class="form-select' . ($bad ? ' is-invalid' : '') . '"' . $attr . ($locked ? ' disabled' : '') . '>'
                    . '<option value="">' . self::e($p['empty'] ?? Text::_('COM_SQUIDFORM_SELECT')) . '</option>';

                foreach ($p['options'] as $v => $l) {
                    $ctl .= '<option value="' . self::e($v) . '"' . ((string) $v === $pv ? ' selected' : '') . '>' . self::e($l) . '</option>';
                }

                $ctl .= '</select>';

                if ($locked && $pv !== '') {
                    $ctl .= '<input type="hidden" name="' . self::e($name . '[' . $p['key'] . ']') . '" value="' . self::e($pv) . '">';
                }
            } else {
                $ctl = '<input type="text" class="form-control' . ($bad ? ' is-invalid' : '') . '"' . $attr
                    . ' value="' . self::e($pv) . '" maxlength="' . (int) ($p['max'] ?? 200) . '"'
                    . (isset($p['inputmode']) ? ' inputmode="' . $p['inputmode'] . '"' : '')
                    . ($locked ? ' readonly aria-readonly="true"' : '') . '>';
            }

            $html .= '<div class="col-12 col-md-' . $p['width'] . ' sf-part sf-part-' . $p['key'] . '">'
                . '<label class="form-label small mb-1" for="' . $pid . '">' . self::e($p['label'])
                . ($req ? ' <span class="sf-req" aria-hidden="true">*</span><span class="visually-hidden"> ' . self::e(Text::_('COM_SQUIDFORM_REQUIRED')) . '</span>' : '')
                . '</label>' . $ctl . '</div>';
        }

        return self::wrap($field, $rc, $html . '</div>', true, 'sf-parts-field');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $raw = \is_array($raw) ? $raw : [];
        $out = [];

        foreach ($this->parts($field) as $p) {
            $v = $raw[$p['key']] ?? '';
            $v = \is_scalar($v) ? trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '') : '';
            $v = mb_substr($v, 0, (int) ($p['max'] ?? 200));

            if (isset($p['options']) && $v !== '' && !\array_key_exists($v, $p['options'])) {
                $v = '';
            }

            if ($v !== '') {
                $out[$p['key']] = $this->tidy($p, $v);
            }
        }

        return $out;
    }

    /** A last tidy of one part (upper-case a postal code, say). */
    protected function tidy(array $part, string $value): string
    {
        return $value;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $vals  = \is_array($value) ? $value : [];

        if ($vals === []) {
            return self::required($field) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        foreach ($this->parts($field) as $p) {
            $pv = (string) ($vals[$p['key']] ?? '');

            // An optional field that was started must still be whole.
            if ($p['required'] && $pv === '') {
                return [$path => Text::sprintf('COM_SQUIDFORM_ERR_PART_REQUIRED', $label, $p['label'])];
            }

            if ($pv !== '' && ($problem = $this->partProblem($field, $p, $pv)) !== null) {
                return [$path => $label . ': ' . $problem];
            }
        }

        return [];
    }
}
