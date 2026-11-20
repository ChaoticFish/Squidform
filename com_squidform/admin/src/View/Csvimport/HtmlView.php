<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Csvimport;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Import\CsvImport;

\defined('_JEXEC') or die;

/** Import submissions (CSV) (0.12.0): choose (default), match (map). */
class HtmlView extends BaseHtmlView
{
    public array $forms = [];
    public array $state = [];
    public array $targets = [];
    public string $formTitle = '';
    public int $preselect = 0;

    public function display($tpl = null): void
    {
        $app         = Factory::getApplication();
        $this->state = (array) $app->getUserState('com_squidform.csvimport', []);

        if ($this->getLayout() === 'map') {
            $loaded = !empty($this->state['form']) ? FormRepository::load((int) $this->state['form'], $app->getIdentity(), false) : null;

            if (!$loaded || empty($this->state['file']) || !CsvImport::path((string) $this->state['file'])) {
                $app->redirect('index.php?option=com_squidform&view=csvimport');
            }

            $this->targets   = CsvImport::targets($loaded['schema']);
            $this->formTitle = (string) $loaded['form']['title'];
        } else {
            $this->forms     = CsvImport::forms($app->getIdentity());
            $this->preselect = $app->getInput()->getInt('form_id', 0);
        }

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_CSV_TITLE'), 'upload');
        $this->getDocument()->getToolbar()->linkButton('back', 'COM_SQUIDFORM_SUBMENU_SUBMISSIONS')->url('index.php?option=com_squidform&view=submissions')->icon('icon-inbox');

        parent::display($tpl);
    }
}
