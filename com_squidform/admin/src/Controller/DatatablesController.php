<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Engine\DataTables;

\defined('_JEXEC') or die;

/**
 * Data tables (0.14.0). Reading needs Admin on SquidForm; the tidy-up also
 * needs Permanently Delete Submissions, a POST with the form token and the
 * name typed in full.
 */
class DatatablesController extends BaseController
{
    public function csv(): void
    {
        $this->checkToken('get');
        $t = $this->table(false);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $t->table_name) . '-' . gmdate('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo DataTables::csv($t);
        $this->app->close();
    }

    public function dropcolumn(): void
    {
        $this->checkToken();
        $t      = $this->table(true);
        $column = $this->input->post->getString('column');
        $ok     = DataTables::dropColumn($t, $column, trim($this->input->post->getString('confirm')));
        $this->setRedirect($this->back($t), Text::sprintf($ok ? 'COM_SQUIDFORM_DT_COLUMN_DROPPED' : 'COM_SQUIDFORM_DT_TYPE_MISMATCH', $column), $ok ? 'message' : 'error');
    }

    public function droptable(): void
    {
        $this->checkToken();
        $t  = $this->table(true);
        $ok = DataTables::dropTable($t, trim($this->input->post->getString('confirm')));
        $this->setRedirect($ok ? Route::_('index.php?option=com_squidform&view=datatables', false) : $this->back($t),
            Text::sprintf($ok ? 'COM_SQUIDFORM_DT_TABLE_DROPPED' : 'COM_SQUIDFORM_DT_TYPE_MISMATCH', $t->table_name), $ok ? 'message' : 'error');
    }

    public function forget(): void
    {
        $this->checkToken();
        $t  = $this->table(true);
        $ok = DataTables::forgetTable($t);
        $this->setRedirect(Route::_('index.php?option=com_squidform&view=datatables', false),
            Text::sprintf($ok ? 'COM_SQUIDFORM_DT_FORGOTTEN' : 'COM_SQUIDFORM_DT_NOT_MISSING', $t->table_name), $ok ? 'message' : 'error');
    }

    private function table(bool $tidy): object
    {
        $user = $this->app->getIdentity();

        if (!$user->authorise('core.admin', 'com_squidform') || $tidy && !$user->authorise('squidform.delete.hard', 'com_squidform')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $t = DataTables::find($this->input->getInt('id', 0), $this->input->getCmd('name', ''));

        if ($t === null) {
            throw new \Exception(Text::_('COM_SQUIDFORM_DT_NOT_FOUND'), 404);
        }

        return $t;
    }

    private function back(object $t): string
    {
        return Route::_('index.php?option=com_squidform&view=datatables&layout=table&' . ($t->id ? 'id=' . (int) $t->id : 'name=' . rawurlencode((string) $t->table_name)), false);
    }
}
