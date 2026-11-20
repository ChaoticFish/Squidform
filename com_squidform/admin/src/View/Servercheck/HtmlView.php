<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Servercheck;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Scanner;
use SquidForm\Component\SquidForm\Administrator\Engine\ServerCheck;

\defined('_JEXEC') or die;

/** "Check this server" (0.9.0): what uploads and virus scanning need. */
class HtmlView extends BaseHtmlView
{
    /** @var list<array{label: string, status: string, value: string, help: string}> */
    public array $checks = [];

    public bool $canTest = false;

    public bool $hasAddon = false;

    public function display($tpl = null): void
    {
        $this->checks  = ServerCheck::run();
        $this->hasAddon = Scanner::addon() !== null;
        $this->canTest  = Scanner::config()['mode'] !== 'none' && $this->hasAddon;

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_SERVERCHECK_TITLE'), 'heartbeat');
        ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_config&view=component&component=com_squidform');
        ToolbarHelper::preferences('com_squidform');

        parent::display($tpl);
    }
}
