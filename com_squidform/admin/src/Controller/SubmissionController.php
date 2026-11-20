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
 * submission.edit / save / cancel / options / delete / restore (admin).
 */
class SubmissionController extends BaseController
{
    use SubmissionTasksTrait;

    protected function detailUrl(int $id): string
    {
        return Route::_('index.php?option=com_squidform&view=submission&id=' . $id, false);
    }

    protected function editUrl(int $id): string
    {
        return Route::_('index.php?option=com_squidform&view=submission&layout=edit&id=' . $id, false);
    }

    protected function listUrl(): string
    {
        return Route::_('index.php?option=com_squidform&view=submissions', false);
    }
}
