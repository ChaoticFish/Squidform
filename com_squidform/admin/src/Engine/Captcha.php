<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Per-form human check on the last page (0.6.0). All three work offline.
 *
 *   "captcha": "pow"
 *   "captcha": "joomla"            the site's default captcha plugin (Global Configuration)
 *   "captcha": { "type": "joomla", "plugin": "powcaptcha" }
 *   "captcha": { "type": "question", "question": "What colour is grass?", "answers": ["green"] }
 *   "captcha": { "type": "pow", "guests_only": false }
 *
 * - pow: SquidForm's own proof-of-work check. The browser finds a number
 *   whose SHA-256 matches a signed challenge (about a second of work), with
 *   nothing to click or read. It is bound to the form's one-time render
 *   token, so a solution can't be reused. Needs JavaScript.
 * - joomla: a Joomla captcha plugin. Joomla 6.2 ships "Captcha - Proof of
 *   Work" (powcaptcha), which runs on the site itself. If the plugin isn't
 *   set up, SquidForm falls back to pow and logs a warning, so the form is
 *   never left unprotected.
 * - question: a question with one or more accepted answers (case, spaces and
 *   a trailing full stop are ignored). Works without JavaScript.
 *
 * guests_only (default true): signed-in people skip the check.
 */
final class Captcha
{
    public const FIELD  = 'sf_captcha';
    public const TYPES  = ['pow', 'joomla', 'question'];

    /**
     * Normalised setting for this form and person, or null when there is no check.
     *
     * @return array{type: string, plugin: string, question: string, answers: list<string>}|null
     */
    public static function config(Schema $schema, User $user): ?array
    {
        $c = $schema->setting('captcha');

        if ($c === null || $c === false || $c === '' || $c === 'none') {
            return null;
        }

        $c = \is_string($c) ? ['type' => $c] : (array) $c;

        if (($c['guests_only'] ?? true) && (int) $user->id > 0) {
            return null;
        }

        $cfg = [
            'type'     => (string) ($c['type'] ?? 'pow'),
            'plugin'   => (string) ($c['plugin'] ?? ''),
            'question' => (string) ($c['question'] ?? ''),
            'answers'  => array_values(array_map('strval', (array) ($c['answers'] ?? []))),
        ];

        if ($cfg['type'] === 'joomla') {
            $plugin = $cfg['plugin'] !== '' ? $cfg['plugin'] : (string) Factory::getApplication()->get('captcha', '');

            if ($plugin === '' || $plugin === '0' || !self::joomla($plugin)) {
                static $warned = false;

                if (!$warned) {
                    $warned = true;
                    Log::add('SquidForm: the form asks for a Joomla captcha, but none is set up' . ($plugin !== '' && $plugin !== '0' ? ' ("' . $plugin . '")' : '') . '; using SquidForm\'s own check instead.', Log::WARNING, 'squidform');
                }

                $cfg['type'] = 'pow';
            } else {
                $cfg['plugin'] = $plugin;
            }
        }

        return $cfg;
    }

    /** Check a definition's "captcha" setting. @return string[] */
    public static function validate(mixed $c): array
    {
        if ($c === null || $c === false || $c === 'none') {
            return [];
        }

        if (\is_string($c)) {
            return \in_array($c, self::TYPES, true) ? [] : ['settings.captcha must be "pow", "joomla", "question" (an object) or false.'];
        }

        if (!\is_array($c) || array_is_list($c)) {
            return ['settings.captcha must be "pow", "joomla", an object, or false.'];
        }

        $errors = [];
        $type   = $c['type'] ?? null;

        if (!\in_array($type, self::TYPES, true)) {
            $errors[] = 'settings.captcha.type must be "pow", "joomla" or "question".';
        }

        if (\array_key_exists('guests_only', $c) && !\is_bool($c['guests_only'])) {
            $errors[] = 'settings.captcha.guests_only must be true or false.';
        }

        if (isset($c['plugin']) && (!\is_string($c['plugin']) || !preg_match('/^[a-z0-9_]+$/', $c['plugin']))) {
            $errors[] = 'settings.captcha.plugin must be a captcha plugin name such as "powcaptcha".';
        }

        if ($type === 'question') {
            if (!\is_string($c['question'] ?? null) || trim($c['question']) === '') {
                $errors[] = 'settings.captcha.question is required for a question challenge.';
            }

            $a = $c['answers'] ?? null;

            if (!\is_array($a) || $a === [] || !array_is_list($a) || array_filter($a, static fn ($x) => !\is_string($x) || trim($x) === '')) {
                $errors[] = 'settings.captcha.answers must list at least one accepted answer.';
            }
        }

        return $errors;
    }

    /**
     * HTML for the check, placed above the buttons on the last page.
     *
     * @param  string  $nonce  the render token's one-time nonce (pow binds to it)
     */
    public static function render(string $alias, array $cfg, string $nonce, ?string $error): string
    {
        $id  = 'sf-' . preg_replace('/[^a-z0-9_-]/i', '', $alias) . '-' . self::FIELD;
        $e   = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $err = $error !== null ? '<div id="' . $id . '-error" class="invalid-feedback d-block" role="alert">' . $e($error) . '</div>' : '';

        switch ($cfg['type']) {
            case 'question':
                return '<div class="sf-field mb-3 sf-captcha sf-captcha-question" id="' . $id . '-group" tabindex="-1">'
                    . '<label class="form-label" for="' . $id . '">' . $e($cfg['question'])
                    . ' <span class="sf-req" aria-hidden="true">*</span><span class="visually-hidden"> ' . $e(Text::_('COM_SQUIDFORM_REQUIRED')) . '</span></label>'
                    . '<input type="text" class="form-control' . ($error !== null ? ' is-invalid' : '') . '" id="' . $id . '" name="' . self::FIELD . '"'
                    . ' required aria-required="true" autocomplete="off" maxlength="200" aria-describedby="' . $id . '-help' . ($error !== null ? ' ' . $id . '-error' : '') . '"'
                    . ($error !== null ? ' aria-invalid="true"' : '') . '>'
                    . '<div id="' . $id . '-help" class="form-text">' . $e(Text::_('COM_SQUIDFORM_CAPTCHA_QUESTION_HELP')) . '</div>' . $err . '</div>';

            case 'joomla':
                try {
                    $html = (string) \Joomla\CMS\Captcha\Captcha::getInstance($cfg['plugin'], ['namespace' => 'squidform_' . $alias])->display(self::FIELD, $id, '');

                    return '<div class="sf-field mb-3 sf-captcha sf-captcha-joomla" id="' . $id . '-group" tabindex="-1">' . $html . $err . '</div>';
                } catch (\Throwable $ex) {
                    Log::add('SquidForm captcha "' . $cfg['plugin'] . '" could not be shown: ' . $ex->getMessage() . '; using SquidForm\'s own check.', Log::WARNING, 'squidform');
                    $cfg['type'] = 'pow';
                }

                // fall through to pow
            default:
                $max = self::powMax();
                $n   = random_int(0, $max);
                $exp = time() + 3600 * max(1, (int) ComponentHelper::getParams('com_squidform')->get('max_age_hours', 8));
                $salt = $nonce . '.' . $exp . '.' . bin2hex(random_bytes(6));
                $chal = hash('sha256', $salt . $n);
                $data = ['c' => $chal, 's' => $salt, 'm' => $max, 'g' => self::sign($chal, $salt)];

                $doc = Factory::getApplication()->getDocument();

                if ($doc && method_exists($doc, 'getWebAssetManager')) {
                    $doc->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_squidform');
                    $doc->getWebAssetManager()->useScript('com_squidform.pow');
                }

                return '<div class="sf-captcha sf-captcha-pow" id="' . $id . '-group" tabindex="-1" data-sf-pow="' . $e(json_encode($data)) . '"'
                    . ' data-sf-pow-wait="' . $e(Text::_('COM_SQUIDFORM_CAPTCHA_POW_WAIT')) . '" data-sf-pow-done="' . $e(Text::_('COM_SQUIDFORM_CAPTCHA_POW_DONE')) . '">'
                    . '<input type="hidden" name="' . self::FIELD . '" value="">'
                    . '<p class="sf-pow-status small text-body-secondary mb-2" role="status" aria-live="polite"></p>'
                    . '<noscript><p class="alert alert-warning">' . $e(Text::_('COM_SQUIDFORM_CAPTCHA_POW_NOSCRIPT')) . '</p></noscript>'
                    . $err . '</div>';
        }
    }

    /**
     * Check the posted answer. Returns an error message, or null when it passes.
     *
     * @param  array<string, mixed>  $post
     */
    public static function check(string $alias, array $cfg, array $post, string $nonce): ?string
    {
        $answer = $post[self::FIELD] ?? '';
        $answer = \is_scalar($answer) ? (string) $answer : '';

        switch ($cfg['type']) {
            case 'question':
                $norm = static fn (string $s) => rtrim(preg_replace('/\s+/u', ' ', mb_strtolower(trim($s))), '.');
                $ok   = $answer !== '' && \in_array($norm($answer), array_map($norm, $cfg['answers']), true);

                return $ok ? null : Text::_($answer === '' ? 'COM_SQUIDFORM_CAPTCHA_QUESTION_EMPTY' : 'COM_SQUIDFORM_CAPTCHA_QUESTION_WRONG');

            case 'joomla':
                try {
                    $ok = (bool) \Joomla\CMS\Captcha\Captcha::getInstance($cfg['plugin'], ['namespace' => 'squidform_' . $alias])->checkAnswer($answer);
                } catch (\Throwable $ex) {
                    Log::add('SquidForm captcha "' . $cfg['plugin'] . '" check failed: ' . $ex->getMessage(), Log::WARNING, 'squidform');
                    $ok = false;
                }

                return $ok ? null : Text::_('COM_SQUIDFORM_CAPTCHA_FAILED');

            default:
                return self::checkPow($answer, $nonce) ? null : Text::_('COM_SQUIDFORM_CAPTCHA_POW_FAILED');
        }
    }

    private static function checkPow(string $answer, string $nonce): bool
    {
        $d = json_decode((string) base64_decode($answer, true), true);

        if (!\is_array($d) || !\is_string($d['c'] ?? null) || !\is_string($d['s'] ?? null) || !\is_string($d['g'] ?? null) || !isset($d['n'])) {
            return false;
        }

        if (!hash_equals(self::sign($d['c'], $d['s']), $d['g'])) {
            return false;
        }

        [$n0, $exp] = array_pad(explode('.', $d['s']), 2, '');

        // Bound to this render's one-time token, and not expired.
        if ($nonce === '' || !hash_equals($nonce, $n0) || (int) $exp < time()) {
            return false;
        }

        $n = filter_var($d['n'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::powMax()]]);

        return $n !== false && hash_equals($d['c'], hash('sha256', $d['s'] . $n));
    }

    private static function sign(string $challenge, string $salt): string
    {
        return hash_hmac('sha256', 'squidform-pow|' . $challenge . '|' . $salt, (string) Factory::getApplication()->get('secret'));
    }

    /** Largest number the browser may have to try (SquidForm → Options; about a second at the default). */
    public static function powMax(): int
    {
        return max(1000, min(1000000, (int) ComponentHelper::getParams('com_squidform')->get('pow_max', 50000)));
    }

    /** Is this Joomla captcha plugin enabled? */
    private static function joomla(string $plugin): bool
    {
        return \Joomla\CMS\Plugin\PluginHelper::isEnabled('captcha', $plugin);
    }
}
