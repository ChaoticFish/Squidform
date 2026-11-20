<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Submission;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Editor;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Front-end detail (default layout) and edit screen (layout=change). Reached
 * only through a Submissions viewer menu item for the submission's form. The
 * IP address is never shown here.
 */
class HtmlView extends BaseHtmlView
{
    public $item;
    public array $viewer = [];
    public bool $canEdit = false;
    public bool $canDelete = false;
    public string $editHtml = '';
    public string $errorHtml = '';
    public string $seen = '';

    public function display($tpl = null): void
    {
        Renderer::loadLanguage();
        $app          = Factory::getApplication();
        $this->viewer = Viewer::current() ?? throw new \Exception(Text::_('COM_SQUIDFORM_VIEWER_NOT_FOUND'), 404);

        // Guests log in first, whatever the link. Then a submission that
        // doesn't exist and one the person may not see look the same, so
        // links can't be used to probe which requests exist (0.6.0).
        if ($app->getIdentity()->guest) {
            Viewer::denied();
        }

        $item = SubmissionService::load(Ref::idOf($app->getInput()->getString('uid', '')));

        if (!$item || (int) $item->form_id !== $this->viewer['formId'] || !Acl::canViewSubmission($item)) {
            throw new \Exception(Text::_('COM_SQUIDFORM_SUBMISSION_NOT_FOUND'), 404);
        }

        // An old link to layout=edit (before 0.10.2) goes to the edit screen's new name.
        if ($this->getLayout() === 'edit') {
            $app->redirect(Viewer::url('view=submission&layout=change&uid=' . $item->uid, false));
        }

        $this->item      = $item;
        $this->canEdit   = Acl::canEditSubmission($item) && !$item->archived && $item->schema;
        $this->canDelete = Acl::can('squidform.delete', (int) $item->form_id) && Acl::can('squidform.view', (int) $item->form_id);
        $app->allowCache(false);
        Renderer::assets();

        // The edit screen's layout is "change" (0.10.2): templates such as T4 treat
        // layout=edit as Joomla article editing and drop the site's own styles.
        if ($this->getLayout() === 'change') {
            if (!$this->canEdit || (int) $item->checked_out !== (int) $app->getIdentity()->id) {
                $app->redirect(Viewer::url('view=submission&uid=' . $item->uid, false));
            }

            $saved  = (array) $app->getUserState(SubmissionService::editStateKey((int) $item->id), []);
            $data   = $saved['data'] ?? $item->data;
            $errors = $saved['errors'] ?? [];
            $this->seen      = (string) ($saved['seen'] ?? $item->modified);
            $this->editHtml  = Editor::render($item, $item->schema, $data, $errors);
            $this->errorHtml = Renderer::errorSummary($errors, $item->schema, Editor::ID_PREFIX);
            $this->getDocument()->setTitle(Text::sprintf('COM_SQUIDFORM_VIEWER_EDIT_N', Ref::short($item->uid)));
        } elseif ($this->getLayout() === 'sign') {
            // Sign again (0.11.0): only the signer, only after an edit voided it.
            if (!\SquidForm\Component\SquidForm\Administrator\Engine\ESign::canResign($item)) {
                $app->redirect(Viewer::url('view=submission&uid=' . $item->uid, false));
            }

            $this->getDocument()->setTitle(Text::sprintf('COM_SQUIDFORM_ESIGN_SIGN_AGAIN_N', Ref::short($item->uid)));
        } else {
            $item->history = SubmissionService::history((int) $item->id, (int) $item->form_id);
            $item->views   = SubmissionService::viewSummary((int) $item->id);
            SubmissionService::logView($item);
            $this->getDocument()->setTitle(Text::sprintf('COM_SQUIDFORM_VIEWER_SUBMISSION_N', Ref::short($item->uid)));
        }

        parent::display($tpl);
    }

    public function layout(string $name, array $data): string
    {
        return LayoutHelper::render('submission.' . $name, $data, JPATH_ADMINISTRATOR . '/components/com_squidform/layouts');
    }
}
