<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * How a list of fields is laid out (0.8.0): Bootstrap rows and columns, and
 * sections. Used by the form, the preview, the edit screen and repeater rows.
 *
 * - "width": 1–12 columns from the medium breakpoint up; everything is full
 *   width on phones. No width = 12.
 * - A "section" starts a group that runs to the next section: a fieldset with
 *   a legend (style "rule", the default, or "box"), or <details> when
 *   "collapsible" is true. A collapsed section opens when one of its fields
 *   has an error.
 */
final class Layout
{
    /**
     * @param list<array<string, mixed>>         $fields
     * @param callable(array<string, mixed>): string $one      renders one field
     * @param callable(string): bool             $hasError field key → has an error
     * @param string                             $idPrefix for section ids
     */
    public static function render(array $fields, callable $one, callable $hasError, string $idPrefix): string
    {
        $out     = '';
        $row     = '';
        $section = null; // ['field' => …, 'body' => …]

        $flushRow = static function () use (&$row): string {
            $r   = $row !== '' ? '<div class="row sf-grid">' . $row . '</div>' : '';
            $row = '';

            return $r;
        };

        $closeSection = static function () use (&$section, $hasError, $idPrefix, $flushRow): string {
            if ($section === null) {
                return '';
            }

            $html    = self::section($section['field'], $section['body'] . $flushRow(), $section['keys'], $hasError, $idPrefix);
            $section = null;

            return $html;
        };

        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'section_end') {
                $out .= $closeSection();
                continue;
            }

            if (($f['type'] ?? '') === 'section') {
                if ($section !== null) {
                    $out .= $closeSection();
                } else {
                    $out .= $flushRow();
                }

                $section = ['field' => $f, 'body' => '', 'keys' => []];
                continue;
            }

            $html = $one($f);

            if ($html === '') {
                continue;
            }

            // Hidden inputs take no room.
            if (($f['type'] ?? '') === 'hidden' || (($f['type'] ?? '') === 'calc' && !empty($f['named']))) {
                $row .= $html;
            } else {
                $w    = (int) ($f['width'] ?? 12);
                $w    = $w >= 1 && $w <= 12 ? $w : 12;
                $own  = self::cssClass($f);
                $row .= '<div class="sf-col col-12' . ($w < 12 ? ' col-md-' . $w : '') . ($own !== '' ? ' ' . $own : '') . '">' . $html . '</div>';
            }

            if ($section !== null) {
                $section['keys'][] = (string) $f['key'];
            }
        }

        return $out . ($section !== null ? $closeSection() : $flushRow());
    }

    /** The field's own classes (0.9.1), already checked by Schema. */
    public static function cssClass(array $f): string
    {
        $c = (string) ($f['css_class'] ?? '');

        return preg_match(Schema::CSS_CLASS, $c) ? $c : '';
    }

    /** @param string[] $keys */
    private static function section(array $f, string $body, array $keys, callable $hasError, string $idPrefix): string
    {
        $e     = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $id    = $idPrefix . '-' . $f['key'];
        $style = ($f['style'] ?? 'rule') === 'box' ? 'box' : 'rule';
        $own   = self::cssClass($f);
        $cls   = 'sf-section sf-section-' . $style . ($style === 'box' ? ' card card-body' : '') . ' mb-3' . ($own !== '' ? ' ' . $own : '');
        $attrs = ' data-sf-key="' . $e($f['key']) . '" data-sf-section="1"'
            . (!empty($f['show_if']) ? ' data-sf-show-if="' . $e(json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\Conditions::forBrowser($f['show_if']))) . '"' : '');
        $intro = !empty($f['help']) ? '<p class="sf-section-intro text-body-secondary" id="' . $id . '-intro">' . $e($f['help']) . '</p>' : '';
        $title = $e($f['label'] ?? '');

        if (!empty($f['collapsible'])) {
            $open = empty($f['collapsed']) || array_filter($keys, $hasError);

            return '<details class="' . $cls . ' sf-section-collapsible" id="' . $id . '"' . $attrs . ($open ? ' open' : '') . '>'
                . '<summary class="sf-section-title h5" id="' . $id . '-title">' . $title . '</summary>'
                . '<div role="group" aria-labelledby="' . $id . '-title"' . ($intro ? ' aria-describedby="' . $id . '-intro"' : '') . '>' . $intro . $body . '</div>'
                . '</details>';
        }

        return '<fieldset class="' . $cls . '" id="' . $id . '"' . $attrs . ($intro ? ' aria-describedby="' . $id . '-intro"' : '') . '>'
            . '<legend class="sf-section-title h5">' . $title . '</legend>' . $intro . $body . '</fieldset>';
    }
}
