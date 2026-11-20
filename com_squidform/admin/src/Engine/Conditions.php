<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Evaluates declarative condition trees. The same rules run in the browser
 * (media/js/squidform.js) so show/hide matches what the server enforces.
 *
 * Shape:
 *   { "all": [ cond, ... ] }   every item true
 *   { "any": [ cond, ... ] }   at least one true
 *   { "not": cond }            negation
 *   { "field": "f_kind", "op": "eq", "value": "Unescorted" }
 *   { "action": "a_save", "is": "success" }   (only in action `when`)
 *   { "user_group": "Supervisors" }            (0.14.0) the person is in the group
 *   { "user_field": "dept", "op": "eq", "value": "HR" }  (0.14.0) a Joomla user field
 *   { "session": "abcdefuser.fineaccesscontrol", "op": "contains", "value": "X" }  (0.15.0) session data
 *
 * User data is judged on the server only. The browser gets each user leaf
 * already worked out as { "user_result": true|false } (forBrowser()), so no
 * group list or field value goes to the page.
 *
 * Operators: eq, neq, in, nin, empty, filled, gt, gte, lt, lte, contains, matches.
 * Comparisons never execute code.
 */
final class Conditions
{
    public const OPS = ['eq', 'neq', 'in', 'nin', 'empty', 'filled', 'gt', 'gte', 'lt', 'lte', 'contains', 'ncontains', 'matches'];

    /** The account user-data conditions are about; null = whoever is signed in. */
    private static ?int $subject = null;

    /**
     * Judge user-data conditions for this account while $fn runs (staff
     * editing a submission: the person who sent it, not the editor).
     */
    public static function asUser(int $userId, callable $fn): mixed
    {
        $was           = self::$subject;
        self::$subject = $userId;

        try {
            return $fn();
        } finally {
            self::$subject = $was;
        }
    }

    public static function subject(): int
    {
        if (self::$subject !== null) {
            return self::$subject;
        }

        try {
            return (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function me(): int
    {
        try {
            return (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** True for a user_group or user_field leaf. */
    public static function isUserLeaf(array $cond): bool
    {
        return isset($cond['user_group']) || isset($cond['user_field']) || isset($cond['session']);
    }

    /**
     * The condition as the browser gets it: user leaves replaced by their
     * result for the person viewing the page.
     *
     * @param   array<string, mixed>  $cond
     *
     * @return  array<string, mixed>
     */
    public static function forBrowser(array $cond, int $depth = 0): array
    {
        if ($depth > 16) {
            return $cond;
        }

        foreach (['all', 'any'] as $g) {
            if (isset($cond[$g])) {
                return [$g => array_map(static fn ($c) => self::forBrowser((array) $c, $depth + 1), array_values((array) $cond[$g]))];
            }
        }

        if (isset($cond['not'])) {
            return ['not' => self::forBrowser((array) $cond['not'], $depth + 1)];
        }

        if (self::isUserLeaf($cond)) {
            return ['user_result' => self::user($cond)];
        }

        return $cond;
    }

    /** @param array<string, mixed> $cond */
    private static function user(array $cond): bool
    {
        $id = self::subject();

        if (isset($cond['user_group'])) {
            return UserFacts::inGroup($id, $cond['user_group']);
        }

        // Session data (0.15.0) belongs to whoever is using the site now. When
        // the rule is judged for someone else (staff editing, a later approval
        // step), their session isn't here: treat it as met, so nothing they
        // answered is dropped.
        if (isset($cond['session'])) {
            if (self::$subject !== null && self::$subject !== self::me()) {
                return true;
            }

            return self::compare(SessionData::get((string) $cond['session']), (string) ($cond['op'] ?? 'eq'), $cond['value'] ?? null);
        }

        return self::compare(UserFacts::field($id, (string) $cond['user_field']), (string) ($cond['op'] ?? 'eq'), $cond['value'] ?? null);
    }

    /**
     * @param   array<string, mixed>          $cond
     * @param   callable(string): mixed       $value    Returns a field's current value by key.
     * @param   callable(string): ?string     $outcome  Returns an action's outcome (success|fail|skipped) by key.
     */
    public static function evaluate(array $cond, callable $value, ?callable $outcome = null, int $depth = 0): bool
    {
        if ($depth > 16) {
            throw new \RuntimeException('Condition is nested too deeply.');
        }

        if (isset($cond['all'])) {
            foreach ((array) $cond['all'] as $c) {
                if (!self::evaluate((array) $c, $value, $outcome, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($cond['any'])) {
            foreach ((array) $cond['any'] as $c) {
                if (self::evaluate((array) $c, $value, $outcome, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($cond['not'])) {
            return !self::evaluate((array) $cond['not'], $value, $outcome, $depth + 1);
        }

        if (isset($cond['action'])) {
            $is = $outcome ? $outcome((string) $cond['action']) : null;

            return $is === (string) ($cond['is'] ?? 'success');
        }

        if (self::isUserLeaf($cond)) {
            return self::user($cond);
        }

        if (\array_key_exists('user_result', $cond)) {
            return (bool) $cond['user_result'];
        }

        if (!isset($cond['field'])) {
            return true;
        }

        return self::compare($value((string) $cond['field']), (string) ($cond['op'] ?? 'eq'), $cond['value'] ?? null);
    }

    public static function compare(mixed $left, string $op, mixed $right): bool
    {
        $isEmpty = static fn ($v) => $v === null || $v === '' || $v === [] || $v === false;

        // Multi-value fields (checkboxes) compare by membership.
        if (\is_array($left) && \in_array($op, ['eq', 'neq', 'contains', 'ncontains'], true)) {
            $hit = \in_array((string) $right, array_map('strval', $left), true);

            return \in_array($op, ['neq', 'ncontains'], true) ? !$hit : $hit;
        }

        // "does not contain" (0.9.1): the opposite of contains.
        if ($op === 'ncontains') {
            return !self::compare($left, 'contains', $right);
        }

        $l = \is_array($left) ? '' : (string) $left;

        switch ($op) {
            case 'eq':
                return $l === (string) $right;
            case 'neq':
                return $l !== (string) $right;
            case 'in':
            case 'nin':
                $hit = \in_array($l, array_map('strval', (array) $right), true);

                return $op === 'in' ? $hit : !$hit;
            case 'empty':
                return $isEmpty($left);
            case 'filled':
                return !$isEmpty($left);
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                if (!is_numeric($l) || !is_numeric($right)) {
                    // Fall back to string order so ISO dates (YYYY-MM-DD) compare correctly.
                    $cmp = strcmp($l, (string) $right);
                } else {
                    $cmp = (float) $l <=> (float) $right;
                }

                return match ($op) {
                    'gt'  => $cmp > 0,
                    'gte' => $cmp >= 0,
                    'lt'  => $cmp < 0,
                    'lte' => $cmp <= 0,
                };
            case 'contains':
                return $right !== null && $right !== '' && str_contains(mb_strtolower($l), mb_strtolower((string) $right));
            case 'matches':
                return @preg_match(self::regex((string) $right), $l) === 1;
        }

        return false;
    }

    /**
     * Build a PCRE from a pattern written the JavaScript way (no delimiters,
     * "/" needs no escaping). Used for `matches` and field `rules.pattern`.
     *
     * @param   bool  $whole  true = the whole value must match
     */
    public static function regex(string $pattern, bool $whole = false): string
    {
        $body = str_replace('~', '\\~', $pattern);

        return '~' . ($whole ? '^(?:' . $body . ')$' : $body) . '~u';
    }

    /**
     * Field keys a condition depends on (used by validation and by the browser
     * script to know which inputs to watch).
     *
     * @param   array<string, mixed>  $cond
     *
     * @return  string[]
     */
    public static function referencedFields(array $cond): array
    {
        $out = [];

        foreach (['all', 'any'] as $group) {
            if (isset($cond[$group])) {
                foreach ((array) $cond[$group] as $c) {
                    $out = array_merge($out, self::referencedFields((array) $c));
                }
            }
        }

        if (isset($cond['not'])) {
            $out = array_merge($out, self::referencedFields((array) $cond['not']));
        }

        if (isset($cond['field'])) {
            $out[] = (string) $cond['field'];
        }

        return array_values(array_unique($out));
    }
}
