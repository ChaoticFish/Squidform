<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

class DisplayController extends BaseController
{
    protected $default_view = 'form';

    public function display($cachable = false, $urlparams = [])
    {
        // Forms carry one-time tokens: never cache this view.
        return parent::display(false, $urlparams);
    }
}
