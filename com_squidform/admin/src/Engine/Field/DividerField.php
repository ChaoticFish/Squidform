<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

\defined('_JEXEC') or die;

/**
 * A divider (0.9.1): a horizontal line between fields. "space" above and
 * below: none, small, medium (default) or large. Style it with css_class or
 * .sf-divider. Stores nothing.
 */
final class DividerField extends AbstractField
{
    private const SPACE = ['none' => 'my-0', 'small' => 'my-2', 'medium' => 'my-3', 'large' => 'my-5'];

    public function storesValue(): bool
    {
        return false;
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $space = self::SPACE[$field['space'] ?? 'medium'] ?? 'my-3';

        return '<hr class="sf-field sf-divider ' . $space . '" data-sf-key="' . self::e($field['key']) . '"'
            . (!empty($field['show_if']) ? ' data-sf-show-if="' . self::e(json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::forBrowser($field['show_if']))) . '"' : '') . '>';
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
