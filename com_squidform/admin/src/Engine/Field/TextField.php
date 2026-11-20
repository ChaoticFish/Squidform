<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Single-value inputs: text, textarea, email, number, tel, url, date, hidden,
 * and (0.8.0) time and datetime.
 *
 * Extra rules: number → min, max, step; date → min, max (YYYY-MM-DD);
 * time → min, max (HH:MM); datetime → min, max (YYYY-MM-DDTHH:MM).
 * Options (0.8.0): hide_input (text: dots instead of what's typed), counter
 * (text, textarea: "12 of 120 characters"), mask (text, tel: "(999) 999-9999",
 * also checked on the server).
 */
final class TextField extends AbstractField
{
    private const HTML_TYPES = [
        'text' => 'text', 'email' => 'email', 'number' => 'number',
        'tel'  => 'tel', 'url' => 'url', 'date' => 'date', 'hidden' => 'hidden',
        'time' => 'time', 'datetime' => 'datetime-local',
    ];

    /** The regular expression a mask stands for (9 digit, a letter, * either; the rest as written). */
    public static function maskRegex(string $mask): string
    {
        $re = '';

        foreach (mb_str_split($mask) as $c) {
            $re .= match ($c) {
                '9'     => '[0-9]',
                'a'     => '[A-Za-z]',
                '*'     => '[A-Za-z0-9]',
                default => preg_quote($c, '/'),
            };
        }

        return '/^' . $re . '$/u';
    }

    /** "12 of 120 characters" under the box, updated by the browser. */
    private static function counter(array $field, string $id, string $val): string
    {
        if (empty($field['counter'])) {
            return '';
        }

        $max = (int) ($field['rules']['max_length'] ?? 0);
        $len = mb_strlen($val);

        return '<div class="form-text sf-counter" id="' . $id . '-count" data-sf-counter-for="' . $id . '"' . ($max ? ' data-sf-max="' . $max . '"' : '') . '>'
            . self::e($max ? Text::sprintf('COM_SQUIDFORM_COUNTER_OF', $len, $max) : Text::plural('COM_SQUIDFORM_COUNTER_N', $len)) . '</div>'
            . '<div class="visually-hidden sf-counter-live" aria-live="polite"></div>';
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $type  = $field['type'];
        $key   = $field['key'];
        $id    = $rc->id($key);
        $name  = $rc->name($key);
        $val   = \is_array($value) ? '' : (string) $value;
        $attrs = self::controlAttrs($field, $rc);
        $cls   = 'form-control' . ($rc->error($key) !== null ? ' is-invalid' : '');

        if ($type === 'hidden') {
            return '<input type="hidden" name="' . self::e($name) . '" id="' . $id . '" value="' . self::e($val) . '" data-sf-key="' . self::e($key) . '">';
        }

        if ($type === 'textarea') {
            $rows    = max(2, min(30, (int) ($field['rows'] ?? 4)));
            $max     = isset($field['rules']['max_length']) ? ' maxlength="' . (int) $field['rules']['max_length'] . '"' : '';
            $control = '<textarea class="' . $cls . '" id="' . $id . '" name="' . self::e($name) . '" rows="' . $rows . '"' . $max . $attrs . '>'
                . self::e($val) . '</textarea>' . self::counter($field, $id, $val);

            return self::wrap($field, $rc, $control);
        }

        $extra = '';
        $rules = $field['rules'] ?? [];

        if (\in_array($type, ['number', 'date', 'time', 'datetime'], true)) {
            foreach (['min', 'max', 'step'] as $r) {
                if (isset($rules[$r])) {
                    // "today+2" (0.13.0) → the actual date.
                    $v      = \in_array($type, ['date', 'datetime'], true) && $r !== 'step' ? \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules[$r], $type, $r) : $rules[$r];
                    $extra .= ' ' . $r . '="' . self::e($v) . '"';
                }
            }
        }

        if (\in_array($type, ['date', 'datetime'], true)) {
            $extra .= \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::attrs($field, $rc);
        }

        if (isset($rules['max_length']) && !\in_array($type, ['number', 'date', 'time', 'datetime'], true)) {
            $extra .= ' maxlength="' . (int) $rules['max_length'] . '"';
        }

        if ($type === 'email' && empty($field['autocomplete'])) {
            $extra .= ' autocomplete="email"';
        }

        if ($type === 'number') {
            $extra .= ' inputmode="decimal"';
        }

        $htmlType = self::HTML_TYPES[$type];

        // Dots instead of what's typed. Not a password store: see SCHEMA.md.
        if ($type === 'text' && !empty($field['hide_input'])) {
            $htmlType = 'password';
            $extra   .= empty($field['autocomplete']) ? ' autocomplete="off"' : '';
            $extra   .= ' spellcheck="false"';
        }

        if (!empty($field['mask']) && \in_array($type, ['text', 'tel'], true)) {
            $mask   = (string) $field['mask'];
            $extra .= ' data-sf-mask="' . self::e($mask) . '"';
            $extra .= !preg_match('/[a*]/', $mask) ? ' inputmode="numeric"' : '';
            $extra .= !isset($rules['max_length']) ? ' maxlength="' . mb_strlen($mask) . '"' : '';
            $extra .= empty($field['placeholder']) ? ' placeholder="' . self::e(strtr($mask, ['9' => '_', 'a' => '_', '*' => '_'])) . '"' : '';
        }

        $control = '<input type="' . $htmlType . '" class="' . $cls . '" id="' . $id . '" name="' . self::e($name) . '"'
            . ' value="' . self::e($val) . '"' . $extra . $attrs . '>' . self::counter($field, $id, $val);

        // A Show button for hidden typing (0.12.1). The script adds it, so it never appears where it can't work.
        if ($htmlType === 'password' && !str_contains($attrs, 'readonly')) {
            $control = '<div class="sf-reveal" data-sf-reveal="' . $id . '">' . $control . '</div>';
        }

        // "Ask for it twice" (0.9.2): a second box that must match. Not on the
        // staff edit screen, and only for top-level fields.
        if ($type === 'email' && !empty($field['confirm']) && $rc->namePrefix === 'sf' && $rc->idPrefix !== \SquidForm\Component\SquidForm\Administrator\Engine\Editor::ID_PREFIX) {
            $again    = $rc->error($key) === null ? $val : '';
            // 0.13.1: signed in and it's their account's address? The second
            // box waits, hidden, until they change it (the server agrees).
            $mine     = self::accountEmail();
            $same     = $mine !== '' && mb_strtolower(trim((string) $val)) === mb_strtolower($mine) && $rc->error($key) === null;
            $control .= '<div class="sf-again"' . ($mine !== '' ? ' data-sf-account="' . self::e($mine) . '"' : '') . ($same ? ' hidden' : '') . '>';
            $control .= '<label class="form-label mt-2" for="' . $id . '-again">' . self::e(Text::sprintf('COM_SQUIDFORM_EMAIL_AGAIN', (string) ($field['label'] ?? $key))) . '</label>'
                . '<input type="email" class="' . $cls . '" id="' . $id . '-again" name="sf_confirm[' . self::e($key) . ']" value="' . self::e($again) . '" autocomplete="email"'
                . (self::required($field) && !$same ? ' required aria-required="true"' : '') . ($same ? ' disabled' : '') . ($rc->error($key) !== null ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '') . '></div>';
        }

        return self::wrap($field, $rc, $control);
    }

    /** The signed-in person's own address, '' for a guest. */
    public static function accountEmail(): string
    {
        try {
            $u = \Joomla\CMS\Factory::getApplication()->getIdentity();
        } catch (\Throwable) {
            return '';
        }

        return $u && (int) $u->id > 0 ? trim((string) $u->email) : '';
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        if (\is_array($raw)) {
            return '';
        }

        $s = (string) $raw;
        // Strip control characters except tab/newline; normalise line endings.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $s)) ?? '';

        $type = $field['type'];

        if ($type !== 'textarea') {
            $s = trim(str_replace("\n", ' ', $s));
        }

        // Some browsers send seconds; SquidForm keeps minutes.
        if ($type === 'time' && preg_match('/^(\d{2}:\d{2}):\d{2}(\.\d+)?$/', $s, $m)) {
            $s = $m[1];
        }

        if ($type === 'datetime' && preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2}(\.\d+)?)?$/', $s, $m)) {
            $s = $m[1] . 'T' . $m[2];
        }

        $limit = $type === 'textarea' ? 65535 : 4000;

        return mb_substr($s, 0, $limit);
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $errors = self::textRules($field, $value, $vc);

        if ($errors || self::isEmpty($value)) {
            return $errors;
        }

        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);
        $rules = $field['rules'] ?? [];
        $v     = (string) $value;

        if (!empty($field['mask']) && \in_array($field['type'], ['text', 'tel'], true) && !preg_match(self::maskRegex((string) $field['mask']), $v)) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MASK', $label, strtr((string) $field['mask'], ['9' => '0', 'a' => 'A', '*' => 'A']))];
        }

        switch ($field['type']) {
            case 'email':
                if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_EMAIL', $label)];
                }
                break;

            case 'url':
                if (filter_var($v, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $v)) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_URL', $label)];
                }
                break;

            case 'number':
                if (!is_numeric($v)) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_NUMBER', $label)];
                }

                if (isset($rules['min']) && (float) $v < (float) $rules['min']) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN', $label, $rules['min'])];
                }

                if (isset($rules['max']) && (float) $v > (float) $rules['max']) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX', $label, $rules['max'])];
                }
                break;

            case 'time':
            case 'datetime':
                $fmt = $field['type'] === 'time' ? 'H:i' : 'Y-m-d\\TH:i';
                $d   = \DateTimeImmutable::createFromFormat('!' . $fmt, $v);

                if (!$d || $d->format($fmt) !== $v) {
                    return [$path => Text::sprintf($field['type'] === 'time' ? 'COM_SQUIDFORM_ERR_TIME' : 'COM_SQUIDFORM_ERR_DATETIME', $label)];
                }

                $min = $field['type'] === 'datetime' ? \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules['min'] ?? null, 'datetime', 'min') : ($rules['min'] ?? null);
                $max = $field['type'] === 'datetime' ? \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules['max'] ?? null, 'datetime', 'max') : ($rules['max'] ?? null);

                if ($min !== null && $v < (string) $min) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN', $label, str_replace('T', ' ', (string) $min))];
                }

                if ($max !== null && $v > (string) $max) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX', $label, str_replace('T', ' ', (string) $max))];
                }

                if ($field['type'] === 'datetime' && ($msg = self::dateLimits($field, $v, $label, $vc)) !== null) {
                    return [$path => $msg];
                }
                break;

            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

                if (!$d || $d->format('Y-m-d') !== $v) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_DATE', $label)];
                }

                $min = \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules['min'] ?? null, 'date', 'min');
                $max = \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::resolve($rules['max'] ?? null, 'date', 'max');

                if ($min !== null && $v < $min) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MIN', $label, $min)];
                }

                if ($max !== null && $v > $max) {
                    return [$path => Text::sprintf('COM_SQUIDFORM_ERR_MAX', $label, $max)];
                }

                if (($msg = self::dateLimits($field, $v, $label, $vc)) !== null) {
                    return [$path => $msg];
                }
                break;
        }

        return [];
    }

    /** Weekdays, hours and "after another date" (0.13.0). */
    private static function dateLimits(array $field, string $v, string $label, ValidateContext $vc): ?string
    {
        $other = (string) ($field['rules']['after']['field'] ?? '');

        return \SquidForm\Component\SquidForm\Administrator\Engine\DateLimits::check($field, $v, $label, $vc->data, $other !== '' ? (string) ($vc->schema->field($other)['label'] ?? $other) : '');
    }
}
