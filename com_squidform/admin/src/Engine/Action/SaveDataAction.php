<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Approvals;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;
use SquidForm\Component\SquidForm\Administrator\Engine\Uploads;

\defined('_JEXEC') or die;

/**
 * Save Data: stores the submission in SquidForm's built-in store.
 *
 * config:
 *   target  "submissions" (default). Saving to a custom table arrives in phase 2.
 *   status  initial status; default "pending" when the form has an approval
 *           block, otherwise "submitted". A pending submission of a form with
 *           an approval block starts the approval chain.
 *
 * Output (readable as {action:KEY}): the new submission id.
 * A second submit of the same run (double-click, replay) is not saved again:
 * the action fails with the first submission's id, so actions with
 * when {"action": KEY, "is": "success"} (e.g. the notification email) are skipped.
 * Also sets {submission:id} and {submission:uid} for later actions.
 */
final class SaveDataAction implements ActionInterface
{
    public function run(array $config, Context $ctx): ActionResult
    {
        $target = (string) ($config['target'] ?? 'submissions');

        if ($target !== 'submissions') {
            return ActionResult::fail('Saving to a custom table is not available until phase 2.');
        }

        if ($ctx->submissionId !== null) {
            return ActionResult::fail('This submission was already saved by an earlier Save Data action.');
        }

        // Same run submitted twice (double-click, replayed request): keep the first.
        if (($existing = SubmissionStore::findByUid($ctx->runUid)) !== null) {
            $ctx->submissionId  = $existing;
            $ctx->submissionUid = $ctx->runUid;

            return ActionResult::fail('Duplicate submit; already saved as #' . $existing, $existing);
        }

        $status = (string) ($config['status'] ?? ($ctx->schema->approval() ? 'pending' : 'submitted'));

        if (!\in_array($status, SubmissionStore::STATUSES, true)) {
            return ActionResult::fail('Unknown status "' . $status . '".');
        }

        [$id, $uid] = SubmissionStore::create($ctx, $status);

        // Uploaded files (0.9.0) now belong to this submission.
        $files = [];

        foreach ($ctx->schema->valueFields() as $f) {
            if (($f['type'] ?? '') === 'upload' && \is_array($ctx->data[$f['key']] ?? null)) {
                $files = array_merge($files, $ctx->data[$f['key']]);
            }
        }

        Uploads::attach($id, $files);

        // Pending submissions of a form with approvals enter the chain now.
        if ($status === 'pending' && $ctx->schema->approval()) {
            foreach (Approvals::start($id, (int) $ctx->form['id'], $ctx->schema, $ctx->data, (int) $ctx->user->id) as $job) {
                $ctx->afterCommit[] = $job;
            }
        }

        $ctx->submissionId  = $id;
        $ctx->submissionUid = $uid;
        $ctx->created       = true;

        return ActionResult::success($id, 'submission #' . $id);
    }
}
