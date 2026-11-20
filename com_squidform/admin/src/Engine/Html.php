<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Filter\InputFilter;

\defined('_JEXEC') or die;

/**
 * The HTML SquidForm keeps (0.8.0): an allowlist, the same everywhere HTML
 * can be written (text blocks, messages, the text above a form, emails'
 * {data.raw:…}, and what people type in an editor field).
 *
 * Anything not listed is removed, tag and all. Joomla's filter also removes
 * javascript:, vbscript: and similar addresses and CSS expression().
 * The list is shown in the builder (ⓘ) and in SCHEMA.md.
 */
final class Html
{
    public const TAGS = [
        'p', 'br', 'hr', 'div', 'span', 'blockquote', 'pre', 'code',
        'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark',
        'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'a', 'img', 'figure', 'figcaption',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    public const ATTRIBUTES = [
        'class', 'title', 'lang', 'dir',
        'href', 'target', 'rel',
        'src', 'alt', 'width', 'height', 'loading',
        'colspan', 'rowspan', 'scope',
        // 0.9.1: "show parts of this text by" a field
        'data-show-when',
    ];

    /**
     * @param list<string>|null $tags  0.13.2: a narrower list for one field (never wider: anything not in TAGS is dropped)
     * @param list<string>|null $attrs the same for attributes
     */
    public static function clean(string $html, ?array $tags = null, ?array $attrs = null): string
    {
        $tags  = $tags === null ? self::TAGS : array_values(array_intersect(self::TAGS, $tags));
        $attrs = $attrs === null ? self::ATTRIBUTES : array_values(array_intersect(self::ATTRIBUTES, $attrs));

        if ($html === '') {
            return '';
        }

        // These go with everything inside them, not just the tags (the filter
        // alone would leave a script's code behind as text).
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|template|noscript|textarea|select|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html);

        $filter = InputFilter::getInstance($tags, $attrs, InputFilter::ONLY_ALLOW_DEFINED_TAGS, InputFilter::ONLY_ALLOW_DEFINED_ATTRIBUTES);
        $out    = $filter->clean($html, 'html');

        // Links that open a new tab must not hand it the form's window.
        return (string) preg_replace_callback('/<a\b[^>]*\btarget="_blank"[^>]*>/i', static function ($m) {
            return str_contains(strtolower($m[0]), 'rel=') ? $m[0] : substr($m[0], 0, -1) . ' rel="noopener noreferrer">';
        }, $out);
    }
}
