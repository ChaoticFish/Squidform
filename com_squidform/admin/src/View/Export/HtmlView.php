<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Export;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\ExportService;
use SquidForm\Component\SquidForm\Administrator\Model\ExportModel;

\defined('_JEXEC') or die;

/**
 * Export screen: pick the form, which submissions, the format and the columns.
 */
class HtmlView extends BaseHtmlView
{
    public int $formId = 0;
    public ?object $form = null;

    /** @var array<int, object> */
    public array $forms = [];

    /** @var array<int, array> */
    public array $fieldColumns = [];

    /** @var string[] */
    public array $picks = [];

    public int $selectedCount = 0;
    public int $filteredCount = 0;
    public int $tickedCount = 0;
    public bool $tickedMixed = false;

    public function display($tpl = null): void
    {
        /** @var ExportModel $model */
        $model = $this->getModel();
        $input = Factory::getApplication()->getInput();

        $tickedForms       = $model->getTickedForms();
        $this->tickedCount = \count($model->getTicked());
        $this->tickedMixed = \count($tickedForms) > 1;

        $this->formId = $input->getInt('export_form')
            ?: (\count($tickedForms) === 1 ? $tickedForms[0] : $model->getListFormFilter());

        $this->form = $model->getFormRow($this->formId);

        if (!$this->form) {
            $this->formId = 0;
        }

        $this->forms = $model->getForms();

        if ($this->formId) {
            $this->fieldColumns  = $model->getFieldColumns($this->formId);
            $this->selectedCount = \count($model->getSelectedIds($this->formId));
            $this->filteredCount = \count($model->getFilteredIds($this->formId));
            $this->picks         = $model->getPicks($this->formId) ?? ExportService::defaultPicks($this->fieldColumns);
        }

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_EXPORT_TITLE'), 'download');
        $this->getDocument()->getToolbar()->standardButton('cancel', 'JTOOLBAR_CLOSE', 'export.cancel')->icon('icon-times');

        parent::display($tpl);
    }
}
