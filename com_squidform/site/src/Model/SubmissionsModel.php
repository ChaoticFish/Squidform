<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Exporter;
use SquidForm\Component\SquidForm\Administrator\Engine\FormData;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Front-end viewer list for the menu item's form: search, filters, sort and
 * paging, remembered per menu item for the session. Rows are limited to what
 * the user may see: every submission (View Submissions) or only their own
 * (View Own Submissions).
 */
class SubmissionsModel extends ListModel
{
    /** @var array|null */
    private ?array $viewer = null;

    public function __construct($config = [], ?\Joomla\CMS\MVC\Factory\MVCFactoryInterface $factory = null)
    {
        // A module passes its own context; the menu item's is the default.
        if (isset($config['viewer']) && \is_array($config['viewer'])) {
            $this->viewer = $config['viewer'];
        }

        parent::__construct($config, $factory);
    }

    public function viewer(): array
    {
        return $this->viewer ??= (Viewer::current() ?? throw new \RuntimeException('No viewer menu item.', 404));
    }

    /** 'all', 'own' or '' (no access). */
    public function scope(): string
    {
        $v    = $this->viewer();
        $user = Factory::getApplication()->getIdentity();

        if (Acl::can('squidform.view', $v['formId'], $user)) {
            return 'all';
        }

        if (!$user->guest && Acl::can('squidform.view.own', $v['formId'], $user)) {
            return 'own';
        }

        return '';
    }

    protected function populateState($ordering = null, $direction = null)
    {
        $app   = Factory::getApplication();
        $v     = $this->viewer();
        $ctx   = $v['key'];
        $names = $v['names'];
        $cfg   = $v['config'];
        $input = $app->getInput();

        $this->context = $ctx;

        if ($input->getInt($names['clear'])) {
            $app->setUserState($ctx . '.filter', []);
            $input->set($names['filter'], null);
        }

        $raw = $app->getUserStateFromRequest($ctx . '.filter', $names['filter'], [], 'array');
        $raw = \is_array($raw) ? $raw : [];

        $filter = [
            'search'       => trim((string) ($raw['search'] ?? '')),
            'status'       => preg_replace('/[^a-z_]/', '', (string) ($raw['status'] ?? '')),
            'created_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($raw['created_from'] ?? '')) ? $raw['created_from'] : '',
            'created_to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($raw['created_to'] ?? '')) ? $raw['created_to'] : '',
            'archived'     => (int) ($raw['archived'] ?? 0) === 1 && Acl::can('squidform.delete', $v['formId']) ? 1 : 0,
            'fields'       => [],
        ];

        foreach ($cfg['filters'] as $f) {
            if ($f['kind'] === 'field') {
                $val = $raw['f'][$f['name']] ?? '';
                $filter['fields'][$f['name']] = \is_scalar($val) ? mb_substr((string) $val, 0, 255) : '';
            }
        }

        $this->setState('filter', $filter);

        $types = [];

        foreach ($cfg['columns'] as $c) {
            if ($c['kind'] === 'field') {
                $types[$c['name']] = $c['field']['type'];
            }
        }

        $v2 = $v['schema'];

        foreach ($v2->pages() as $i => $p) {
            foreach ($v2->pageFields($i) as $f) {
                $types[$f['key']] = $f['type'];
            }
        }

        $sort = (string) $app->getUserStateFromRequest($ctx . '.sort', $names['sort'], $cfg['sort'], 'cmd');
        $dir  = strtolower((string) $app->getUserStateFromRequest($ctx . '.dir', $names['dir'], $cfg['dir'], 'cmd')) === 'asc' ? 'asc' : 'desc';

        if (!\SquidForm\Component\SquidForm\Administrator\Engine\ViewerConfig::sortable($sort, $types)) {
            $sort = $cfg['sort'];
        }

        $this->setState('sort', $sort);
        $this->setState('dir', $dir);
        $this->setState('sort_types', $types);
        $this->setState('list.limit', $cfg['per_page']);
        $this->setState('list.start', max(0, $input->getInt($names['start'], 0)));
    }

    /** Paging links use this list's own parameter (several lists can share a page). */
    public function getPagination()
    {
        return new \Joomla\CMS\Pagination\Pagination($this->getTotal(), (int) $this->getState('list.start'), (int) $this->getState('list.limit'), $this->viewer()['names']['prefix']);
    }

    protected function getStoreId($id = '')
    {
        return parent::getStoreId($id . ':' . json_encode($this->getState('filter')) . ':' . $this->getState('sort') . ':' . $this->getState('dir'));
    }

    protected function getListQuery()
    {
        $q = $this->baseQuery();
        $db = $this->getDatabase();

        $q->select([
            's.id', 's.uid', 's.form_id', 's.version_id', 's.status', 's.current_step', 's.user_id', 's.created', 's.modified', 's.archived',
            's.checked_out', 's.checked_out_time',
            $db->quoteName('u.name', 'user_name'),
            $db->quoteName('ec.name', 'editor_name'),
        ])->join('LEFT', $db->quoteName('#__users', 'ec'), 'ec.id = s.checked_out');

        $sort  = (string) $this->getState('sort');
        $dir   = $this->getState('dir') === 'asc' ? 'ASC' : 'DESC';
        $types = (array) $this->getState('sort_types');

        $field = isset($types[$sort]) ? $this->viewer()['schema']->field($sort) : null;
        $col   = $field ? $this->data()->column($field) : null;

        if ($col !== null) {
            $q->order('d.' . $db->quoteName($col) . ' ' . $dir)->order('s.id ' . $dir);
        } else {
            $q->order($db->quoteName('s.' . (\in_array($sort, ['id', 'created', 'modified', 'status'], true) ? $sort : 'created')) . ' ' . $dir)
                ->order('s.id ' . $dir);
        }

        return $q;
    }

    /** All matching ids (no paging), for "export everything that matches". @return int[] */
    public function getAllIds(): array
    {
        $this->getState();
        $q = $this->baseQuery()->select('s.id')->order('s.id');

        return array_map('intval', $this->getDatabase()->setQuery($q)->loadColumn());
    }

    /** FROM, joins and WHERE shared by the list and the export. */
    private function baseQuery(): QueryInterface
    {
        $db     = $this->getDatabase();
        $v      = $this->viewer();
        $formId = $v['formId'];
        $filter = (array) $this->getState('filter');
        $fd     = $this->data();
        $q      = $db->createQuery()
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = s.user_id')
            ->join('LEFT', $db->quoteName($fd->mainTable(), 'd'), 'd.submission_id = s.id')
            ->where('s.form_id = :form')
            ->bind(':form', $formId, ParameterType::INTEGER);

        $scope = $this->scope();

        if ($scope === '') {
            $q->where('1 = 0');
        } elseif ($scope === 'own') {
            $me = (int) Factory::getApplication()->getIdentity()->id;
            $q->where('s.user_id = :me')->bind(':me', $me, ParameterType::INTEGER);
        }

        $archived = (int) ($filter['archived'] ?? 0);
        $q->where('s.archived = :arch')->bind(':arch', $archived, ParameterType::INTEGER);
        // 0.15.0: test submissions stay out of site lists.
        $q->where('s.is_test = 0');

        if (($filter['status'] ?? '') !== '') {
            $q->where('s.status = :status')->bind(':status', $filter['status']);
        }

        $tz = Exporter::userTimezone();

        if (($filter['created_from'] ?? '') !== '') {
            $from = (new \DateTimeImmutable($filter['created_from'] . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $q->where('s.created >= :cfrom')->bind(':cfrom', $from);
        }

        if (($filter['created_to'] ?? '') !== '') {
            $to = (new \DateTimeImmutable($filter['created_to'] . ' 00:00:00', $tz))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $q->where('s.created < :cto')->bind(':cto', $to);
        }

        // Bound values are held in an array: Joomla binds by reference, so the
        // loop variables themselves must not be bound.
        $n    = 0;
        $vals = [];

        foreach ((array) ($filter['fields'] ?? []) as $key => $val) {
            $field = $v['schema']->field((string) $key);
            $col   = $field ? $fd->column($field) : null;

            if ($val === '' || $col === null) {
                continue;
            }

            $n++;
            $vals[$n] = (string) $val;
            $c        = 'd.' . $db->quoteName($col);
            $vp       = ':fv' . $n;

            if (\in_array($field['type'], ['checkboxes', 'multiselect', 'imagechoice'], true)) {
                $q->where('JSON_CONTAINS(' . $c . ', JSON_QUOTE(' . $vp . '))')->bind($vp, $vals[$n]);
            } elseif ($field['type'] === 'checkbox') {
                $q->where($val === '1' ? $c . ' = 1' : '(' . $c . ' IS NULL OR ' . $c . ' = 0)');
            } else {
                $q->where($c . ' = ' . $vp)->bind($vp, $vals[$n]);
            }
        }

        if (($filter['search'] ?? '') !== '') {
            $s = $filter['search'];

            if (($ref = \SquidForm\Component\SquidForm\Administrator\Engine\Ref::searchPrefix($s)) !== null) {
                // A reference (the start of the uid), as shown on pages and in emails.
                $like = $ref . '%';
                $q->where('s.uid LIKE :uidp')->bind(':uidp', $like);
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
                // An all-digit term may also be a reference.
                $digits = \SquidForm\Component\SquidForm\Administrator\Engine\Ref::digitPrefix($s);
                $q->where('(' . $fd->searchSql('d', ':like') . ' OR u.name LIKE :like2' . ($digits !== null ? ' OR s.uid LIKE :uidd' : '') . ')')
                    ->bind(':like', $like)
                    ->bind(':like2', $like);

                if ($digits !== null) {
                    $uidd = $digits . '%';
                    $q->bind(':uidd', $uidd);
                }
            }
        }

        return $q;
    }

    public function getItems()
    {
        $items    = parent::getItems() ?: [];
        $byId     = [];
        $versions = [];

        foreach ($items as $it) {
            $byId[(int) $it->id] = (int) $it->version_id;
        }

        $data = $this->data()->readMany(array_keys($byId), static function (int $id) use ($byId, &$versions) {
            return $versions[$byId[$id]] ??= \SquidForm\Component\SquidForm\Administrator\Engine\FormRepository::version($byId[$id]);
        });

        foreach ($items as $it) {
            $it->data = $data[(int) $it->id] ?? [];
        }

        return $items;
    }

    /** The viewer form's data tables (created for its live definition if missing). */
    private function data(): FormData
    {
        $v = $this->viewer();

        return FormData::ensureLive($v['formId'], $v['schema'], (string) $v['form']['alias']);
    }
}
