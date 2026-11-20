<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * 1.0.3 add-on hooks. Each asks plugins in the "squidform" group once per
 * request, checks what comes back, and leaves out (and logs) anything broken.
 *
 *  onSquidformOptionSources   $event['registry']['tidetable.categories'] = ['title' => 'Event categories', 'options' => fn (): array => [['value' => 'tour', 'label' => 'Night Sky Tours'], …]]
 *                             A choice field uses it as "options": {"addon": "tidetable.categories"}.
 *  onSquidformLanguages       $event['registry']['it-IT'] = ['name' => 'Italian', 'own' => 'Italiano', 'html' => 'it', 'path' => JPATH_PLUGINS . '/squidform/mylangs/language']
 *                             path is a folder named "language" holding it-IT/com_squidform.ini (the visitor words, same keys as en-GB).
 *  onSquidformStarters        $event['registry']['tidetable-events'] = ['title' => 'Tide Table: Events', 'description' => 'One line.', 'json' => '{…form definition…}']
 *                             Offered in the builder's starter forms, after the built-in ones.
 */
final class Addons
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, array{title: string, options: callable}> */
    public static function optionSources(): array
    {
        return self::$cache['options'] ??= self::collect('onSquidformOptionSources', static function ($name, $s): ?array {
            if (!\is_string($name) || !preg_match('/^[a-z][a-z0-9_.-]{0,59}$/', $name) || !\is_array($s) || !\is_callable($s['options'] ?? null)) {
                return null;
            }

            return ['title' => mb_substr((string) ($s['title'] ?? $name), 0, 100), 'options' => $s['options']];
        });
    }

    /**
     * Choices from an add-on's list. Unknown names, and lists that throw, give none (logged).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $name): array
    {
        $src = self::optionSources()[$name] ?? null;

        if ($src === null) {
            self::log('a form uses the add-on choice list "' . $name . '", but no add-on offers it.');

            return [];
        }

        try {
            $raw = ($src['options'])();
        } catch (\Throwable $e) {
            self::log('the add-on choice list "' . $name . '" failed: ' . $e->getMessage());

            return [];
        }

        $out  = [];
        $seen = [];

        foreach (\is_array($raw) ? $raw : [] as $o) {
            if (\is_array($o)) {
                $v = (string) ($o['value'] ?? $o['label'] ?? '');
                $l = (string) ($o['label'] ?? $v);
            } elseif (\is_scalar($o)) {
                $v = $l = (string) $o;
            } else {
                continue;
            }

            if ($v === '' || isset($seen[$v]) || \count($out) >= 2000) {
                continue;
            }

            $seen[$v] = true;
            $out[]    = ['value' => mb_substr($v, 0, 255), 'label' => mb_substr($l, 0, 255)];
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: string}> tag => [English name, own name, HTML lang, false, folder] */
    public static function languages(): array
    {
        return self::$cache['languages'] ??= self::collect('onSquidformLanguages', static function ($tag, $p): ?array {
            if (!\is_string($tag) || !preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag) || isset(Lang::PACKS[$tag]) || !\is_array($p)) {
                return null;
            }

            $path = rtrim((string) ($p['path'] ?? ''), '/');
            $html = strtolower((string) ($p['html'] ?? substr($tag, 0, 2)));

            if ($path === '' || basename($path) !== 'language' || !is_file($path . '/' . $tag . '/com_squidform.ini') || !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $html)) {
                return null;
            }

            return [mb_substr((string) ($p['name'] ?? $tag), 0, 60), mb_substr((string) ($p['own'] ?? $p['name'] ?? $tag), 0, 60), $html, false, $path];
        });
    }

    /** @return list<array{id: string, title: string, description: string, json: string}> */
    public static function starters(): array
    {
        $all = self::$cache['starters'] ??= self::collect('onSquidformStarters', static function ($id, $s): ?array {
            if (!\is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,59}$/', $id) || !\is_array($s) || !\is_string($s['json'] ?? null)) {
                return null;
            }

            try {
                Schema::fromJson($s['json']);
            } catch (\Throwable $e) {
                Addons::log('the add-on starter form "' . $id . '" isn\'t a valid form: ' . $e->getMessage());

                return null;
            }

            return ['id' => 'addon-' . $id, 'title' => mb_substr((string) ($s['title'] ?? $id), 0, 100), 'description' => mb_substr((string) ($s['description'] ?? ''), 0, 300), 'json' => $s['json']];
        });

        return array_values($all);
    }

    /** For tests. */
    public static function reset(): void
    {
        self::$cache = [];
    }

    public static function log(string $msg): void
    {
        try {
            Log::add('SquidForm: ' . $msg, Log::WARNING, 'squidform');
        } catch (\Throwable) {
        }
    }

    /**
     * @param  callable(mixed, mixed): ?array  $check
     *
     * @return array<string, array>
     */
    private static function collect(string $name, callable $check): array
    {
        $registry = new \ArrayObject();

        try {
            PluginHelper::importPlugin('squidform');
            Factory::getApplication()->getDispatcher()->dispatch($name, new Event($name, ['registry' => $registry]));
        } catch (\Throwable $e) {
            self::log($name . ' failed: ' . $e->getMessage());
        }

        $out = [];

        foreach ($registry as $k => $v) {
            $ok = $check($k, $v);

            if ($ok === null) {
                self::log($name . ': left out "' . (\is_scalar($k) ? (string) $k : '?') . '", which isn\'t set up right.');
                continue;
            }

            $out[(string) $k] = $ok;
        }

        return $out;
    }
}
