<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;

\defined('_JEXEC') or die;

/**
 * The visitor's IP address (0.6.0).
 *
 * By default this is REMOTE_ADDR, the address that connected to the web
 * server. Behind a reverse proxy or load balancer that is the proxy, so
 * every visitor would look the same. SquidForm → Options → Security →
 * "Trusted proxies" lists the proxies' addresses (single IPs or CIDR
 * ranges, IPv4 or IPv6). Only when the connection comes from one of them is
 * the forwarded header read, and then the right-most address in it that is
 * not itself a trusted proxy is used (the left part can be set by anyone).
 */
final class ClientIp
{
    public static function get(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '';
        }

        $params  = ComponentHelper::getParams('com_squidform');
        $proxies = self::list((string) $params->get('trusted_proxies', ''));

        if (!$proxies || !self::inAny($remote, $proxies)) {
            return $remote;
        }

        $header = (string) $params->get('proxy_header', 'x-forwarded-for');
        $raw    = $header === 'x-real-ip'
            ? (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')
            : (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        $hops = array_reverse(array_map('trim', explode(',', $raw)));

        foreach ($hops as $hop) {
            // "[2001:db8::1]:443" or "203.0.113.5:8080" → address only.
            $hop = preg_replace('/^\[([^\]]+)\](?::\d+)?$/', '$1', $hop);
            $hop = preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $hop) ? explode(':', $hop)[0] : $hop;

            if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                break; // garbage: stop, use the last good address
            }

            if (!self::inAny($hop, $proxies)) {
                return $hop;
            }

            $remote = $hop;
        }

        return $remote;
    }

    /** @return list<string> valid entries of a "one per line or comma" list */
    public static function list(string $text): array
    {
        $out = [];

        foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (self::validEntry($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public static function validEntry(string $entry): bool
    {
        [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }

    /** @param list<string> $entries */
    public static function inAny(string $ip, array $entries): bool
    {
        foreach ($entries as $e) {
            if (self::in($ip, $e)) {
                return true;
            }
        }

        return false;
    }

    /** Is $ip inside "$net" (an address, or address/bits)? */
    public static function in(string $ip, string $net): bool
    {
        [$base, $bits] = array_pad(explode('/', $net, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $base);

        if ($a === false || $b === false || \strlen($a) !== \strlen($b)) {
            return false;
        }

        $bits = $bits === null ? \strlen($a) * 8 : (int) $bits;
        $full = intdiv($bits, 8);
        $rest = $bits % 8;

        if (strncmp($a, $b, $full) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($a[$full]) & $mask) === (\ord($b[$full]) & $mask);
    }
}
