<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * How people refer to a submission (0.6.0).
 *
 * - The site uses the uid (a random UUID) in every link and form post, never
 *   the numeric id, so ids can't be guessed, counted or probed.
 * - The label people read is the first 8 characters ("Request ca9a4d1b");
 *   search finds it.
 * - The admin still accepts numeric ids as well.
 */
final class Ref
{
    // Any stored 36-character uid (new ones are UUID v4); lookups are exact matches.
    public const UUID = '/^[0-9a-z][0-9a-z-]{31,35}$/';

    /** The short label: first 8 characters of the uid. */
    public static function short(?string $uid): string
    {
        return substr((string) $uid, 0, 8);
    }

    public static function isUuid(mixed $v): bool
    {
        return \is_string($v) && preg_match(self::UUID, strtolower($v)) === 1;
    }

    /** Submission id for a uid, or 0. */
    public static function idOf(mixed $uid): int
    {
        return self::isUuid($uid) ? (int) (SubmissionStore::findByUid(strtolower((string) $uid)) ?? 0) : 0;
    }

    /** The uid of a submission id ('' when there is none). */
    public static function uidOf(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $db = SubmissionStore::db();

        return (string) $db->setQuery(
            $db->createQuery()->select('uid')->from($db->quoteName('#__squidform_submissions'))->where('id = ' . $id)
        )->loadResult();
    }

    /**
     * Ids for a list of request values. $uidOnly (site): only uids count.
     * Otherwise numeric ids are accepted too.
     *
     * @return int[]
     */
    public static function ids(array $raw, bool $uidOnly): array
    {
        $out = [];

        foreach ($raw as $v) {
            if (\is_string($v) && self::isUuid($v)) {
                $id = self::idOf($v);
            } elseif (!$uidOnly && (\is_int($v) || (\is_string($v) && ctype_digit($v)))) {
                $id = (int) $v;
            } else {
                $id = 0;
            }

            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * An all-digit search term that could also be a reference (about 1 in 43
     * references is digits only, e.g. "38220767"). The caller searches the
     * answers as usual OR this uid prefix. Null when it can't be one.
     */
    public static function digitPrefix(string $s): ?string
    {
        $s = trim($s);

        return preg_match('/^[0-9]{8}$/', $s) ? $s : null;
    }

    /** "uid:ca9a4d1b" or a bare 8–36 character uid prefix, as typed in a search box; null when it is not one. */
    public static function searchPrefix(string $s): ?string
    {
        $s = strtolower(trim($s));

        if (str_starts_with($s, 'uid:')) {
            $s = trim(substr($s, 4));
        } elseif (!preg_match('/^[0-9a-f]{8}(-[0-9a-f-]{0,27})?$/', $s) || ctype_digit($s)) {
            return null;
        }

        return preg_match('/^[0-9a-z-]{4,36}$/', $s) ? $s : null;
    }
}
