<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Export;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Exporter;
use SquidForm\Component\SquidForm\Administrator\Engine\ExportService;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Site\Controller\ExportController;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Front-end export page for the viewer's form (same choices as the admin).
 */
class HtmlView extends BaseHtmlView
{
    public array $viewer = [];
    public string $picklist = '';

    public function display($tpl = null): void
    {
        Renderer::loadLanguage();
        $this->viewer = Viewer::current() ?? throw new \Exception(Text::_('COM_SQUIDFORM_VIEWER_NOT_FOUND'), 404);

        if (!Acl::can('squidform.export', $this->viewer['formId'])) {
            Viewer::denied();
        }

        $cols = Exporter::fieldColumns($this->viewer['formId']);

        $this->picklist = LayoutHelper::render('export.picklist', [
            'picks'         => ExportService::picks($this->viewer['formId']) ?? ExportService::defaultPicks($cols),
            'fieldColumns'  => $cols,
            'selectedCount' => \count(ExportController::tickedIds($this->viewer)),
            'filteredCount' => \count(ExportController::filteredIds()),
        ], JPATH_ADMINISTRATOR . '/components/com_squidform/layouts');

        Factory::getApplication()->allowCache(false);
        $this->getDocument()->setTitle(Text::sprintf('COM_SQUIDFORM_VIEWER_EXPORT_TITLE', $this->viewer['form']['title']));

        parent::display($tpl);
    }
}
