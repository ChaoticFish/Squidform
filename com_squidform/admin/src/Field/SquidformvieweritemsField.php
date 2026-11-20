<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Site menu items of type "SquidForm » Submissions viewer" (for the viewer module).
 */
class SquidformvieweritemsField extends ListField
{
    protected $type = 'Squidformvieweritems';

    protected function getOptions()
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $db->createQuery()
            ->select(['m.id', 'm.title', 'm.menutype', 'm.link', $db->quoteName('f.title', 'form_title')])
            ->from($db->quoteName('#__menu', 'm'))
            ->join('LEFT', $db->quoteName('#__squidform_forms', 'f'), "f.id = CAST(SUBSTRING_INDEX(m.link, 'form_id=', -1) AS UNSIGNED)")
            ->where('m.client_id = 0')
            ->where('m.published IN (0, 1)')
            ->where($db->quoteName('m.link') . ' LIKE ' . $db->quote('index.php?option=com_squidform&view=submissions%'))
            ->order('m.menutype, m.lft');

        $options = parent::getOptions();

        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $options[] = HTMLHelper::_('select.option', $r->id, $r->title . ' (' . $r->menutype . ($r->form_title ? ' · ' . $r->form_title : '') . ')');
        }

        return $options;
    }
}
