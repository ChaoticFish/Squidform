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
 * Session data (0.15.0): values another script on the site put in the
 * Joomla session (for example a sign-on script's "abcdefuser" with
 * employeenumber, primaryorg, fineaccesscontrol…), read only.
 *
 * Only the top-level keys listed in Options → Data Sources → Session data forms may read can be read;
 * the list is empty when SquidForm is installed. A dotted path reaches
 * inside: abcdefuser.employeenumber, abcdefuser.fineaccesscontrol.0 (first entry).
 *
 *   {session:abcdefuser.employeenumber}       placeholder (prefill, emails…)
 *   {"session": "abcdefuser.fineaccesscontrol", "op": "contains", "value": "X"}
 *
 * The values are trusted: the site put them there, not the person, so they
 * may choose email recipients. Rules are judged on the server; the page
 * only gets true or false. A field prefilled from session data is locked
 * unless the form says "prefill_lock": false.
 */
final class SessionData
{
    /** @return list<string> allowed top-level keys */
    public static function allowed(): array
    {
        $raw = (string) ComponentHelper::getParams('com_squidform')->get('session_keys', '');
        $out = [];

        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $k) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/', $k) && !str_starts_with($k, '__') && !\in_array(strtolower($k), ['user', 'registry', 'session', 'token'], true)) {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    /** The value at a dotted path, or null when it isn't there or isn't allowed. */
    public static function get(string $path): mixed
    {
        $parts = explode('.', $path);
        $top   = array_shift($parts);

        if (!\in_array($top, self::allowed(), true)) {
            return null;
        }

        try {
            $v = Factory::getApplication()->getSession()->get($top);
        } catch (\Throwable) {
            return null;
        }

        foreach ($parts as $p) {
            if (\is_array($v) && \array_key_exists($p, $v)) {
                $v = $v[$p];
            } elseif (\is_object($v) && isset($v->$p)) {
                $v = $v->$p;
            } else {
                return null;
            }
        }

        return self::plain($v);
    }

    /** As text: a list becomes one entry per line. */
    public static function text(string $path): ?string
    {
        $v = self::get($path);

        if ($v === null) {
            return null;
        }

        return \is_array($v) ? implode("\n", array_map(static fn ($x) => \is_array($x) ? json_encode($x) : (string) $x, $v)) : (string) $v;
    }

    /**
     * The paths in the current person's session, for the builder (so an
     * admin who has the data can pick instead of type). Lists show their
     * first entry's path and how many there are.
     *
     * @return list<array{path: string, sample: string, list: int}>
     */
    public static function paths(): array
    {
        $out = [];

        foreach (self::allowed() as $top) {
            self::walk($top, self::get($top), $out, 0);
        }

        return \array_slice($out, 0, 300);
    }

    private static function walk(string $path, mixed $v, array &$out, int $depth): void
    {
        if ($v === null || $depth > 4) {
            return;
        }

        if (\is_array($v) && array_is_list($v)) {
            $out[] = ['path' => $path, 'sample' => '', 'list' => \count($v)];

            if ($v !== [] && !\is_array($v[0])) {
                $out[] = ['path' => $path . '.0', 'sample' => mb_substr((string) $v[0], 0, 40), 'list' => 0];
            }

            return;
        }

        if (\is_array($v)) {
            foreach ($v as $k => $x) {
                if (\is_string($k) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $k)) {
                    self::walk($path . '.' . $k, $x, $out, $depth + 1);
                }
            }

            return;
        }

        $out[] = ['path' => $path, 'sample' => mb_substr((string) $v, 0, 40), 'list' => 0];
    }

    /** Arrays and scalars only (objects become arrays; nothing else gets through). */
    private static function plain(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return null;
        }

        if (\is_object($v)) {
            $v = get_object_vars($v);
        }

        if (\is_array($v)) {
            $out = [];

            foreach ($v as $k => $x) {
                $x = self::plain($x, $depth + 1);

                if ($x !== null) {
                    $out[$k] = $x;
                }
            }

            return $out;
        }

        return \is_scalar($v) ? $v : null;
    }
}
