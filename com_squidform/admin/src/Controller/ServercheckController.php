<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use SquidForm\Component\SquidForm\Administrator\Engine\Scanner;

\defined('_JEXEC') or die;

/** "Check this server": the EICAR test (0.9.0). */
class ServercheckController extends BaseController
{
    /** Sends the EICAR test file (from memory, never saved) to the scanner set in the Options. */
    public function eicar(): void
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_squidform')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $bytes = Scanner::testBytes();

        if ($bytes === null) {
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_SC_EICAR_ADDON'), 'warning');
            $this->setRedirect('index.php?option=com_squidform&view=servercheck');

            return;
        }

        $r = Scanner::scanBytes($bytes);

        if ($r['status'] === Scanner::INFECTED) {
            $this->app->enqueueMessage(Text::sprintf('COM_SQUIDFORM_SC_EICAR_OK', $r['detail']), 'success');
        } elseif ($r['status'] === Scanner::CLEAN) {
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_SC_EICAR_MISSED'), 'warning');
        } else {
            $this->app->enqueueMessage(Text::sprintf('COM_SQUIDFORM_SC_EICAR_ERROR', $r['detail']), 'error');
        }

        $this->setRedirect('index.php?option=com_squidform&view=servercheck');
    }
}
