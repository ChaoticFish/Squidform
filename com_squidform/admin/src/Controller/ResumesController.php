<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Resume;

\defined('_JEXEC') or die;

/**
 * Saved for later (0.11.1): staff resend someone's way back in, or delete a
 * draft. Resend needs View Submissions on the draft's form; Delete also
 * needs Archive Submissions.
 */
class ResumesController extends BaseController
{
    private const LIST = 'index.php?option=com_squidform&view=resumes';

    public function resend(): void
    {
        $this->checkToken();
        $sent = 0;
        $errs = [];

        foreach ($this->rows('squidform.view') as $row) {
            $err = Resume::resend($row, $this->app->getIdentity());
            $err === null ? $sent++ : $errs[] = $err;
        }

        if ($sent) {
            $this->app->enqueueMessage(Text::plural('COM_SQUIDFORM_RESUMES_N_RESENT', $sent), 'success');
        }

        foreach (array_unique($errs) as $e) {
            $this->app->enqueueMessage($e, 'error');
        }

        $this->setRedirect(self::LIST);
    }

    public function delete(): void
    {
        $this->checkToken();
        $n = 0;

        foreach ($this->rows('squidform.delete') as $row) {
            Resume::remove($row, $this->app->getIdentity());
            $n++;
        }

        $this->setRedirect(self::LIST, Text::plural('COM_SQUIDFORM_RESUMES_N_DELETED', $n), $n ? 'success' : 'warning');
    }

    /** The ticked drafts this person may act on. @return list<object> */
    private function rows(string $also): array
    {
        $out = [];

        foreach (array_unique(array_map('intval', (array) $this->input->get('cid', [], 'array'))) as $id) {
            $row = $id > 0 ? Resume::byId($id) : null;

            if ($row && Acl::can('squidform.view', (int) $row->form_id) && Acl::can($also, (int) $row->form_id)) {
                $out[] = $row;
            }
        }

        if (!$out) {
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_RESUMES_NONE_TICKED'), 'warning');
        }

        return $out;
    }
}
