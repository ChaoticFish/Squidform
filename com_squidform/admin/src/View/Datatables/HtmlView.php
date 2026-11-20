<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Datatables;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\DataTables;

\defined('_JEXEC') or die;

/**
 * Data tables (0.14.0): the list (default layout) and one table (layout "table").
 */
class HtmlView extends BaseHtmlView
{
    public array $tables = [];
    public array $leftovers = [];
    public ?object $table = null;
    public array $columns = [];
    public array $rows = [];
    public ?Pagination $pagination = null;
    public bool $canTidy = false;

    public function display($tpl = null): void
    {
        $app           = Factory::getApplication();
        $user          = $app->getIdentity();
        $this->canTidy = $user->authorise('squidform.delete.hard', 'com_squidform');
        $tb            = $this->getDocument()->getToolbar();

        if ($this->getLayout() === 'table') {
            $this->table = DataTables::find($app->getInput()->getInt('id', 0), $app->getInput()->getCmd('name', ''));

            if ($this->table === null) {
                throw new \Exception(Text::_('COM_SQUIDFORM_DT_NOT_FOUND'), 404);
            }

            $start            = max(0, $app->getInput()->getInt('limitstart', 0));
            $this->columns    = DataTables::columns($this->table);
            $this->rows       = DataTables::rows($this->table, $start);
            $this->pagination = new Pagination((int) $this->table->rows, $start, DataTables::PER_PAGE);

            ToolbarHelper::title(Text::sprintf('COM_SQUIDFORM_DT_TABLE_TITLE', $this->table->table_name), 'database');

            if ($this->table->exists && $this->table->rows) {
                $tb->linkButton('download', 'COM_SQUIDFORM_DT_CSV')
                    ->url('index.php?option=com_squidform&task=datatables.csv&' . ($this->table->id ? 'id=' . (int) $this->table->id : 'name=' . rawurlencode((string) $this->table->table_name)) . '&' . Session::getFormToken() . '=1')
                    ->icon('icon-download');
            }

            $tb->linkButton('back', 'COM_SQUIDFORM_DT_ALL')->url('index.php?option=com_squidform&view=datatables')->icon('icon-arrow-left');
        } else {
            $this->tables    = DataTables::all();
            $this->leftovers = DataTables::leftovers($this->tables);
            ToolbarHelper::title(Text::_('COM_SQUIDFORM_DT_TITLE'), 'database');
            $tb->linkButton('back', 'COM_SQUIDFORM_SUBMENU_FORMS')->url('index.php?option=com_squidform&view=forms')->icon('icon-list');
        }

        parent::display($tpl);
    }

    /** "12.4 KB" */
    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(0, $bytes) / 1024, 1) . ' KB';
    }
}
