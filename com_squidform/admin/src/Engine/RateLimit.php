<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Per-form rate limiting (0.6.0). On by default.
 *
 * Who is counted:
 *  - a signed-in person by their account, wherever they are;
 *  - a guest by their session, and also by their IP address unless
 *    "Limit guests by IP address" is off (switch it off behind a proxy that
 *    isn't listed in Trusted proxies, or every guest would share one limit).
 *
 * What is counted, per form, in a sliding window:
 *  - saved submissions: at most `max` per `minutes`;
 *  - page posts (any submit, valid or not): at most 10 × max, so a bot
 *    can't hammer the form with invalid posts either.
 *
 * Settings: the form's "settings.rate_limit" ({ "max": 5, "minutes": 10 },
 * or false to turn it off for that form), else SquidForm → Options →
 * Security (default 5 per 10 minutes; 0 turns the default off).
 *
 * Keys are stored only as an HMAC with the site secret: this table holds no
 * IP addresses, session ids or account ids in readable form.
 */
final class RateLimit
{
    public const POSTS_FACTOR = 10;

    /**
     * Effective limit for a form, or null when it is off.
     *
     * @return array{max: int, minutes: int}|null
     */
    public static function limits(Schema $schema): ?array
    {
        $set    = $schema->setting('rate_limit');
        $params = ComponentHelper::getParams('com_squidform');

        if ($set === false) {
            return null;
        }

        $max = \is_array($set) && isset($set['max']) ? (int) $set['max'] : (int) $params->get('rate_limit_max', 5);
        $min = \is_array($set) && isset($set['minutes']) ? (int) $set['minutes'] : (int) $params->get('rate_limit_minutes', 10);

        return $max > 0 && $min > 0 ? ['max' => $max, 'minutes' => min(1440, $min)] : null;
    }

    /** @return list<array{0: string, 1: string}> [kind, hashed key] */
    public static function keys(User $user): array
    {
        $secret = (string) Factory::getApplication()->get('secret');
        $h      = static fn (string $k) => hash_hmac('sha256', 'squidform-rate|' . $k, $secret);

        if ((int) $user->id > 0) {
            return [['user', $h('u:' . (int) $user->id)]];
        }

        $keys = [['session', $h('s:' . Factory::getApplication()->getSession()->getId())]];

        if ((int) ComponentHelper::getParams('com_squidform')->get('rate_limit_ip', 1) && ($ip = ClientIp::get()) !== '') {
            $keys[] = ['ip', $h('i:' . $ip)];
        }

        return $keys;
    }

    /**
     * Minutes until this person may save again, or 0 when they may now.
     */
    public static function savesBlocked(int $formId, Schema $schema, User $user): int
    {
        $lim = self::limits($schema);

        return $lim ? self::blocked($formId, 'save', $lim['max'], $lim['minutes'], $user) : 0;
    }

    /** Minutes until this person may post again (too many page posts), or 0. */
    public static function postsBlocked(int $formId, Schema $schema, User $user): int
    {
        $lim = self::limits($schema);

        return $lim ? self::blocked($formId, 'post', max(20, $lim['max'] * self::POSTS_FACTOR), $lim['minutes'], $user) : 0;
    }

    public static function record(int $formId, string $what, User $user): void
    {
        $db  = SubmissionStore::db();
        $now = Factory::getDate()->toSql();

        foreach (self::keys($user) as [$kind, $hash]) {
            $row = (object) ['form_id' => $formId, 'kind' => $kind, 'key_hash' => $hash, 'what' => $what, 'created' => $now];
            $db->insertObject('#__squidform_rate', $row);
        }

        // Now and then, forget rows older than the longest window (a day).
        if (random_int(1, 50) === 1) {
            self::prune();
        }
    }

    public static function prune(): void
    {
        $db  = SubmissionStore::db();
        $cut = Factory::getDate('-25 hours')->toSql();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_rate'))->where('created < :cut')->bind(':cut', $cut))->execute();
    }

    private static function blocked(int $formId, string $what, int $max, int $minutes, User $user): int
    {
        $db    = SubmissionStore::db();
        $since = Factory::getDate('-' . $minutes . ' minutes')->toSql();
        $wait  = 0;

        foreach (self::keys($user) as [$kind, $hash]) {
            $q = $db->createQuery()->select(['COUNT(*) AS n', 'MIN(created) AS first'])
                ->from($db->quoteName('#__squidform_rate'))
                ->where('form_id = :f')->where('what = :w')->where('key_hash = :k')->where('created >= :since')
                ->bind(':f', $formId, ParameterType::INTEGER)->bind(':w', $what)->bind(':k', $hash)->bind(':since', $since);
            $r = $db->setQuery($q)->loadObject();

            if ($r && (int) $r->n >= $max) {
                // The oldest counted row leaves the window first.
                $left = (int) ceil((strtotime($r->first . ' UTC') + $minutes * 60 - time()) / 60);
                $wait = max($wait, max(1, $left));
            }
        }

        return $wait;
    }
}
