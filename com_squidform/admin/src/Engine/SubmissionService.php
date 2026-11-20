<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Operations on one stored submission, shared by the admin screens and the
 * front-end viewer: load, history, view logging, soft delete/restore, the
 * edit lock and saving an edit. Permission checks are per form (Acl).
 */
final class SubmissionService
{
    /**
     * @return object|null  row + data (array), schema (Schema|null), form_title, form_alias,
     *                      user_name, version_no, editor_name
     */
    public static function load(int $id): ?object
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select([
                's.*', $db->quoteName('f.title', 'form_title'), $db->quoteName('f.alias', 'form_alias'),
                $db->quoteName('u.name', 'user_name'), 'v.version_no', $db->quoteName('e.name', 'editor_name'),
                $db->quoteName('m.name', 'modified_by_name'),
            ])
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->join('LEFT', $db->quoteName('#__squidform_forms', 'f'), 'f.id = s.form_id')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = s.user_id')
            ->join('LEFT', $db->quoteName('#__users', 'e'), 'e.id = s.checked_out')
            ->join('LEFT', $db->quoteName('#__users', 'm'), 'm.id = s.modified_by')
            ->join('LEFT', $db->quoteName('#__squidform_form_versions', 'v'), 'v.id = s.version_id')
            ->where('s.id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $item = $db->setQuery($q)->loadObject();

        if (!$item) {
            return null;
        }

        $item->schema = FormRepository::version((int) $item->version_id);
        $item->data   = FormData::forForm((int) $item->form_id)->read((int) $item->id, $item->schema);

        return $item;
    }

    /**
     * History rows (everything except views), oldest first, with decoded diffs.
     *
     * @return list<object>  action, created, user_name, diff (array|null)
     */
    public static function history(int $id, ?int $formId = null): array
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select(['a.action', 'a.created', 'a.diff_json', $db->quoteName('u.name', 'user_name')])
            ->from($db->quoteName('#__squidform_audit', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
            ->where('a.submission_id = :sid')
            ->where('a.action <> ' . $db->quote('view'))
            ->order('a.id')
            ->bind(':sid', $id, ParameterType::INTEGER);

        $rows = $db->setQuery($q)->loadObjectList();

        // 0.13.0: exports that included this submission (an export row lists its ids).
        if ($formId) {
            $num = (string) $id;
            $str = json_encode((string) $id);
            $eq  = $db->createQuery()
                ->select(['a.action', 'a.created', 'a.diff_json', $db->quoteName('u.name', 'user_name')])
                ->from($db->quoteName('#__squidform_audit', 'a'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
                ->where('a.form_id = :fid')
                ->where('a.action = ' . $db->quote('export'))
                ->where("(JSON_CONTAINS(a.diff_json, :num, '$.ids') OR JSON_CONTAINS(a.diff_json, :str, '$.ids'))")
                ->bind(':fid', $formId, ParameterType::INTEGER)
                ->bind(':num', $num)
                ->bind(':str', $str);

            try {
                foreach ($db->setQuery($eq)->loadObjectList() as $r) {
                    $rows[] = $r;
                }
            } catch (\Throwable) {
                // An old row that isn't JSON: leave the exports out rather than the History.
            }
        }

        // Edits (with their old → new values) live in the form's own history table.
        if ($formId) {
            foreach (FormData::forForm($formId)->history($id) as $h) {
                $rows[] = $h;
            }

            usort($rows, static fn ($a, $b) => strcmp((string) $a->created, (string) $b->created));
        }

        foreach ($rows as $r) {
            $r->diff = !empty($r->diff_json) ? json_decode((string) $r->diff_json, true) : null;
        }

        return $rows;
    }

    /** @return object{count:int, last_name:?string, last_time:?string} */
    public static function viewSummary(int $id): object
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select(['COUNT(*) AS n', 'MAX(a.id) AS last_id'])
            ->from($db->quoteName('#__squidform_audit', 'a'))
            ->where('a.submission_id = :sid')
            ->where('a.action = ' . $db->quote('view'))
            ->bind(':sid', $id, ParameterType::INTEGER);
        $agg = $db->setQuery($q)->loadObject();

        $out = (object) ['count' => (int) ($agg->n ?? 0), 'last_name' => null, 'last_time' => null];

        if ($out->count) {
            $lid  = (int) $agg->last_id;
            $last = $db->setQuery(
                $db->createQuery()->select(['a.created', $db->quoteName('u.name', 'name')])
                    ->from($db->quoteName('#__squidform_audit', 'a'))
                    ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
                    ->where('a.id = :id')->bind(':id', $lid, ParameterType::INTEGER)
            )->loadObject();
            $out->last_name = $last->name ?? null;
            $out->last_time = $last->created ?? null;
        }

        return $out;
    }

    public static function logView(object $item, ?User $user = null): void
    {
        $user ??= Factory::getApplication()->getIdentity();
        Audit::log((int) $item->id, (int) $item->form_id, (int) $user->id, 'view');
    }

    /**
     * Archive (flag 1) or restore (flag 0). The record is always kept. Each id
     * is checked against its form's Archive permission (squidform.delete);
     * refused ids are skipped.
     *
     * @param   int[]  $ids
     *
     * @return  int  number changed
     */
    public static function setArchived(array $ids, int $flag, ?User $user = null): int
    {
        $user ??= Factory::getApplication()->getIdentity();
        $db   = SubmissionStore::db();
        $n    = 0;
        $uid  = (int) $user->id;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $form = (int) $db->setQuery(
                $db->createQuery()->select('form_id')->from($db->quoteName('#__squidform_submissions'))
                    ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
            )->loadResult();

            if (!$form || !Acl::can('squidform.delete', $form, $user) || !Acl::can('squidform.view', $form, $user)) {
                continue;
            }

            $now = Factory::getDate()->toSql();
            $q   = $db->createQuery()
                ->update($db->quoteName('#__squidform_submissions'))
                ->set('archived = :flag')
                ->set('modified = :now')
                ->set('modified_by = :uid')
                ->where('id = :id')
                ->where('archived <> :flag2')
                ->bind(':flag', $flag, ParameterType::INTEGER)
                ->bind(':flag2', $flag, ParameterType::INTEGER)
                ->bind(':now', $now)
                ->bind(':uid', $uid, ParameterType::INTEGER)
                ->bind(':id', $id, ParameterType::INTEGER);
            $db->setQuery($q)->execute();

            if ($db->getAffectedRows() > 0) {
                Audit::log($id, $form, $uid, $flag ? 'archive' : 'restore');
                $n++;
            }
        }

        return $n;
    }

    /** User-state key holding a failed edit's entries and errors. */
    public static function editStateKey(int $id): string
    {
        return 'com_squidform.edit.submission.' . $id;
    }

    // ------------------------------------------------------------ edit lock

    /** Minutes an edit lock lasts without a save (the Joomla session lifetime, at least 15). */
    public static function lockMinutes(): int
    {
        return max(15, (int) Factory::getApplication()->get('lifetime', 15));
    }

    /**
     * Take the edit lock. Returns null when the lock is ours, or a message
     * naming who holds it.
     */
    public static function lock(object $item, ?User $user = null): ?string
    {
        $user ??= Factory::getApplication()->getIdentity();
        $me   = (int) $user->id;

        if (self::heldByOther($item, $me)) {
            return Text::sprintf(
                'COM_SQUIDFORM_EDIT_LOCKED',
                (string) ($item->editor_name ?: ('#' . (int) $item->checked_out)),
                \Joomla\CMS\HTML\HTMLHelper::_('date', $item->checked_out_time, Text::_('DATE_FORMAT_LC2'))
            );
        }

        $db  = SubmissionStore::db();
        $now = Factory::getDate()->toSql();
        $id  = (int) $item->id;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__squidform_submissions'))
                ->set('checked_out = :me')->set('checked_out_time = :now')
                ->where('id = :id')
                ->bind(':me', $me, ParameterType::INTEGER)->bind(':now', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return null;
    }

    /**
     * The lock someone else holds now, or null (none, it's ours, or it has
     * run out): {by, since} (0.10.2).
     *
     * @return array{by: string, since: string}|null
     */
    public static function activeLock(object $item, ?User $user = null): ?array
    {
        $user ??= Factory::getApplication()->getIdentity();

        if (!self::heldByOther($item, (int) $user->id)) {
            return null;
        }

        return [
            'by'    => (string) ($item->editor_name ?? '') !== '' ? (string) $item->editor_name : '#' . (int) $item->checked_out,
            'since' => (string) $item->checked_out_time,
        ];
    }

    /**
     * Who may check a submission in (0.10.2): the person holding the lock,
     * and managers (Joomla's Global Check-in, or Manage on SquidForm).
     */
    public static function canCheckin(object $item, ?User $user = null): bool
    {
        $user ??= Factory::getApplication()->getIdentity();

        if ((int) ($item->checked_out ?? 0) === 0 || (int) $user->id === 0) {
            return false;
        }

        return (int) $item->checked_out === (int) $user->id
            || $user->authorise('core.manage', 'com_checkin')
            || $user->authorise('core.manage', 'com_squidform');
    }

    /**
     * Check submissions in (0.10.2): clear their edit locks, each one only if
     * this person may, and write it to the audit log. Returns how many.
     *
     * @param int[] $ids
     */
    public static function checkin(array $ids, ?User $user = null): int
    {
        $user ??= Factory::getApplication()->getIdentity();
        $db   = SubmissionStore::db();
        $n    = 0;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $item = self::load($id);

            if (!$item || !Acl::canViewSubmission($item, $user) || !self::canCheckin($item, $user)) {
                continue;
            }

            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_submissions'))
                    ->set('checked_out = NULL')->set('checked_out_time = NULL')
                    ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
            )->execute();

            Audit::log($id, (int) $item->form_id, (int) $user->id, 'checkin');
            $n++;
        }

        return $n;
    }

    /** Release our lock (never someone else's). */
    public static function unlock(int $id, ?User $user = null): void
    {
        $user ??= Factory::getApplication()->getIdentity();
        $me   = (int) $user->id;
        $db   = SubmissionStore::db();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__squidform_submissions'))
                ->set('checked_out = NULL')->set('checked_out_time = NULL')
                ->where('id = :id')->where('checked_out = :me')
                ->bind(':id', $id, ParameterType::INTEGER)->bind(':me', $me, ParameterType::INTEGER)
        )->execute();
    }

    public static function heldByOther(object $item, int $me): bool
    {
        $holder = (int) ($item->checked_out ?? 0);

        if ($holder === 0 || $holder === $me || empty($item->checked_out_time)) {
            return false;
        }

        $age = time() - strtotime($item->checked_out_time . ' UTC');

        return $age < self::lockMinutes() * 60;
    }

    // ----------------------------------------------------------------- edit

    /**
     * Validate and save an edit.
     *
     * @param   array<string, mixed>  $input   the posted sf[...] array
     * @param   string                $seen    the modified time the editor started from
     *
     * @return  array{errors: array<string, string>, data: array<string, mixed>, diff: array<string, mixed>}
     */
    public static function saveEdit(object $item, array $input, string $seen, ?User $user = null): array
    {
        $user   ??= Factory::getApplication()->getIdentity();
        $schema = $item->schema;

        if (!$schema instanceof Schema) {
            return ['errors' => ['_form' => Text::_('COM_SQUIDFORM_VERSION_MISSING')], 'data' => $item->data, 'diff' => []];
        }

        $result = Editor::apply($item, $schema, $input, $user);

        if ($result['errors']) {
            return $result + ['diff' => []];
        }

        // Someone else saved, or took the lock, since this editor opened.
        if ((string) $item->modified !== $seen || self::heldByOther($item, (int) $user->id)) {
            return ['errors' => ['_form' => Text::_('COM_SQUIDFORM_EDIT_CONFLICT')], 'data' => $result['data'], 'diff' => []];
        }

        $diff = Editor::diff($item->data, $result['data']);

        if (!$diff) {
            if ((string) $item->status === 'returned' && Acl::owns($item, $user) && Approvals::flow($item)) {
                $err = self::resubmitUnchanged($item, $user);

                return ['errors' => $err ? ['_form' => $err] : [], 'data' => $result['data'], 'diff' => [], 'resubmitted' => $err === null];
            }

            self::unlock((int) $item->id, $user);

            return ['errors' => [], 'data' => $result['data'], 'diff' => []];
        }

        $db  = SubmissionStore::db();
        $id  = (int) $item->id;
        $uid = (int) $user->id;
        $now = Factory::getDate()->toSql();
        $fd  = FormData::forForm((int) $item->form_id);
        $jobs = [];

        try {
            // Columns for this version must exist before the transaction (DDL commits).
            $fd->ensure($schema, (string) $item->form_alias);

            $db->transactionStart();
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_submissions'))
                    ->set('modified = :now')->set('modified_by = :uid')
                    ->set('checked_out = NULL')->set('checked_out_time = NULL')
                    ->where('id = :id')->where('modified = :seen')
                    ->bind(':now', $now)->bind(':uid', $uid, ParameterType::INTEGER)
                    ->bind(':id', $id, ParameterType::INTEGER)->bind(':seen', $seen)
            )->execute();

            if ($db->getAffectedRows() !== 1) {
                throw new \RuntimeException('conflict');
            }

            $fd->write($id, $schema, $result['data']);
            $fd->historyAdd($id, $uid, 'edit', $diff, $now);

            // An edit voids an e-signature set to "void" (0.11.0); its signer can sign again.
            ESign::voidFor($item, $user, Text::_('COM_SQUIDFORM_ESIGN_VOID_EDIT'));
            $jobs = Approvals::afterEdit($item, $result['data'], $user);
            $jobs = array_merge($jobs, self::editHook($item, $schema, $result['data'], $user));
            $db->transactionCommit();
        } catch (\Throwable $e) {
            try {
                $db->transactionRollback();
            } catch (\Throwable) {
            }

            if ($e->getMessage() !== 'conflict') {
                \Joomla\CMS\Log\Log::add('SquidForm edit of submission ' . $id . ' failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'squidform');
            }

            return ['errors' => ['_form' => Text::_($e->getMessage() === 'conflict' ? 'COM_SQUIDFORM_EDIT_CONFLICT' : 'COM_SQUIDFORM_ERR_SAVE')], 'data' => $result['data'], 'diff' => []];
        }

        Approvals::runJobs($jobs);

        return ['errors' => [], 'data' => $result['data'], 'diff' => $diff];
    }

    /**
     * Save a returned request without changes, as a resubmission (the
     * submitter's "Save and resubmit" with nothing changed).
     */
    public static function resubmitUnchanged(object $item, ?User $user = null): ?string
    {
        self::unlock((int) $item->id, $user);

        return Approvals::resubmit((int) $item->id, $user);
    }

    /**
     * The form's on_edit actions (email), run inside the edit's transaction;
     * mail goes after commit.
     *
     * @return list<callable(): void>
     */
    private static function editHook(object $item, Schema $schema, array $data, User $user): array
    {
        $actions = $schema->toArray()['on_edit'] ?? [];

        if (!$actions) {
            return [];
        }

        $ctx       = Approvals::hookContext($item, $schema, $user);
        $ctx->data = $data;
        Runner::run($ctx, $actions);

        return $ctx->afterCommit;
    }

    // -------------------------------------------------------- true deletion

    /**
     * The given ids that may be permanently deleted now: archived, and the
     * user has Permanently Delete Submissions on their form.
     *
     * @param   int[]  $ids
     *
     * @return  int[]
     */
    public static function purgeEligible(array $ids, ?User $user = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $user ??= Factory::getApplication()->getIdentity();
        $db   = SubmissionStore::db();
        $rows = $db->setQuery(
            $db->createQuery()->select(['id', 'form_id'])->from($db->quoteName('#__squidform_submissions'))
                ->whereIn($db->quoteName('id'), $ids)->where('archived = 1')->order('id')
        )->loadObjectList();

        // Signed records (0.11.0) are kept for their retention period.
        $held = ESign::retainedIds(array_map(static fn ($r) => (int) $r->id, $rows));

        return array_values(array_map(static fn ($r) => (int) $r->id, array_filter($rows, static fn ($r) => !\in_array((int) $r->id, $held, true) && Acl::can('squidform.delete.hard', (int) $r->form_id, $user))));
    }

    /**
     * Permanently delete archived submissions: their answers, repeater rows
     * and edit history. Needs Permanently Delete Submissions on each one's
     * form; submissions that are not archived are skipped. One "purge" line
     * per form is left in the shared log (ids and count, no content).
     *
     * @param   int[]  $ids
     *
     * @return  int  number deleted
     */
    public static function purge(array $ids, ?User $user = null): int
    {
        $user ??= Factory::getApplication()->getIdentity();
        $db   = SubmissionStore::db();
        $done = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $row = $db->setQuery(
                $db->createQuery()->select(['form_id', 'archived', 'uid'])->from($db->quoteName('#__squidform_submissions'))
                    ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
            )->loadObject();

            if (!$row || !(int) $row->archived || !Acl::can('squidform.delete.hard', (int) $row->form_id, $user) || ESign::retained($id) !== null) {
                continue;
            }

            $form = (int) $row->form_id;

            try {
                // Uploaded files (0.9.0) leave the disk with their submission.
                Uploads::deleteForSubmissions([$id]);
                $db->transactionStart();
                FormData::forForm($form)->deleteRows($id, true);

                foreach (['#__squidform_approvals', '#__squidform_approval_queue', '#__squidform_submission_files', '#__squidform_signatures', '#__squidform_submissions'] as $t) {
                    $col = $t === '#__squidform_submissions' ? 'id' : 'submission_id';
                    $db->setQuery($db->createQuery()->delete($db->quoteName($t))->where($db->quoteName($col) . ' = ' . $id))->execute();
                }

                // Its e-signature trail (0.11.0) goes too.
                $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_esign_events'))->where($db->quoteName('run') . ' = ' . $db->quote((string) $row->uid)))->execute();
                $db->transactionCommit();
                $done[$form][] = $id;
            } catch (\Throwable $e) {
                try {
                    $db->transactionRollback();
                } catch (\Throwable) {
                }

                \Joomla\CMS\Log\Log::add('SquidForm permanent delete of submission ' . $id . ' failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'squidform');
            }
        }

        foreach ($done as $form => $list) {
            Audit::log(null, $form, (int) $user->id, 'purge', ['ids' => $list, 'count' => \count($list)]);
        }

        return array_sum(array_map('count', $done));
    }
}
