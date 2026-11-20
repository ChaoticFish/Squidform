<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\Resume;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * My submissions (0.14.0): mine.deletesaved deletes one of the signed-in
 * person's own forms saved for later (and its uploaded files).
 */
class MineController extends BaseController
{
    public function deletesaved(): void
    {
        Renderer::loadLanguage();
        $this->checkToken();
        $user = $this->app->getIdentity();

        if (!$user || $user->guest) {
            Viewer::denied();
        }

        $ok     = Resume::removeOwn((int) $this->input->post->getInt('id', 0), $user);
        $itemId = $this->input->getInt('Itemid');
        $this->setRedirect(Route::_('index.php?option=com_squidform&view=mine' . ($itemId ? '&Itemid=' . $itemId : ''), false),
            Text::_($ok ? 'COM_SQUIDFORM_MINE_DELETED' : 'COM_SQUIDFORM_MINE_NOT_DELETED'), $ok ? 'message' : 'warning');
    }
}
