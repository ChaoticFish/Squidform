<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Every check a definition must pass before it can be published, in one
 * place: used by Save and by the builder's live check (0.7.0).
 *
 * Each problem has its message, the path it's about when there is one
 * ("pages[0].fields[2]") so the builder can point at the item, and a line
 * (and column, for syntax errors) for the JSON box.
 */
final class DefinitionCheck
{
    /**
     * @return array{schema: ?Schema, problems: list<array{message: string, path: string, line: int, column: int}>}
     */
    public static function run(string $json): array
    {
        try {
            $schema = Schema::fromJson($json);
        } catch (SchemaException $e) {
            if ($e->at !== null) {
                return ['schema' => null, 'problems' => [[
                    'message' => $e->getErrors()[0], 'path' => '', 'line' => (int) $e->at['line'], 'column' => (int) $e->at['column'],
                ]]];
            }

            $paths    = JsonCheck::scan($json)['paths'];
            $problems = [];

            foreach ($e->getErrors() as $err) {
                $problems[] = self::problem($err, $paths);
            }

            return ['schema' => null, 'problems' => $problems];
        }

        $scan     = JsonCheck::scan($json);
        $problems = [];

        // Groups and users named by approval steps and user fields must exist,
        // or requests would wait for nobody.
        foreach (ApprovalFlow::problems($schema) as $err) {
            $problems[] = self::problem($err, $scan['paths']);
        }

        // Joomla user data (0.14.0): named groups and user fields must exist.
        foreach (UserFacts::problems($schema) as $err) {
            $problems[] = self::problem($err, $scan['paths']);
        }

        // A key written twice in one object: JSON keeps only the last one,
        // so half of what was written would be lost without a word.
        foreach ($scan['duplicates'] as $d) {
            $problems[] = [
                'message' => Text::sprintf('COM_SQUIDFORM_ERR_DUPLICATE_KEY', $d['key'], $d['line'], $d['first'], $d['path']),
                'path'    => $d['path'],
                'line'    => $d['line'],
                'column'  => 1,
            ];
        }

        return ['schema' => $problems ? null : $schema, 'problems' => $problems];
    }

    /**
     * Things to look at before a form goes live, which don't stop it being
     * saved (1.0.4): text in emails and messages that looks like a
     * placeholder but isn't one (it would go out exactly as written).
     *
     * @return list<array{message: string, path: string, line: int, column: int}>
     */
    public static function notes(string $json): array
    {
        $d = json_decode($json, true);

        if (!\is_array($d)) {
            return [];
        }

        $keys = [];
        $walk = static function (array $fields) use (&$walk, &$keys): void {
            foreach ($fields as $f) {
                if (\is_array($f) && isset($f['key'])) {
                    $keys[] = (string) $f['key'];
                    if (\is_array($f['fields'] ?? null)) {
                        $walk($f['fields']);
                    }
                }
            }
        };

        foreach ((array) ($d['pages'] ?? []) as $p) {
            $walk(\is_array($p['fields'] ?? null) ? $p['fields'] : []);
        }

        $lists = [];

        foreach ((array) ($d['pages'] ?? []) as $i => $p) {
            foreach (['load', 'submit'] as $ev) {
                foreach ((array) ($p['events'][$ev] ?? []) as $j => $a) {
                    $lists['pages[' . (int) $i . '].events.' . $ev . '[' . (int) $j . ']'] = $a;
                }
            }
        }

        foreach (Schema::HOOKS as $hook) {
            foreach ((array) ($d[$hook] ?? []) as $j => $a) {
                $lists[$hook . '[' . (int) $j . ']'] = $a;
            }
        }

        $scan  = JsonCheck::scan($json);
        $notes = [];

        foreach ($lists as $path => $a) {
            if (!\is_array($a) || !\in_array($a['type'] ?? '', ['email', 'confirm_email', 'message'], true)) {
                continue;
            }

            foreach (['to', 'cc', 'bcc', 'reply_to', 'subject', 'body', 'text'] as $prop) {
                $v = $a['config'][$prop] ?? null;

                if (!\is_string($v)) {
                    continue;
                }

                foreach (Placeholders::suspicious($v, $keys) as $tok) {
                    $msg     = Text::sprintf('COM_SQUIDFORM_NOTE_PLACEHOLDER', $path . ' ("' . ($a['key'] ?? '') . '").' . $prop, $tok);
                    $notes[] = ['message' => $msg, 'path' => $path, 'line' => JsonCheck::lineFor($path, $scan['paths']), 'column' => 1];
                }
            }
        }

        return $notes;
    }

    /** @param array<string, int> $paths */
    private static function problem(string $message, array $paths): array
    {
        // "pages[0].fields[3] ("f_rows").fields[2] ("f_who"): …" → pages[0].fields[3].fields[2]
        $bare = (string) preg_replace('/ \("[^"]*"\)/', '', $message);
        $path = preg_match('/^"?([A-Za-z_][A-Za-z0-9_]*(?:\[\d+\]|\.[A-Za-z_][A-Za-z0-9_]*)*)"?/', $bare, $m) ? $m[1] : '';

        // "approval step "s_sup": …" names the step by key.
        // ("f_sup.groups: …" names a field by key; the builder resolves both.)
        if (preg_match('/^approval step "([A-Za-z0-9_]+)"/', $message, $k)) {
            $path = 'approval.step:' . $k[1];
        }

        return ['message' => $message, 'path' => $path, 'line' => JsonCheck::lineFor($message, $paths), 'column' => 1];
    }
}
