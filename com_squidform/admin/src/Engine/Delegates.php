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
 * Standing delegates: "while I'm away, X approves for me".
 *
 * - The delegate is one person, or a user group (0.6.0): then any member
 *   can act. Membership is checked when they act, so people added to the
 *   group later can, and people removed can't.
 * - One form or all forms; optional first and last day (site time zone).
 * - The delegate can act wherever the person they cover could, and needs
 *   Approve Submissions on that form themselves.
 * - One level only: a delegate's own delegate does not inherit.
 * - The person keeps their own rights while delegated.
 * - Approvals record who acted and for whom.
 *
 * People manage their own delegates; Super Users (core.admin on SquidForm)
 * manage anyone's.
 */
final class Delegates
{
    /** @var array<string, mixed> */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /** Today in the site's time zone (Y-m-d). */
    public static function today(): string
    {
        $tz = (string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC';

        return Factory::getDate('now', 'UTC')->setTimezone(new \DateTimeZone($tz))->format('Y-m-d', true);
    }

    /**
     * Active delegations where $delegateId covers someone: named directly,
     * or through one of their groups (read now, with parent groups).
     *
     * @return list<object>  {id, user_id, delegate_id, delegate_group_id, form_id, start_date, end_date}
     */
    public static function delegations(int $delegateId): array
    {
        $k = 'of' . $delegateId;

        if (!isset(self::$cache[$k])) {
            $db     = SubmissionStore::db();
            $today  = self::today();
            $groups = Users::groupsOf($delegateId) ?: [0];
            self::$cache[$k] = $db->setQuery(
                $db->createQuery()->select('*')->from($db->quoteName('#__squidform_delegates'))
                    ->where('(delegate_id = :d OR delegate_group_id IN (' . implode(',', array_map('intval', $groups)) . '))')
                    ->where('user_id <> :me')
                    ->where('(start_date IS NULL OR start_date <= :t1)')
                    ->where('(end_date IS NULL OR end_date >= :t2)')
                    ->bind(':d', $delegateId, ParameterType::INTEGER)->bind(':me', $delegateId, ParameterType::INTEGER)
                    ->bind(':t1', $today)->bind(':t2', $today)
                    ->order('id')
            )->loadObjectList();
        }

        return self::$cache[$k];
    }

    /** @return int[] people $delegateId covers on this form today */
    public static function delegatorsOf(int $delegateId, int $formId): array
    {
        $out = [];

        foreach (self::delegations($delegateId) as $d) {
            if ((int) $d->form_id === 0 || (int) $d->form_id === $formId) {
                $out[] = (int) $d->user_id;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return int[] people covering $userId on this form today (group delegates' current members included) */
    public static function delegatesOf(int $userId, int $formId): array
    {
        $db    = SubmissionStore::db();
        $today = self::today();
        $rows  = $db->setQuery(
            $db->createQuery()->select(['delegate_id', 'delegate_group_id'])->from($db->quoteName('#__squidform_delegates'))
                ->where('user_id = :u')->where('(form_id = 0 OR form_id = :f)')
                ->where('(start_date IS NULL OR start_date <= :t1)')->where('(end_date IS NULL OR end_date >= :t2)')
                ->bind(':u', $userId, ParameterType::INTEGER)->bind(':f', $formId, ParameterType::INTEGER)
                ->bind(':t1', $today)->bind(':t2', $today)
        )->loadObjectList();
        $out   = [];

        foreach ($rows as $r) {
            if ((int) $r->delegate_group_id > 0) {
                foreach (Users::inGroups([(int) $r->delegate_group_id]) as $u) {
                    $out[$u->id] = $u->id;
                }
            } elseif ((int) $r->delegate_id > 0) {
                $out[(int) $r->delegate_id] = (int) $r->delegate_id;
            }
        }

        unset($out[$userId]);

        return array_values($out);
    }

    /**
     * Delegations to show: a person's own (as the one covered or the
     * delegate), or every one when $userId is null.
     *
     * @return list<object>  with user_name, delegate_name, form_title, active (bool)
     */
    public static function listFor(?int $userId): array
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select(['d.*', $db->quoteName('u.name', 'user_name'), $db->quoteName('g.name', 'person_name'), $db->quoteName('ug.title', 'group_title'), $db->quoteName('f.title', 'form_title')])
            ->from($db->quoteName('#__squidform_delegates', 'd'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = d.user_id')
            ->join('LEFT', $db->quoteName('#__users', 'g'), 'g.id = d.delegate_id')
            ->join('LEFT', $db->quoteName('#__usergroups', 'ug'), 'ug.id = d.delegate_group_id')
            ->join('LEFT', $db->quoteName('#__squidform_forms', 'f'), 'f.id = d.form_id')
            ->order('d.id DESC');

        if ($userId !== null) {
            $groups = Users::groupsOf($userId) ?: [0];
            $q->where('(d.user_id = :u OR d.delegate_id = :u2 OR d.delegate_group_id IN (' . implode(',', array_map('intval', $groups)) . '))')
                ->bind(':u', $userId, ParameterType::INTEGER)->bind(':u2', $userId, ParameterType::INTEGER);
        }

        $today = self::today();

        foreach ($rows = $db->setQuery($q)->loadObjectList() as $r) {
            $r->is_group      = (int) $r->delegate_group_id > 0;
            $r->delegate_name = $r->is_group
                ? Text::sprintf('COM_SQUIDFORM_DELEGATE_GROUP_LABEL', (string) ($r->group_title ?? '#' . (int) $r->delegate_group_id))
                : (string) ($r->person_name ?? '#' . (int) $r->delegate_id);
            $r->active  = (empty($r->start_date) || $r->start_date <= $today) && (empty($r->end_date) || $r->end_date >= $today);
            $r->expired = !empty($r->end_date) && $r->end_date < $today;
        }

        return $rows;
    }

    /** May $by manage delegates for $userId? */
    public static function canManage(int $userId, ?User $by = null): bool
    {
        $by ??= Users::current();

        if ((int) $by->id <= 0) {
            return false;
        }

        if ($by->authorise('core.admin', 'com_squidform')) {
            return true;
        }

        return (int) $by->id === $userId && Acl::forms('squidform.approve', $by) !== [];
    }

    /**
     * Add a delegation to one person ($delegateId) or a user group
     * ($groupId; then $delegateId is 0). Returns an error message, or null.
     */
    public static function add(int $userId, int $delegateId, int $formId, string $start, string $end, string $note, ?User $by = null, int $groupId = 0): ?string
    {
        $by ??= Users::current();

        if (!self::canManage($userId, $by)) {
            return Text::_('COM_SQUIDFORM_DELEGATE_ERR_PERMISSION');
        }

        if (!Users::isActive($userId) || ($groupId > 0) === ($delegateId > 0)) {
            return Text::_('COM_SQUIDFORM_DELEGATE_ERR_PERSON');
        }

        if ($groupId > 0) {
            // The group must be allowed to approve where the delegation applies;
            // each member is still checked when they act.
            if (!isset(Users::groupsWithPermission('squidform.approve', max(0, $formId))[$groupId])) {
                return Text::_('COM_SQUIDFORM_DELEGATE_ERR_GROUP_NO_APPROVE');
            }
        } else {
            if ($delegateId === $userId || !Users::isActive($delegateId)) {
                return Text::_('COM_SQUIDFORM_DELEGATE_ERR_PERSON');
            }

            // The delegate must be able to approve where the delegation applies.
            $ok = $formId > 0
                ? Users::can($delegateId, 'squidform.approve', $formId)
                : \in_array($delegateId, array_map(static fn ($u) => $u->id, Users::withPermission('squidform.approve')), true);

            if (!$ok) {
                return Text::_('COM_SQUIDFORM_DELEGATE_ERR_NO_APPROVE');
            }
        }

        foreach (['start' => &$start, 'end' => &$end] as &$d) {
            $d = trim($d);

            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return Text::_('COM_SQUIDFORM_DELEGATE_ERR_DATE');
            }
        }

        unset($d);

        if ($start !== '' && $end !== '' && $end < $start) {
            return Text::_('COM_SQUIDFORM_DELEGATE_ERR_DATES');
        }

        $row = (object) [
            'user_id'     => $userId,
            'delegate_id' => $groupId > 0 ? 0 : $delegateId,
            'delegate_group_id' => max(0, $groupId),
            'form_id'     => max(0, $formId),
            'start_date'  => $start !== '' ? $start : null,
            'end_date'    => $end !== '' ? $end : null,
            'note'        => mb_substr(trim($note), 0, 255),
            'created'     => Factory::getDate()->toSql(),
            'created_by'  => (int) $by->id,
        ];
        SubmissionStore::db()->insertObject('#__squidform_delegates', $row, 'id');
        Audit::log(null, max(0, $formId), (int) $by->id, 'delegate_add', ['id' => (int) $row->id, 'user' => $userId, 'delegate' => (int) $row->delegate_id, 'group' => (int) $row->delegate_group_id, 'start' => $row->start_date, 'end' => $row->end_date]);
        self::reset();

        return null;
    }

    /** Remove a delegation (its owner, or a Super User). */
    public static function remove(int $id, ?User $by = null): bool
    {
        $by ??= Users::current();
        $db  = SubmissionStore::db();
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__squidform_delegates'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject();

        if (!$row || !self::canManage((int) $row->user_id, $by)) {
            return false;
        }

        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_delegates'))->where('id = ' . $id))->execute();
        Audit::log(null, (int) $row->form_id, (int) $by->id, 'delegate_remove', ['id' => $id, 'user' => (int) $row->user_id, 'delegate' => (int) $row->delegate_id, 'group' => (int) ($row->delegate_group_id ?? 0)]);
        self::reset();

        return true;
    }
}
