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
 * The "approval" block of a form definition, checked and normalised.
 *
 * One approver (or group) for the whole form:
 *
 *   "approval": { "mode": "single", "approvers": ["group:Supervisors"] }
 *
 * Steps in order, with optional conditions:
 *
 *   "approval": {
 *     "mode": "chain",
 *     "steps": [
 *       { "key": "s_sup", "title": "Supervisor", "approvers": ["field:f_supervisor"] },
 *       { "key": "s_sec", "title": "Security", "approvers": ["group:Security"], "rule": "any",
 *         "when": { "field": "f_citizen", "op": "eq", "value": "No" } }
 *     ]
 *   }
 *
 * Approver entries:  group:<title or id>, user:<username or id>, field:<user field key>
 * rule:              any (default) = one approval finishes the step;
 *                    all = every entry must approve (a group entry: any one member)
 * when:              the step only runs when this is true; checked when the chain
 *                    reaches the step. A field inside a repeater is true when
 *                    any row matches.
 *
 * Options (defaults shown):
 *   allow_return        true   approvers may send it back for changes
 *   allow_withdraw      true   the submitter may withdraw it while undecided
 *   edit_while_pending  true   the submitter may edit (with Edit Own) while pending;
 *                              saving restarts the chain from the first step
 *   allow_self_approval false  the submitter may approve their own request
 *   notify.approvers    true   email the approvers when it reaches their step
 *   notify.submitter    true   email the submitter when it's approved, rejected or returned
 *   notify.submitter_email     a field key holding the address (for guests);
 *                              otherwise the submitter's account email
 */
final class ApprovalFlow
{
    public const RULES = ['any', 'all'];

    private const KEY = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /**
     * Normalised flow for a schema, or null when the form has no approvals.
     *
     * @return array{steps: list<array{key: string, title: string, approvers: list<string>, rule: string, when: ?array}>,
     *               allow_return: bool, allow_withdraw: bool, edit_while_pending: bool, allow_self_approval: bool,
     *               notify: array{approvers: bool, submitter: bool, submitter_email: string}}|null
     */
    public static function of(?Schema $schema): ?array
    {
        $a = $schema?->approval();

        if (!\is_array($a)) {
            return null;
        }

        if (($a['mode'] ?? '') === 'single') {
            $steps = [[
                'key'       => 'approval',
                'title'     => (string) ($a['title'] ?? Text::_('COM_SQUIDFORM_APPROVAL_STEP_DEFAULT')),
                'approvers' => array_values((array) ($a['approvers'] ?? [])),
                'rule'      => (string) ($a['rule'] ?? 'any'),
                'when'      => null,
            ]];
        } else {
            $steps = [];

            foreach ((array) ($a['steps'] ?? []) as $s) {
                $steps[] = [
                    'key'       => (string) $s['key'],
                    'title'     => (string) ($s['title'] ?? $s['key']),
                    'approvers' => array_values((array) ($s['approvers'] ?? [])),
                    'rule'      => (string) ($s['rule'] ?? 'any'),
                    'when'      => \is_array($s['when'] ?? null) && $s['when'] !== [] ? $s['when'] : null,
                ];
            }
        }

        $n = \is_array($a['notify'] ?? null) ? $a['notify'] : [];

        return [
            'steps'               => $steps,
            'allow_return'        => (bool) ($a['allow_return'] ?? true),
            'allow_withdraw'      => (bool) ($a['allow_withdraw'] ?? true),
            'edit_while_pending'  => (bool) ($a['edit_while_pending'] ?? true),
            'allow_self_approval' => (bool) ($a['allow_self_approval'] ?? false),
            'notify'              => [
                'approvers'       => (bool) ($n['approvers'] ?? true),
                'submitter'       => (bool) ($n['submitter'] ?? true),
                'submitter_email' => (string) ($n['submitter_email'] ?? ''),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function step(array $flow, string $key): ?array
    {
        foreach ($flow['steps'] as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    public static function stepIndex(array $flow, string $key): int
    {
        foreach ($flow['steps'] as $i => $s) {
            if ($s['key'] === $key) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Does this step apply to the submission's answers?
     *
     * @param  array<string, mixed>  $data
     */
    public static function applies(array $step, Schema $schema, array $data): bool
    {
        return $step['when'] === null || self::evaluate($step['when'], $schema, $data);
    }

    /**
     * Condition with "any row" meaning for fields inside a repeater.
     *
     * @param  array<string, mixed>  $data
     */
    public static function evaluate(array $cond, Schema $schema, array $data, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }

        if (isset($cond['all'])) {
            foreach ((array) $cond['all'] as $c) {
                if (!self::evaluate((array) $c, $schema, $data, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($cond['any'])) {
            foreach ((array) $cond['any'] as $c) {
                if (self::evaluate((array) $c, $schema, $data, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($cond['not'])) {
            return !self::evaluate((array) $cond['not'], $schema, $data, $depth + 1);
        }

        if (Conditions::isUserLeaf($cond)) {
            return Conditions::evaluate($cond, static fn () => null);
        }

        if (!isset($cond['field'])) {
            return true;
        }

        $key    = (string) $cond['field'];
        $op     = (string) ($cond['op'] ?? 'eq');
        $right  = $cond['value'] ?? null;
        $parent = $schema->parentOf($key);

        if ($parent === null) {
            return Conditions::compare($data[$key] ?? null, $op, $right);
        }

        foreach ((array) ($data[$parent] ?? []) as $row) {
            if (\is_array($row) && Conditions::compare($row[$key] ?? null, $op, $right)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check the block's shape. Groups and users are checked against the site
     * separately (problems()), when a definition is saved.
     *
     * @param  array<string, string>                              $top        top-level field key => type
     * @param  array<string, array{page: int, parent: ?string}>   $fieldKeys
     *
     * @return string[]
     */
    public static function validate(mixed $a, array $top, array $fieldKeys, int $lastPage): array
    {
        if (!\is_array($a) || !\in_array($a['mode'] ?? null, ['single', 'chain'], true)) {
            return ['approval.mode must be "single" or "chain".'];
        }

        $errors = [];

        foreach (['allow_return', 'allow_withdraw', 'edit_while_pending', 'allow_self_approval'] as $b) {
            if (\array_key_exists($b, $a) && !\is_bool($a[$b])) {
                $errors[] = 'approval.' . $b . ' must be true or false.';
            }
        }

        if (isset($a['notify'])) {
            if (!\is_array($a['notify']) || array_is_list($a['notify']) && $a['notify'] !== []) {
                $errors[] = 'approval.notify must be an object.';
            } else {
                foreach (['approvers', 'submitter'] as $b) {
                    if (\array_key_exists($b, $a['notify']) && !\is_bool($a['notify'][$b])) {
                        $errors[] = 'approval.notify.' . $b . ' must be true or false.';
                    }
                }

                $se = $a['notify']['submitter_email'] ?? null;

                if ($se !== null && (!\is_string($se) || ($top[$se] ?? '') !== 'email')) {
                    $errors[] = 'approval.notify.submitter_email must be the key of a top-level email field.';
                }
            }
        }

        $steps = [];

        if ($a['mode'] === 'single') {
            if (isset($a['steps'])) {
                $errors[] = 'approval: "steps" is for mode "chain"; mode "single" uses "approvers".';
            }

            $steps[] = ['key' => 'approval', 'approvers' => $a['approvers'] ?? null, 'rule' => $a['rule'] ?? 'any'] + (isset($a['title']) ? ['title' => $a['title']] : []);
            $where   = ['approval'];
        } else {
            if (isset($a['approvers'])) {
                $errors[] = 'approval: mode "chain" lists approvers on each step, not at the top.';
            }

            if (!\is_array($a['steps'] ?? null) || $a['steps'] === [] || !array_is_list($a['steps'])) {
                $errors[] = 'approval.steps must be a non-empty list for a chain.';

                return $errors;
            }

            $steps = $a['steps'];
            $where = array_map(static fn ($i) => 'approval.steps[' . $i . ']', array_keys($steps));
        }

        // Conditions on steps may use any field, including repeater fields (any row).
        $flat = array_map(static fn ($i) => ['page' => $i['page'], 'parent' => null], $fieldKeys);
        $seen = [];

        foreach ($steps as $i => $s) {
            $w = $where[$i];

            if (!\is_array($s)) {
                $errors[] = $w . ' must be an object.';
                continue;
            }

            $k = $s['key'] ?? null;

            if (!\is_string($k) || !preg_match(self::KEY, $k) || isset($seen[$k])) {
                $errors[] = $w . '.key is missing, invalid or repeated.';
            }

            $seen[(string) $k] = true;

            if (isset($s['title']) && !\is_string($s['title'])) {
                $errors[] = $w . '.title must be text.';
            }

            if (!\in_array($s['rule'] ?? 'any', self::RULES, true)) {
                $errors[] = $w . '.rule must be "any" or "all".';
            }

            $list = $s['approvers'] ?? null;

            if (!\is_array($list) || $list === [] || !array_is_list($list)) {
                $errors[] = $w . '.approvers must list at least one group, user or field.';
                continue;
            }

            foreach ($list as $entry) {
                if (!\is_string($entry) || !preg_match('/^(group|user|field):(.+)$/', $entry, $m)) {
                    $errors[] = $w . '.approvers: "' . (\is_string($entry) ? $entry : '?') . '" must be group:<name>, user:<username> or field:<key>.';
                    continue;
                }

                if ($m[1] === 'field' && ($top[$m[2]] ?? '') !== 'user') {
                    $errors[] = $w . '.approvers: "' . $entry . '" must name a top-level user field.';
                }
            }

            if (\array_key_exists('when', $s)) {
                $errors = array_merge($errors, Schema::conditionErrors($s['when'], $w . '.when', $lastPage, $flat));
            }
        }

        return $errors;
    }

    /**
     * Site checks for a definition being saved: every group and user named in
     * the approval block and in user fields exists.
     *
     * @return string[]
     */
    public static function problems(Schema $schema): array
    {
        $errors = [];
        $stack  = [];

        foreach ($schema->pages() as $p) {
            foreach ($p['fields'] ?? [] as $f) {
                $stack[] = $f;
            }
        }

        while ($stack) {
            $f = array_pop($stack);

            if (($f['type'] ?? '') === 'user') {
                foreach ((array) ($f['groups'] ?? []) as $g) {
                    if (Users::groupId((string) $g) === null) {
                        $errors[] = $f['key'] . '.groups: there is no user group "' . $g . '".';
                    }
                }
            }

            foreach ((array) ($f['fields'] ?? []) as $c) {
                $stack[] = $c;
            }
        }

        $flow = self::of($schema);

        foreach ($flow['steps'] ?? [] as $s) {
            foreach ($s['approvers'] as $entry) {
                [$kind, $ref] = explode(':', $entry, 2);

                if ($kind === 'group' && Users::groupId($ref) === null) {
                    $errors[] = 'approval step "' . $s['key'] . '": there is no user group "' . $ref . '".';
                } elseif ($kind === 'user' && Users::userId($ref) === null) {
                    $errors[] = 'approval step "' . $s['key'] . '": there is no user "' . $ref . '".';
                }
            }
        }

        return $errors;
    }

    /**
     * What people read for a status: "Pending: Supervisor", "Approved", …
     */
    public static function statusText(string $status, string $stepKey = '', ?Schema $schema = null): string
    {
        $text = Text::_('COM_SQUIDFORM_STATUS_' . strtoupper($status));

        if (\in_array($status, ['pending', 'returned'], true) && $stepKey !== '' && ($flow = self::of($schema)) && ($s = self::step($flow, $stepKey))) {
            return Text::sprintf('COM_SQUIDFORM_STATUS_AT_STEP', $text, $s['title']);
        }

        return $text;
    }
}
