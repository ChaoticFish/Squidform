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
        $this->setTitle($alias);

        parent::display($tpl);
    }

    /**
     * The browser tab's title (1.0.4, WCAG 2.4.2): the menu item's page title
     * when this menu item is for this form, otherwise the form's title; on a
     * form with several pages, which step it's on comes first, so each page
     * has its own title ("Step 2 of 3: Contact details - Visitor request").
     */
    private function setTitle(string $alias): void
    {
        $t = Renderer::$lastTitle;

        if ($t === null) {
            return;
        }

        $menu = Factory::getApplication()->getMenu()->getActive();
        $mine = $menu && ($menu->query['option'] ?? '') === 'com_squidform' && ($menu->query['view'] ?? '') === 'form' && ($menu->query['form'] ?? '') === $alias;
        $base = $mine ? (string) ($this->params->get('page_title', '') ?: $menu->title) : $t['form'];
        $base = $base !== '' ? $base : $t['form'];

        $this->setDocumentTitle($t['step'] !== '' ? $t['step'] . ' - ' . $base : $base);
    }
}
