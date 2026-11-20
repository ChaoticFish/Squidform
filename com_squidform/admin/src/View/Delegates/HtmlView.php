<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Delegates;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Delegates;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;
use SquidForm\Component\SquidForm\Administrator\Engine\Users;

\defined('_JEXEC') or die;

/**
 * Standing delegates. Super Users see and manage everyone's; approvers see
 * and manage their own.
 */
class HtmlView extends BaseHtmlView
{
    public array $rows = [];
    public array $people = [];
    public array $groups = [];
    public array $forms = [];
    public array $forAnyone = [];
    public int $me = 0;

    public function display($tpl = null): void
    {
        $user     = Factory::getApplication()->getIdentity();
        $this->me = (int) $user->id;
        $admin    = (bool) $user->authorise('core.admin', 'com_squidform');
        $approvers = Users::withPermission('squidform.approve');

        $this->rows   = Delegates::listFor($admin ? null : $this->me);
        $this->people = Users::labels($admin ? $approvers : array_values(array_filter($approvers, fn ($u) => $u->id !== $this->me)));

        $this->groups = Users::groupsWithPermission('squidform.approve');

        if ($admin) {
            $this->forAnyone = Users::labels($approvers);
        }

        $ids = Acl::forms('squidform.approve', $user);
        $db  = SubmissionStore::db();

        if ($admin) {
            $ids = array_map('intval', $db->setQuery($db->createQuery()->select('id')->from($db->quoteName('#__squidform_forms')))->loadColumn());
        }

        if ($ids) {
            foreach ($db->setQuery(
                $db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__squidform_forms'))
                    ->whereIn($db->quoteName('id'), $ids)->where('state <> 2')->order('title')
            )->loadObjectList() as $r) {
                $this->forms[(int) $r->id] = (string) $r->title;
            }
        }

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_DELEGATES_TITLE'), 'users');
        $this->getDocument()->getToolbar()->linkButton('back', 'COM_SQUIDFORM_SUBMENU_SUBMISSIONS')
            ->url('index.php?option=com_squidform&view=submissions')->icon('icon-inbox');

        parent::display($tpl);
    }
}
