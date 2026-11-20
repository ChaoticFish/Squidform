<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Submission;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Editor;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * Detail view (default layout) and edit screen (layout=edit).
 */
class HtmlView extends BaseHtmlView
{
    public $item;
    public bool $canEdit = false;
    public bool $canDelete = false;

    /** Edit layout: fields HTML, error summary, the "modified" value the editor started from. */
    public string $editHtml = '';
    public string $errorHtml = '';
    public string $seen = '';

    public function display($tpl = null): void
    {
        $app        = Factory::getApplication();
        $this->item = $this->getModel()->getItem();
        $item       = $this->item;

        $this->canEdit   = Acl::canEditSubmission($item) && !$item->archived && $item->schema;
        $this->canDelete = Acl::can('squidform.delete', (int) $item->form_id);

        if ($this->getLayout() === 'edit') {
            // The edit screen needs our lock (taken by submission.edit).
            if (!$this->canEdit || (int) $item->checked_out !== (int) $app->getIdentity()->id) {
                $app->redirect('index.php?option=com_squidform&view=submission&id=' . (int) $item->id);
            }

            $saved  = (array) $app->getUserState(SubmissionService::editStateKey((int) $item->id), []);
            $data   = $saved['data'] ?? $item->data;
            $errors = $saved['errors'] ?? [];
            $this->seen      = (string) ($saved['seen'] ?? $item->modified);
            $this->editHtml  = Editor::render($item, $item->schema, $data, $errors);
            $this->errorHtml = Renderer::errorSummary($errors, $item->schema, Editor::ID_PREFIX);
            Renderer::assets();
            $this->editToolbar();
        } else {
            SubmissionService::logView($item);
            Renderer::assets();
            $this->detailToolbar();
        }

        parent::display($tpl);
    }

    public function layout(string $name, array $data): string
    {
        return LayoutHelper::render('submission.' . $name, $data, JPATH_ADMINISTRATOR . '/components/com_squidform/layouts');
    }

    private function detailToolbar(): void
    {
        ToolbarHelper::title(Text::sprintf('COM_SQUIDFORM_SUBMISSION_TITLE', (int) $this->item->id), 'inbox');
        $tb = $this->getDocument()->getToolbar();
        $tb->linkButton('back', 'JTOOLBAR_BACK')->url('index.php?option=com_squidform&view=submissions')->icon('icon-arrow-left');

        if ($this->canEdit) {
            $tb->standardButton('edit', 'JTOOLBAR_EDIT', 'submission.edit')->icon('icon-edit');
        }

        if ($this->canDelete) {
            if ($this->item->archived) {
                $tb->standardButton('restore', 'COM_SQUIDFORM_TOOLBAR_RESTORE', 'submission.restore')->icon('icon-undo');
            } else {
                $tb->standardButton('archive', 'COM_SQUIDFORM_TOOLBAR_ARCHIVE', 'submission.archive')->icon('icon-archive');
            }
        }

        if ($this->item->archived && Acl::can('squidform.delete.hard', (int) $this->item->form_id)) {
            $tb->standardButton('purge', 'COM_SQUIDFORM_TOOLBAR_PURGE', 'purge.start')->icon('icon-delete')->buttonClass('btn btn-danger');
        }
    }

    private function editToolbar(): void
    {
        ToolbarHelper::title(Text::sprintf('COM_SQUIDFORM_SUBMISSION_EDIT_TITLE', (int) $this->item->id), 'edit');
        $tb = $this->getDocument()->getToolbar();
        $tb->standardButton('save', 'JTOOLBAR_SAVE', 'submission.save')->icon('icon-save')->buttonClass('btn btn-success');
        $tb->standardButton('cancel', 'JTOOLBAR_CANCEL', 'submission.cancel')->icon('icon-times');
    }
}
