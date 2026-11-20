<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Activity;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Activity;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Activity (0.13.0): the audit trail of the forms this person may view
 * submissions of, newest first, with filters and a CSV download.
 */
class HtmlView extends BaseHtmlView
{
    public array $rows = [];
    public array $forms = [];
    public array $filters = [];
    public int $total = 0;
    public ?Pagination $pagination = null;

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        $raw = [];

        foreach (['form_id' => 'int', 'group' => 'cmd', 'user' => 'string', 'from' => 'string', 'to' => 'string'] as $k => $type) {
            $raw[$k] = $app->getUserStateFromRequest('com_squidform.activity.' . $k, $k, $type === 'int' ? 0 : '', $type);
        }

        $this->filters = Activity::filters($raw);
        $start         = max(0, $app->getInput()->getInt('limitstart', 0));
        $found         = Activity::find($this->filters, $start);
        $this->rows    = $found['rows'];
        $this->total   = $found['total'];
        $this->pagination = new Pagination($this->total, $start, Activity::PER_PAGE);

        $ids = Acl::forms('squidform.view');

        if ($ids) {
            $db = SubmissionStore::db();

            foreach ($db->setQuery(
                $db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__squidform_forms'))
                    ->whereIn($db->quoteName('id'), $ids)->order('title')
            )->loadObjectList() as $r) {
                $this->forms[(int) $r->id] = (string) $r->title;
            }
        }

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_ACTIVITY_TITLE'), 'list');
        $tb = $this->getDocument()->getToolbar();

        if ($this->total) {
            $tb->linkButton('download', 'COM_SQUIDFORM_ACTIVITY_CSV')
                ->url('index.php?option=com_squidform&task=activity.csv&' . \Joomla\CMS\Session\Session::getFormToken() . '=1')
                ->icon('icon-download');
        }

        $tb->linkButton('back', 'COM_SQUIDFORM_SUBMENU_SUBMISSIONS')->url('index.php?option=com_squidform&view=submissions')->icon('icon-inbox');

        parent::display($tpl);
    }
}
