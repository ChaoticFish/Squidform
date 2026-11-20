<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Log\Log;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * 0.13.2 add-on hook: onSquidformAddressFormats. An add-on (the planned
 * "Address formats" add-on) adds address layouts beyond "us" and "intl":
 *
 *   $event['registry']['mx'] = [
 *     'name' => 'México',
 *     'rows' => [
 *       [['part' => 'street', 'label' => 'Calle y número', 'autocomplete' => 'address-line1', 'required' => true, 'width' => 12]],
 *       [['part' => 'zip', 'label' => 'Código postal', 'autocomplete' => 'postal-code', 'pattern' => '^\d{5}$', 'width' => 4],
 *        ['part' => 'city', 'label' => 'Ciudad', 'autocomplete' => 'address-level2', 'required' => true, 'width' => 8]],
 *     ],
 *     'display' => "{street}\n{zip} {city}",
 *   ];
 *
 * Each format is checked here: a bad one is left out (and logged), never
 * half-used. Labels are plain text; patterns must work the same in PHP and
 * browsers; nothing in a format becomes HTML or code.
 */
final class AddressFormats
{
    public const AUTOCOMPLETE = ['address-line1', 'address-line2', 'address-line3', 'address-level1', 'address-level2', 'address-level3', 'address-level4', 'postal-code', 'country', 'country-name', ''];

    /** @var array<string, array>|null */
    private static ?array $all = null;

    /** @return array<string, array{name: string, rows: list<list<array>>, display: string}> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $registry = new \ArrayObject();

        try {
            PluginHelper::importPlugin('squidform');
            Factory::getApplication()->getDispatcher()->dispatch('onSquidformAddressFormats', new Event('onSquidformAddressFormats', ['registry' => $registry]));
        } catch (\Throwable) {
            // No add-ons.
        }

        $out = [];

        foreach ($registry as $id => $f) {
            $id = \is_string($id) ? strtolower($id) : '';

            if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || \in_array($id, ['us', 'intl'], true)) {
                continue;
            }

            $clean = self::check(\is_array($f) ? $f : []);

            if ($clean === null) {
                Log::add('SquidForm: address format "' . $id . '" from an add-on was left out; it doesn\'t follow the format rules.', Log::WARNING, 'squidform');
                continue;
            }

            $out[$id] = $clean;
        }

        return self::$all = $out;
    }

    public static function get(string $id): ?array
    {
        return self::all()[strtolower($id)] ?? null;
    }

    /** A format as SquidForm will use it, or null when it breaks a rule. */
    public static function check(array $f): ?array
    {
        $name = trim((string) ($f['name'] ?? ''));
        $rows = $f['rows'] ?? null;

        if ($name === '' || mb_strlen($name) > 80 || !\is_array($rows) || $rows === [] || \count($rows) > 12) {
            return null;
        }

        $seen = [];
        $out  = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || $row === [] || \count($row) > 4) {
                return null;
            }

            $r = [];

            foreach ($row as $p) {
                $key = (string) ($p['part'] ?? '');

                if (!\is_array($p) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || isset($seen[$key])) {
                    return null;
                }

                $seen[$key] = true;
                $label      = trim((string) ($p['label'] ?? ''));
                $ac         = (string) ($p['autocomplete'] ?? '');
                $width      = (int) ($p['width'] ?? 12);
                $pattern    = isset($p['pattern']) ? (string) $p['pattern'] : null;

                if ($label === '' || mb_strlen($label) > 60 || !\in_array($ac, self::AUTOCOMPLETE, true) || $width < 1 || $width > 12) {
                    return null;
                }

                if ($pattern !== null && ($pattern === '' || \strlen($pattern) > 200 || Schema::checkPattern($pattern) !== null)) {
                    return null;
                }

                $part = ['key' => $key, 'label' => $label, 'width' => $width, 'autocomplete' => $ac, 'required' => !empty($p['required'])];

                if ($pattern !== null) {
                    $part['pattern'] = $pattern;
                }

                if (isset($p['options'])) {
                    if (!\is_array($p['options']) || \count($p['options']) > 300) {
                        return null;
                    }

                    $opts = [];

                    foreach ($p['options'] as $o) {
                        $o = trim((string) $o);

                        if ($o === '' || mb_strlen($o) > 80) {
                            return null;
                        }

                        $opts[$o] = $o;
                    }

                    $part['options'] = $opts;
                }

                $r[] = $part;
            }

            $out[] = $r;
        }

        $display = (string) ($f['display'] ?? '');

        if ($display === '') {
            $display = implode("\n", array_map(static fn ($row) => implode(' ', array_map(static fn ($p) => '{' . $p['key'] . '}', $row)), $out));
        }

        return ['name' => $name, 'rows' => $out, 'display' => mb_substr($display, 0, 400)];
    }

    /** "{street}\n{zip} {city}" filled in; empty parts and the spaces or commas next to them fall away. */
    public static function display(array $format, array $v, string $glue = ', '): string
    {
        $lines = [];

        foreach (explode("\n", $format['display']) as $line) {
            $text = preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', static fn ($m) => trim((string) ($v[$m[1]] ?? '')), $line);
            $text = trim((string) preg_replace(['/\s+/', '/\s*,\s*(,\s*)+/', '/^[,\s]+|[,\s]+$/'], [' ', ', ', ''], (string) $text));

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return implode($glue, $lines);
    }
}
