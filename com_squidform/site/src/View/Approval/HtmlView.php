<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Approval;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * One request as an approver sees it (reached from "Awaiting my approval"
 * or an approval email): the answers, details (never the IP address), the
 * step tracker, the buttons and the trail. Open to anyone who may view the
 * submission, which includes approvers who act or acted on it.
 */
class HtmlView extends BaseHtmlView
{
    public $item;
    public int $itemId = 0;

    public function display($tpl = null): void
    {
        Renderer::loadLanguage();
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if ($user->guest) {
            Viewer::denied();
        }

        // Not found and not allowed look the same (0.6.0).
        $item = SubmissionService::load(Ref::idOf($app->getInput()->getString('uid', '')));

        if (!$item || !Approvals::flow($item) || !Acl::canViewSubmission($item)) {
            throw new \Exception(Text::_('COM_SQUIDFORM_SUBMISSION_NOT_FOUND'), 404);
        }

        $this->item   = $item;
        $this->itemId = (int) ($app->getMenu()->getActive()->id ?? 0);
        SubmissionService::logView($item);
        $app->allowCache(false);
        Renderer::assets();
        $this->getDocument()->setTitle(Text::sprintf('COM_SQUIDFORM_APPROVALS_REQUEST_N', Ref::short($item->uid)));

        parent::display($tpl);
    }

    public function url(string $query, bool $xhtml = true): string
    {
        return Route::_('index.php?option=com_squidform&' . $query . ($this->itemId ? '&Itemid=' . $this->itemId : ''), $xhtml);
    }

    public function layout(string $name, array $data): string
    {
        return LayoutHelper::render('submission.' . $name, $data, JPATH_ADMINISTRATOR . '/components/com_squidform/layouts');
    }
}
