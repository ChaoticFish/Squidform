<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Forms;

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public $filterForm;
    public $activeFilters = [];
    protected $items = [];
    protected $pagination;
    protected $state;

    public function display($tpl = null): void
    {
        $model = $this->getModel();

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $canDo   = ContentHelper::getActions('com_squidform');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_FORMS_TITLE'), 'list');

        if ($canDo->get('core.create')) {
            $toolbar->addNew('form.add');
        }

        // 0.9.1: copies, and moving forms between sites.
        if ($canDo->get('core.create')) {
            $toolbar->standardButton('duplicate', 'COM_SQUIDFORM_TOOLBAR_DUPLICATE', 'forms.duplicate')->icon('icon-copy')->listCheck(true);
        }

        if ($canDo->get('core.edit')) {
            $toolbar->standardButton('export', 'COM_SQUIDFORM_TOOLBAR_EXPORT_FORMS', 'forms.export')->icon('icon-download')->listCheck(true);
            // 0.12.0
            $toolbar->linkButton('chronoforms', 'COM_SQUIDFORM_TOOLBAR_CHRONOFORMS')->url('index.php?option=com_squidform&view=chronoforms')->icon('icon-upload');
        }

        if ($canDo->get('core.edit.state')) {
            $dropdown = $toolbar->dropdownButton('status-group', 'JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('icon-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $child = $dropdown->getChildToolbar();
            if ((string) $this->state->get('filter.state') === '2') {
                $child->standardButton('restore', 'COM_SQUIDFORM_TOOLBAR_RESTORE', 'forms.unpublish')->icon('icon-undo')->listCheck(true);
            } else {
                $child->publish('forms.publish')->listCheck(true);
                $child->unpublish('forms.unpublish')->listCheck(true);
                $child->checkin('forms.checkin')->listCheck(true);
                // "Archive", never "delete": the form and all its data are kept.
                $child->standardButton('archive', 'COM_SQUIDFORM_TOOLBAR_ARCHIVE', 'forms.archive')->icon('icon-archive')->listCheck(true);
            }
        }

        // Permanent delete: archived forms only, with its own permission.
        if ((string) $this->state->get('filter.state') === '2' && $this->getCurrentUser()->authorise('squidform.delete.hard', 'com_squidform')) {
            $toolbar->standardButton('purge', 'COM_SQUIDFORM_TOOLBAR_PURGE', 'purge.start')->icon('icon-delete')->buttonClass('btn btn-danger')->listCheck(true);
        }

        if ($canDo->get('core.admin') || $canDo->get('core.options')) {
            $toolbar->preferences('com_squidform');
        }
    }
}
