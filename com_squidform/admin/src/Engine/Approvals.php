<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Approval chains (0.5.0).
 *
 * A submission of a form with an "approval" block starts as pending at the
 * first step whose condition holds. Each decision is a row in
 * #__squidform_approvals (the trail). Who may act on the current step is kept
 * in #__squidform_approval_queue, rebuilt after every change, so "Awaiting my
 * approval" is one query and group membership is read live.
 *
 * Statuses: pending → approved | rejected | returned → (resubmit) pending;
 * pending | returned → withdrawn (by the submitter; also archived).
 *
 * Rounds: a submitter's edit while pending restarts the chain from the first
 * step in a new round; decisions of earlier rounds stay in the trail but no
 * longer count. A resubmit after "Return for changes" stays in the round and
 * restarts at the step that returned it.
 *
 * Trail decisions: start, approve, reject, return, reassign, skip (step
 * condition false), restart (edited while pending), resubmit, withdraw.
 *
 * To act, a person needs Approve Submissions on the form AND to match the
 * current step: one of its entries, a reassignment to them, or a standing
 * delegate of someone who matches. The submitter never matches their own
 * request unless the form sets allow_self_approval.
 */
final class Approvals
{
    /** Decisions that reset a step's approvals (rule "all" counts after the last one). */
    private const RESETS = ['return', 'resubmit', 'reassign', 'restart', 'start'];

    // ================================================================ reading

    /** The approval flow of this submission's own version, or null. */
    public static function flow(object $item): ?array
    {
        $schema = $item->schema ?? FormRepository::version((int) $item->version_id);

        return ApprovalFlow::of($schema instanceof Schema ? $schema : null);
    }

    /** Is this submission under an approval chain (now or before)? */
    public static function applies(object $item): bool
    {
        return self::flow($item) !== null && \in_array((string) $item->status, ['pending', 'approved', 'rejected', 'returned', 'withdrawn'], true);
    }

    /**
     * The trail, oldest first, with names.
     *
     * @return list<object>
     */
    public static function trail(int $submissionId): array
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select(['a.*', $db->quoteName('u.name', 'user_name'), $db->quoteName('b.name', 'behalf_name'), $db->quoteName('t.name', 'assignee_name')])
            ->from($db->quoteName('#__squidform_approvals', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
            ->join('LEFT', $db->quoteName('#__users', 'b'), 'b.id = a.on_behalf_of')
            ->join('LEFT', $db->quoteName('#__users', 't'), 't.id = a.assignee_id')
            ->where('a.submission_id = :sid')
            ->order('a.id')
            ->bind(':sid', $submissionId, ParameterType::INTEGER);

        return $db->setQuery($q)->loadObjectList();
    }

    /**
     * One line per step for the step tracker.
     *
     * @return list<array{key: string, title: string, state: string, rule: string, people: list<string>, when: ?string}>
     *         state: done, current, returned, rejected, skipped, upcoming, not_needed
     */
    public static function tracker(object $item): array
    {
        $flow = self::flow($item);

        if (!$flow) {
            return [];
        }

        $round  = (int) $item->approval_round;
        $rows   = array_values(array_filter(self::trail((int) $item->id), static fn ($r) => (int) $r->round === $round));
        $cur    = (string) $item->current_step;
        $curIx  = ApprovalFlow::stepIndex($flow, $cur);
        $status = (string) $item->status;
        $out    = [];

        foreach ($flow['steps'] as $i => $s) {
            $mine  = array_values(array_filter($rows, static fn ($r) => $r->step_key === $s['key']));
            $last  = $mine ? end($mine) : null;
            $state = 'upcoming';

            if ($last && $last->decision === 'skip') {
                $state = 'skipped';
            } elseif ($status === 'approved') {
                $state = $mine ? 'done' : 'not_needed';
            } elseif ($s['key'] === $cur && \in_array($status, ['pending', 'returned', 'rejected', 'withdrawn'], true)) {
                $state = match ($status) {
                    'pending'   => 'current',
                    'returned'  => 'returned',
                    'rejected'  => 'rejected',
                    default     => 'withdrawn',
                };
            } elseif ($curIx >= 0 && $i < $curIx) {
                $state = 'done';
            } elseif (\in_array($status, ['rejected', 'withdrawn'], true)) {
                $state = 'not_needed';
            }

            $people = [];
            $when   = null;

            foreach ($mine as $r) {
                if ($r->decision === 'approve') {
                    $people[] = (string) $r->user_name . ((int) $r->on_behalf_of ? ' ' . Text::sprintf('COM_SQUIDFORM_APPROVAL_FOR', (string) $r->behalf_name) : '');
                    $when     = (string) $r->created;
                }
            }

            $out[] = ['key' => $s['key'], 'title' => $s['title'], 'state' => $state, 'rule' => $s['rule'], 'people' => $people, 'when' => $when];
        }

        return $out;
    }

    /** @return list<object> queue rows of a submission */
    public static function queue(int $submissionId): array
    {
        $db = SubmissionStore::db();

        return $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__squidform_approval_queue'))
                ->where('submission_id = :sid')->bind(':sid', $submissionId, ParameterType::INTEGER)->order('id')
        )->loadObjectList();
    }

    /**
     * How this user may act on the submission's current step:
     * null = may not; otherwise ['entries' => [...], 'behalf' => delegator id or 0].
     */
    public static function acting(object $item, ?User $user = null): ?array
    {
        $user ??= Users::current();
        $me   = (int) $user->id;

        if ($me <= 0 || (string) $item->status !== 'pending' || (int) $item->archived
            || \in_array((int) $item->form_id, self::archivedForms(), true)
            || !Acl::can('squidform.approve', (int) $item->form_id, $user) || !self::flow($item)) {
            return null;
        }

        $rows = self::queue((int) $item->id);
        $mine = self::matching($rows, $me, $me);

        if ($mine) {
            return ['entries' => $mine, 'behalf' => 0];
        }

        foreach (Delegates::delegatorsOf($me, (int) $item->form_id) as $d) {
            if ($hit = self::matching($rows, $d, $me)) {
                return ['entries' => $hit, 'behalf' => $d];
            }
        }

        return null;
    }

    public static function canAct(object $item, ?User $user = null): bool
    {
        return self::acting($item, $user) !== null;
    }

    /** Entries in these queue rows that $who matches, with $actor not excluded. */
    private static function matching(array $rows, int $who, int $actor): array
    {
        $groups = null;
        $out    = [];

        foreach ($rows as $r) {
            if ((int) $r->exclude_user > 0 && \in_array((int) $r->exclude_user, [$who, $actor], true)) {
                continue;
            }

            if ($r->kind === 'user' && (int) $r->ref_id === $who) {
                $out[] = (string) $r->entry;
            } elseif ($r->kind === 'group') {
                $groups ??= Users::groupsOf($who);

                if (\in_array((int) $r->ref_id, $groups, true)) {
                    $out[] = (string) $r->entry;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * People who can act on the current step now (for the detail page and
     * notifications): accounts matching an entry that have Approve
     * Submissions, plus active delegates of anyone matching an entry.
     *
     * @return list<object>  {id, name, username, email, behalf: int}
     */
    public static function eligible(object $item): array
    {
        if ((string) $item->status !== 'pending') {
            return [];
        }

        $form   = (int) $item->form_id;
        $by     = [];
        $people = [];

        foreach (self::queue((int) $item->id) as $r) {
            $list = $r->kind === 'group' ? Users::inGroups([(int) $r->ref_id]) : Users::many([(int) $r->ref_id]);

            foreach ($list as $u) {
                if ((int) $r->exclude_user !== $u->id) {
                    $by[$u->id] = $u;
                }
            }
        }

        $exclude = 0;

        foreach (self::queue((int) $item->id) as $r) {
            $exclude = max($exclude, (int) $r->exclude_user);
        }

        foreach ($by as $u) {
            if (Users::can($u->id, 'squidform.approve', $form)) {
                $people[$u->id] = (object) ((array) $u + ['behalf' => 0]);
            }

            foreach (Delegates::delegatesOf($u->id, $form) as $d) {
                if (!isset($people[$d]) && $d !== $exclude && Users::can($d, 'squidform.approve', $form) && ($du = Users::get($d))) {
                    $people[$d] = (object) ['id' => $d, 'name' => $du->name, 'username' => $du->username, 'email' => $du->email, 'behalf' => $u->id];
                }
            }
        }

        uasort($people, static fn ($a, $b) => strcasecmp($a->name, $b->name));

        return array_values($people);
    }

    /**
     * Has this user approved, or been able to approve, this submission? Lets
     * approvers open a request without View Submissions.
     */
    public static function involved(object $item, ?User $user = null): bool
    {
        $user ??= Users::current();
        $me   = (int) $user->id;

        if ($me <= 0 || !Acl::can('squidform.approve', (int) $item->form_id, $user)) {
            return false;
        }

        if (self::canAct($item, $user)) {
            return true;
        }

        $db = SubmissionStore::db();
        $id = (int) $item->id;

        return (bool) $db->setQuery(
            $db->createQuery()->select('1')->from($db->quoteName('#__squidform_approvals'))
                ->where('submission_id = :sid')->where('(user_id = :me OR assignee_id = :me2)')
                ->bind(':sid', $id, ParameterType::INTEGER)->bind(':me', $me, ParameterType::INTEGER)->bind(':me2', $me, ParameterType::INTEGER),
            0,
            1
        )->loadResult();
    }

    /** May reassign the current step: someone who can act now, or a Super User / component admin. */
    public static function canReassign(object $item, ?User $user = null): bool
    {
        $user ??= Users::current();

        if ((string) $item->status !== 'pending' || (int) $item->archived) {
            return false;
        }

        return self::canAct($item, $user) || (bool) $user->authorise('core.admin', 'com_squidform');
    }

    public static function canWithdraw(object $item, ?User $user = null): bool
    {
        $user ??= Users::current();
        $flow = self::flow($item);

        return $flow && $flow['allow_withdraw'] && !(int) $item->archived
            && \in_array((string) $item->status, ['pending', 'returned'], true) && Acl::owns($item, $user)
            && Acl::canViewSubmission($item, $user);
    }

    public static function canResubmit(object $item, ?User $user = null): bool
    {
        $user ??= Users::current();

        return (string) $item->status === 'returned' && !(int) $item->archived && Acl::owns($item, $user)
            && Acl::canViewSubmission($item, $user) && self::flow($item) !== null;
    }

    /**
     * Restrict "awaiting my approval" on a query over #__squidform_submissions
     * aliased $s: pending, not archived, the user has Approve Submissions on
     * the form, and the current step's queue matches the user or someone the
     * user is a delegate for.
     */
    public static function whereAwaiting(DatabaseQuery $q, ?User $user = null, string $s = 's'): void
    {
        $user  ??= Users::current();
        $db    = SubmissionStore::db();
        $me    = (int) $user->id;
        // Requests of archived forms wait until the form is restored.
        $forms = $me > 0 ? array_values(array_diff(Acl::forms('squidform.approve', $user), self::archivedForms())) : [];

        if (!$forms) {
            $q->where('1 = 0');

            return;
        }

        $match = static function (int $who, array $notIn) use ($db): string {
            $groups = Users::groupsOf($who) ?: [0];

            return '(' . 'aq.exclude_user NOT IN (' . implode(',', $notIn) . ')'
                . ' AND ((aq.kind = ' . $db->quote('user') . ' AND aq.ref_id = ' . $who . ')'
                . ' OR (aq.kind = ' . $db->quote('group') . ' AND aq.ref_id IN (' . implode(',', $groups) . '))))';
        };

        $or = [$match($me, [$me])];

        foreach (Delegates::delegations($me) as $d) {
            $or[] = '(' . ((int) $d->form_id ? 'aq.form_id = ' . (int) $d->form_id . ' AND ' : '') . $match((int) $d->user_id, [$me, (int) $d->user_id]) . ')';
        }

        $q->where($db->quoteName($s . '.status') . ' = ' . $db->quote('pending'))
            ->where($db->quoteName($s . '.archived') . ' = 0')
            ->whereIn($db->quoteName($s . '.form_id'), $forms)
            ->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__squidform_approval_queue', 'aq')
                . ' WHERE aq.submission_id = ' . $db->quoteName($s . '.id') . ' AND (' . implode(' OR ', $or) . '))');
    }

    /** @return int[] ids of archived forms */
    private static function archivedForms(): array
    {
        static $ids = null;

        if ($ids === null) {
            $db  = SubmissionStore::db();
            $ids = array_map('intval', $db->setQuery(
                $db->createQuery()->select('id')->from($db->quoteName('#__squidform_forms'))->where('state = 2')
            )->loadColumn());
        }

        return $ids;
    }

    public static function awaitingCount(?User $user = null): int
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_submissions', 's'));
        self::whereAwaiting($q, $user);

        return (int) $db->setQuery($q)->loadResult();
    }

    // ================================================================ changes

    /**
     * Start the chain for a new submission. Runs inside Save Data's
     * transaction. Returns after-commit jobs (notifications).
     *
     * @param  array<string, mixed>  $data
     *
     * @return list<callable(): void>
     */
    public static function start(int $submissionId, int $formId, Schema $schema, array $data, int $submitterId): array
    {
        $flow = ApprovalFlow::of($schema);

        if (!$flow) {
            return [];
        }

        $now = Factory::getDate()->toSql();
        self::log($submissionId, $formId, 0, '', 'start', $submitterId, '', $now);
        $step = self::nextStep($flow, $schema, $data, 0, $submissionId, $formId, 0, $now);
        $item = (object) ['id' => $submissionId, 'form_id' => $formId, 'user_id' => $submitterId, 'approval_round' => 0];

        self::setState($submissionId, $step === '' ? 'approved' : 'pending', $step, 0, $now);
        self::rebuildQueue($item, $flow, $schema, $data, $step);

        if ($step === '') {
            return [];
        }

        return [static fn () => ApprovalMail::toApprovers($submissionId)];
    }

    /**
     * Approve, reject or return the current step.
     *
     * @param  string  $seenStep   step key the person saw (stale page check)
     * @param  int     $seenRound  round the person saw
     *
     * @return string|null  error message, or null when done
     */
    public static function decide(int $id, string $decision, string $comment, string $seenStep, int $seenRound, ?User $user = null): ?string
    {
        $user    ??= Users::current();
        $comment = trim(mb_substr($comment, 0, 4000));

        if (!\in_array($decision, ['approve', 'reject', 'return'], true)) {
            return Text::_('COM_SQUIDFORM_APPROVAL_ERR_DECISION');
        }

        if ($decision !== 'approve' && $comment === '') {
            return Text::_('COM_SQUIDFORM_APPROVAL_ERR_COMMENT');
        }

        return self::change($id, $user, $seenStep, $seenRound, function (object $item, array $flow, Schema $schema) use ($decision, $comment, $user): array {
            $acting = self::acting($item, $user);

            if ($acting === null) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_NOT_YOURS'));
            }

            if ($decision === 'return' && !$flow['allow_return']) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_NO_RETURN'));
            }

            $id    = (int) $item->id;
            $form  = (int) $item->form_id;
            $round = (int) $item->approval_round;
            $step  = (string) $item->current_step;
            $now   = Factory::getDate()->toSql();
            $me    = (int) $user->id;

            self::log($id, $form, $round, $step, $decision, $me, $comment, $now, $acting['behalf'], 0, $acting['entries']);
            Audit::log($id, $form, $me, $decision);

            $jobs  = [];
            $final = null;

            if ($decision === 'approve') {
                $def  = ApprovalFlow::step($flow, $step);
                $done = $def['rule'] === 'any' || self::reassigned($id, $round, $step);

                if (!$done) {
                    // Rule "all": the step is finished when no entry is left to approve.
                    self::rebuildQueue($item, $flow, $schema, $item->data, $step);
                    $done = self::queue($id) === [];
                }

                if ($done) {
                    $next = self::nextStep($flow, $schema, $item->data, ApprovalFlow::stepIndex($flow, $step) + 1, $id, $form, $round, $now);
                    self::setState($id, $next === '' ? 'approved' : 'pending', $next, $round, $now);
                    self::rebuildQueue($item, $flow, $schema, $item->data, $next);

                    if ($next === '') {
                        $final  = 'approved';
                        $jobs[] = static fn () => ApprovalMail::toSubmitter($id, 'approved', $comment, $step);
                    } else {
                        $jobs[] = static fn () => ApprovalMail::toApprovers($id);
                    }
                }
            } else {
                $status = $decision === 'reject' ? 'rejected' : 'returned';
                self::setState($id, $status, $step, $round, $now);
                self::clearQueue($id);
                $final  = $status;
                $jobs[] = static fn () => ApprovalMail::toSubmitter($id, $status, $comment, $step);
            }

            if ($final !== null) {
                $hook = ['approved' => 'on_approve', 'rejected' => 'on_reject', 'returned' => 'on_return'][$final];
                $jobs = array_merge($jobs, self::runHook($hook, $item, $schema, $user, [
                    'decision'   => $final,
                    'step'       => $step,
                    'step_title' => (string) (ApprovalFlow::step($flow, $step)['title'] ?? $step),
                    'comment'    => $comment,
                    'by'         => (string) $user->name,
                    'by_email'   => (string) $user->email,
                ]));
            }

            return $jobs;
        });
    }

    /** Hand the current step to one person. */
    public static function reassign(int $id, int $target, string $comment, string $seenStep, int $seenRound, ?User $user = null): ?string
    {
        $user    ??= Users::current();
        $comment = trim(mb_substr($comment, 0, 4000));

        return self::change($id, $user, $seenStep, $seenRound, function (object $item, array $flow, Schema $schema) use ($target, $comment, $user): array {
            if (!self::canReassign($item, $user)) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_NOT_YOURS'));
            }

            $form = (int) $item->form_id;

            if (!Users::can($target, 'squidform.approve', $form)) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_TARGET'));
            }

            if (!$flow['allow_self_approval'] && $target === (int) $item->user_id) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_TARGET_SELF'));
            }

            $id    = (int) $item->id;
            $round = (int) $item->approval_round;
            $step  = (string) $item->current_step;
            $now   = Factory::getDate()->toSql();
            $acting = self::acting($item, $user);

            self::log($id, $form, $round, $step, 'reassign', (int) $user->id, $comment, $now, (int) ($acting['behalf'] ?? 0), $target);
            Audit::log($id, $form, (int) $user->id, 'reassign');
            self::setState($id, 'pending', $step, $round, $now);
            self::rebuildQueue($item, $flow, $schema, $item->data, $step);

            return [static fn () => ApprovalMail::toApprovers($id, $comment)];
        });
    }

    /** The submitter withdraws an undecided request: it is closed and archived. */
    public static function withdraw(int $id, ?User $user = null): ?string
    {
        $user ??= Users::current();

        return self::change($id, $user, null, null, function (object $item, array $flow, Schema $schema) use ($user): array {
            if (!self::canWithdraw($item, $user)) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_WITHDRAW'));
            }

            $id  = (int) $item->id;
            $now = Factory::getDate()->toSql();
            self::log($id, (int) $item->form_id, (int) $item->approval_round, (string) $item->current_step, 'withdraw', (int) $user->id, '', $now);
            Audit::log($id, (int) $item->form_id, (int) $user->id, 'withdraw');
            self::setState($id, 'withdrawn', (string) $item->current_step, (int) $item->approval_round, $now);
            self::clearQueue($id);

            $db = SubmissionStore::db();
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_submissions'))->set('archived = 1')
                    ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
            )->execute();

            return [];
        });
    }

    /** The submitter sends a returned request back, without changing it. */
    public static function resubmit(int $id, ?User $user = null): ?string
    {
        $user ??= Users::current();

        return self::change($id, $user, null, null, function (object $item, array $flow, Schema $schema) use ($user): array {
            if (!self::canResubmit($item, $user)) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_RESUBMIT'));
            }

            return self::resubmitInTx($item, $flow, $schema, $item->data, $user);
        });
    }

    /**
     * After an edit is written (inside SaveEdit's transaction):
     *  - the submitter edited a pending request → restart from the first step
     *  - the submitter edited a returned request → resubmit
     *  - anyone else edited a pending request → rebuild the queue (a user
     *    field naming the approver may have changed)
     *
     * @param  array<string, mixed>  $data  the new answers
     *
     * @return list<callable(): void>
     */
    public static function afterEdit(object $item, array $data, User $user): array
    {
        $flow   = self::flow($item);
        $schema = $item->schema;

        if (!$flow || !$schema instanceof Schema || !\in_array((string) $item->status, ['pending', 'returned'], true) || (int) $item->archived) {
            return [];
        }

        self::lockRow((int) $item->id);
        $fresh = self::row((int) $item->id);
        $item  = (object) array_merge((array) $item, (array) $fresh);

        if (Acl::owns($item, $user)) {
            if ((string) $item->status === 'returned') {
                return self::resubmitInTx($item, $flow, $schema, $data, $user);
            }

            $id    = (int) $item->id;
            $form  = (int) $item->form_id;
            $round = (int) $item->approval_round + 1;
            $now   = Factory::getDate()->toSql();

            self::log($id, $form, $round, '', 'restart', (int) $user->id, '', $now);
            Audit::log($id, $form, (int) $user->id, 'restart');
            $step = self::nextStep($flow, $schema, $data, 0, $id, $form, $round, $now);
            self::setState($id, $step === '' ? 'approved' : 'pending', $step, $round, $now);
            $item->approval_round = $round;
            self::rebuildQueue($item, $flow, $schema, $data, $step);

            return $step === '' ? [] : [static fn () => ApprovalMail::toApprovers($id)];
        }

        if ((string) $item->status === 'pending') {
            self::rebuildQueue($item, $flow, $schema, $data, (string) $item->current_step);
        }

        return [];
    }

    /**
     * Notice for the edit screen when saving will move the request, or null.
     */
    public static function editNotice(object $item, ?User $user = null): ?string
    {
        $user ??= Users::current();

        if (!self::flow($item) || !Acl::owns($item, $user) || (int) $item->archived) {
            return null;
        }

        return match ((string) $item->status) {
            'pending'  => Text::_('COM_SQUIDFORM_APPROVAL_EDIT_RESTARTS'),
            'returned' => Text::_('COM_SQUIDFORM_APPROVAL_EDIT_RESUBMITS'),
            default    => null,
        };
    }

    // ================================================================ internals

    /** @return list<callable(): void> */
    private static function resubmitInTx(object $item, array $flow, Schema $schema, array $data, User $user): array
    {
        $id    = (int) $item->id;
        $form  = (int) $item->form_id;
        $round = (int) $item->approval_round;
        $now   = Factory::getDate()->toSql();
        $from  = max(0, ApprovalFlow::stepIndex($flow, (string) $item->current_step));

        self::log($id, $form, $round, (string) $item->current_step, 'resubmit', (int) $user->id, '', $now);
        Audit::log($id, $form, (int) $user->id, 'resubmit');
        $step = self::nextStep($flow, $schema, $data, $from, $id, $form, $round, $now);
        self::setState($id, $step === '' ? 'approved' : 'pending', $step, $round, $now);
        self::rebuildQueue($item, $flow, $schema, $data, $step);

        return $step === '' ? [] : [static fn () => ApprovalMail::toApprovers($id)];
    }

    /**
     * Run a change in a transaction with the submission row locked, after
     * checking the person is looking at the current state. $work returns
     * after-commit jobs, run once the transaction is committed.
     */
    private static function change(int $id, User $user, ?string $seenStep, ?int $seenRound, callable $work): ?string
    {
        $db   = SubmissionStore::db();
        $jobs = [];

        try {
            $db->transactionStart();
            self::lockRow($id);
            $item = SubmissionService::load($id);

            // Someone who may not see the request gets the same answer as for one that doesn't exist.
            if (!$item || !$item->schema instanceof Schema || !($flow = ApprovalFlow::of($item->schema)) || !Acl::canViewSubmission($item, $user)) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_SUBMISSION_NOT_FOUND'));
            }

            if ($seenStep !== null && ((string) $item->current_step !== $seenStep || (int) $item->approval_round !== (int) $seenRound)) {
                throw new ApprovalException(Text::_('COM_SQUIDFORM_APPROVAL_ERR_STALE'));
            }

            $jobs = $work($item, $flow, $item->schema);
            $db->transactionCommit();
        } catch (ApprovalException $e) {
            self::rollback();

            return $e->getMessage();
        } catch (\Throwable $e) {
            self::rollback();
            Log::add('SquidForm approval change on submission ' . $id . ' failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), Log::ERROR, 'squidform');

            return Text::_('COM_SQUIDFORM_ERR_SAVE');
        }

        self::runJobs($jobs);

        return null;
    }

    /** @param list<callable(): void> $jobs */
    public static function runJobs(array $jobs): void
    {
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (\Throwable $e) {
                Log::add('SquidForm approval after-commit job failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
            }
        }
    }

    private static function rollback(): void
    {
        try {
            SubmissionStore::db()->transactionRollback();
        } catch (\Throwable) {
        }
    }

    private static function lockRow(int $id): void
    {
        $db = SubmissionStore::db();
        $db->setQuery('SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__squidform_submissions') . ' WHERE ' . $db->quoteName('id') . ' = ' . $id . ' FOR UPDATE')->loadResult();
    }

    private static function row(int $id): ?object
    {
        $db = SubmissionStore::db();

        return $db->setQuery(
            $db->createQuery()->select(['status', 'current_step', 'approval_round', 'archived'])->from($db->quoteName('#__squidform_submissions'))
                ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject() ?: null;
    }

    /**
     * First step from index $from whose condition holds; steps passed over
     * are recorded as "skip". '' when no step is left.
     */
    private static function nextStep(array $flow, Schema $schema, array $data, int $from, int $id, int $form, int $round, string $now): string
    {
        // A step's user-data rule (0.14.0) is about the person who sent it, not the approver.
        $db  = SubmissionStore::db();
        $who = (int) $db->setQuery($db->createQuery()->select('user_id')->from($db->quoteName('#__squidform_submissions'))
            ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER))->loadResult();

        for ($i = $from; $i < \count($flow['steps']); $i++) {
            $s = $flow['steps'][$i];

            if (Conditions::asUser($who, static fn () => ApprovalFlow::applies($s, $schema, $data))) {
                return $s['key'];
            }

            self::log($id, $form, $round, $s['key'], 'skip', 0, '', $now);
        }

        return '';
    }

    private static function setState(int $id, string $status, string $step, int $round, string $now): void
    {
        $db    = SubmissionStore::db();
        $since = $status === 'pending' ? $now : null;
        $q     = $db->createQuery()->update($db->quoteName('#__squidform_submissions'))
            ->set('status = :status')->set('current_step = :step')->set('approval_round = :round')
            ->where('id = :id')
            ->bind(':status', $status)->bind(':step', $step)->bind(':round', $round, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER);

        $since !== null ? $q->set('step_since = :since')->bind(':since', $since) : $q->set('step_since = NULL');
        $db->setQuery($q)->execute();
    }

    private static function clearQueue(int $id): void
    {
        $db = SubmissionStore::db();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_approval_queue'))->where('submission_id = ' . $id))->execute();
    }

    /** Entries of a step already approved in this round since its last reset. */
    private static function satisfied(int $id, int $round, string $step): array
    {
        $out = [];

        foreach (self::sinceReset($id, $round, $step) as $r) {
            if ($r->decision === 'approve') {
                $out = array_merge($out, json_decode((string) $r->entries, true) ?: []);
            }
        }

        return array_values(array_unique($out));
    }

    /** The assignee, when the latest reset of the step is a reassignment. */
    private static function reassigned(int $id, int $round, string $step): int
    {
        $rows = self::stepRows($id, $round, $step);

        for ($i = \count($rows) - 1; $i >= 0; $i--) {
            if (\in_array($rows[$i]->decision, self::RESETS, true)) {
                return $rows[$i]->decision === 'reassign' ? (int) $rows[$i]->assignee_id : 0;
            }
        }

        return 0;
    }

    /** @return list<object> */
    private static function sinceReset(int $id, int $round, string $step): array
    {
        $rows = self::stepRows($id, $round, $step);
        $out  = [];

        for ($i = \count($rows) - 1; $i >= 0; $i--) {
            if (\in_array($rows[$i]->decision, self::RESETS, true)) {
                break;
            }

            array_unshift($out, $rows[$i]);
        }

        return $out;
    }

    /** @return list<object> */
    private static function stepRows(int $id, int $round, string $step): array
    {
        $db = SubmissionStore::db();

        return $db->setQuery(
            $db->createQuery()->select(['id', 'decision', 'entries', 'assignee_id'])->from($db->quoteName('#__squidform_approvals'))
                ->where('submission_id = :sid')->where('round = :r')->where('(step_key = :step OR step_key = ' . $db->quote('') . ')')
                ->bind(':sid', $id, ParameterType::INTEGER)->bind(':r', $round, ParameterType::INTEGER)->bind(':step', $step)
                ->order('id')
        )->loadObjectList();
    }

    /** Rebuild who may act on $step ('' = nobody). */
    private static function rebuildQueue(object $item, array $flow, Schema $schema, array $data, string $step): void
    {
        $id = (int) $item->id;
        self::clearQueue($id);

        if ($step === '') {
            return;
        }

        $def     = ApprovalFlow::step($flow, $step);
        $round   = (int) $item->approval_round;
        $exclude = !$flow['allow_self_approval'] ? (int) $item->user_id : 0;
        $rows    = [];

        if ($to = self::reassigned($id, $round, $step)) {
            $rows[] = ['user', $to, 'user:' . $to];
        } else {
            $done = $def['rule'] === 'all' ? self::satisfied($id, $round, $step) : [];

            foreach ($def['approvers'] as $entry) {
                if (\in_array($entry, $done, true)) {
                    continue;
                }

                [$kind, $ref] = explode(':', $entry, 2);

                if ($kind === 'group' && ($gid = Users::groupId($ref)) !== null) {
                    $rows[] = ['group', $gid, $entry];
                } elseif ($kind === 'user' && ($uid = Users::userId($ref)) !== null) {
                    $rows[] = ['user', $uid, $entry];
                } elseif ($kind === 'field' && (int) ($data[$ref] ?? 0) > 0) {
                    $rows[] = ['user', (int) $data[$ref], $entry];
                }
            }
        }

        $db = SubmissionStore::db();

        foreach ($rows as [$kind, $ref, $entry]) {
            $row = (object) [
                'submission_id' => $id,
                'form_id'       => (int) $item->form_id,
                'step_key'      => $step,
                'kind'          => $kind,
                'ref_id'        => (int) $ref,
                'entry'         => mb_substr($entry, 0, 255),
                'exclude_user'  => $exclude,
            ];
            $db->insertObject('#__squidform_approval_queue', $row);
        }
    }

    /** @param string[] $entries */
    private static function log(int $id, int $form, int $round, string $step, string $decision, int $user, string $comment, string $now, int $behalf = 0, int $assignee = 0, array $entries = []): void
    {
        $row = (object) [
            'submission_id' => $id,
            'form_id'       => $form,
            'round'         => $round,
            'step_key'      => $step,
            'decision'      => $decision,
            'comment'       => $comment !== '' ? $comment : null,
            'user_id'       => $user,
            'on_behalf_of'  => $behalf,
            'assignee_id'   => $assignee,
            'entries'       => $entries ? mb_substr(json_encode(array_values($entries), JSON_UNESCAPED_UNICODE), 0, 2048) : '',
            'created'       => $now,
        ];
        SubmissionStore::db()->insertObject('#__squidform_approvals', $row);
    }

    /**
     * Run a form's on_approve / on_reject / on_return actions (email). They run
     * now, inside the transaction; mail goes out after commit.
     *
     * @param  array<string, string>  $approval
     *
     * @return list<callable(): void>
     */
    private static function runHook(string $hook, object $item, Schema $schema, User $user, array $approval): array
    {
        $actions = $schema->toArray()[$hook] ?? [];

        if (!$actions) {
            return [];
        }

        $ctx                = self::hookContext($item, $schema, $user);
        $ctx->meta          = ['approval' => $approval, 'submitter' => self::submitterMeta((int) $item->user_id)];
        Runner::run($ctx, $actions);

        return $ctx->afterCommit;
    }

    /** A Context for running form-level hooks against a stored submission. */
    public static function hookContext(object $item, Schema $schema, User $user): Context
    {
        $form = ['id' => (int) $item->form_id, 'alias' => (string) $item->form_alias, 'title' => (string) $item->form_title];
        $ctx  = new Context($schema, $form, (int) $item->version_id, $user, (string) $item->uid, $schema->pageCount() - 1);
        $ctx->data          = (array) $item->data;
        $ctx->submissionId  = (int) $item->id;
        $ctx->submissionUid = (string) $item->uid;
        $ctx->meta          = ['submitter' => self::submitterMeta((int) $item->user_id)];

        return $ctx;
    }

    /** @return array<string, string> */
    public static function submitterMeta(int $userId): array
    {
        $u = Users::get($userId);

        return ['id' => (string) $userId, 'name' => (string) ($u->name ?? ''), 'username' => (string) ($u->username ?? ''), 'email' => (string) ($u->email ?? '')];
    }
}
