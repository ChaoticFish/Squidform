<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Controller;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\Delegates;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;
use SquidForm\Component\SquidForm\Administrator\Engine\Url;

\defined('_JEXEC') or die;

/**
 * Approval tasks shared by the admin and front-end controllers:
 * approve / return / reject, reassign, bulk approve, withdraw, resubmit, and
 * adding or removing delegates. Every task checks the CSRF token; the
 * Approvals service checks who may do what.
 */
trait ApprovalTasksTrait
{
    /** The site posts only uids (0.6.0); the admin also accepts ids. */
    protected function approvalUidOnly(): bool
    {
        return false;
    }

    private function approvalId(): int
    {
        $uid = $this->input->getString('uid', '');

        if ($uid !== '') {
            return Ref::idOf($uid);
        }

        return $this->approvalUidOnly() ? 0 : $this->input->getInt('id');
    }

    /** Where to go after an action on one submission when no return URL was posted. */
    abstract protected function approvalBackUrl(int $id): string;

    /** Where bulk actions and delegate changes go back to. */
    abstract protected function approvalListUrl(): string;

    public function approve(): void
    {
        $this->decideTask('approve');
    }

    public function reject(): void
    {
        $this->decideTask('reject');
    }

    /** "return" is a PHP keyword, so the task is mapped in the constructor. */
    public function returnForChanges(): void
    {
        $this->decideTask('return');
    }

    private function decideTask(string $decision): void
    {
        $this->checkToken();
        $id  = $this->approvalId();
        $err = Approvals::decide(
            $id,
            $decision,
            $this->input->post->getString('sf_comment', ''),
            $this->input->post->getString('sf_step', ''),
            $this->input->post->getInt('sf_round', 0)
        );

        $this->setRedirect($this->back($id), $err ?? Text::_('COM_SQUIDFORM_APPROVAL_DONE_' . strtoupper($decision)), $err ? 'error' : 'message');
    }

    public function reassign(): void
    {
        $this->checkToken();
        $id  = $this->approvalId();
        $err = Approvals::reassign(
            $id,
            $this->input->post->getInt('sf_target', 0),
            $this->input->post->getString('sf_comment', ''),
            $this->input->post->getString('sf_step', ''),
            $this->input->post->getInt('sf_round', 0)
        );

        $this->setRedirect($this->back($id), $err ?? Text::_('COM_SQUIDFORM_APPROVAL_DONE_REASSIGN'), $err ? 'error' : 'message');
    }

    /** Approve several at once. Each id carries the step and round the person saw (sf_seen[id] = "step:round"). */
    public function bulkApprove(): void
    {
        $this->checkToken();
        $raw     = array_values(array_filter((array) $this->input->get('cid', [], 'array'), 'is_scalar'));
        $seenIn  = (array) $this->input->post->get('sf_seen', [], 'array');
        $ids     = [];
        $seen    = [];

        // cid[] and sf_seen[] are keyed by uid on the site, by id in the admin.
        foreach ($raw as $ref) {
            $id = Ref::ids([(string) $ref], $this->approvalUidOnly())[0] ?? 0;

            if ($id > 0 && !isset($seen[$id])) {
                $ids[]     = $id;
                $seen[$id] = (string) ($seenIn[(string) $ref] ?? '');
            }
        }
        $comment = $this->input->post->getString('sf_comment', '');
        $ok      = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            [$step, $round] = array_pad(explode(':', (string) ($seen[$id] ?? ''), 2), 2, '');

            if ($step === '' || Approvals::decide($id, 'approve', $comment, $step, (int) $round) !== null) {
                $skipped++;
            } else {
                $ok++;
            }
        }

        $msg = Text::plural('COM_SQUIDFORM_APPROVAL_N_APPROVED', $ok) . ($skipped ? ' ' . Text::plural('COM_SQUIDFORM_APPROVAL_N_SKIPPED', $skipped) : '');
        $this->setRedirect($this->back(0, $this->approvalListUrl()), $msg, $ok ? 'message' : 'warning');
    }

    public function withdraw(): void
    {
        $this->checkToken();
        $id  = $this->approvalId();
        $err = Approvals::withdraw($id);

        $this->setRedirect($this->back($id), $err ?? Text::_('COM_SQUIDFORM_APPROVAL_DONE_WITHDRAW'), $err ? 'error' : 'message');
    }

    public function resubmit(): void
    {
        $this->checkToken();
        $id  = $this->approvalId();
        $err = Approvals::resubmit($id);

        if ($err === null) {
            SubmissionService::unlock($id);
        }

        $this->setRedirect($this->back($id), $err ?? Text::_('COM_SQUIDFORM_APPROVAL_DONE_RESUBMIT'), $err ? 'error' : 'message');
    }

    public function addDelegate(): void
    {
        $this->checkToken();
        $me     = (int) $this->app->getIdentity()->id;
        $userId = $this->input->post->getInt('sf_user', 0) ?: $me;
        // "u:<id>" a person, "g:<id>" a user group (0.6.0).
        $pick   = $this->input->post->getString('sf_delegate', '');
        $person = preg_match('/^(?:u:)?(\d+)$/', $pick, $m) ? (int) $m[1] : 0;
        $group  = preg_match('/^g:(\d+)$/', $pick, $m) ? (int) $m[1] : 0;
        $err    = Delegates::add(
            $userId,
            $person,
            $this->input->post->getInt('sf_form', 0),
            $this->input->post->getString('sf_start', ''),
            $this->input->post->getString('sf_end', ''),
            $this->input->post->getString('sf_note', ''),
            null,
            $group
        );

        $this->setRedirect($this->back(0, $this->approvalListUrl()), $err ?? Text::_('COM_SQUIDFORM_DELEGATE_ADDED'), $err ? 'error' : 'message');
    }

    public function removeDelegate(): void
    {
        $this->checkToken();
        $ok = Delegates::remove($this->input->post->getInt('sf_delegation', 0));

        $this->setRedirect($this->back(0, $this->approvalListUrl()), Text::_($ok ? 'COM_SQUIDFORM_DELEGATE_REMOVED' : 'COM_SQUIDFORM_DELEGATE_ERR_PERMISSION'), $ok ? 'message' : 'error');
    }

    /** The posted return URL if it is on this site, else the default. */
    private function back(int $id, ?string $default = null): string
    {
        $r = base64_decode($this->input->getBase64('sf_return', ''), true);

        if (\is_string($r) && $r !== '' && Url::isInternal($r)) {
            return $r;
        }

        return $default ?? $this->approvalBackUrl($id);
    }
}
