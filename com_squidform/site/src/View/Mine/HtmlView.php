<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\View\Mine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\Resume;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;
use SquidForm\Component\SquidForm\Site\Helper\Viewer;

\defined('_JEXEC') or die;

/**
 * Menu item "SquidForm » My submissions" (0.14.0): everything the signed-in
 * person sent, on every form (or the chosen ones), newest first, and their
 * forms saved for later. Only their own rows, chosen on the server by their
 * account. Guests are sent to log in; guest submissions are never matched
 * by email.
 *
 * A row links to the submission only where View Own Submissions (or more)
 * allows it; otherwise it's listed with its status and no link. The detail
 * page carries the usual actions (edit while pending, fix and resubmit,
 * withdraw, PDF) for those allowed.
 */
class HtmlView extends BaseHtmlView
{
    public const STATUSES = ['submitted', 'pending', 'returned', 'approved', 'rejected', 'withdrawn'];

    public array $items = [];
    public array $saved = [];
    public ?Pagination $pagination = null;
    public int $itemId = 0;
    public string $status = '';
    public bool $showSaved = true;
    public $params;

    public function display($tpl = null): void
    {
        Renderer::loadLanguage();
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            Viewer::denied();
        }

        $this->itemId    = (int) ($app->getMenu()->getActive()->id ?? 0);
        $this->params    = $app->getParams();
        $this->showSaved = (int) $this->params->get('show_saved', 1) === 1;
        $only            = array_values(array_filter(array_map('intval', (array) $this->params->get('forms', []))));
        $limit           = max(5, min(100, (int) $this->params->get('per_page', 20) ?: 20));
        $start           = max(0, $app->getInput()->getInt('limitstart', 0));
        $status          = $app->getInput()->getCmd('status', (string) $this->params->get('status', ''));
        $this->status    = \in_array($status, self::STATUSES, true) || $status === 'saved' && $this->showSaved ? $status : '';
        $uid             = (int) $user->id;
        $db              = SubmissionStore::db();

        $base = $db->createQuery()
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->join('INNER', $db->quoteName('#__squidform_forms', 'f'), 'f.id = s.form_id')
            ->where('s.user_id = :u')->where('s.archived = 0')->where('s.is_test = 0')->where('f.state IN (0, 1)')
            ->bind(':u', $uid, ParameterType::INTEGER);

        if ($only) {
            $base->whereIn($db->quoteName('s.form_id'), $only);
        }

        if ($this->status !== '' && $this->status !== 'saved') {
            $st = $this->status;
            $base->where('s.status = :st')->bind(':st', $st);
        }

        $total = 0;

        if ($this->status !== 'saved') {
            $count = clone $base;
            $total = (int) $db->setQuery($count->select('COUNT(*)'))->loadResult();
            $q     = (clone $base)->select(['s.id', 's.uid', 's.form_id', 's.version_id', 's.status', 's.current_step', 's.created', 's.modified', 's.user_id',
                $db->quoteName('f.title', 'form_title')])->order('s.created DESC, s.id DESC');
            $this->items = $db->setQuery($q, $start, $limit)->loadObjectList();
        }

        foreach ($this->items as $r) {
            $r->canView = Acl::canViewSubmission($r, $user);
            $schema     = null;

            try {
                $schema = FormRepository::version((int) $r->version_id);
            } catch (\Throwable) {
                // An old definition that no longer loads: the plain status is enough.
            }

            $r->statusText = ApprovalFlow::statusText((string) $r->status, (string) $r->current_step, $schema);
        }

        if ($this->showSaved && \in_array($this->status, ['', 'saved'], true)) {
            $this->saved = Resume::mine($uid, $only);
        }

        $this->pagination = new Pagination($total, $start, $limit);
        $this->pagination->setAdditionalUrlParam('status', $this->status);

        $app->allowCache(false);
        Renderer::assets();
        $this->getDocument()->setTitle($this->params->get('page_title', '') ?: Text::_('COM_SQUIDFORM_MINE_TITLE'));

        parent::display($tpl);
    }

    /** URL on this menu item. */
    public function url(string $query, bool $xhtml = true): string
    {
        return Route::_('index.php?option=com_squidform&' . $query . ($this->itemId ? '&Itemid=' . $this->itemId : ''), $xhtml);
    }
}
