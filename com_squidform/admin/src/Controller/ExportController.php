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
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\ExportService;
use SquidForm\Component\SquidForm\Administrator\Model\ExportModel;

\defined('_JEXEC') or die;

/**
 * export.start     from the Submissions toolbar: remembers the ticked rows, opens the export screen
 * export.download  from the export screen: streams the CSV or JSON file
 *
 * Both need View Submissions and Export Submissions. Every download is
 * written to the audit log. The IP address is never exportable.
 */
class ExportController extends BaseController
{
    public function start(): void
    {
        $this->checkToken();
        $this->assertAllowed();

        $ids = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array'))));
        $this->app->setUserState(ExportModel::STATE_IDS, $ids);

        $this->setRedirect(Route::_('index.php?option=com_squidform&view=export', false));
    }

    public function cancel(): void
    {
        $this->app->setUserState(ExportModel::STATE_IDS, []);
        $this->setRedirect(Route::_('index.php?option=com_squidform&view=submissions', false));
    }

    public function download(): void
    {
        $this->checkToken();
        $this->assertAllowed();

        $formId = $this->input->post->getInt('export_form');
        $scope  = $this->input->post->getCmd('scope') === 'selected' ? 'selected' : 'filtered';
        $format = $this->input->post->getCmd('format') === 'json' ? 'json' : 'csv';
        $keys   = $this->input->post->getCmd('headers') === 'keys';
        $picks  = array_map('strval', (array) $this->input->post->get('cols', [], 'array'));
        $back   = Route::_('index.php?option=com_squidform&view=export&export_form=' . $formId, false);

        /** @var ExportModel $model */
        $model = $this->getModel('Export', 'Administrator', ['ignore_request' => true]);
        $form  = $model->getFormRow($formId);

        if (!$form || !Acl::can('squidform.export', $formId)) {
            $this->setRedirect($back, Text::_('COM_SQUIDFORM_EXPORT_ERR_FORM'), 'error');

            return;
        }

        $ids = $scope === 'selected' ? $model->getSelectedIds($formId) : $model->getFilteredIds($formId);
        $err = ExportService::run($form, $ids, $format, $keys, $picks, $scope, 'admin');

        // run() streams the file and ends the request; it only returns on a problem.
        $this->setRedirect($back, Text::_($err), $err === 'COM_SQUIDFORM_EXPORT_ERR_NONE' ? 'warning' : 'error');
    }

    private function assertAllowed(): void
    {
        $user = $this->app->getIdentity();

        // Per form since 0.3.0: export needs Export and View on the chosen form
        // (checked in download()); here, on at least one form.
        if (!$user->authorise('core.manage', 'com_squidform') || !Acl::forms('squidform.export', $user)) {
            throw new \Exception(Text::_('COM_SQUIDFORM_ERR_NO_EXPORT_PERMISSION'), 403);
        }
    }
}
