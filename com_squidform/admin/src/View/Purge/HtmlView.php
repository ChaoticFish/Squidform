<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Purge;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SquidForm\Component\SquidForm\Administrator\Controller\PurgeController;
use SquidForm\Component\SquidForm\Administrator\Engine\FormData;
use SquidForm\Component\SquidForm\Administrator\Engine\FormPurge;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * Confirmation screen for permanently deleting archived submissions or an
 * archived form. The person types the number of submissions, or the form's alias.
 */
class HtmlView extends BaseHtmlView
{
    public string $kind = 'submissions';

    /** @var int[] */
    public array $ids = [];

    /** @var list<object> */
    public array $forms = [];

    /** @var string[] */
    public array $tables = [];

    public int $rows = 0;
    public int $skipped = 0;

    public function display($tpl = null): void
    {
        $p          = (array) Factory::getApplication()->getUserState(PurgeController::STATE, []);
        $this->kind = ($p['kind'] ?? '') === 'forms' ? 'forms' : 'submissions';
        $asked      = array_map('intval', (array) ($p['ids'] ?? []));

        if ($this->kind === 'forms') {
            $this->forms   = FormPurge::eligible($asked);
            $this->skipped = \count($asked) - \count($this->forms);

            if (\count($this->forms) === 1) {
                $fd = FormData::forForm((int) $this->forms[0]->id);

                foreach ($fd->tables() as $t) {
                    $this->tables[] = FormData::real($t->table_name);
                }

                $db         = \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore::db();
                $this->rows = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__squidform_submissions') . ' WHERE form_id = ' . (int) $this->forms[0]->id)->loadResult();
            }
        } else {
            $this->ids     = SubmissionService::purgeEligible($asked);
            $this->skipped = \count($asked) - \count($this->ids);
        }

        ToolbarHelper::title(Text::_('COM_SQUIDFORM_PURGE_TITLE'), 'delete');
        $this->getDocument()->getToolbar()->standardButton('cancel', 'JTOOLBAR_CANCEL', 'purge.cancel')->icon('icon-times');

        parent::display($tpl);
    }
}
