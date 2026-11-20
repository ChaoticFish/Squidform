<?php

/**
 * @package     SquidForm
 * @subpackage  mod_squidform_viewer
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Module\SquidFormViewer\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * The SquidForm submissions viewer in a module position. It points at a
 * "Submissions viewer" menu item, which fixes the form and is where the
 * detail, edit and export pages open. People who may not see the form's
 * submissions see nothing here (no login prompt in a module).
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData()
    {
        $data         = parent::getLayoutData();
        $data['html'] = '';

        if (!class_exists(Viewer::class)) {
            return $data;
        }

        try {
            Renderer::loadLanguage();
            $ctx = Viewer::forModule((int) $this->module->id, $data['params']);

            if (!$ctx) {
                return $data;
            }

            $app = Factory::getApplication();

            /** @var \SquidForm\Component\SquidForm\Site\Model\SubmissionsModel $model */
            $model = $app->bootComponent('com_squidform')->getMVCFactory()
                ->createModel('Submissions', 'Site', ['ignore_request' => false, 'viewer' => $ctx]);
            $scope = $model->scope();

            if ($scope === '') {
                return $data;
            }

            $app->allowCache(false);
            Renderer::assets();

            $data['html'] = LayoutHelper::render('viewer.list', [
                'ctx'        => $ctx,
                'items'      => $model->getItems(),
                'pagination' => $model->getPagination(),
                'state'      => $model->getState(),
                'canExport'  => Acl::can('squidform.export', $ctx['formId']),
                'canDelete'  => Acl::can('squidform.delete', $ctx['formId']) && $scope === 'all',
                'scope'      => $scope,
                'heading'    => '',
                'headingTag' => 'h2',
            ], JPATH_SITE . '/components/com_squidform/layouts');
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('SquidForm viewer module ' . (int) $this->module->id . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'squidform');
        }

        return $data;
    }
}
