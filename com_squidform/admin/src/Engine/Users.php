<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Small directory of site accounts used by the user picker field, approval
 * steps and delegates. Only active accounts count: not blocked, activated.
 *
 * References:
 *   group:<title or id>   a Joomla user group; its child groups are included,
 *                          the same way Joomla permissions inherit
 *   user:<username or id> one account
 */
final class Users
{
    /** @var array<string, int|null> */
    private static array $groupIds = [];

    /** @var array<int, object|null> id => {id, name, username, email} */
    private static array $users = [];

    /** @var array<string, list<int>> */
    private static array $members = [];

    public static function reset(): void
    {
        self::$groupIds = self::$users = self::$members = [];
    }

    /** Group id from a title or id, or null when there is no such group. */
    public static function groupId(string $ref): ?int
    {
        $ref = trim($ref);

        if (!\array_key_exists($ref, self::$groupIds)) {
            $db = SubmissionStore::db();
            $q  = $db->createQuery()->select('id')->from($db->quoteName('#__usergroups'));

            if (ctype_digit($ref)) {
                $id = (int) $ref;
                $q->where('id = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $q->where('title = :t')->bind(':t', $ref);
            }

            $id                   = $db->setQuery($q)->loadResult();
            self::$groupIds[$ref] = $id ? (int) $id : null;
        }

        return self::$groupIds[$ref];
    }

    public static function groupTitle(int $id): string
    {
        $db = SubmissionStore::db();

        return (string) $db->setQuery(
            $db->createQuery()->select('title')->from($db->quoteName('#__usergroups'))->where('id = ' . $id)
        )->loadResult();
    }

    /** Account id from a username or id, or null. */
    public static function userId(string $ref): ?int
    {
        $ref = trim($ref);
        $db  = SubmissionStore::db();
        $q   = $db->createQuery()->select('id')->from($db->quoteName('#__users'));

        if (ctype_digit($ref)) {
            $id = (int) $ref;
            $q->where('id = :id')->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $q->where('username = :u')->bind(':u', $ref);
        }

        $id = $db->setQuery($q)->loadResult();

        return $id ? (int) $id : null;
    }

    /** @return object|null {id, name, username, email, active} */
    public static function get(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        if (!\array_key_exists($id, self::$users)) {
            $db  = SubmissionStore::db();
            $row = $db->setQuery(
                $db->createQuery()->select(['id', 'name', 'username', 'email', 'block', 'activation'])
                    ->from($db->quoteName('#__users'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
            )->loadObject();

            if ($row) {
                $row->id     = (int) $row->id;
                $row->active = (int) $row->block === 0 && \in_array((string) $row->activation, ['', '0'], true);
            }

            self::$users[$id] = $row ?: null;
        }

        return self::$users[$id];
    }

    public static function name(int $id): string
    {
        $u = self::get($id);

        return $u ? (string) $u->name : ($id > 0 ? '#' . $id : '');
    }

    public static function isActive(int $id): bool
    {
        return (bool) (self::get($id)->active ?? false);
    }

    /**
     * Active members of these groups (and their child groups), sorted by name.
     *
     * @param   int[]  $groupIds
     *
     * @return  list<object>  {id, name, username, email}
     */
    public static function inGroups(array $groupIds): array
    {
        $ids = [];

        foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
            if (!isset(self::$members[(string) $gid])) {
                self::$members[(string) $gid] = array_map('intval', Access::getUsersByGroup($gid, true) ?: []);
            }

            foreach (self::$members[(string) $gid] as $uid) {
                $ids[$uid] = true;
            }
        }

        return self::many(array_keys($ids));
    }

    /**
     * Active accounts by id, sorted by name.
     *
     * @param   int[]  $ids
     *
     * @return  list<object>
     */
    public static function many(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));

        if (!$ids) {
            return [];
        }

        $db   = SubmissionStore::db();
        $rows = $db->setQuery(
            $db->createQuery()->select(['id', 'name', 'username', 'email'])->from($db->quoteName('#__users'))
                ->whereIn($db->quoteName('id'), $ids)->where('block = 0')
                ->where('(' . $db->quoteName('activation') . ' = ' . $db->quote('') . ' OR ' . $db->quoteName('activation') . ' = ' . $db->quote('0') . ')')
                ->order('name, username')
        )->loadObjectList();

        foreach ($rows as $r) {
            $r->id = (int) $r->id;
        }

        return $rows;
    }

    /**
     * Group ids of a user, including parent groups (what Joomla uses to
     * decide permissions). A user in a child of group G matches "group:G".
     *
     * @return int[]
     */
    public static function groupsOf(int $userId): array
    {
        return $userId > 0 ? array_map('intval', Access::getGroupsByUser($userId, true)) : [];
    }

    /**
     * Groups allowed this permission on a form (or, with formId 0, on at
     * least one form), with the title: id => title.
     *
     * @return array<int, string>
     */
    public static function groupsWithPermission(string $action, int $formId = 0): array
    {
        $assets = $formId > 0 ? [Acl::assetName($formId)] : array_merge(['com_squidform'], array_map(
            static fn ($id) => Acl::assetName((int) $id),
            self::formIds()
        ));
        $db     = SubmissionStore::db();
        $out    = [];

        foreach ($db->setQuery($db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__usergroups'))->order('title'))->loadObjectList() as $g) {
            foreach (array_unique($assets) as $asset) {
                if (Access::checkGroup((int) $g->id, $action, $asset) || Access::checkGroup((int) $g->id, 'core.admin', $asset)) {
                    $out[(int) $g->id] = (string) $g->title;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Active accounts that have this permission on a form (or, with formId 0,
     * on at least one form), sorted by name.
     *
     * @return list<object>
     */
    public static function withPermission(string $action, int $formId = 0): array
    {
        // Groups are checked one by one, so take their direct members only:
        // a child group that is denied is checked on its own.
        $ids = [];

        foreach (array_keys(self::groupsWithPermission($action, $formId)) as $gid) {
            foreach (Access::getUsersByGroup($gid, false) ?: [] as $uid) {
                $ids[(int) $uid] = true;
            }
        }

        return self::many(array_keys($ids));
    }

    /** Can this account (by id) do this on the form? */
    public static function can(int $userId, string $action, int $formId): bool
    {
        if ($userId <= 0 || !self::isActive($userId)) {
            return false;
        }

        return Acl::can($action, $formId, Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId));
    }

    /** Can this account sign in to the administrator? (Super Users always can.) */
    public static function canAdminLogin(int $userId): bool
    {
        if ($userId <= 0 || !self::isActive($userId)) {
            return false;
        }

        $user = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId);

        return (bool) ($user->authorise('core.login.admin') || $user->authorise('core.admin'));
    }

    /** Label for a picker: the name, with the username when two people share a name. */
    public static function labels(array $users): array
    {
        $count = [];

        foreach ($users as $u) {
            $count[$u->name] = ($count[$u->name] ?? 0) + 1;
        }

        $out = [];

        foreach ($users as $u) {
            $out[$u->id] = $count[$u->name] > 1 ? $u->name . ' (' . $u->username . ')' : $u->name;
        }

        return $out;
    }

    /** @return int[] */
    private static function formIds(): array
    {
        $db = SubmissionStore::db();

        return array_map('intval', $db->setQuery($db->createQuery()->select('id')->from($db->quoteName('#__squidform_forms')))->loadColumn());
    }

    public static function current(): User
    {
        return Factory::getApplication()->getIdentity() ?? new User();
    }
}
