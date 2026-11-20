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
use SquidForm\Component\SquidForm\Administrator\Engine\FormPurge;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * Permanent deletion of archived items, behind a typed confirmation:
 *   purge.start  from the Forms or Submissions list (Archived filter): remember the ticked items
 *   purge.run    from the confirmation screen
 * Needs Permanently Delete Submissions (squidform.delete.hard).
 */
class PurgeController extends BaseController
{
    public const STATE = 'com_squidform.purge';

    public function start(): void
    {
        $this->checkToken();
        $kind = $this->input->getCmd('view') === 'forms' || $this->input->getCmd('kind') === 'forms' ? 'forms' : 'submissions';
        $ids  = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array'))));

        $this->app->setUserState(self::STATE, ['kind' => $kind, 'ids' => $ids]);
        $this->setRedirect(Route::_('index.php?option=com_squidform&view=purge', false));
    }

    public function cancel(): void
    {
        $p = (array) $this->app->getUserState(self::STATE, []);
        $this->app->setUserState(self::STATE, null);
        $this->setRedirect($this->back((string) ($p['kind'] ?? 'submissions')));
    }

    public function run(): void
    {
        $this->checkToken();
        $p    = (array) $this->app->getUserState(self::STATE, []);
        $kind = (string) ($p['kind'] ?? '');
        $ids  = array_map('intval', (array) ($p['ids'] ?? []));
        $typed = trim($this->input->post->getString('confirm'));

        if ($kind === 'forms') {
            $forms = FormPurge::eligible($ids);

            if (\count($forms) !== 1 || $typed !== $forms[0]->alias) {
                $this->setRedirect(Route::_('index.php?option=com_squidform&view=purge', false), Text::_('COM_SQUIDFORM_PURGE_TYPE_MISMATCH'), 'error');

                return;
            }

            $r = FormPurge::run((int) $forms[0]->id);
            $this->app->setUserState(self::STATE, null);
            $this->setRedirect($this->back('forms'), Text::sprintf('COM_SQUIDFORM_PURGE_FORM_DONE', $forms[0]->title, \count($r['tables']), $r['rows']));

            return;
        }

        $eligible = SubmissionService::purgeEligible($ids);

        if (!$eligible || $typed !== (string) \count($eligible)) {
            $this->setRedirect(Route::_('index.php?option=com_squidform&view=purge', false), Text::_('COM_SQUIDFORM_PURGE_TYPE_MISMATCH'), 'error');

            return;
        }

        $n = SubmissionService::purge($eligible);
        $this->app->setUserState(self::STATE, null);
        $this->setRedirect($this->back('submissions'), Text::plural('COM_SQUIDFORM_PURGE_SUBMISSIONS_DONE', $n));
    }

    private function back(string $kind): string
    {
        return Route::_('index.php?option=com_squidform&view=' . ($kind === 'forms' ? 'forms' : 'submissions'), false);
    }
}
