<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Unpublished changes to a form (0.7.0): one draft per form, kept on the
 * server while someone works in the builder or the JSON box.
 *
 * - The builder saves the draft as you work. A draft may be incomplete or
 *   invalid; nothing checks it until it's published.
 * - Publishing (Save) turns it into a new live version and removes it.
 * - Discard removes it; the live version is untouched.
 * - Joomla's check-out keeps two people from editing a form at once, so one
 *   draft per form is enough.
 */
final class Drafts
{
    /** Drafts larger than this are refused (a definition is normally a few KB). */
    public const MAX_BYTES = 2_000_000;

    /** @return object{form_id: int, schema_json: string, base_version_no: int, modified: string, modified_by: int, modified_by_name: string}|null */
    public static function get(int $formId): ?object
    {
        if ($formId <= 0) {
            return null;
        }

        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $row = $db->setQuery(
            $db->createQuery()
                ->select(['d.*', $db->quoteName('u.name', 'modified_by_name')])
                ->from($db->quoteName('#__squidform_drafts', 'd'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = d.modified_by')
                ->where('d.form_id = :id')
                ->bind(':id', $formId, ParameterType::INTEGER)
        )->loadObject();

        return $row ?: null;
    }

    public static function put(int $formId, string $json, int $baseVersionNo, int $userId): string
    {
        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $now = Factory::getDate()->toSql();
        $q   = 'INSERT INTO ' . $db->quoteName('#__squidform_drafts')
            . ' (form_id, schema_json, base_version_no, modified, modified_by) VALUES (:f, :j, :b, :m, :u)'
            . ' ON DUPLICATE KEY UPDATE schema_json = VALUES(schema_json), modified = VALUES(modified), modified_by = VALUES(modified_by)';
        $db->setQuery($db->createQuery()->setQuery($q)
            ->bind(':f', $formId, ParameterType::INTEGER)
            ->bind(':j', $json)
            ->bind(':b', $baseVersionNo, ParameterType::INTEGER)
            ->bind(':m', $now)
            ->bind(':u', $userId, ParameterType::INTEGER))->execute();

        return $now;
    }

    public static function delete(int $formId): void
    {
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName('#__squidform_drafts'))->where('form_id = :id')->bind(':id', $formId, ParameterType::INTEGER)
        )->execute();
    }
}
