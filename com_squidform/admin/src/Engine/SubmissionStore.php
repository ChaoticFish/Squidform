<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Writes submissions to the built-in store (0.4.0):
 *  - #__squidform_submissions   one slim row: form, version, status, who, when (no answers)
 *  - the form's own tables       the answers (see FormData)
 */
final class SubmissionStore
{
    public const STATUSES = ['draft', 'submitted', 'pending', 'approved', 'rejected', 'returned', 'withdrawn', 'archived'];

    /**
     * Insert a new submission. Must run inside the caller's transaction.
     *
     * @return array{0: int, 1: string} [id, uid]
     */
    public static function create(Context $ctx, string $status): array
    {
        $db   = self::db();
        $now  = Factory::getDate()->toSql();
        // The run id (one per pass through the form) is the submission's unique
        // uid, so a double-click or replayed request cannot save twice.
        $uid  = preg_match('/^[0-9a-f-]{36}$/', $ctx->runUid) ? $ctx->runUid : self::uuid();
        $ip   = self::clientIp();

        $row = (object) [
            'form_id'      => (int) $ctx->form['id'],
            'version_id'   => $ctx->versionId,
            'uid'          => $uid,
            'status'       => $status,
            'current_step' => '',
            'user_id'      => (int) $ctx->user->id,
            'ip'           => $ip,
            'page'         => (string) $ctx->pageIndex,
            'created'      => $now,
            'modified'     => $now,
            'modified_by'  => (int) $ctx->user->id,
            'archived'     => 0,
            // 0.15.0: test mode, and the language it was sent in.
            'is_test'      => TestMode::on($ctx->schema) ? 1 : 0,
            'language'     => Lang::current(),
        ];

        $db->insertObject('#__squidform_submissions', $row, 'id');
        $id = (int) $row->id;

        FormData::forForm((int) $ctx->form['id'])->write($id, $ctx->schema, $ctx->data);
        Audit::log($id, (int) $ctx->form['id'], (int) $ctx->user->id, 'create');

        return [$id, $uid];
    }

    /** Id of an existing submission with this uid, or null. */
    public static function findByUid(string $uid): ?int
    {
        $db = self::db();
        $q  = $db->createQuery()
            ->select('id')
            ->from($db->quoteName('#__squidform_submissions'))
            ->where('uid = :uid')
            ->bind(':uid', $uid);

        $id = $db->setQuery($q)->loadResult();

        return $id ? (int) $id : null;
    }

    public static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** RFC 4122 version 4 UUID. */
    public static function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0f) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** The visitor's IP (REMOTE_ADDR, or the forwarded address from a trusted proxy: see ClientIp). */
    public static function clientIp(): string
    {
        return ClientIp::get();
    }
}
