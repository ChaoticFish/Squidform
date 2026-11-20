<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * List actions for submissions. Archive keeps the submission (hidden from the
 * list, restorable) and is written to the audit log. Restore undoes it. Each
 * ticked row is checked against its own form's Archive permission.
 */
class SubmissionsController extends BaseController
{
    public function archive(): void
    {
        $this->change(1);
    }

    public function restore(): void
    {
        $this->change(0);
    }

    /** Check in the ticked submissions (0.10.2), the padlock in the list. */
    public function checkin(): void
    {
        $this->checkToken();
        $ids = array_map('intval', (array) $this->input->get('cid', [], 'array'));
        $n   = SubmissionService::checkin($ids);

        $this->setRedirect(
            Route::_('index.php?option=com_squidform&view=submissions', false),
            $n ? Text::plural('COM_SQUIDFORM_N_CHECKED_IN', $n) : Text::_('COM_SQUIDFORM_NOTHING_CHANGED'),
            $n ? 'message' : 'warning'
        );
    }

    private function change(int $flag): void
    {
        $this->checkToken();
        $ids = array_map('intval', (array) $this->input->get('cid', [], 'array'));
        $n   = SubmissionService::setArchived($ids, $flag);
        $key = $flag ? 'COM_SQUIDFORM_SUBMISSIONS_N_ITEMS_ARCHIVED' : 'COM_SQUIDFORM_SUBMISSIONS_N_ITEMS_RESTORED';

        $this->setRedirect(
            Route::_('index.php?option=com_squidform&view=submissions', false),
            $n ? Text::plural($key, $n) : Text::_('COM_SQUIDFORM_NOTHING_CHANGED'),
            $n ? 'message' : 'warning'
        );
    }
}
