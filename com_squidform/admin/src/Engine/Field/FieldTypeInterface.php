<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

\defined('_JEXEC') or die;

/**
 * Contract for a field type. Built-in types live in this folder; extra types can
 * be added by a plugin listening to `onSquidformFieldTypes` (see docs/DEVELOPER.md).
 */
interface FieldTypeInterface
{
    /** Does this type hold a value that is validated and stored? */
    public function storesValue(): bool;

    /**
     * Render the field's HTML (label, control, help and error).
     *
     * @param   array<string, mixed>  $field
     */
    public function render(array $field, mixed $value, RenderContext $rc): string;

    /**
     * Turn raw request input into the stored shape (string, list or rows).
     *
     * @param   array<string, mixed>  $field
     */
    public function normalize(array $field, mixed $raw): mixed;

    /**
     * Check a normalized value. Returns error messages keyed by field path
     * ("f_key", or "f_rep.2.f_child" inside a repeater). Empty array = valid.
     *
     * @param   array<string, mixed>  $field
     * @param   array<string, mixed>  $scope  Sibling values used by option filters and conditions.
     *
     * @return  array<string, string>
     */
    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array;
}
