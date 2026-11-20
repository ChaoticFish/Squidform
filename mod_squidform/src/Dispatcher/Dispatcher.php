<?php

/**
 * @package     SquidForm
 * @subpackage  mod_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Module\SquidForm\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;

\defined('_JEXEC') or die;

/**
 * Renders the chosen SquidForm form in a module position.
 * Requires com_squidform to be installed and enabled.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData()
    {
        $data  = parent::getLayoutData();
        $alias = (string) $data['params']->get('form', '');

        $data['formHtml'] = ($alias !== '' && class_exists(Renderer::class)) ? Renderer::render($alias) : '';

        return $data;
    }
}
