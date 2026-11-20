<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Uploads;

\defined('_JEXEC') or die;

/**
 * File upload (0.9.0). What's allowed is set by the site admin in
 * Options → Uploads; a field can narrow it:
 *
 *   "accept":    ["pdf", "docx"]   only these of the allowed types
 *   "max_mb":    5                 at most this size (never above the Options)
 *   "max_files": 3                 how many (1–20, default 1)
 *
 * The answer is the list of the files' ids. The files themselves are taken
 * by Processor (they arrive in $_FILES, not the form fields) and checked,
 * scanned and stored by Engine\Uploads. Files already sent show as a list
 * with "Keep" ticks, so a page with a mistake doesn't lose them.
 */
final class UploadField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $uids   = \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        $rows   = Uploads::rows($uids);
        $max    = self::maxFiles($field);
        $types  = Uploads::typesFor($field);
        $bytes  = Uploads::maxBytesFor($field);
        $locked = isset($rc->locked[$key]) || !empty($field['readonly']);
        $html   = '';

        if ($rows) {
            $html .= '<ul class="list-unstyled sf-files mb-2" aria-label="' . self::e(Text::sprintf('COM_SQUIDFORM_FILES_SENT', (string) ($field['label'] ?? $key))) . '">';

            foreach ($uids as $u) {
                if (!isset($rows[$u])) {
                    continue;
                }

                $r     = $rows[$u];
                $kid   = $id . '-keep-' . $u;
                $html .= '<li class="form-check"><input class="form-check-input" type="checkbox" id="' . $kid . '" name="' . self::e($name . '[keep][]') . '" value="' . self::e($u) . '" checked'
                    . ($locked ? ' disabled' : '') . '>'
                    . '<label class="form-check-label" for="' . $kid . '">' . self::e(Text::sprintf('COM_SQUIDFORM_FILE_KEEP', $r->original_name, Uploads::size((int) $r->size))) . '</label>'
                    . ($locked ? '<input type="hidden" name="' . self::e($name . '[keep][]') . '" value="' . self::e($u) . '">' : '')
                    . '</li>';
            }

            $html .= '</ul>';
        }

        $hint = Text::sprintf('COM_SQUIDFORM_FILE_HINT', implode(', ', array_map('strtoupper', $types)), Uploads::size($bytes))
            . ($max > 1 ? ' ' . Text::sprintf('COM_SQUIDFORM_FILE_HINT_N', $max) : '');
        $db   = trim(self::describedBy($field, $rc) . ' ' . $id . '-types');
        $cls  = 'form-control' . ($rc->error($key) !== null ? ' is-invalid' : '');

        if (!$locked) {
            $html .= '<input type="file" class="' . $cls . '" id="' . $id . '" name="' . self::e($name . '[new][]') . '"'
                . ($max > 1 ? ' multiple' : '')
                . ' accept="' . self::e(implode(',', array_map(static fn ($t) => '.' . $t, $types))) . '"'
                . ' data-sf-max-bytes="' . $bytes . '" data-sf-max-files="' . $max . '"'
                . ' aria-describedby="' . $db . '"'
                . ($rc->error($key) !== null ? ' aria-invalid="true"' : '')
                . (self::required($field) && !$rows ? ' aria-required="true"' : '') . '>';
        }

        $html .= '<div class="form-text" id="' . $id . '-types">' . self::e($hint) . '</div>';

        return self::wrap($field, $rc, $html);
    }

    public static function maxFiles(array $field): int
    {
        return max(1, min(20, (int) ($field['max_files'] ?? 1)));
    }

    /** Only file ids get through; the files are handled by Processor. */
    public function normalize(array $field, mixed $raw): mixed
    {
        $list = \is_array($raw) && isset($raw['keep']) ? (array) $raw['keep'] : (\is_array($raw) ? $raw : []);

        return array_values(array_unique(array_filter($list, static fn ($u) => \is_string($u) && preg_match('/^[0-9a-f]{32}$/', $u))));
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        if (self::isEmpty($value) && self::required($field)) {
            return [$vc->path($field['key']) => Text::sprintf('COM_SQUIDFORM_ERR_FILE_REQUIRED', (string) ($field['label'] ?? $field['key']))];
        }

        return [];
    }
}
