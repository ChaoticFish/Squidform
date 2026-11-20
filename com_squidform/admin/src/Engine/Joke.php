<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * The just-for-fun languages (0.15.0): Swedish Chef, Feline and Canine.
 * They turn English into their own words, leaving alone what makes a page
 * work: HTML tags, entities, {placeholders} and %s / %1$d markers.
 *
 * The same word always comes out the same, so a page doesn't change each
 * time it loads. (The packs' .ini files were made with this class too.)
 */
final class Joke
{
    private const MEOW = ['Meow', 'Mew', 'Mrrp', 'Purr', 'Mrow', 'Meeeow', 'Mrrrow', 'Prrrt', 'Mreow', 'Nyaa', 'Mew-mew', 'Purrrr'];
    private const WOOF = ['Woof', 'Arf', 'Bark', 'Ruff', 'Yip', 'Aroooo', 'Grrr', 'Bow-wow', 'Awoo', 'Wuf', 'Woof-woof', 'Yap'];

    /** Bits that must come through untouched. */
    private const KEEP = '/(\\\\n|<[^>]*>|&[#A-Za-z0-9]+;|\{[^{}]*\}|%(?:\d+\$)?[sdfu]|https?:\/\/\S+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+)/u';

    public static function say(string $tag, string $text): string
    {
        $parts = preg_split(self::KEEP, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        $out = '';

        foreach ($parts as $i => $p) {
            $out .= $i % 2 === 1 ? $p : match ($tag) {
                'en-XC' => self::chef($p),
                'en-XF' => self::animal($p, self::MEOW),
                'en-XD' => self::animal($p, self::WOOF),
                default => $p,
            };
        }

        // The Chef signs off long sentences.
        if ($tag === 'en-XC' && preg_match('/[.!?]\s*$/u', strip_tags($text)) && mb_strlen(strip_tags($text)) > 30 && !str_contains($text, '<')) {
            $out = rtrim($out) . ' Bork bork bork!';
        }

        return $out;
    }

    /** The classic "encheferizer", gently. */
    private static function chef(string $s): string
    {
        return (string) preg_replace_callback('/[A-Za-z]+/', static function (array $m): string {
            $w = $m[0];

            if (\strlen($w) < 2) {
                return $w === 'a' ? 'e' : $w;
            }

            $low = strtolower($w);
            $map = ['the' => 'zee', 'an' => 'un', 'and' => 'und', 'to' => 'tu', 'of' => 'ooff', 'is' => 'ees', 'you' => 'yuoo', 'your' => 'yuoor', 'this' => 'thees', 'with' => 'veet', 'for' => 'fur', 'it' => 'eet', 'in' => 'een'];

            if (isset($map[$low])) {
                $new = $map[$low];
            } else {
                // One pass, so a changed letter isn't changed again.
                $new = (string) preg_replace_callback(
                    '/tion|^th|th$|ow|au|ir|en$|ew|oo|v|w|(?<=[a-z])i(?=[a-z])|(?<=[a-z])u(?=[a-z])|(?<=[a-z])o(?=[a-z])|e$/',
                    static fn (array $x): string => ['tion' => 'shun', 'th' => 't', 'ow' => 'oo', 'au' => 'oo', 'ir' => 'ur', 'en' => 'ee', 'ew' => 'oo', 'oo' => 'u',
                        'v' => 'f', 'w' => 'v', 'i' => 'ee', 'u' => 'oo', 'o' => 'u', 'e' => 'e-a'][$x[0]],
                    $low
                );
            }

            if (ctype_upper($w) && \strlen($w) > 1) {
                return strtoupper($new);
            }

            return ctype_upper($w[0]) ? ucfirst($new) : $new;
        }, $s);
    }

    /** Every word becomes a sound; punctuation and spacing stay. */
    private static function animal(string $s, array $sounds): string
    {
        return (string) preg_replace_callback('/[\p{L}\p{N}\']+/u', static function (array $m) use ($sounds): string {
            $w = $m[0];

            if (preg_match('/^\d+$/', $w)) {
                return $w; // numbers stay numbers
            }

            $say = $sounds[crc32(mb_strtolower($w)) % \count($sounds)];

            if (mb_strlen($w) <= 2) {
                $say = $sounds[0];
            }

            if (mb_strtoupper($w) === $w && mb_strlen($w) > 1) {
                return mb_strtoupper($say);
            }

            return preg_match('/^\p{Lu}/u', $w) ? $say : mb_strtolower($say);
        }, $s);
    }
}
