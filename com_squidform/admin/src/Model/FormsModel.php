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

\defined('_JEXEC') or die;

/**
 * Admin list of forms with version and submission counts.
 */
class FormsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= [
            'id', 'a.id', 'title', 'a.title', 'alias', 'a.alias',
            'state', 'a.state', 'modified', 'a.modified', 'submissions',
        ];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.title', $direction = 'asc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search') . ':' . $this->getState('filter.state');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();

        $subs = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->where('s.form_id = a.id AND s.archived = 0');

        $vers = $db->createQuery()
            ->select('MAX(v.version_no)')
            ->from($db->quoteName('#__squidform_form_versions', 'v'))
            ->where('v.form_id = a.id');

        $query = $db->createQuery()
            ->select([
                'a.id', 'a.title', 'a.alias', 'a.state', 'a.access', 'a.modified',
                'a.checked_out', 'a.checked_out_time', 'a.current_version_id',
                '(' . $subs . ') AS submissions',
                '(' . $vers . ') AS version_no',
                $db->quoteName('uc.name', 'editor'),
                $db->quoteName('ag.title', 'access_level'),
            ])
            ->from($db->quoteName('#__squidform_forms', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'uc'), 'uc.id = a.checked_out')
            ->join('LEFT', $db->quoteName('#__viewlevels', 'ag'), 'ag.id = a.access');

        $state = (string) $this->getState('filter.state');

        if (is_numeric($state)) {
            $s = (int) $state;
            $query->where('a.state = :state')->bind(':state', $s, ParameterType::INTEGER);
        } elseif ($state === '') {
            $query->where('a.state IN (0, 1)');
        }

        if ($search = trim((string) $this->getState('filter.search', ''))) {
            if (stripos($search, 'id:') === 0) {
                $sid = (int) substr($search, 3);
                $query->where('a.id = :sid')->bind(':sid', $sid, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(' ', '%', $search) . '%';
                $query->where('(a.title LIKE :s1 OR a.alias LIKE :s2)')
                    ->bind(':s1', $like)
                    ->bind(':s2', $like);
            }
        }

        $order = $this->getState('list.ordering', 'a.title');
        $dir   = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        // Only known columns (Joomla checks too; this is a second lock).
        $cols  = ['id' => 'a.id', 'a.id' => 'a.id', 'title' => 'a.title', 'a.title' => 'a.title', 'alias' => 'a.alias', 'a.alias' => 'a.alias',
            'state' => 'a.state', 'a.state' => 'a.state', 'modified' => 'a.modified', 'a.modified' => 'a.modified', 'submissions' => 'submissions'];
        $query->order(($cols[(string) $order] ?? 'a.title') . ' ' . $dir);

        return $query;
    }
}
