<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * One submission for the admin detail and edit screens.
 */
class SubmissionModel extends BaseDatabaseModel
{
    /**
     * @return object  SubmissionService::load() row + history, views
     *
     * @throws \Exception 404 when missing or not allowed
     */
    public function getItem(?int $id = null): object
    {
        $id ??= Factory::getApplication()->getInput()->getInt('id');
        $item = SubmissionService::load((int) $id);

        if (!$item || !Acl::canViewSubmission($item)) {
            throw new \Exception(Text::_('COM_SQUIDFORM_SUBMISSION_NOT_FOUND'), 404);
        }

        $item->history = SubmissionService::history((int) $item->id, (int) $item->form_id);
        $item->views   = SubmissionService::viewSummary((int) $item->id);

        return $item;
    }
}
