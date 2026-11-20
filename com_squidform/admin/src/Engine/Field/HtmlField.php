<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Filter\InputFilter;

\defined('_JEXEC') or die;

/**
 * Static content: headings, instructions, notices. Stores nothing.
 *
 * "content" is filtered with Joomla's input filter (safe tags only, no scripts
 * or event handlers). Placeholders are allowed and escaped, e.g.
 * "Hello {user:name}". Set "tag" to "h2"..."h4" for a heading.
 */
final class HtmlField extends AbstractField
{
    public function storesValue(): bool
    {
        return false;
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $content = $rc->placeholders->replace((string) ($field['content'] ?? ''), 'html');
        $content = \SquidForm\Component\SquidForm\Administrator\Engine\Html::clean((string) $content);
        $tag     = \in_array($field['tag'] ?? '', ['h2', 'h3', 'h4', 'p'], true) ? $field['tag'] : 'div';

        // "Show parts of this text by" a field (0.9.1): each element with
        // data-show-when="A" (or "A|B") shows only while that field is A (or B).
        // Done here for the first view (and without JavaScript); squidform.js
        // keeps it up to date as people answer.
        $by = \is_string($field['parts_by'] ?? null) ? $field['parts_by'] : '';

        if ($by !== '') {
            $current = array_map('strval', (array) ($rc->lookup($by) ?? []));
            $content = (string) preg_replace_callback('/<([a-z][a-z0-9]*)\b([^>]*?)\sdata-show-when="([^"]*)"([^>]*)>/i', static function ($m) use ($current) {
                $want = array_map('trim', explode('|', html_entity_decode($m[3], ENT_QUOTES, 'UTF-8')));
                $show = (bool) array_intersect($want, $current);

                return '<' . $m[1] . $m[2] . ' data-show-when="' . $m[3] . '"' . $m[4] . ($show ? '' : ' hidden') . '>';
            }, $content);
        }

        return '<' . $tag . ' class="sf-field sf-type-html mb-3" data-sf-key="' . self::e($field['key']) . '"'
            . ($by !== '' ? ' data-sf-parts-by="' . self::e($by) . '"' : '')
            . (!empty($field['show_if']) ? ' data-sf-show-if="' . self::e(json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::forBrowser($field['show_if']))) . '"' : '') . '>'
            . $content . '</' . $tag . '>';
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
