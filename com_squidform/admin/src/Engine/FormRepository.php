<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Loads forms and their current published definition.
 */
final class FormRepository
{
    /**
     * Load a form by alias or id with its current version's Schema.
     *
     * @param   bool  $forVisitor  true = only published forms the user's view levels allow
     *
     * @return  array{form: array<string, mixed>, schema: Schema, version_id: int}|null
     *
     * @throws  SchemaException  when the stored definition is invalid
     */
    public static function load(string|int $ref, ?User $user = null, bool $forVisitor = true): ?array
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select('f.id, f.title, f.alias, f.state, f.access, f.current_version_id, v.schema_json')
            ->from($db->quoteName('#__squidform_forms', 'f'))
            ->join('INNER', $db->quoteName('#__squidform_form_versions', 'v'), 'v.id = f.current_version_id');

        // Integers are ids; strings are always aliases (an alias like "2027" is not an id).
        if (\is_int($ref)) {
            $id = (int) $ref;
            $q->where('f.id = :id')->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $alias = (string) $ref;
            $q->where('f.alias = :alias')->bind(':alias', $alias);
        }

        $row = $db->setQuery($q)->loadAssoc();

        if (!$row) {
            return null;
        }

        if ($forVisitor) {
            $user ??= Factory::getApplication()->getIdentity() ?? new User();

            if ((int) $row['state'] !== 1 || !\in_array((int) $row['access'], $user->getAuthorisedViewLevels(), true)) {
                return null;
            }
        }

        $schema = Schema::fromJson((string) $row['schema_json']);

        return [
            'form'       => ['id' => (int) $row['id'], 'title' => $row['title'], 'alias' => $row['alias']],
            'schema'     => $schema,
            'version_id' => (int) $row['current_version_id'],
        ];
    }

    /**
     * Load the definition a submission was made against (for display).
     */
    public static function version(int $versionId): ?Schema
    {
        if (\array_key_exists($versionId, self::$versions)) {
            return self::$versions[$versionId];
        }

        return self::$versions[$versionId] = self::loadVersion($versionId);
    }

    /** @var array<int, Schema|null> versions are never changed once saved */
    private static array $versions = [];

    private static function loadVersion(int $versionId): ?Schema
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select('schema_json')
            ->from($db->quoteName('#__squidform_form_versions'))
            ->where('id = :id')
            ->bind(':id', $versionId, ParameterType::INTEGER);

        $json = $db->setQuery($q)->loadResult();

        if (!$json) {
            return null;
        }

        try {
            return Schema::fromJson((string) $json);
        } catch (SchemaException) {
            return null;
        }
    }
}
