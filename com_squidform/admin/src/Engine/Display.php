<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/**
 * Turns stored values into what people read: option labels instead of
 * values, Yes/No for single checkboxes, lists joined with commas. Plain text;
 * escape it when printing.
 */
final class Display
{
    /** @var array<string, array<string, string>> */
    private static array $maps = [];

    /**
     * @param array<string, mixed> $field
     * @param bool $reveal  show a "Hide what's typed" answer (0.8.0) instead of dots
     */
    public static function value(array $field, mixed $value, string $empty = '—', bool $reveal = false): string
    {
        if ($value === null || $value === '' || $value === []) {
            return $empty;
        }

        $type = $field['type'] ?? '';

        if (!empty($field['hide_input']) && !$reveal) {
            return '••••••';
        }

        if ($type === 'datetime') {
            return str_replace('T', ' ', (string) $value);
        }

        // 0.9.2
        if ($type === 'calc') {
            return Field\CalcField::format($field, $value);
        }

        if ($type === 'slider') {
            return Field\SliderField::format($field, $value);
        }

        if ($type === 'imagechoice') {
            return Field\ImageChoiceField::format($field, $value);
        }

        if ($type === 'phone' && \is_array($value)) {
            return Field\PhoneField::format($value);
        }

        if ($type === 'daterange' && \is_array($value)) {
            return Field\DateRangeField::format($value);
        }

        if ($type === 'ranking' && \is_array($value)) {
            return Field\RankingField::format($field, $value);
        }

        if ($type === 'consent' && \is_array($value)) {
            return Field\ConsentField::format($value);
        }

        if ($type === 'rating') {
            return Text::sprintf('COM_SQUIDFORM_RATING_OF', (string) $value, (int) ($field['max'] ?? 5));
        }

        if ($type === 'editor') {
            return self::plain((string) $value);
        }

        // 0.8.1: answers made of parts.
        if (\is_array($value)) {
            switch ($type) {
                case 'signature':
                    return Field\SignatureField::describe($value);
                case 'grid':
                    return implode('; ', Field\GridField::lines($field, $value));
                case 'name':
                    return Field\NameField::format($field, $value);
                case 'address':
                    return Field\AddressField::format($field, $value);
                case 'upload':
                    $rows = Uploads::rows($value);
                    $rows = array_values(array_filter(array_map(static fn ($u) => $rows[$u] ?? null, $value)));

                    return implode(', ', array_map(static fn ($r) => $r->original_name . ' (' . Uploads::size((int) $r->size) . ')', $rows));
            }
        }

        if (($field['type'] ?? '') === 'repeater') {
            return Text::plural('COM_SQUIDFORM_N_ROWS', \count((array) $value));
        }

        if (($field['type'] ?? '') === 'user') {
            return Users::name((int) $value);
        }

        if (isset($field['options'])) {
            $map = self::map($field);

            if ($map) {
                $value = \is_array($value)
                    ? array_map(static fn ($v) => $map[(string) $v] ?? $v, $value)
                    : ($map[(string) $value] ?? $value);
            }
        }

        if (($field['type'] ?? '') === 'checkbox') {
            return Text::_((string) $value === '1' ? 'JYES' : 'JNO');
        }

        return \is_array($value) ? implode(', ', array_map(static fn ($v) => \is_scalar($v) ? (string) $v : json_encode($v), $value)) : (string) $value;
    }

    /** Safe HTML for a detail page: rich text keeps its (filtered) formatting; the rest is escaped text. */
    public static function html(array $field, mixed $value, bool $reveal = false): string
    {
        $type = $field['type'] ?? '';

        if ($type === 'editor' && \is_string($value) && $value !== '') {
            return '<div class="sf-richtext">' . Html::clean($value) . '</div>';
        }

        $esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        if (\is_array($value) && $value !== []) {
            // The drawing itself; cleanPng() lets only a real PNG through.
            if ($type === 'signature') {
                $png = ($value['mode'] ?? '') === 'draw' ? Field\SignatureField::cleanPng((string) ($value['png'] ?? '')) : '';

                return ($png !== '' ? '<img class="sf-sig-image d-block mb-1" src="' . $png . '" alt="' . $esc(Text::_('COM_SQUIDFORM_SIG_IMAGE_ALT')) . '">' : '')
                    . $esc(Field\SignatureField::describe($value));
            }

            if ($type === 'grid') {
                return implode('<br>', array_map($esc, Field\GridField::lines($field, $value)));
            }

            // Files (0.9.0): links through SquidForm's own download, which checks access.
            if ($type === 'upload') {
                $out = [];

                // Route::_ makes it the admin's or the site's own address.
                $rows = Uploads::rows($value);

                foreach (array_filter(array_map(static fn ($u) => $rows[$u] ?? null, $value)) as $r) {
                    $url   = Route::_('index.php?option=com_squidform&task=file.download&uid=' . $r->uid, false);
                    $out[] = '<a class="sf-file-link" href="' . $esc($url) . '">' . $esc($r->original_name) . '</a> <span class="small text-body-secondary">(' . $esc(Uploads::size((int) $r->size)) . ')</span>'
                        . ($r->scan === 'not_scanned' && Scanner::config()['mode'] !== 'none' ? ' <span class="badge text-bg-warning">' . $esc(Text::_('COM_SQUIDFORM_FILE_NOT_SCANNED')) . '</span>' : '');
                }

                return $out ? implode('<br>', $out) : '—';
            }

            if ($type === 'address') {
                return $esc(Field\AddressField::format($field, $value, "\n")) === '' ? '—' : nl2br($esc(Field\AddressField::format($field, $value, "\n")));
            }
        }

        return nl2br(htmlspecialchars(self::value($field, $value, '—', $reveal), ENT_QUOTES, 'UTF-8'));
    }

    /** Rich text as plain text: line breaks for blocks, no tags. */
    /**
     * Left out of what the sender sees (their copy, review, confirmation):
     * hidden fields, "Hide what's typed" answers and named values (0.13.0).
     */
    public static function isPrivate(array $field): bool
    {
        return ($field['type'] ?? '') === 'hidden' || !empty($field['hide_input']) || (($field['type'] ?? '') === 'calc' && !empty($field['named']));
    }

    public static function plain(string $html): string
    {
        $t = preg_replace('#<(br|/p|/div|/li|/h[2-6]|/tr|/blockquote)\b[^>]*>#i', "\n", Html::clean($html)) ?? '';
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $t));
    }

    /**
     * @param  array<string, mixed>  $field
     *
     * @return array<string, string>
     */
    public static function map(array $field): array
    {
        $id = md5(json_encode($field['options'] ?? null) ?: '');

        if (!isset(self::$maps[$id])) {
            try {
                self::$maps[$id] = Options::labelMap($field);
            } catch (\Throwable) {
                self::$maps[$id] = []; // option source unavailable: show stored values
            }
        }

        return self::$maps[$id];
    }
}
