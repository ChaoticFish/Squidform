<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Approvals;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\Delegates;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;
use SquidForm\Component\SquidForm\Administrator\Engine\Users;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Menu item "SquidForm » Awaiting my approval": every request, on any form,
 * whose current step the signed-in person can act on (themselves or as a
 * delegate), oldest waiting first; bulk approve; and the person's delegates.
 */
class HtmlView extends BaseHtmlView
{
    public array $items = [];
    public ?Pagination $pagination = null;
    public int $itemId = 0;
    public array $delegations = [];
    public array $delegateChoices = [];
    public array $groupChoices = [];
    public array $formChoices = [];
    public bool $canDelegate = false;
    public $params;

    public function display($tpl = null): void
    {
        Renderer::loadLanguage();
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if ($user->guest) {
            Viewer::denied();
        }

        $this->itemId = (int) ($app->getMenu()->getActive()->id ?? 0);
        $this->params = $app->getParams();
        $start        = max(0, $app->getInput()->getInt('limitstart', 0));
        $limit        = 20;
        $db           = SubmissionStore::db();

        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_submissions', 's'));
        Approvals::whereAwaiting($count, $user);
        $total = (int) $db->setQuery($count)->loadResult();

        $q = $db->createQuery()
            ->select(['s.id', 's.uid', 's.form_id', 's.version_id', 's.status', 's.current_step', 's.approval_round', 's.step_since', 's.user_id', 's.created',
                $db->quoteName('f.title', 'form_title'), $db->quoteName('u.name', 'user_name')])
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->join('LEFT', $db->quoteName('#__squidform_forms', 'f'), 'f.id = s.form_id')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = s.user_id')
            ->order('s.step_since ASC, s.id ASC');
        Approvals::whereAwaiting($q, $user);
        $this->items = $db->setQuery($q, $start, $limit)->loadObjectList();

        $this->pagination = new Pagination($total, $start, $limit);

        $this->canDelegate = Delegates::canManage((int) $user->id, $user);

        if ($this->canDelegate) {
            $this->delegations     = Delegates::listFor((int) $user->id);
            $this->delegateChoices = Users::labels(array_values(array_filter(
                Users::withPermission('squidform.approve'),
                static fn ($u) => $u->id !== (int) $user->id
            )));

            $this->groupChoices = Users::groupsWithPermission('squidform.approve');
            $forms = Acl::forms('squidform.approve', $user);

            if ($forms) {
                $rows = $db->setQuery(
                    $db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__squidform_forms'))
                        ->whereIn($db->quoteName('id'), $forms)->where('state <> 2')->order('title')
                )->loadObjectList();

                foreach ($rows as $r) {
                    $this->formChoices[(int) $r->id] = (string) $r->title;
                }
            }
        }

        $app->allowCache(false);
        Renderer::assets();
        $this->getDocument()->setTitle($this->params->get('page_title', '') ?: Text::_('COM_SQUIDFORM_APPROVALS_TITLE'));

        parent::display($tpl);
    }

    /** URL on this menu item. */
    public function url(string $query, bool $xhtml = true): string
    {
        return \Joomla\CMS\Router\Route::_('index.php?option=com_squidform&' . $query . ($this->itemId ? '&Itemid=' . $this->itemId : ''), $xhtml);
    }
}
