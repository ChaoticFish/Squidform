<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\SessionData;

\defined('_JEXEC') or die;

/**
 * Session user (1.0.2): fills every field whose key matches a value your
 * site's sign-on script keeps in the session, in one action, instead of
 * setting "Where the value comes from" on each field. With the session name
 * abcdefuser and keys starting f_, the field f_employeenumber gets
 * abcdefuser.employeenumber, f_primaryorg gets abcdefuser.primaryorg, and
 * so on (case and - or _ don't matter).
 *
 * Put it under "When it opens" to fill the fields people see (only empty
 * ones), and under "When it's sent" on the last page to lock them: then the
 * session's values are what's saved, whatever the browser sent.
 *
 * Only session names an admin lists in Options → Data Sources → Session data
 * forms may read can be read (SessionData checks), and only the top level
 * of that record: text and numbers, or a list for tick boxes. Nothing in the
 * session is changed. People who aren't signed in through the sign-on
 * script have no such session record, so nothing is filled.
 */
final class SessionUser extends Starter
{
    /** Field types a session value can go into. */
    private const TYPES = ['text', 'textarea', 'email', 'tel', 'url', 'number', 'hidden', 'date', 'select', 'radios', 'checkbox', 'checkboxes', 'multiselect', 'lookup'];

    private const LISTS = ['checkboxes', 'multiselect'];

    public function label(): string
    {
        return 'Session user';
    }

    public function description(): string
    {
        return 'Fills every field whose key matches a session value (f_employeenumber ← abcdefuser.employeenumber). Under “When it opens” it fills empty fields; under “When it’s sent” on the last page it locks them.';
    }

    public function settings(): array
    {
        return [
            'session' => 'Session name (as listed in Options; empty = the first one listed)',
            'prefix'  => 'Field keys start with (default f_)',
            'only'    => 'Only these session values (names separated by commas; empty = all)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        $allowed = SessionData::allowed();
        $name    = self::text($settings, 'session', $allowed[0] ?? '', 64);

        if ($name === '' || !\in_array($name, $allowed, true)) {
            return ActionResult::fail('The session name "' . $name . '" isn’t listed in Options → Data Sources → Session data forms may read.');
        }

        $record = SessionData::get($name);

        if (!\is_array($record) || $record === [] || array_is_list($record)) {
            return ActionResult::skipped('no session data for this person');
        }

        $norm   = static fn (string $s): string => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
        $values = [];

        foreach ($record as $k => $v) {
            if (\is_string($k) && $norm($k) !== '' && (\is_scalar($v) || (\is_array($v) && array_is_list($v)))) {
                $values[$norm($k)] = $v;
            }
        }

        $only = array_map($norm, array_filter(array_map('trim', explode(',', self::text($settings, 'only', '', 2000)))));

        if ($only) {
            $values = array_intersect_key($values, array_flip($only));
        }

        $prefix = $norm(self::text($settings, 'prefix', 'f_', 20));
        $prefix = $prefix === '' ? '' : $prefix . '_';
        $load   = !self::sending($ctx);
        $filled = [];

        foreach ($ctx->schema->valueFields() as $f) {
            $key  = (string) $f['key'];
            $type = (string) ($f['type'] ?? '');
            $bare = $norm($key);

            if ($prefix !== '' && str_starts_with($bare, $prefix)) {
                $bare = substr($bare, \strlen($prefix));
            }

            if (!\array_key_exists($bare, $values) || !\in_array($type, self::TYPES, true)) {
                continue;
            }

            // When the page opens, never over what's already there.
            $now = $ctx->data[$key] ?? null;

            if ($load && $now !== null && $now !== '' && $now !== []) {
                continue;
            }

            $v = $values[$bare];

            if (\in_array($type, self::LISTS, true)) {
                $ctx->data[$key] = array_values(array_map('strval', array_filter(\is_array($v) ? $v : [$v], 'is_scalar')));
            } else {
                $ctx->data[$key] = \is_array($v) ? implode(', ', array_map('strval', array_filter($v, 'is_scalar'))) : (string) $v;
            }

            $filled[] = $key;
        }

        return ActionResult::success(\count($filled), $filled ? 'filled ' . implode(', ', $filled) : 'no fields match');
    }
}
