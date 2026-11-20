<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Append-only audit trail (#__squidform_audit). Phase 1 records create and
 * delete; phase 2 adds view, edit, approve and export.
 */
final class Audit
{
    /** @param array<string, mixed>|null $diff */
    public static function log(?int $submissionId, int $formId, int $userId, string $action, ?array $diff = null): void
    {
        $row = (object) [
            'submission_id' => $submissionId,
            'form_id'       => $formId,
            'user_id'       => $userId,
            'action'        => substr($action, 0, 32),
            'diff_json'     => $diff !== null ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null,
            'created'       => Factory::getDate()->toSql(),
        ];

        SubmissionStore::db()->insertObject('#__squidform_audit', $row);
    }
}
