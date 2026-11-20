<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Conditions;
use SquidForm\Component\SquidForm\Administrator\Engine\FieldRegistry;

\defined('_JEXEC') or die;

/**
 * A set of child fields the person can repeat (one row per visitor, item, ...).
 *
 * Value shape: list of rows, each row an object of child key => value.
 * Stored one row per repeater row in the form's own repeater table (see FormData), with
 * group_key = this field's key and row_index = 0, 1, 2 ...
 *
 * Settings: min (default 0), max (default 50), add_label, row_label.
 * Rows that are completely empty are dropped before validation.
 */
final class RepeaterField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $min    = (int) ($field['min'] ?? 0);
        $max    = (int) ($field['max'] ?? 50);
        $rows   = \is_array($value) ? array_values($value) : [];
        $count  = max(\count($rows), $min, 1);
        $label  = (string) ($field['label'] ?? $key);
        $rowLbl = (string) ($field['row_label'] ?? $label);
        $error  = $rc->error($key);

        $html = '<div class="sf-rows" id="' . $id . '-rows">';

        for ($i = 0; $i < $count; $i++) {
            $html .= $this->renderRow($field, $rc, $i, $rows[$i] ?? [], $rowLbl, $min);
        }

        $html .= '</div>';

        // Template for new rows, cloned by the browser script (index placeholder __SFROW__).
        $html .= '<template class="sf-row-template">' . $this->renderRow($field, $rc, '__SFROW__', [], $rowLbl, $min, true) . '</template>';

        $html .= '<button type="button" class="btn btn-sm btn-outline-secondary sf-add"'
            . ' aria-controls="' . $id . '-rows">'
            . self::e($field['add_label'] ?? Text::sprintf('COM_SQUIDFORM_REPEATER_ADD', $rowLbl)) . '</button>'
            . '<div class="visually-hidden sf-live" aria-live="polite"></div>';

        $attrs = ' data-sf-key="' . self::e($key) . '" data-sf-repeater="1" data-sf-min="' . $min . '" data-sf-max="' . $max . '"'
            . ' data-sf-next="' . $count . '" data-sf-row-label="' . self::e($rowLbl) . '"'
            . (!empty($field['show_if']) ? ' data-sf-show-if="' . self::e(json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::forBrowser($field['show_if']))) . '"' : '');

        return '<fieldset class="sf-field mb-3 sf-type-repeater" id="' . $id . '-group"' . $attrs
            . ($error !== null ? ' aria-describedby="' . $id . '-error"' : '') . '>'
            . '<legend class="form-label fs-6">' . self::labelText($field) . '</legend>'
            . (!empty($field['help']) ? '<div id="' . $id . '-help" class="form-text mb-2">' . self::e($field['help']) . '</div>' : '')
            . $html
            . ($error !== null ? '<div id="' . $id . '-error" class="invalid-feedback d-block" role="alert">' . self::e($error) . '</div>' : '')
            . '</fieldset>';
    }

    private function renderRow(array $field, RenderContext $rc, int|string $i, array $row, string $rowLbl, int $min, bool $template = false): string
    {
        $rrc     = $rc->forRow($field['key'], $i, $row);
        $titleId = $rc->id($field['key']) . '-row-' . $i . '-title';
        $num     = \is_int($i) ? (string) ($i + 1) : '__SFNUM__';
        $html    = '<div class="sf-row border rounded p-3 mb-2" role="group" aria-labelledby="' . $titleId . '" data-sf-row="' . self::e($i) . '">'
            . '<p class="sf-row-title fw-semibold mb-2" id="' . $titleId . '">' . self::e($rowLbl) . ' <span class="sf-row-num">' . $num . '</span></p>';

        $html .= \SquidForm\Component\SquidForm\Administrator\Engine\Layout::render(
            $field['fields'],
            static fn (array $child) => FieldRegistry::get($child['type'])->render($child, $row[$child['key']] ?? ($child['default'] ?? null), $rrc),
            static fn () => false,
            $rc->id($field['key']) . '-' . $i
        );

        $html .= '<button type="button" class="btn btn-sm btn-outline-danger sf-remove">'
            . self::e(Text::_('COM_SQUIDFORM_REPEATER_REMOVE'))
            . '<span class="visually-hidden"> ' . self::e($rowLbl) . ' <span class="sf-row-num">' . $num . '</span></span></button>';

        return $html . '</div>';
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $max  = (int) ($field['max'] ?? 50);
        $rows = [];

        foreach (\is_array($raw) ? $raw : [] as $r) {
            if (!\is_array($r)) {
                continue;
            }

            $row   = [];
            $empty = true;

            foreach ($field['fields'] as $child) {
                $type = FieldRegistry::get($child['type']);

                if (!$type->storesValue()) {
                    continue;
                }

                $v                   = $type->normalize($child, $r[$child['key']] ?? null);
                $row[$child['key']] = $v;

                if (!self::isEmpty($v) && $child['type'] !== 'hidden') {
                    $empty = false;
                }
            }

            if (!$empty) {
                $rows[] = $row;
            }

            // Hard stop well above max so a tampered request cannot flood the server.
            if (\count($rows) > $max + 1) {
                break;
            }
        }

        return $rows;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $rows  = \is_array($value) ? $value : [];
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $min   = (int) ($field['min'] ?? 0);
        $max   = (int) ($field['max'] ?? 50);

        if (!empty($field['rules']['required']) && $rows === []) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)];
        }

        if (\count($rows) < $min) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN_ROWS', $label, $min)];
        }

        if (\count($rows) > $max) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX_ROWS', $label, $max)];
        }

        $errors = [];

        foreach ($rows as $i => $row) {
            $rvc = $vc->forRow($field['key'], $i);

            foreach ($field['fields'] as $child) {
                $type = FieldRegistry::get($child['type']);

                if (!$type->storesValue()) {
                    continue;
                }

                if (!empty($child['show_if'])) {
                    $visible = Conditions::evaluate(
                        $child['show_if'],
                        static fn (string $k) => \array_key_exists($k, $row) ? $row[$k] : ($vc->data[$k] ?? null)
                    );

                    if (!$visible) {
                        continue;
                    }
                }

                $errors += $type->validate($child, $row[$child['key']] ?? null, $row, $rvc);
            }
        }

        return $errors;
    }
}
