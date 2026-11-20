<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Form;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;

\defined('_JEXEC') or die;

/**
 * Menu item "SquidForm » Form": shows one form chosen in the menu item.
 */
class HtmlView extends BaseHtmlView
{
    /** @var string rendered form HTML */
    protected $formHtml = '';

    /** @var \Joomla\Registry\Registry */
    protected $params;

    public function display($tpl = null): void
    {
        $app          = Factory::getApplication();
        $this->params = $app->getParams();
        $alias        = $app->getInput()->getCmd('form');

        $this->formHtml = $alias !== '' ? Renderer::render($alias) : '';

        parent::display($tpl);
    }
}
