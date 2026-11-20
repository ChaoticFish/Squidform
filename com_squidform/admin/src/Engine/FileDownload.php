<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Sends an uploaded file (0.9.0), to someone who may see its submission
 * (View Submissions, View Own for their own, or an approver of it). Always
 * as a download, never shown in the browser, with no-sniff and a sandbox,
 * so a file can't run as a page on the site. Each download is in the audit
 * trail.
 */
final class FileDownload
{
    public static function send(CMSApplicationInterface $app, string $uid): void
    {
        $row  = preg_match('/^[0-9a-f]{32}$/', $uid) ? Uploads::byUid($uid) : null;
        $item = $row && (int) $row->submission_id > 0 ? SubmissionService::load((int) $row->submission_id) : null;
        $path = $row ? Uploads::pathOf($row) : '';

        if (!$item || !Acl::canViewSubmission($item) || !is_file($path)) {
            throw new \RuntimeException(Text::_('COM_SQUIDFORM_FILE_NOT_FOUND'), 404);
        }

        $user = $app->getIdentity();
        Audit::log((int) $row->submission_id, (int) $row->form_id, (int) ($user?->id ?? 0), 'file_download', ['file' => $row->original_name]);

        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $row->original_name) ?: 'file';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string) $row->original_name));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cache-Control: private, no-store');
        readfile($path);
        $app->close();
    }
}
