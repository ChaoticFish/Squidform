<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Strict "is this URL on this site?" check.
 *
 * Joomla's Uri::isInternal() also accepts strings that are only a prefix of
 * the site address (e.g. "http://127.0.0" for "http://127.0.0.1"), so
 * SquidForm compares scheme, host and port exactly instead.
 */
final class Url
{
    public static function isInternal(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            return false; // control characters or backslashes: refuse
        }

        // Scheme-relative ("//host/...") counts as absolute.
        if (str_starts_with($url, '//')) {
            $url = Uri::getInstance()->getScheme() . ':' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (!isset($parts['scheme']) && !isset($parts['host'])) {
            return true; // relative to this site
        }

        if (!\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $root = parse_url(Uri::root());

        $port = static fn (array $p) => (int) ($p['port'] ?? (strtolower($p['scheme'] ?? 'http') === 'https' ? 443 : 80));

        return strtolower($parts['host'] ?? '') === strtolower($root['host'] ?? '')
            && strtolower($parts['scheme']) === strtolower($root['scheme'] ?? 'http')
            && $port($parts) === $port($root);
    }
}
