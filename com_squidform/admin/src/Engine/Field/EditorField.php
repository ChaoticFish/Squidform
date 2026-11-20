<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Editor\Editor;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Display;
use SquidForm\Component\SquidForm\Administrator\Engine\Html;

\defined('_JEXEC') or die;

/**
 * Formatted text (0.8.0): Joomla's own editor (TinyMCE ships with Joomla and
 * works offline), or a plain text box when the site has no editor or "none".
 * What people type is kept as HTML, filtered with SquidForm's allowlist
 * (Engine\Html) both when it arrives and whenever it's shown.
 * Rules: required, min_length and max_length count the visible text.
 */
final class EditorField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key  = $field['key'];
        $id   = $rc->id($key);
        $name = $rc->name($key);
        [$tags, $attrs] = self::allowed($field);
        $html = \is_string($value) ? Html::clean($value, $tags, $attrs) : '';
        $rows = max(4, min(30, (int) ($field['rows'] ?? 8)));
        // 0.13.2: what HTML is kept here, shown while the raw HTML is.
        $note = '<div class="form-text sf-html-note" id="' . $id . '-htmlnote">' . self::e(self::note($field)) . '</div>';

        $editor = self::editorName();
        $open   = !isset($rc->locked[$key]) && empty($field['readonly']);

        // 0.13.1: an add-on may bring its own editor (a Quill add-on, say).
        if ($open && ($addon = self::addonEditor($field, $name, $id, $html, $rows)) !== null) {
            return self::wrap($field, $rc, '<div class="sf-editor" id="' . $id . '" data-sf-editor="' . $id . '">' . $addon . '</div>' . $note);
        }

        if ($editor !== '' && $open) {
            try {
                // Editors rewrite the id (TinyMCE turns "-" into "_"), so the
                // label points at the editor's id and the wrapper keeps ours,
                // for the error list's links (squidform.js moves focus in).
                $eid     = (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $id);

                // 0.13.1: TinyMCE gets a short toolbar for visitors: no menus,
                // no Media Manager or other editor buttons.
                if ($editor === 'tinymce') {
                    $parts = explode('[', str_replace(['[]', ']'], '', $name));
                    $has  = static fn (string ...$t) => (bool) array_intersect($t, $tags);
                    $bar  = array_filter([
                        'undo redo',
                        trim(($has('strong', 'b') ? 'bold ' : '') . ($has('em', 'i') ? 'italic ' : '') . ($has('u') ? 'underline' : '')),
                        $has('li') ? trim(($has('ul') ? 'bullist ' : '') . ($has('ol') ? 'numlist' : '')) : '',
                        $has('a') && \in_array('href', $attrs, true) ? 'link unlink' : '',
                        'removeformat',
                    ]);
                    $valid = implode(',', array_map(static fn ($t) => $t . ($attrs ? '[' . implode('|', $attrs) . ']' : ''), $tags));
                    Factory::getApplication()->getDocument()->addScriptOptions('plg_editor_tinymce', ['tinyMCE' => [end($parts) => [
                        'menubar'                     => false,
                        'toolbar'                     => implode(' | ', $bar),
                        'quickbars_selection_toolbar' => trim(($has('strong', 'b') ? 'bold ' : '') . ($has('em', 'i') ? 'italic ' : '') . ($has('a') ? '| link' : ''), ' |') ?: false,
                        'statusbar'                   => false,
                        // Pasted content keeps only what this field allows (the server checks again).
                        'valid_elements'              => $valid !== '' ? $valid : '-p',
                    ]]]);
                }

                $control = Editor::getInstance($editor)->display($name, $html, '100%', (string) ($rows * 28), '60', (string) $rows, false, $eid, null, null, ['readonly' => false]);
                $out     = self::wrap($field, $rc, '<div class="sf-editor" id="' . $id . '" data-sf-editor="' . $eid . '" data-sf-htmlnote="' . $id . '-htmlnote">' . $control . '</div>' . str_replace('class="form-text sf-html-note"', 'class="form-text sf-html-note" hidden', $note));

                return str_replace('<label class="form-label" for="' . $id . '">', '<label class="form-label" for="' . $eid . '" id="' . $id . '-label">', $out);
            } catch (\Throwable) {
                // fall through to a plain box
            }
        }

        $cls = 'form-control' . ($rc->error($key) !== null ? ' is-invalid' : '');

        $ca = self::controlAttrs($field, $rc);
        $ca = str_contains($ca, 'aria-describedby="') ? str_replace('aria-describedby="', 'aria-describedby="' . $id . '-htmlnote ', $ca) : $ca . ' aria-describedby="' . $id . '-htmlnote"';

        // A plain box is raw HTML, so its note always shows.
        return self::wrap($field, $rc, '<textarea class="' . $cls . '" id="' . $id . '" name="' . self::e($name) . '" rows="' . $rows . '"'
            . $ca . '>' . self::e($html) . '</textarea>' . $note);
    }

    /**
     * 0.13.2: the tags and attributes this field keeps: "allowed_tags" and
     * "allowed_attributes" narrow SquidForm's list (never widen it).
     * "data-show-when" is a builder feature, never kept in an answer.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function allowed(array $field): array
    {
        $tags  = \is_array($field['allowed_tags'] ?? null) ? array_values(array_intersect(Html::TAGS, $field['allowed_tags'])) : Html::TAGS;
        $attrs = \is_array($field['allowed_attributes'] ?? null) ? $field['allowed_attributes'] : Html::ATTRIBUTES;
        $attrs = array_values(array_diff(array_intersect(Html::ATTRIBUTES, $attrs), ['data-show-when']));

        return [$tags, $attrs];
    }

    /** The note under the raw HTML: the field's own words, or the list. */
    public static function note(array $field): string
    {
        $own = trim((string) ($field['html_note'] ?? ''));

        if ($own !== '') {
            return $own;
        }

        [$tags, $attrs] = self::allowed($field);

        if (!$tags) {
            return Text::_('COM_SQUIDFORM_HTML_NOTE_NONE');
        }

        return Text::sprintf('COM_SQUIDFORM_HTML_NOTE', implode(', ', $tags), $attrs ? implode(', ', $attrs) : Text::_('COM_SQUIDFORM_HTML_NOTE_NO_ATTRS'));
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        if (!\is_string($raw)) {
            return '';
        }

        [$tags, $attrs] = self::allowed($field);
        $html = Html::clean(mb_substr($raw, 0, 65535), $tags, $attrs);

        // An editor left empty still sends <p></p> or &nbsp;.
        return trim(str_replace("\u{a0}", ' ', Display::plain($html))) === '' && !str_contains($html, '<img') ? '' : $html;
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $text = \is_string($value) ? Display::plain($value) : '';

        return self::textRules($field + ['rules' => []], $text === '' && \is_string($value) && str_contains($value, '<img') ? ' ' : $text, $vc);
    }

    /** Editors that show code, not formatting: never given to people filling in a form. */
    public const CODE_EDITORS = ['none', 'codemirror'];

    /**
     * 0.13.1: TinyMCE whenever it's on, whatever the site's or the person's own
     * editor (a developer's CodeMirror shouldn't reach visitors). Else the
     * site's or their editor if it formats text (JCE, say); else '' (a plain box).
     */
    private static function editorName(): string
    {
        if (PluginHelper::isEnabled('editors', 'tinymce')) {
            return 'tinymce';
        }

        $app = Factory::getApplication();

        foreach ([(string) $app->getIdentity()?->getParam('editor'), (string) $app->get('editor', '')] as $name) {
            if ($name !== '' && !\in_array($name, self::CODE_EDITORS, true) && PluginHelper::isEnabled('editors', $name)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * 0.13.1 add-on hook: onSquidformEditor. A plugin in the "squidform" group
     * may set $event['result']['html'] to its own control, which must post
     * HTML under $name; SquidForm filters it on arrival like any other.
     */
    private static function addonEditor(array $field, string $name, string $id, string $html, int $rows): ?string
    {
        try {
            PluginHelper::importPlugin('squidform');
            $result = new \ArrayObject();
            Factory::getApplication()->getDispatcher()->dispatch('onSquidformEditor', new \Joomla\Event\Event('onSquidformEditor', [
                'field' => $field, 'name' => $name, 'id' => $id, 'value' => $html, 'rows' => $rows, 'result' => $result,
            ]));
        } catch (\Throwable) {
            return null;
        }

        return isset($result['html']) && \is_string($result['html']) && $result['html'] !== '' ? $result['html'] : null;
    }
}
