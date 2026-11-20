<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Field;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

\defined('_JEXEC') or die;

/**
 * Default sort column for the viewer (menu item or module).
 */
class SquidformsortField extends ListField
{
    use ViewerFormTrait;

    protected $type = 'Squidformsort';

    protected function getOptions()
    {
        $opts = parent::getOptions();

        foreach ($this->viewerChoices('sort') as $v => $label) {
            if (!\in_array($v, ['submitted_by', 'uid'], true)) {
                $opts[] = HTMLHelper::_('select.option', $v, $label);
            }
        }

        return $opts;
    }
}
