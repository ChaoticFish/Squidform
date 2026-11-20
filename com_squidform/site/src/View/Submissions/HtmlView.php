<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Submissions;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Menu item "SquidForm » Submissions viewer": list with search, filters,
 * sort and paging; export and delete for those allowed.
 */
class HtmlView extends BaseHtmlView
{
    public array $viewer = [];
    public array $items = [];
    public $pagination;
    public $state;
    public $params;
    public bool $canExport = false;
    public bool $canDelete = false;
    public string $scope = '';

    public function display($tpl = null): void
    {
        Renderer::loadLanguage();
        $this->viewer = Viewer::current() ?? throw new \Exception(Text::_('COM_SQUIDFORM_VIEWER_NOT_FOUND'), 404);

        /** @var \SquidForm\Component\SquidForm\Site\Model\SubmissionsModel $model */
        $model       = $this->getModel();
        $this->scope = $model->scope();

        if ($this->scope === '') {
            Viewer::denied();
        }

        $this->state      = $model->getState();
        $this->items      = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->params     = Factory::getApplication()->getParams();
        $this->canExport  = Acl::can('squidform.export', $this->viewer['formId']);
        $this->canDelete  = Acl::can('squidform.delete', $this->viewer['formId']) && $this->scope === 'all';

        Factory::getApplication()->allowCache(false);
        Renderer::assets();
        $this->getDocument()->setTitle($this->params->get('page_title', '') ?: $this->viewer['form']['title']);

        parent::display($tpl);
    }
}
