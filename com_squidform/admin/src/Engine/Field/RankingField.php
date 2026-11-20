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
 * Ranking (0.9.2): put these in order. "items" are texts or
 * {"value", "label"} (2–20).
 *
 * Each item has a rank dropdown (1, 2, 3…), which works without JavaScript
 * and from the keyboard; squidform.js adds moving items with ↑ ↓ buttons or
 * by dragging, and keeps the dropdowns in step. "required": every item
 * ranked. Each rank once. Stored as the values in order, first first.
 */
final class RankingField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key   = $field['key'];
        $id    = $rc->id($key);
        $name  = $rc->name($key);
        $items = GridField::items($field['items'] ?? []);
        $n     = \count($items);
        $order = \is_array($value) ? array_values($value) : [];
        $bad   = $rc->error($key) !== null;
        $db    = self::describedBy($field, $rc);

        // Ranked items first, in their order; the rest after.
        $byVal = array_column($items, null, 'value');
        $shown = [];

        foreach ($order as $v) {
            if (isset($byVal[$v])) {
                $shown[] = $byVal[$v];
                unset($byVal[$v]);
            }
        }

        $shown = array_merge($shown, array_values($byVal));
        $html  = '<p class="form-text mt-0" id="' . $id . '-how">' . self::e(Text::_('COM_SQUIDFORM_RANK_HOW')) . '</p>'
            . '<ol class="list-unstyled sf-rank" data-sf-rank="' . self::e($key) . '">';

        foreach ($shown as $i => $it) {
            $rid   = $id . '-' . $i;
            $pos   = array_search($it['value'], $order, true);
            $html .= '<li class="sf-rank-item d-flex align-items-center gap-2 mb-1" data-sf-value="' . self::e($it['value']) . '">'
                . '<select class="form-select form-select-sm sf-rank-select w-auto" id="' . $rid . '" name="' . self::e($name . '[' . $it['value'] . ']') . '"'
                . ' aria-describedby="' . trim($id . '-how ' . $db) . '"' . ($bad ? ' aria-invalid="true"' : '') . '>'
                . '<option value="">—</option>';

            for ($r = 1; $r <= $n; $r++) {
                $html .= '<option value="' . $r . '"' . ($pos !== false && $pos + 1 === $r ? ' selected' : '') . '>' . $r . '</option>';
            }

            $html .= '</select><label class="sf-rank-label mb-0" for="' . $rid . '">' . self::e($it['label']) . '</label></li>';
        }

        $html .= '</ol><div class="visually-hidden" aria-live="polite" data-sf-rank-live></div>';

        return self::wrap($field, $rc, $html, true, 'sf-ranking');
    }

    /** {"value": "rank"} from the dropdowns → the values in rank order. */
    public function normalize(array $field, mixed $raw): mixed
    {
        $valid = array_column(GridField::items($field['items'] ?? []), 'value');

        if (\is_array($raw) && array_is_list($raw)) {
            // Already an order (carried data): only real items, once each.
            return array_values(array_unique(array_intersect(array_map('strval', array_filter($raw, 'is_scalar')), $valid)));
        }
        $ranks = [];

        foreach (\is_array($raw) ? $raw : [] as $v => $r) {
            if (\in_array((string) $v, $valid, true) && \is_scalar($r) && ctype_digit((string) $r) && (int) $r >= 1) {
                $ranks[(string) $v] = (int) $r;
            }
        }

        // A rank used twice keeps its first item; the order is by rank.
        asort($ranks, SORT_NUMERIC);
        $seen = [];
        $out  = [];

        foreach ($ranks as $v => $r) {
            if (!isset($seen[$r])) {
                $seen[$r] = true;
                $out[]    = $v;
            }
        }

        return $out;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $n     = \count(GridField::items($field['items'] ?? []));
        $have  = \is_array($value) ? \count($value) : 0;

        if (!empty($field['rules']['required']) && $have < $n) {
            return [$vc->path($field['key']) => Text::sprintf($have ? 'COM_SQUIDFORM_ERR_RANK_ALL' : 'COM_SQUIDFORM_ERR_REQUIRED', $label)];
        }

        return [];
    }

    /** "1. Coffee, 2. Tea, 3. Water" */
    public static function format(array $field, array $order): string
    {
        $labels = array_column(GridField::items($field['items'] ?? []), 'label', 'value');
        $out    = [];

        foreach (array_values($order) as $i => $v) {
            $out[] = ($i + 1) . '. ' . ($labels[$v] ?? $v);
        }

        return implode(', ', $out);
    }
}
