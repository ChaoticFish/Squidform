<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Activity;

\defined('_JEXEC') or die;

/**
 * Activity (0.13.0): the filtered list as a CSV file. Same filters and the
 * same rows as the screen (only forms the person may view submissions of).
 */
class ActivityController extends BaseController
{
    public function csv(): void
    {
        $this->checkToken('get');
        $user = $this->app->getIdentity();

        if (!Acl::forms('squidform.view', $user) && !$user->authorise('core.admin', 'com_squidform')) {
            throw new \Exception(Text::_('COM_SQUIDFORM_ERR_NO_VIEW_PERMISSION'), 403);
        }

        $raw = [];

        foreach (['form_id', 'group', 'user', 'from', 'to'] as $k) {
            $raw[$k] = $this->app->getUserState('com_squidform.activity.' . $k, '');
        }

        $csv = Activity::csv(Activity::filters($raw), $user);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="squidform-activity-' . gmdate('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $csv;
        $this->app->close();
    }
}
