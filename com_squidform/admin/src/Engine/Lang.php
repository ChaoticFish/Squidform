<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;

\defined('_JEXEC') or die;

/**
 * Languages for visitors (0.15.0).
 *
 * SquidForm brings its own packs of the words visitors see (buttons, error
 * messages, help) in components/com_squidform/language/<tag>/: English,
 * Spanish, French, German, Esperanto, and three just for fun (Swedish Chef,
 * Feline, Canine). Joomla doesn't need those languages installed.
 *
 *  - Options → Languages: which packs this site offers (all, at install).
 *  - A form's Settings → Languages: which of those it offers. With more than
 *    one, visitors get a row of language tabs above the form. The fun packs
 *    only appear on forms that pick them.
 *  - The first language is Joomla's (the page's language), or English.
 *  - The form's own words (labels, help, choices, intro, messages,
 *    confirmation emails) come from its Translations tab; anything left
 *    empty stays as written. The fun packs make up their own.
 *  - Confirmation emails go out in the sender's language; emails to staff,
 *    and anything from a fun pack, stay in the site's language.
 */
final class Lang
{
    /** tag => [English name, own name, HTML lang, just for fun] */
    public const PACKS = [
        'en-GB' => ['English', 'English', 'en', false],
        'es-ES' => ['Spanish', 'Español', 'es', false],
        'fr-FR' => ['French', 'Français', 'fr', false],
        'de-DE' => ['German', 'Deutsch', 'de', false],
        'eo-XX' => ['Esperanto', 'Esperanto', 'eo', false],
        'en-XC' => ['Swedish Chef', 'Bork bork bork!', 'en', true],
        'en-XF' => ['Feline', 'Meow', 'en', true],
        'en-XD' => ['Canine', 'Woof', 'en', true],
    ];

    /**
     * 1.0.3: the built-in packs plus any an add-on offers (onSquidformLanguages).
     * Add-on packs are offered whenever their plugin is on.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function packs(): array
    {
        $all = self::PACKS;

        foreach (Addons::languages() as $tag => $p) {
            $all[$tag] = [$p[0], $p[1], $p[2], false];
        }

        return $all;
    }

    /** The language in use now (null: the page's own). */
    private static ?string $active = null;

    /** The page's Language, before any switch. */
    private static ?Language $home = null;

    /** @var array<string, Language> one copy per pack, made when first needed */
    private static array $made = [];

    /** @return list<string> the packs this site offers (Options → Languages) */
    public static function site(): array
    {
        $want = ComponentHelper::getParams('com_squidform')->get('languages', null);
        $want  = $want === null || $want === '' ? array_keys(self::PACKS) : (array) $want;
        $packs = self::packs();

        // Add-on packs (1.0.3) aren't in Options: their plugin being on offers them.
        $want = array_merge(array_map('strval', $want), array_keys(Addons::languages()));
        $out  = array_values(array_unique(array_filter($want, static fn ($t) => isset($packs[$t]))));

        return $out ?: ['en-GB'];
    }

    /** The pack nearest Joomla's language for this page (es-MX → es-ES), else English. */
    public static function default(): string
    {
        try {
            $tag = (self::$home ?? Factory::getApplication()->getLanguage())->getTag();
        } catch (\Throwable) {
            return 'en-GB';
        }

        return self::nearest($tag);
    }

    /** The pack the form's own words are written in: the site's default language. */
    public static function base(): string
    {
        return self::nearest((string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB'));
    }

    public static function nearest(string $tag): string
    {
        if (isset(self::packs()[$tag]) && !self::packs()[$tag][3]) {
            return $tag;
        }

        $two = strtolower(substr($tag, 0, 2));

        foreach (self::packs() as $t => $p) {
            if (!$p[3] && $p[2] === $two) {
                return $t;
            }
        }

        return 'en-GB';
    }

    /** @return list<string> what a form offers, its first language first */
    public static function forForm(Schema $schema): array
    {
        $site = self::site();
        $list = [self::default()];

        foreach ((array) $schema->setting('languages', []) as $t) {
            if (\is_string($t) && \in_array($t, $site, true)) {
                $list[] = $t;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Which language this visitor gets on this form: one they just chose
     * (if the form offers it), else the one they chose before, else the first.
     */
    public static function pick(Schema $schema, string $alias, ?string $chosen = null): string
    {
        $list = self::forForm($schema);

        if (\count($list) < 2) {
            return $list[0];
        }

        $key = 'squidform.lang.' . $alias;

        try {
            $session = Factory::getApplication()->getSession();
        } catch (\Throwable) {
            return $list[0];
        }

        if ($chosen !== null && \in_array($chosen, $list, true)) {
            $session->set($key, $chosen);

            return $chosen;
        }

        $saved = (string) $session->get($key, '');

        return \in_array($saved, $list, true) ? $saved : $list[0];
    }

    /** The language words are coming out in now. */
    public static function current(): string
    {
        return self::$active ?? self::default();
    }

    public static function isJoke(string $tag): bool
    {
        return (bool) (self::packs()[$tag][3] ?? false);
    }

    /** For HTML lang="": the part screen readers use. */
    public static function htmlLang(string $tag): string
    {
        return self::packs()[$tag][2] ?? 'en';
    }

    /** Run $fn with SquidForm's visitor words in $tag, then switch back. */
    public static function run(string $tag, callable $fn): mixed
    {
        $before = self::$active;
        $lang   = self::enter($tag);

        try {
            return $fn();
        } finally {
            self::$active = $before;
            self::swap($before === null ? self::$home : (self::$made[$before] ?? $lang));
        }
    }

    /** Switch for the rest of this request (a form post, which ends in a redirect). */
    public static function enter(string $tag): ?Language
    {
        if (!isset(self::packs()[$tag])) {
            return null;
        }

        Renderer::loadLanguage();
        $app = Factory::getApplication();
        self::$home ??= $app->getLanguage();
        self::$active = $tag;

        if (!isset(self::$made[$tag])) {
            $l = clone self::$home;

            // English first, so a word the pack lacks is still words.
            $l->load('com_squidform', JPATH_ADMINISTRATOR . '/components/com_squidform', 'en-GB', true, false);
            $l->load('com_squidform', JPATH_SITE . '/components/com_squidform', 'en-GB', true, false);

            $addon = Addons::languages()[$tag][4] ?? null;

            if ($addon !== null) {
                // An add-on's pack (1.0.3): <plugin>/language/<tag>/com_squidform.ini.
                $l->load('com_squidform', \dirname($addon), $tag, true, false);
            } elseif ($tag !== 'en-GB') {
                $l->load('com_squidform', JPATH_SITE . '/components/com_squidform', $tag, true, false);
            }

            self::$made[$tag] = $l;
        }

        self::swap(self::$made[$tag]);

        return self::$made[$tag];
    }

    private static function swap(?Language $l): void
    {
        if ($l === null) {
            return;
        }

        Factory::$language = $l;
        $app = Factory::getApplication();

        if (method_exists($app, 'loadLanguage')) {
            $app->loadLanguage($l);
        }
    }

    /**
     * The form with its own words in $tag: the Translations tab's text where
     * there is some, the fun packs' made-up words, the original elsewhere.
     */
    public static function schemaFor(Schema $schema, string $tag): Schema
    {
        $table = (array) ($schema->toArray()['translations'][$tag] ?? []);
        $joke  = self::isJoke($tag);

        if ($table === [] && !$joke) {
            return $schema;
        }

        $tr = static function (string $path, mixed $text) use ($table, $joke, $tag): mixed {
            if (!\is_string($text) || $text === '') {
                return $text;
            }

            if (isset($table[$path]) && \is_string($table[$path]) && trim($table[$path]) !== '') {
                return $table[$path];
            }

            return $joke ? Joke::say($tag, $text) : $text;
        };

        $def = $schema->toArray();

        foreach (self::SETTINGS as $s) {
            if (isset($def['settings'][$s])) {
                $def['settings'][$s] = $tr('s.' . $s, $def['settings'][$s]);
            }
        }

        foreach ($def['pages'] as $n => &$page) {
            foreach (['title', 'submit_label'] as $p) {
                if (isset($page[$p])) {
                    $page[$p] = $tr('p.' . $n . '.' . $p, $page[$p]);
                }
            }

            $page['fields'] = self::fields((array) ($page['fields'] ?? []), $tr);

            foreach ((array) ($page['events'] ?? []) as $ev => $list) {
                foreach ((array) $list as $i => $a) {
                    $k = (string) ($a['key'] ?? '');
                    $t = (string) ($a['type'] ?? '');

                    if ($k === '' || !\in_array($t, ['message', 'confirm_email'], true) || ($joke && $t === 'confirm_email')) {
                        continue;
                    }

                    foreach (['text', 'subject', 'body'] as $c) {
                        if (isset($a['config'][$c])) {
                            $page['events'][$ev][$i]['config'][$c] = $tr('a.' . $k . '.' . $c, $a['config'][$c]);
                        }
                    }
                }
            }
        }

        unset($page);

        try {
            return Schema::fromArray($def);
        } catch (SchemaException) {
            return $schema; // a translation that doesn't fit: the original is better than nothing
        }
    }

    /** Settings with words visitors read. */
    public const SETTINGS = ['intro', 'submit_label', 'success_message', 'review_title', 'review_intro', 'before_message', 'closed_message', 'full_message', 'user_message'];

    /** What the Translations tab lists, so the builder and the server agree. */
    public const FIELD_TEXT = ['label', 'help', 'placeholder', 'content', 'row_label', 'add_label', 'prefix', 'suffix', 'terms', 'agree_label'];

    /** Lists of choices: property => path part */
    public const FIELD_LISTS = ['choices' => 'opt', 'items' => 'opt', 'rows' => 'row', 'columns' => 'col'];

    private static function fields(array $fields, callable $tr): array
    {
        foreach ($fields as &$f) {
            if (!\is_array($f) || !isset($f['key'])) {
                continue;
            }

            $k = (string) $f['key'];

            foreach (self::FIELD_TEXT as $p) {
                if (isset($f[$p])) {
                    $f[$p] = $tr('f.' . $k . '.' . $p, $f[$p]);
                }
            }

            if (\is_array($f['options']['static'] ?? null)) {
                $f['options']['static'] = self::list($f['options']['static'], 'f.' . $k . '.opt.', $tr);
            }

            foreach (self::FIELD_LISTS as $prop => $part) {
                if (\is_array($f[$prop] ?? null) && array_is_list($f[$prop])) {
                    $f[$prop] = self::list($f[$prop], 'f.' . $k . '.' . $part . '.', $tr);
                }
            }

            if (\is_array($f['fields'] ?? null)) {
                $f['fields'] = self::fields($f['fields'], $tr);
            }
        }

        return $fields;
    }

    /** Choices given as text become {value, label} so the stored value doesn't change. */
    private static function list(array $items, string $prefix, callable $tr): array
    {
        foreach ($items as &$o) {
            if (\is_string($o) || \is_int($o)) {
                $label = $tr($prefix . $o, (string) $o);

                if ($label !== (string) $o) {
                    $o = ['value' => (string) $o, 'label' => $label];
                }
            } elseif (\is_array($o) && isset($o['value'])) {
                if (isset($o['label'])) {
                    $o['label'] = $tr($prefix . $o['value'], $o['label']);
                }

                if (isset($o['alt']) && $o['alt'] !== '') {
                    $o['alt'] = $tr($prefix . $o['value'] . '.alt', $o['alt']);
                }
            }
        }

        return $items;
    }

    /**
     * Checks for the "translations" block of a definition.
     *
     * @return list<string> problems
     */
    public static function problems(mixed $tr): array
    {
        if ($tr === null) {
            return [];
        }

        if (!\is_array($tr) || array_is_list($tr) && $tr !== []) {
            return ['translations: must be an object of languages, like {"es-ES": {"f.f_name.label": "Nombre"}}.'];
        }

        $out = [];

        foreach ($tr as $tag => $table) {
            if (!isset(self::packs()[$tag])) {
                $out[] = 'translations: "' . $tag . '" isn\'t a language SquidForm has (' . implode(', ', array_keys(self::packs())) . ').';
                continue;
            }

            if (!\is_array($table) || array_is_list($table) && $table !== []) {
                $out[] = 'translations.' . $tag . ': must be an object of "path": "text".';
                continue;
            }

            if (\count($table) > 2000) {
                $out[] = 'translations.' . $tag . ': at most 2000 texts.';
            }

            foreach ($table as $path => $text) {
                if (!preg_match('/^(f\.[A-Za-z0-9_]{1,64}\.[a-z_]{1,20}(\.[^\r\n]{1,255})?|p\.\d{1,3}\.(title|submit_label)|s\.(intro|submit_label|success_message|review_title|review_intro|before_message|closed_message|full_message|user_message)|a\.[A-Za-z0-9_]{1,64}\.(text|subject|body))$/', (string) $path)) {
                    $out[] = 'translations.' . $tag . ': "' . mb_substr((string) $path, 0, 80) . '" isn\'t a place that can be translated.';
                } elseif (!\is_string($text) || mb_strlen($text) > 20000) {
                    $out[] = 'translations.' . $tag . '.' . $path . ': must be text (up to 20,000 characters).';
                }
            }
        }

        return $out;
    }
}
