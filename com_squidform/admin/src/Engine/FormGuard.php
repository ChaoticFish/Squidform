<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Invisible human checks that run on every form (all offline):
 *
 *  1. Honeypot  – a field people never see; bots that fill it are refused.
 *  2. Signed render token – HMAC (site secret) over form alias, render time,
 *     a one-time nonce and a tag of the visitor's session. Refused when:
 *     missing or altered, for another form, from a different session,
 *     submitted faster than the minimum fill time, older than the maximum age,
 *     or already used in this session (replay).
 *
 * Joomla's CSRF token is checked separately by the controller. Rate
 * limiting (RateLimit) and the per-form captcha (Captcha) are separate too;
 * the captcha's proof of work is bound to this token's nonce.
 */
final class FormGuard
{
    public const HONEYPOT = 'sf_hp_website';
    public const TOKEN    = 'sf_t';

    private const SESSION_NONCES = 'squidform.nonces';

    /** Nonce of the token made by the last fields() call (the captcha binds to it). */
    public static string $lastNonce = '';

    /**
     * Hidden inputs to place inside the <form>.
     *
     * $started: when this person first got the form (from an earlier token
     * that passed). After an error or on a later page, the minimum fill time
     * counts from then, so a quick fix isn't mistaken for a bot (0.6.1).
     */
    public static function fields(string $alias, int $started = 0): string
    {
        $nonce   = bin2hex(random_bytes(12));
        self::$lastNonce = $nonce;
        $now     = time();
        $claims  = ['a' => $alias, 't' => $now, 'n' => $nonce, 's' => self::sessionTag()];

        if ($started > 0 && $started < $now) {
            $claims['f'] = $started;
        }

        $payload = base64_encode(json_encode($claims));
        $token   = $payload . '.' . self::sign($payload);

        $html = '<input type="hidden" name="' . self::TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';

        if (self::params()->get('honeypot', 1)) {
            // Off-screen, not focusable, hidden from assistive technology.
            $html .= '<div class="sf-hp" aria-hidden="true">'
                . '<label for="' . self::HONEYPOT . '-' . $alias . '">Website</label>'
                . '<input type="text" id="' . self::HONEYPOT . '-' . $alias . '" name="' . self::HONEYPOT . '" value="" tabindex="-1" autocomplete="off">'
                . '</div>';
        }

        return $html;
    }

    /**
     * @param   array<string, mixed>  $post  Raw POST array.
     *
     * @return  string|null  A reason code when refused, null when the checks pass.
     */
    public static function check(string $alias, array $post): ?string
    {
        $params = self::params();

        if ($params->get('honeypot', 1) && trim((string) ($post[self::HONEYPOT] ?? '')) !== '') {
            return 'honeypot';
        }

        $token = (string) ($post[self::TOKEN] ?? '');

        if (!str_contains($token, '.')) {
            return 'token_missing';
        }

        [$payload, $sig] = explode('.', $token, 2);

        if (!hash_equals(self::sign($payload), $sig)) {
            return 'token_invalid';
        }

        $data = json_decode((string) base64_decode($payload, true), true);

        if (!\is_array($data) || ($data['a'] ?? null) !== $alias) {
            return 'token_invalid';
        }

        // The token only works in the session it was issued to, so one
        // harvested token cannot be replayed from many fresh sessions.
        if (!hash_equals(self::sessionTag(), (string) ($data['s'] ?? ''))) {
            return 'token_session';
        }

        $age  = time() - (int) ($data['t'] ?? 0);
        $fill = time() - (int) ($data['f'] ?? $data['t'] ?? 0);

        if ($fill < (int) $params->get('min_fill_seconds', 3)) {
            return 'too_fast';
        }

        if ($age > 3600 * max(1, (int) $params->get('max_age_hours', 8))) {
            return 'expired';
        }

        // One-time nonce: remember the last 200 used in this session.
        $session = Factory::getApplication()->getSession();
        $used    = (array) $session->get(self::SESSION_NONCES, []);
        $nonce   = (string) ($data['n'] ?? '');

        if ($nonce === '' || \in_array($nonce, $used, true)) {
            return 'replay';
        }

        $used[] = $nonce;
        $session->set(self::SESSION_NONCES, \array_slice($used, -200));

        return null;
    }

    /** The nonce in a posted token (only meaningful after check() passed). */
    public static function nonceOf(array $post): string
    {
        $token = (string) ($post[self::TOKEN] ?? '');
        $data  = json_decode((string) base64_decode(explode('.', $token, 2)[0], true), true);

        return \is_array($data) ? (string) ($data['n'] ?? '') : '';
    }

    /** When the person first got the form, from a posted token (only meaningful after check() passed). */
    public static function startedOf(array $post): int
    {
        $token = (string) ($post[self::TOKEN] ?? '');
        $data  = json_decode((string) base64_decode(explode('.', $token, 2)[0], true), true);

        return \is_array($data) ? (int) ($data['f'] ?? $data['t'] ?? 0) : 0;
    }

    /** Short, non-reversible tag of the current session id. */
    private static function sessionTag(): string
    {
        $id = (string) Factory::getApplication()->getSession()->getId();

        return substr(hash_hmac('sha256', 'squidform-session|' . $id, (string) Factory::getApplication()->get('secret')), 0, 32);
    }

    private static function sign(string $payload): string
    {
        $secret = (string) Factory::getApplication()->get('secret');

        return hash_hmac('sha256', 'squidform|' . $payload, $secret);
    }

    private static function params(): \Joomla\Registry\Registry
    {
        return ComponentHelper::getParams('com_squidform');
    }
}
