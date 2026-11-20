<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\CheckboxesField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Tick-boxes for the viewer's Filters (mode="filters") of the menu item's or
 * module's form, with Select all / Select none.
 * Nothing ticked = use the form definition's "viewer" setting or the defaults.
 */
class SquidformcolumnsField extends CheckboxesField
{
    use ViewerFormTrait;

    protected $type = 'Squidformcolumns';

    protected function getInput()
    {
        if (!$this->getOptions()) {
            return '<p class="form-text">' . Text::_('COM_SQUIDFORM_MENU_SAVE_FIRST') . '</p>';
        }

        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-sf-tick]');
  if (!b) return;
  e.preventDefault();
  document.querySelectorAll('#' + b.dataset.sfFor + ' input[type=checkbox]').forEach((c) => { c.checked = b.dataset.sfTick === '1'; });
});
JS, ['name' => 'com_squidform.tickall']);

        return parent::getInput()
            . '<div class="mt-1"><button type="button" class="btn btn-sm btn-link px-0" data-sf-tick="1" data-sf-for="' . $this->id . '">' . Text::_('COM_SQUIDFORM_EXPORT_ALL') . '</button> '
            . '<button type="button" class="btn btn-sm btn-link" data-sf-tick="0" data-sf-for="' . $this->id . '">' . Text::_('COM_SQUIDFORM_EXPORT_NONE') . '</button></div>';
    }

    protected function getOptions()
    {
        $opts = [];

        foreach ($this->viewerChoices((string) ($this->element['mode'] ?? 'filters')) as $v => $label) {
            $opts[] = HTMLHelper::_('select.option', $v, $label);
        }

        return $opts;
    }
}
