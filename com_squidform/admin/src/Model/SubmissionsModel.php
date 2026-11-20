<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Model;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\FormData;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;

\defined('_JEXEC') or die;

/**
 * Admin list of submissions across forms, with filters for form, status and
 * deleted, and a search that looks inside submitted values.
 */
class SubmissionsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= [
            'id', 's.id', 'created', 's.created', 'status', 's.status',
            'form_id', 's.form_id', 'form_title', 'user_name', 'archived',
        ];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 's.created', $direction = 'desc')
    {
        $formId = $this->getUserStateFromRequest($this->context . '.filter.form_id', 'form_id', '', 'string');

        if ($formId !== '') {
            $this->setState('filter.form_id', $formId);
        }

        parent::populateState($ordering, $direction);

        // Approvers without View Submissions only ever see what waits for them.
        if (!Acl::forms('squidform.view') && Acl::forms('squidform.approve')) {
            $this->setState('filter.awaiting', 'me');
        }
    }

    protected function getStoreId($id = '')
    {
        foreach (['search', 'form_id', 'status', 'archived', 'awaiting'] as $f) {
            $id .= ':' . $this->getState('filter.' . $f);
        }

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select([
                's.id', 's.uid', 's.form_id', 's.version_id', 's.status', 's.current_step', 's.approval_round', 's.step_since', 's.user_id', 's.created', 's.archived', 's.is_test',
                's.checked_out', 's.checked_out_time',
                $db->quoteName('f.title', 'form_title'),
                $db->quoteName('u.name', 'user_name'),
                $db->quoteName('ec.name', 'editor_name'),
            ])
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->join('LEFT', $db->quoteName('#__squidform_forms', 'f'), 'f.id = s.form_id')
            ->join('LEFT', $db->quoteName('#__users', 'ec'), 'ec.id = s.checked_out')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = s.user_id');

        if ($this->getState('filter.awaiting') === 'me') {
            // Awaiting my approval: approvers see these without View Submissions.
            $allowed = array_values(array_unique(array_merge(Acl::forms('squidform.view'), Acl::forms('squidform.approve'))));
            Approvals::whereAwaiting($query, null, 's');
        } else {
            // Only forms this user may view (per-form permissions).
            $allowed = Acl::forms('squidform.view');
            $query->whereIn($db->quoteName('s.form_id'), $allowed ?: [0]);

            $archived = (int) $this->getState('filter.archived', 0);
            $query->where('s.archived = :arch')->bind(':arch', $archived, ParameterType::INTEGER);
        }

        if ($formId = (int) $this->getState('filter.form_id')) {
            $query->where('s.form_id = :fid')->bind(':fid', $formId, ParameterType::INTEGER);
        }

        if ($status = (string) $this->getState('filter.status')) {
            $query->where('s.status = :status')->bind(':status', $status);
        }

        if ($search = trim((string) $this->getState('filter.search', ''))) {
            if (stripos($search, 'id:') === 0) {
                $sid = (int) substr($search, 3);
                $query->where('s.id = :sid')->bind(':sid', $sid, ParameterType::INTEGER);
            } elseif (($ref = \SquidForm\Component\SquidForm\Administrator\Engine\Ref::searchPrefix($search)) !== null) {
                $like = $ref . '%';
                $query->where('s.uid LIKE :uidp')->bind(':uidp', $like);
            } else {
                // Answers live in each form's own tables: search those of the
                // forms in scope, then match by id (or the submitter's name).
                $like  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $forms = $formId ? array_intersect([$formId], $allowed) : $allowed;
                $ids   = [];

                foreach ($forms as $fid) {
                    $fd = FormData::forForm((int) $fid);

                    if (!$fd->hasTables()) {
                        continue;
                    }

                    $sq = $db->createQuery()->select('d.submission_id')
                        ->from($db->quoteName($fd->mainTable(), 'd'))
                        ->where($fd->searchSql('d', ':q'))
                        ->bind(':q', $like);

                    foreach ($db->setQuery($sq)->loadColumn() as $id) {
                        $ids[] = (int) $id;
                    }
                }

                // An all-digit term may also be a reference.
                $digits = \SquidForm\Component\SquidForm\Administrator\Engine\Ref::digitPrefix($search);
                $query->where('(' . ($ids ? 's.id IN (' . implode(',', array_unique($ids)) . ')' : '0 = 1') . ' OR u.name LIKE :like2'
                    . ($digits !== null ? ' OR s.uid LIKE :uidd' : '') . ')')
                    ->bind(':like2', $like);

                if ($digits !== null) {
                    $uidd = $digits . '%';
                    $query->bind(':uidd', $uidd);
                }
            }
        }

        $order = (string) $this->getState('list.ordering', 's.created');
        $dir   = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        // Only known columns (Joomla checks too; this is a second lock).
        $cols  = ['id' => 's.id', 's.id' => 's.id', 'created' => 's.created', 's.created' => 's.created', 'status' => 's.status', 's.status' => 's.status',
            'form_id' => 's.form_id', 's.form_id' => 's.form_id', 'form_title' => 'form_title', 'user_name' => 'user_name', 'archived' => 's.archived'];
        $query->order(($cols[$order] ?? 's.created') . ' ' . $dir);

        return $query;
    }

    /**
     * Ids of every submission of one form that matches the list's current
     * search, status and deleted filters (no paging). Used by export.
     *
     * @return int[]
     */
    public function getIdsForExport(int $formId): array
    {
        $this->getState(); // populate filters from the session
        $this->setState('filter.form_id', $formId);

        $query = $this->getListQuery();
        $query->clear('select')->clear('order')->select('s.id')->order('s.id');
        // 0.15.0: test submissions never go into an export of the list.
        $query->where('s.is_test = 0');

        return array_map('intval', $this->getDatabase()->setQuery($query)->loadColumn());
    }

    /**
     * First few answers of each listed submission, for the Summary column.
     *
     * @param   object[]  $items  rows with id, form_id, version_id
     *
     * @return  array<int, string>
     */
    public function getSummaries(array $items, int $perRow = 3): array
    {
        $byForm = [];

        foreach ($items as $it) {
            $byForm[(int) $it->form_id][(int) $it->id] = (int) $it->version_id;
        }

        $out = [];

        foreach ($byForm as $formId => $ids) {
            $schemas = [];
            $data    = FormData::forForm($formId)->readMany(array_keys($ids), static function (int $id) use ($ids, &$schemas) {
                return $schemas[$ids[$id]] ??= FormRepository::version($ids[$id]);
            });

            foreach ($data as $id => $values) {
                $schema = $schemas[$ids[$id]] ?? null;
                $parts  = [];

                foreach ($schema ? $schema->valueFields() : [] as $f) {
                    $v = $values[$f['key']] ?? null;

                    if ($f['type'] === 'repeater' || $v === null || $v === '' || $v === [] || \count($parts) >= $perRow) {
                        continue;
                    }

                    // A user field stores an account id: show the name.
                    if ($f['type'] === 'user') {
                        $v = \SquidForm\Component\SquidForm\Administrator\Engine\Users::name((int) $v);
                    }

                    $parts[] = mb_strimwidth(\is_array($v) ? implode(', ', $v) : (string) $v, 0, 60, '…');
                }

                $out[$id] = implode(' · ', $parts);
            }
        }

        return $out;
    }
}
