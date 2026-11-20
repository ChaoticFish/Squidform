<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\ExportService;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Front-end viewer export:
 *   export.start     from the list: remember the ticked rows, open the export page
 *   export.download  stream the file (ticked rows, or everything the list's filters match)
 * Needs Export Submissions (and View Submissions) on the menu item's form.
 */
class ExportController extends BaseController
{
    public static function idsKey(int $itemId): string
    {
        return 'com_squidform.viewer.export.ids.' . $itemId;
    }

    /** Which list the export came from ('' = the menu item, 'mod<ID>' = a module) and where to go back to. */
    public static function fromKey(int $itemId): string
    {
        return 'com_squidform.viewer.export.from.' . $itemId;
    }

    private function viewer(): array
    {
        Renderer::loadLanguage();
        $v = Viewer::current();

        if (!$v) {
            throw new \Exception(Text::_('COM_SQUIDFORM_VIEWER_NOT_FOUND'), 404);
        }

        if (!Acl::can('squidform.export', $v['formId'])) {
            throw new \Exception(Text::_('COM_SQUIDFORM_ERR_NO_EXPORT_PERMISSION'), 403);
        }

        return $v;
    }

    public function start(): void
    {
        $this->checkToken();
        $v   = $this->viewer();
        // The site posts uids (0.6.0).
        $ids = \SquidForm\Component\SquidForm\Administrator\Engine\Ref::ids((array) $this->input->get('cid', [], 'array'), true);
        $this->app->setUserState(self::idsKey($v['itemId']), $ids);
        $ctx = preg_match('/^mod\d+$/', $this->input->getCmd('sf_ctx')) ? $this->input->getCmd('sf_ctx') : '';
        $this->app->setUserState(self::fromKey($v['itemId']), ['ctx' => $ctx, 'return' => Viewer::returnUrl()]);
        $this->setRedirect(Viewer::url('view=export', false));
    }

    public function download(): void
    {
        $this->checkToken();
        $v      = $this->viewer();
        $scope  = $this->input->post->getCmd('scope') === 'selected' ? 'selected' : 'filtered';
        $format = $this->input->post->getCmd('format') === 'json' ? 'json' : 'csv';
        $keys   = $this->input->post->getCmd('headers') === 'keys';
        $picks  = array_map('strval', (array) $this->input->post->get('cols', [], 'array'));

        $ids = $scope === 'selected' ? self::tickedIds($v) : self::filteredIds();
        $err = ExportService::run((object) $v['form'], $ids, $format, $keys, $picks, $scope, 'viewer');

        $this->setRedirect(Viewer::url('view=export', false), Text::_((string) $err), $err === 'COM_SQUIDFORM_EXPORT_ERR_NONE' ? 'warning' : 'error');
    }

    /** Ticked rows that belong to this viewer's form. @return int[] */
    public static function tickedIds(array $v): array
    {
        $ids = array_map('intval', (array) \Joomla\CMS\Factory::getApplication()->getUserState(self::idsKey($v['itemId']), []));

        if (!$ids) {
            return [];
        }

        $db     = SubmissionStore::db();
        $formId = (int) $v['formId'];

        return array_map('intval', $db->setQuery(
            $db->createQuery()->select('id')->from($db->quoteName('#__squidform_submissions'))
                ->whereIn($db->quoteName('id'), $ids)->where('form_id = :f')->order('id')
                ->bind(':f', $formId, ParameterType::INTEGER)
        )->loadColumn());
    }

    /**
     * Everything the list's current filters match: the menu item's list, or
     * the module's list the export was started from.
     *
     * @return int[]
     */
    public static function filteredIds(): array
    {
        $app  = \Joomla\CMS\Factory::getApplication();
        $menu = Viewer::current();
        $from = $menu ? (array) $app->getUserState(self::fromKey($menu['itemId']), []) : [];
        $ctx  = Viewer::fromKey((string) ($from['ctx'] ?? '')) ?? $menu;

        /** @var \SquidForm\Component\SquidForm\Site\Model\SubmissionsModel $m */
        $m = $app->bootComponent('com_squidform')->getMVCFactory()->createModel('Submissions', 'Site', ['ignore_request' => false, 'viewer' => $ctx]);

        return $m->getAllIds();
    }

    /** Where the export page's "Back" goes. */
    public static function backUrl(): string
    {
        $menu = Viewer::current();
        $from = $menu ? (array) \Joomla\CMS\Factory::getApplication()->getUserState(self::fromKey($menu['itemId']), []) : [];

        return (string) (($from['return'] ?? null) ?: Viewer::listUrl());
    }
}
