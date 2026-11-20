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
 * Activity (0.13.0): the audit trail across forms, for Components → SquidForm
 * → Activity. Who looked at, exported, downloaded, imported, approved,
 * archived or deleted what, and when.
 *
 * A person sees the rows of the forms they may view submissions of; rows that
 * belong to no form (a delegate for every form) only with Admin on SquidForm.
 * Edits, with their old and new values, stay on each submission's History.
 */
final class Activity
{
    /** Filter groups → the actions in them. */
    public const GROUPS = [
        'views'     => ['view'],
        'exports'   => ['export'],
        'downloads' => ['pdf', 'file_download'],
        'imports'   => ['import'],
        'records'   => ['create', 'archive', 'delete', 'restore', 'checkin', 'purge'],
        'approvals' => ['approve', 'reject', 'return', 'reassign', 'withdraw', 'resubmit', 'restart'],
        'forms'     => ['archive_form', 'restore_form', 'purge_form', 'test_clear'],
        'tables'    => ['table_export', 'drop_column', 'drop_table', 'forget_table'],
        'people'    => ['delegate_add', 'delegate_remove', 'resume_resend', 'resume_delete', 'resume_claim'],
        'security'  => ['upload_infected'],
    ];

    public const PER_PAGE = 50;

    /** Most rows a CSV download holds. */
    public const CSV_MOST = 50000;

    /**
     * @param array{form_id?:int, group?:string, user?:string, from?:string, to?:string} $f
     *
     * @return array{rows: list<object>, total: int}
     */
    public static function find(array $f, int $start = 0, int $limit = self::PER_PAGE, ?User $user = null): array
    {
        $user ??= Factory::getApplication()->getIdentity();
        $db    = SubmissionStore::db();
        $q     = self::base($f, $user);

        if ($q === null) {
            return ['rows' => [], 'total' => 0];
        }

        $count = clone $q;
        $count->clear('select')->select('COUNT(*)');
        $total = (int) $db->setQuery($count)->loadResult();

        $q->order('a.id DESC');
        $rows = $db->setQuery($q, max(0, $start), max(1, $limit))->loadObjectList();

        foreach ($rows as $r) {
            $r->diff = !empty($r->diff_json) ? json_decode((string) $r->diff_json, true) : null;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** The filters as sent, cleaned. */
    public static function filters(array $in): array
    {
        $date = static fn ($v) => \is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';

        return [
            'form_id' => max(0, (int) ($in['form_id'] ?? 0)),
            'group'   => isset(self::GROUPS[$in['group'] ?? '']) ? (string) $in['group'] : '',
            'user'    => mb_substr(trim((string) ($in['user'] ?? '')), 0, 100),
            'from'    => $date($in['from'] ?? ''),
            'to'      => $date($in['to'] ?? ''),
        ];
    }

    /** "Downloaded as PDF" */
    public static function label(string $action): string
    {
        $key = 'COM_SQUIDFORM_AUDIT_' . strtoupper($action);

        return Text::_($key) !== $key ? Text::_($key) : $action;
    }

    /** A short plain-text note: "CSV, 12 submissions", a file name, … */
    public static function detail(object $r): string
    {
        $d = \is_array($r->diff ?? null) ? $r->diff : [];

        return match ((string) $r->action) {
            'export'                         => Text::plural('COM_SQUIDFORM_ACTIVITY_EXPORT_N', (int) ($d['count'] ?? 0), strtoupper((string) ($d['format'] ?? ''))),
            'file_download'                  => (string) ($d['file'] ?? ''),
            'upload_infected'                => trim((string) ($d['file'] ?? '') . ' (' . (string) ($d['found'] ?? '') . ')', ' ()'),
            'purge'                          => Text::plural('COM_SQUIDFORM_ACTIVITY_N_SUBMISSIONS', (int) ($d['count'] ?? 0)),
            'resume_resend'                  => (string) ($d['to'] ?? ''),
            'table_export'                   => Text::plural('COM_SQUIDFORM_ACTIVITY_TABLE_N', (int) ($d['count'] ?? 0), (string) ($d['table'] ?? '')),
            'drop_column'                    => (string) ($d['table'] ?? '') . '.' . (string) ($d['column'] ?? ''),
            'drop_table', 'forget_table'     => (string) ($d['table'] ?? ''),
            'resume_claim'                   => (string) ($d['who'] ?? ''),
            'test_clear'                     => (string) (int) ($d['count'] ?? 0),
            'resume_delete'                  => (string) ($d['who'] ?? ''),
            'delegate_add', 'delegate_remove' => Text::sprintf('COM_SQUIDFORM_ACTIVITY_DELEGATE', self::userName((int) ($d['user'] ?? 0)), self::userName((int) ($d['delegate'] ?? 0))),
            default                          => '',
        };
    }

    /**
     * The whole filtered list as CSV (newest first), formula guard on every
     * cell. Nothing is written to the trail for it: it is a list of what was
     * done, not of anyone's answers.
     */
    public static function csv(array $f, ?User $user = null): string
    {
        $found = self::find($f, 0, self::CSV_MOST, $user);
        $out   = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        $put = static fn (array $cells) => fputcsv($out, array_map(static fn ($c) => Exporter::cell((string) $c), $cells), ',', '"', '', "\r\n");
        $put([Text::_('COM_SQUIDFORM_ACTIVITY_WHEN'), Text::_('COM_SQUIDFORM_ACTIVITY_WHO'), Text::_('COM_SQUIDFORM_ACTIVITY_WHAT'), Text::_('COM_SQUIDFORM_HEADING_FORM'), Text::_('COM_SQUIDFORM_PDF_REFERENCE'), Text::_('COM_SQUIDFORM_ACTIVITY_DETAIL')]);

        foreach ($found['rows'] as $r) {
            $put([
                (string) $r->created . ' UTC',
                $r->user_id ? (string) ($r->user_name ?? '#' . $r->user_id) : Text::_('COM_SQUIDFORM_GUEST'),
                self::label((string) $r->action),
                (string) ($r->form_title ?? ''),
                $r->sub_uid ? Ref::short((string) $r->sub_uid) : '',
                self::detail($r),
            ]);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** null: nothing this person may see. */
    private static function base(array $f, User $user): ?\Joomla\Database\QueryInterface
    {
        $db    = SubmissionStore::db();
        $ids   = Acl::forms('squidform.view', $user);
        $admin = $user->authorise('core.admin', 'com_squidform');

        if ($f['form_id'] ?? 0) {
            if (!\in_array((int) $f['form_id'], $ids, true)) {
                return null;
            }

            $ids = [(int) $f['form_id']];
        }

        if (!$ids && !$admin) {
            return null;
        }

        $q = $db->createQuery()
            ->select(['a.id', 'a.submission_id', 'a.form_id', 'a.user_id', 'a.action', 'a.diff_json', 'a.created',
                $db->quoteName('u.name', 'user_name'), $db->quoteName('fm.title', 'form_title'), $db->quoteName('s.uid', 'sub_uid')])
            ->from($db->quoteName('#__squidform_audit', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = a.user_id')
            ->join('LEFT', $db->quoteName('#__squidform_forms', 'fm'), 'fm.id = a.form_id')
            ->join('LEFT', $db->quoteName('#__squidform_submissions', 's'), 's.id = a.submission_id');

        $ids  = array_map('intval', $ids);
        $mine = $ids ? 'a.form_id IN (' . implode(',', $ids) . ')' : '0';
        $q->where(($admin && !($f['form_id'] ?? 0)) ? '(' . $mine . ' OR a.form_id = 0)' : $mine);

        if (($f['group'] ?? '') !== '') {
            $q->whereIn($db->quoteName('a.action'), self::GROUPS[$f['group']], ParameterType::STRING);
        }

        if (($f['user'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['user']) . '%';
            $q->where('(u.name LIKE :uname OR u.username LIKE :uname2)')
                ->bind(':uname', $like)->bind(':uname2', $like);
        }

        // The dates are the site's days; the trail is kept in UTC.
        $tz = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');

        if (($f['from'] ?? '') !== '') {
            $from = (new \DateTimeImmutable($f['from'] . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $q->where('a.created >= :from')->bind(':from', $from);
        }

        if (($f['to'] ?? '') !== '') {
            $to = (new \DateTimeImmutable($f['to'] . ' 00:00:00', $tz))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $q->where('a.created < :to')->bind(':to', $to);
        }

        return $q;
    }

    private static function userName(int $id): string
    {
        if ($id <= 0) {
            return '—';
        }

        $u = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($id);

        return $u->id ? (string) $u->name : '#' . $id;
    }
}
