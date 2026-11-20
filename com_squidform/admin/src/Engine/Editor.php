<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RenderContext;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ValidateContext;

\defined('_JEXEC') or die;

/**
 * Edit screen for a stored submission, used in the admin and the front-end
 * viewer.
 *
 *  - Uses the definition version the submission was made with.
 *  - Every page is shown on one screen, one section per page.
 *  - All value fields are editable unless the field says "editable": false;
 *    those are shown as text and their stored value is always kept.
 *  - The same normalising, show_if stripping and validation as a submit.
 *  - No submit actions run (no email, no redirect).
 */
final class Editor
{
    public const ID_PREFIX = 'sf-edit';

    /** @param array<string, mixed> $field */
    public static function editable(array $field): bool
    {
        // A signature (0.8.1) is the signer's own; staff never change it.
        if (\in_array($field['type'] ?? '', ['signature', 'upload', 'consent', 'calc'], true)) {
            return false;
        }

        return !\array_key_exists('editable', $field) || $field['editable'] !== false;
    }

    /**
     * Fields HTML for every page.
     *
     * @param  array<string, mixed>   $data
     * @param  array<string, string>  $errors
     */
    public static function render(object $item, Schema $schema, array $data, array $errors, ?User $user = null): string
    {
        // User-data rules (0.14.0) are about the person who sent it, not the editor.
        return Conditions::asUser((int) ($item->user_id ?? 0), static fn () => self::renderAs($item, $schema, $data, $errors, $user));
    }

    private static function renderAs(object $item, Schema $schema, array $data, array $errors, ?User $user): string
    {
        $user ??= Factory::getApplication()->getIdentity();
        $form = ['id' => (int) $item->form_id, 'title' => (string) $item->form_title, 'alias' => (string) $item->form_alias];
        $ph   = new Placeholders($schema, $user, $data, [], [], $form, (int) $item->id, (string) $item->uid);
        $rc   = new RenderContext($schema, (string) $item->form_alias, $ph, $errors, $data, [], 'sf', self::ID_PREFIX);

        $html  = '';
        $multi = $schema->pageCount() > 1;

        foreach ($schema->pages() as $i => $page) {
            $inner = '';

            $inner = Layout::render($schema->pageFields($i), static function (array $f) use ($data, $rc): string {
                $type = FieldRegistry::get($f['type']);

                if (!$type->storesValue()) {
                    return ''; // headings and help text belong to the public form (sections stay, as headings)
                }

                if (!self::editable($f)) {
                    return self::readonly($f, $data[$f['key']] ?? null);
                }

                return $type->render($f, $data[$f['key']] ?? null, $rc);
            }, static fn (string $k) => isset($errors[$k]), self::ID_PREFIX);

            if ($multi) {
                $title = (string) ($page['title'] ?? ('#' . ($i + 1)));
                $html .= '<fieldset class="sf-edit-page mb-4"><legend class="h5">' . Renderer::e($title) . '</legend>' . $inner . '</fieldset>';
            } else {
                $html .= $inner;
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $field */
    private static function readonly(array $field, mixed $value): string
    {
        if (($field['type'] ?? '') === 'repeater') {
            $rows = '';

            foreach ((array) $value as $n => $row) {
                $cells = '';

                foreach ($field['fields'] ?? [] as $c) {
                    $cells .= '<td>' . nl2br(Renderer::e(Display::value($c, $row[$c['key']] ?? null))) . '</td>';
                }

                $rows .= '<tr><th scope="row">' . ((int) $n + 1) . '</th>' . $cells . '</tr>';
            }

            $head = '';

            foreach ($field['fields'] ?? [] as $c) {
                $head .= '<th scope="col">' . Renderer::e($c['label'] ?? $c['key']) . '</th>';
            }

            $body = $rows !== '' ? '<table class="table table-sm table-bordered"><thead><tr><th scope="col">#</th>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>' : '<p>—</p>';

            return '<div class="mb-3 sf-readonly" id="' . self::ID_PREFIX . '-' . Renderer::e($field['key']) . '"><p class="form-label fw-semibold mb-1">'
                . Renderer::e($field['label'] ?? $field['key']) . ' <span class="badge bg-secondary">' . Renderer::e(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_NOT_EDITABLE')) . '</span></p>' . $body . '</div>';
        }

        return '<div class="mb-3 sf-readonly" id="' . self::ID_PREFIX . '-' . Renderer::e($field['key']) . '"><p class="form-label fw-semibold mb-1">'
            . Renderer::e($field['label'] ?? $field['key']) . ' <span class="badge bg-secondary">' . Renderer::e(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_NOT_EDITABLE')) . '</span></p>'
            . '<p class="mb-0">' . nl2br(Renderer::e(Display::value($field, $value))) . '</p></div>';
    }

    /**
     * Normalise, strip hidden values and validate a posted edit.
     *
     * @param  array<string, mixed>  $input
     *
     * @return array{errors: array<string, string>, data: array<string, mixed>}
     */
    public static function apply(object $item, Schema $schema, array $input, User $user): array
    {
        return Conditions::asUser((int) ($item->user_id ?? 0), static fn () => self::applyAs($item, $schema, $input, $user));
    }

    private static function applyAs(object $item, Schema $schema, array $input, User $user): array
    {
        $old  = (array) $item->data;
        $data = $old;

        foreach ($schema->valueFields() as $f) {
            if (!self::editable($f)) {
                continue; // keep the stored value
            }

            $data[$f['key']] = FieldRegistry::get($f['type'])->normalize($f, $input[$f['key']] ?? null);
        }

        // Calculations (0.10.0) follow the edited answers.
        $data = Processor::settle($schema, $data);

        $form   = ['id' => (int) $item->form_id, 'title' => (string) $item->form_title, 'alias' => (string) $item->form_alias];
        $ph     = new Placeholders($schema, $user, $data, [], [], $form, (int) $item->id, (string) $item->uid);
        $vc     = new ValidateContext($schema, $data, $ph);
        $errors = [];

        foreach ($schema->valueFields() as $f) {
            if (!self::editable($f) || !\array_key_exists($f['key'], $data)) {
                continue;
            }

            $errors += FieldRegistry::get($f['type'])->validate($f, $data[$f['key']], $data, $vc);
        }

        return ['errors' => $errors, 'data' => $data];
    }

    /**
     * Changed top-level values: key => {from, to}. A repeater is compared and
     * recorded as a whole.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $old, array $new): array
    {
        $out = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            $a = $old[$k] ?? null;
            $b = $new[$k] ?? null;

            if (self::same($a, $b)) {
                continue;
            }

            $out[$k] = ['from' => $a, 'to' => $b];
        }

        return $out;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        $norm = static function (mixed $v) use (&$norm): mixed {
            if ($v === '' || $v === []) {
                return null;
            }

            if (\is_array($v)) {
                return array_map($norm, $v);
            }

            return \is_scalar($v) ? (string) $v : $v;
        };

        return json_encode($norm($a)) === json_encode($norm($b));
    }
}
