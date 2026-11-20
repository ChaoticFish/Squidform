<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/**
 * approval.approve / reject / return / reassign / bulkApprove / withdraw /
 * resubmit / addDelegate / removeDelegate (admin).
 */
class ApprovalController extends BaseController
{
    use ApprovalTasksTrait;

    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);
        $this->registerTask('return', 'returnForChanges');
    }

    protected function approvalBackUrl(int $id): string
    {
        return Route::_('index.php?option=com_squidform&view=submission&id=' . $id, false);
    }

    protected function approvalListUrl(): string
    {
        return Route::_('index.php?option=com_squidform&view=submissions', false);
    }
}
