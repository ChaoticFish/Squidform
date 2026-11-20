<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Controller\ApprovalTasksTrait;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Front-end approval tasks (the "Awaiting my approval" page and the
 * submitter's viewer). Guests are sent to log in.
 */
class ApprovalController extends BaseController
{
    use ApprovalTasksTrait;

    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);
        $this->registerTask('return', 'returnForChanges');
    }

    public function execute($task)
    {
        Renderer::loadLanguage();

        if ($this->app->getIdentity()->guest) {
            Viewer::denied();
        }

        return parent::execute($task);
    }

    protected function approvalUidOnly(): bool
    {
        return true;
    }

    protected function approvalBackUrl(int $id): string
    {
        $itemId = $this->input->getInt('Itemid');

        return Route::_('index.php?option=com_squidform&view=approval&uid=' . Ref::uidOf($id) . ($itemId ? '&Itemid=' . $itemId : ''), false);
    }

    protected function approvalListUrl(): string
    {
        $itemId = $this->input->getInt('Itemid');

        return Route::_($itemId ? 'index.php?Itemid=' . $itemId : 'index.php?option=com_squidform&view=approvals', false);
    }
}
