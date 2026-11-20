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
 * Dropdown of SquidForm forms. Used by the menu item, the module and the
 * admin filters. Add `valuefield="alias"` to store the alias instead of the id.
 */
class SquidformsField extends ListField
{
    protected $type = 'Squidforms';

    protected function getOptions()
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $value = (string) ($this->element['valuefield'] ?? 'id') === 'alias' ? 'alias' : 'id';
        $q     = $db->createQuery()
            ->select(['id', $db->quoteName($value, 'value'), $db->quoteName('title', 'text'), 'state'])
            ->from($db->quoteName('#__squidform_forms'))
            ->where('state IN (0, 1)')
            ->order('title');

        $options = parent::getOptions();
        $acl     = (string) ($this->element['acl'] ?? '');
        $allowed = $acl !== '' ? array_flip(\SquidForm\Component\SquidForm\Administrator\Engine\Acl::forms($acl)) : null;

        foreach ($db->setQuery($q)->loadObjectList() as $row) {
            if ($allowed !== null && !isset($allowed[(int) ($row->id ?? 0)])) {
                continue;
            }

            $options[] = HTMLHelper::_('select.option', $row->value, $row->text . ((int) $row->state === 0 ? ' (unpublished)' : ''));
        }

        return $options;
    }
}
