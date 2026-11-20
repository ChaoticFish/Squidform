<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Resumes;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Resume;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Saved for later (0.11.1): drafts people saved with "Save and finish
 * later", for the forms this person may view submissions of. Who, how far,
 * when, and until when; never the answers.
 */
class HtmlView extends BaseHtmlView
{
    public array $rows = [];
    public array $forms = [];
    public int $formId = 0;
    public string $search = '';
    public bool $canDelete = false;

    public function display($tpl = null): void
    {
        $app          = Factory::getApplication();
        $ids          = Acl::forms('squidform.view');
        $this->formId = $app->getUserStateFromRequest('com_squidform.resumes.form_id', 'form_id', 0, 'int');
        $this->search = (string) $app->getUserStateFromRequest('com_squidform.resumes.search', 'search', '', 'string');
        $this->rows   = Resume::listFor($ids, $this->formId, $this->search);

        if ($ids) {
            $db = SubmissionStore::db();

            foreach ($db->setQuery(
                $db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__squidform_forms'))
                    ->whereIn($db->quoteName('id'), $ids)->where('state <> 2')->order('title')
            )->loadObjectList() as $r) {
                $this->forms[(int) $r->id] = (string) $r->title;
            }
        }

        $this->canDelete = (bool) array_filter($ids, static fn ($id) => Acl::can('squidform.delete', $id));

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_RESUMES_TITLE'), 'clock');
        $tb = $this->getDocument()->getToolbar();

        if ($this->rows) {
            $tb->standardButton('mail', 'COM_SQUIDFORM_RESUMES_RESEND', 'resumes.resend')->icon('icon-envelope')->listCheck(true);

            if ($this->canDelete) {
                $tb->delete('resumes.delete', 'JTOOLBAR_DELETE')->message('COM_SQUIDFORM_RESUMES_DELETE_CONFIRM')->listCheck(true);
            }
        }

        $tb->linkButton('back', 'COM_SQUIDFORM_SUBMENU_SUBMISSIONS')->url('index.php?option=com_squidform&view=submissions')->icon('icon-inbox');

        parent::display($tpl);
    }
}
