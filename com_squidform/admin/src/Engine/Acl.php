<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Per-form permission checks (0.3.0).
 *
 * Each form has an asset com_squidform.form.<id> under the component's asset,
 * so a rule not set on the form is inherited from SquidForm → Options →
 * Permissions. A form without an asset row (asset_id = 0) uses the component
 * rules directly.
 *
 *   view    squidform.view       any submission of the form
 *           squidform.view.own   own submissions (logged-in submitter)
 *   edit    squidform.edit / squidform.edit.own
 *   delete  squidform.delete (or core.delete) — soft delete and restore
 *   export  squidform.export (needs view as well)
 *   approve squidform.approve plus a place on the current step (Approvals);
 *           approvers can open the requests they act or acted on
 */
final class Acl
{
    /** @var array<int, int>|null form id => asset id */
    private static ?array $assets = null;

    public static function reset(): void
    {
        self::$assets = null;
    }

    public static function assetName(int $formId): string
    {
        if (self::$assets === null) {
            $db           = SubmissionStore::db();
            self::$assets = [];
            $rows         = $db->setQuery(
                $db->createQuery()->select(['id', 'asset_id'])->from($db->quoteName('#__squidform_forms'))
            )->loadObjectList();

            foreach ($rows as $r) {
                self::$assets[(int) $r->id] = (int) $r->asset_id;
            }
        }

        return !empty(self::$assets[$formId]) ? 'com_squidform.form.' . $formId : 'com_squidform';
    }

    public static function can(string $action, int $formId, ?User $user = null): bool
    {
        $user ??= self::user();

        if ($formId <= 0) {
            return false;
        }

        $asset = self::assetName($formId);

        if ($action === 'squidform.delete') {
            return (bool) ($user->authorise('squidform.delete', $asset) || $user->authorise('core.delete', $asset));
        }

        if ($action === 'squidform.export') {
            return (bool) ($user->authorise('squidform.export', $asset) && $user->authorise('squidform.view', $asset));
        }

        return (bool) $user->authorise($action, $asset);
    }

    /**
     * Form ids where the user has this permission.
     *
     * @return int[]
     */
    public static function forms(string $action, ?User $user = null): array
    {
        $user ??= self::user();
        self::assetName(0); // load the map

        $out = [];

        foreach (array_keys(self::$assets) as $id) {
            if (self::can($action, $id, $user)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** Is this submission the user's own (logged-in submitter)? */
    public static function owns(object $submission, ?User $user = null): bool
    {
        $user ??= self::user();

        return (int) $user->id > 0 && (int) $submission->user_id === (int) $user->id;
    }

    /** View this submission: View Submissions, or View Own for one's own. */
    public static function canViewSubmission(object $submission, ?User $user = null): bool
    {
        $user ??= self::user();
        $form = (int) $submission->form_id;

        return self::can('squidform.view', $form, $user)
            || (self::owns($submission, $user) && self::can('squidform.view.own', $form, $user))
            || Approvals::involved($submission, $user);
    }

    /** Edit this submission: Edit Submissions, or Edit Own for one's own. */
    public static function canEditSubmission(object $submission, ?User $user = null): bool
    {
        $user ??= self::user();
        $form = (int) $submission->form_id;

        if (!self::canViewSubmission($submission, $user)) {
            return false;
        }

        // A signed record (0.11.0) set to "lock" can't be changed by anyone.
        if (isset($submission->schema) && ESign::locks($submission)) {
            return false;
        }

        if (self::can('squidform.edit', $form, $user)) {
            return true;
        }

        if (!self::owns($submission, $user) || !self::can('squidform.edit.own', $form, $user)) {
            return false;
        }

        // Under approvals, the submitter edits only while undecided: when
        // returned for changes, or while pending if the form allows it.
        $flow = Approvals::flow($submission);

        if ($flow === null) {
            return true;
        }

        return match ((string) $submission->status) {
            'returned' => true,
            'pending'  => $flow['edit_while_pending'],
            default    => false,
        };
    }

    private static function user(): User
    {
        return Factory::getApplication()->getIdentity() ?? new User();
    }
}
