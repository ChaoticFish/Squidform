<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

\defined('_JEXEC') or die;

/**
 * A section (0.8.0): a heading, an optional line of text, and the fields that
 * follow it up to the next section. Stores nothing. Drawn by Engine\Layout,
 * which wraps the section's fields in a fieldset (or <details> when it can be
 * collapsed). Its show_if applies to every field in it (see Schema).
 *
 * Properties: label, help, style ("rule" or "box"), collapsible, collapsed.
 */
final class SectionField extends AbstractField
{
    public function storesValue(): bool
    {
        return false;
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        return ''; // Layout draws sections
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        return null;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        return [];
    }
}
