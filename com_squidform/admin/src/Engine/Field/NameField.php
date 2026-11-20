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
 * A person's name (0.8.1). "parts" picks which boxes show, from
 * title, first, middle, last and suffix (default first and last).
 * First and last are needed when the field is required, or once any part
 * is filled in. Stored as {"first": "Pat", "last": "Example"}.
 */
final class NameField extends PartsField
{
    public const PARTS = ['title', 'first', 'middle', 'last', 'suffix'];

    private const INFO = [
        'title'  => ['COM_SQUIDFORM_NAME_TITLE', 2, 'honorific-prefix', false, 30],
        'first'  => ['COM_SQUIDFORM_NAME_FIRST', 4, 'given-name', true, 100],
        'middle' => ['COM_SQUIDFORM_NAME_MIDDLE', 3, 'additional-name', false, 100],
        'last'   => ['COM_SQUIDFORM_NAME_LAST', 4, 'family-name', true, 100],
        'suffix' => ['COM_SQUIDFORM_NAME_SUFFIX', 2, 'honorific-suffix', false, 30],
    ];

    /** The parts a definition asks for, in the standard order. */
    public static function chosen(array $field): array
    {
        $want = \is_array($field['parts'] ?? null) ? $field['parts'] : ['first', 'last'];

        return array_values(array_filter(self::PARTS, static fn ($p) => \in_array($p, $want, true)));
    }

    protected function parts(array $field): array
    {
        $chosen = self::chosen($field);
        $labels = \is_array($field['part_labels'] ?? null) ? $field['part_labels'] : [];
        $out    = [];
        $sum    = array_sum(array_map(static fn ($p) => self::INFO[$p][1], $chosen));

        foreach ($chosen as $p) {
            [$lang, $w, $ac, $req, $max] = self::INFO[$p];

            // Fit one row: two parts share it evenly; too many get narrower.
            if (\count($chosen) <= 2) {
                $w = 12 / max(1, \count($chosen));
            } elseif ($sum > 12) {
                $w = ['first' => 3, 'last' => 3, 'middle' => 2][$p] ?? $w;
            }

            $out[] = ['key' => $p, 'label' => (string) ($labels[$p] ?? Text::_($lang)), 'width' => (int) $w, 'autocomplete' => $ac, 'required' => $req, 'max' => $max];
        }

        return $out;
    }

    /** "Dr Pat Q Example Jr" */
    public static function format(array $field, array $v): string
    {
        return trim(implode(' ', array_filter(array_map(static fn ($p) => (string) ($v[$p] ?? ''), self::chosen($field) ?: self::PARTS), static fn ($s) => $s !== '')));
    }
}
