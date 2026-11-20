<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Chronoforms;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Import\ChronoImport;

\defined('_JEXEC') or die;

/**
 * Import from ChronoForms (0.12.0): choose (default layout), and what
 * happened (report layout: the last import, or one form's record).
 */
class HtmlView extends BaseHtmlView
{
    public array $forms = [];
    public bool $here = false;

    /** ChronoForms 7 (0.12.1) */
    public array $forms7 = [];

    public bool $here7 = false;
    public bool $canLog = false;
    public array $lines = [];

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        if ($this->getLayout() === 'report') {
            $formId = $app->getInput()->getInt('form_id', 0);

            // A stored report: only for someone who may edit that form.
            if ($formId > 0 && !\SquidForm\Component\SquidForm\Administrator\Engine\Acl::can('core.edit', $formId) && !$app->getIdentity()->authorise('core.edit', 'com_squidform')) {
                throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            if ($formId > 0 && ($rec = ChronoImport::recordFor($formId))) {
                $alias       = (string) \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore::db()->setQuery('SELECT alias FROM #__squidform_forms WHERE id = ' . (int) $formId)->loadResult();
                $this->lines = [['title' => (string) $rec->title, 'ok' => true, 'id' => $formId, 'alias' => $alias, 'message' => '', 'logs' => 0,
                    'report' => json_decode((string) $rec->report_json, true) ?: [], 'when' => (string) $rec->created]];
            } else {
                $this->lines = (array) $app->getUserState('com_squidform.chronoimport', []);
            }
        } else {
            $this->here   = ChronoImport::cf8Here();
            $this->forms  = ChronoImport::cf8Forms();
            $this->here7  = ChronoImport::cf7Here();
            $this->forms7 = ChronoImport::cf7Forms();
            $this->canLog = (bool) $app->getIdentity()->authorise('squidform.edit', 'com_squidform');
        }

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_CF_TITLE'), 'upload');
        $this->getDocument()->getToolbar()->linkButton('back', 'COM_SQUIDFORM_SUBMENU_FORMS')->url('index.php?option=com_squidform&view=forms')->icon('icon-list');

        parent::display($tpl);
    }
}
