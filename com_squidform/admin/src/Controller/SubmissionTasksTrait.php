<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\Options;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * One submission's tasks, shared by the admin controller and the front-end
 * viewer controller: edit (take the lock), save, cancel, cascading options
 * for the edit screen, archive and restore. Every task checks the form's
 * permissions; every change is audited by SubmissionService.
 */
trait SubmissionTasksTrait
{
    abstract protected function detailUrl(int $id): string;

    abstract protected function editUrl(int $id): string;

    abstract protected function listUrl(): string;

    /** The site refers to submissions only by uid (0.6.0); the admin also accepts ids. */
    protected function uidOnly(): bool
    {
        return false;
    }

    /** The submission this request is about: "uid", or (admin) "id". 0 when none. */
    protected function submissionId(): int
    {
        $uid = $this->input->getString('uid', '');

        if ($uid !== '') {
            return Ref::idOf($uid);
        }

        return $this->uidOnly() ? 0 : $this->input->getInt('id');
    }

    /** @return int[] ticked submissions (cid[]), or the single one */
    protected function requestIds(): array
    {
        $ids = Ref::ids((array) $this->input->get('cid', [], 'array'), $this->uidOnly());

        if (!$ids && ($one = $this->submissionId())) {
            $ids = [$one];
        }

        return $ids;
    }

    /** Front-end viewers limit a menu item to its form; the admin allows any form. */
    protected function formAllowedHere(int $formId): bool
    {
        return true;
    }

    protected function loadAllowed(int $id): object
    {
        $item = SubmissionService::load($id);

        if (!$item || !$this->formAllowedHere((int) $item->form_id) || !Acl::canViewSubmission($item)) {
            throw new \Exception(Text::_('COM_SQUIDFORM_SUBMISSION_NOT_FOUND'), 404);
        }

        return $item;
    }

    /** Download this submission as a PDF (0.11.0): anyone who may see it. */
    public function pdf(): void
    {
        $id   = $this->submissionId();
        $item = $this->loadAllowed($id);
        \SquidForm\Component\SquidForm\Administrator\Engine\Audit::log((int) $item->id, (int) $item->form_id, (int) $this->app->getIdentity()->id, 'pdf');
        $pdf  = \SquidForm\Component\SquidForm\Administrator\Engine\Pdf\Pdf::class;
        $pdf::send($pdf::bytes($pdf::submission($item, 'staff')), $pdf::fileName($item));
    }

    /** Sign again (0.11.0): the signer, after an edit voided their e-signature. */
    public function resign(): void
    {
        $this->checkToken();
        $id   = $this->submissionId();
        $item = $this->loadAllowed($id);
        $in   = $this->input->post->get('sf', [], 'raw');
        $err  = \SquidForm\Component\SquidForm\Administrator\Engine\ESign::resign($item, \is_array($in) ? $in : [], $this->app->getIdentity());

        if ($err !== null) {
            $this->setRedirect($this->signUrl($id), $err, 'error');

            return;
        }

        $this->setRedirect($this->detailUrl($id), Text::_('COM_SQUIDFORM_ESIGN_SIGNED_AGAIN'));
    }

    /** Where "Sign again" lives (the site only). */
    protected function signUrl(int $id): string
    {
        return $this->detailUrl($id);
    }

    public function edit(): void
    {
        $this->checkToken('request');
        $id   = $this->submissionId();
        $item = $this->loadAllowed($id);

        if (!Acl::canEditSubmission($item) || $item->archived) {
            $this->setRedirect($this->detailUrl($id), Text::_('COM_SQUIDFORM_ERR_NO_EDIT_PERMISSION'), 'error');

            return;
        }

        if ($msg = SubmissionService::lock($item)) {
            $this->setRedirect($this->detailUrl($id), $msg, 'warning');

            return;
        }

        $this->app->setUserState(SubmissionService::editStateKey($id), null);
        $this->setRedirect($this->editUrl($id));
    }

    public function save(): void
    {
        $this->checkToken();
        $id   = $this->submissionId();
        $item = $this->loadAllowed($id);

        if (!Acl::canEditSubmission($item) || $item->archived) {
            $this->setRedirect($this->detailUrl($id), Text::_('COM_SQUIDFORM_ERR_NO_EDIT_PERMISSION'), 'error');

            return;
        }

        $input  = $this->input->post->get('sf', [], 'raw');
        $seen   = $this->input->post->getString('sf_seen');
        $result = SubmissionService::saveEdit($item, \is_array($input) ? $input : [], $seen);

        if ($result['errors']) {
            $now = SubmissionService::load($id);

            // Lost the lock or the record changed: back to the detail with the reason.
            if (!$now || (int) $now->checked_out !== (int) $this->app->getIdentity()->id || (string) $now->modified !== $seen) {
                SubmissionService::unlock($id);
                $this->app->setUserState(SubmissionService::editStateKey($id), null);
                $this->setRedirect($this->detailUrl($id), $result['errors']['_form'] ?? Text::_('COM_SQUIDFORM_EDIT_CONFLICT'), 'error');

                return;
            }

            $this->app->setUserState(SubmissionService::editStateKey($id), ['data' => $result['data'], 'errors' => $result['errors'], 'seen' => $seen]);
            $this->setRedirect($this->editUrl($id));

            return;
        }

        $this->app->setUserState(SubmissionService::editStateKey($id), null);
        $n   = \count($result['diff']);
        $msg = $n ? Text::plural('COM_SQUIDFORM_EDIT_SAVED_N', $n) : Text::_(!empty($result['resubmitted']) ? 'COM_SQUIDFORM_APPROVAL_DONE_RESUBMIT' : 'COM_SQUIDFORM_EDIT_NO_CHANGES');

        // Say where the request went when the submitter's save moved it in the chain.
        if ($n && ($note = Approvals::editNotice($item))) {
            $msg .= ' ' . Text::_($item->status === 'returned' ? 'COM_SQUIDFORM_APPROVAL_DONE_RESUBMIT' : 'COM_SQUIDFORM_APPROVAL_DONE_RESTART');
        }

        $this->setRedirect($this->detailUrl($id), $msg);
    }

    public function cancel(): void
    {
        $this->checkToken();
        $id = $this->submissionId();
        SubmissionService::unlock($id);
        $this->app->setUserState(SubmissionService::editStateKey($id), null);
        $this->setRedirect($this->detailUrl($id));
    }

    /**
     * Check in (0.10.2): clear the edit lock on one submission (from its page)
     * or the ticked ones. The holder and managers may; SubmissionService checks.
     */
    public function checkin(): void
    {
        $this->checkToken();
        $ids = array_values(array_filter($this->requestIds(), fn ($id) => $this->idAllowedHere($id)));
        $n   = SubmissionService::checkin($ids);
        $one = \count($ids) === 1 && !$this->input->get('cid');

        $this->setRedirect($one ? $this->detailUrl($ids[0]) : $this->listUrl(), $n
            ? Text::plural('COM_SQUIDFORM_N_CHECKED_IN', $n)
            : Text::_('COM_SQUIDFORM_NOTHING_CHANGED'), $n ? 'message' : 'warning');
    }

    public function archive(): void
    {
        $this->checkToken();
        $ids = $this->requestIds();
        $ids = array_values(array_filter($ids, fn ($id) => $this->idAllowedHere($id)));
        $n   = SubmissionService::setArchived($ids, 1);

        $this->setRedirect($this->listUrl(), $n
            ? Text::plural('COM_SQUIDFORM_SUBMISSIONS_N_ITEMS_ARCHIVED', $n)
            : Text::_('COM_SQUIDFORM_NOTHING_CHANGED'), $n ? 'message' : 'warning');
    }

    public function restore(): void
    {
        $this->checkToken();
        $ids = $this->requestIds();
        $ids = array_values(array_filter($ids, fn ($id) => $this->idAllowedHere($id)));
        $n   = SubmissionService::setArchived($ids, 0);

        $this->setRedirect($this->listUrl(), $n
            ? Text::plural('COM_SQUIDFORM_SUBMISSIONS_N_ITEMS_RESTORED', $n)
            : Text::_('COM_SQUIDFORM_NOTHING_CHANGED'), $n ? 'message' : 'warning');
    }

    protected function idAllowedHere(int $id): bool
    {
        $item = SubmissionService::load($id);

        return $item && $this->formAllowedHere((int) $item->form_id);
    }

    /** JSON options for a cascading list on the edit screen. */
    public function options(): void
    {
        $list = [];
        $code = 200;

        try {
            $item  = $this->loadAllowed($this->submissionId());
            $field = $item->schema ? $item->schema->field($this->input->getCmd('field')) : null;

            if (!Acl::canEditSubmission($item) || !$field || Options::parentOf($field) === null) {
                throw new \RuntimeException('', 404);
            }

            $form = ['id' => (int) $item->form_id, 'title' => (string) $item->form_title, 'alias' => (string) $item->form_alias];
            $ph   = new Placeholders($item->schema, $this->app->getIdentity(), [], [], [], $form);
            $list = Options::resolve($field, $this->input->getString('parent'), $ph);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode() === 404 ? 404 : 400;
        }

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->setHeader('status', (string) $code, true);
        $this->app->sendHeaders();

        echo json_encode(['options' => $list], JSON_UNESCAPED_UNICODE);

        $this->app->close();
    }
}
