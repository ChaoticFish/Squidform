<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Submissions;

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public $filterForm;
    public $activeFilters = [];
    protected $items = [];
    protected $pagination;
    protected $state;

    /** @var array<int, string> */
    protected $summaries = [];

    public function display($tpl = null): void
    {
        $model = $this->getModel();

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->summaries     = $model->getSummaries($this->items ?: []);

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $canDo   = ContentHelper::getActions('com_squidform');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_SUBMISSIONS_TITLE'), 'inbox');

        if (Acl::forms('squidform.delete')) {
            if ((int) $this->state->get('filter.archived') === 1) {
                $toolbar->standardButton('restore', 'COM_SQUIDFORM_TOOLBAR_RESTORE', 'submissions.restore')
                    ->icon('icon-undo')
                    ->listCheck(true);
            } else {
                // "Archive", never "delete": archived submissions are kept and can be restored.
                $toolbar->standardButton('archive', 'COM_SQUIDFORM_TOOLBAR_ARCHIVE', 'submissions.archive')
                    ->icon('icon-archive')
                    ->listCheck(true);
            }
        }

        // Check in (0.10.2): managers free the ticked submissions' edit locks.
        $me = \Joomla\CMS\Factory::getApplication()->getIdentity();

        if ($me->authorise('core.manage', 'com_checkin') || $me->authorise('core.manage', 'com_squidform')) {
            $toolbar->checkin('submissions.checkin')->listCheck(true);
        }

        if ((int) $this->state->get('filter.archived') === 1 && Acl::forms('squidform.delete.hard')) {
            $toolbar->standardButton('purge', 'COM_SQUIDFORM_TOOLBAR_PURGE', 'purge.start')
                ->icon('icon-delete')->buttonClass('btn btn-danger')->listCheck(true);
        }

        if (Acl::forms('squidform.approve')) {
            // Approves each ticked request the user can act on; the others are skipped.
            $toolbar->standardButton('approve', 'COM_SQUIDFORM_TOOLBAR_APPROVE', 'approval.bulkApprove')
                ->icon('icon-check')->buttonClass('btn btn-success')->listCheck(true);
        }

        if (Acl::forms('squidform.export')) {
            // Not a list-check button: with nothing ticked it exports everything
            // that matches the current filters.
            $toolbar->standardButton('download', 'COM_SQUIDFORM_TOOLBAR_EXPORT', 'export.start')
                ->icon('icon-download');
        }

        // Import submissions (CSV) (0.12.0): into forms where this person may edit submissions.
        if (\SquidForm\Component\SquidForm\Administrator\Engine\Acl::forms('squidform.edit')) {
            $toolbar->linkButton('csvimport', 'COM_SQUIDFORM_TOOLBAR_CSV_IMPORT')->url('index.php?option=com_squidform&view=csvimport')->icon('icon-upload');
        }

        // 0.13.1 add-on hook: onSquidformSubmissionsLinks. An add-on (survey
        // results, say) adds a button for the form being filtered on. Only
        // links inside SquidForm's admin, and only if the person may view it.
        $formId = (int) $this->state->get('filter.form_id');

        if ($formId && \in_array($formId, Acl::forms('squidform.view'), true)) {
            $links = new \ArrayObject();

            try {
                \Joomla\CMS\Plugin\PluginHelper::importPlugin('squidform');
                \Joomla\CMS\Factory::getApplication()->getDispatcher()->dispatch('onSquidformSubmissionsLinks', new \Joomla\Event\Event('onSquidformSubmissionsLinks', ['form_id' => $formId, 'links' => $links]));
            } catch (\Throwable) {
                // No add-ons.
            }

            foreach ($links as $i => $l) {
                $url = \is_array($l) ? (string) ($l['url'] ?? '') : '';

                if (preg_match('#^index\.php\?option=com_squidform[&]#', $url) && !preg_match('/[\s"<>]/', $url)) {
                    $toolbar->linkButton('sfaddon' . (int) $i, mb_substr((string) ($l['label'] ?? 'Add-on'), 0, 60))
                        ->url($url)->icon('icon-' . preg_replace('/[^a-z0-9-]/', '', (string) ($l['icon'] ?? 'chart')));
                }
            }
        }

        if ($canDo->get('core.admin') || $canDo->get('core.options')) {
            $toolbar->preferences('com_squidform');
        }
    }
}
