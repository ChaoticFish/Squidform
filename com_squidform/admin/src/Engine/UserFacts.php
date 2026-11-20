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
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

\defined('_JEXEC') or die;

/**
 * Joomla user data, read only (0.14.0): a person's access groups and their
 * Joomla user fields, for conditions and choice lists. Nothing here writes
 * to a user, and nothing here sends a field's value to the browser: the
 * server works conditions out and the browser only gets true or false.
 *
 *   {"user_group": "Supervisors"}                    in that group (or a child of it)
 *   {"user_group": ["Supervisors", "Managers"]}      in any of them
 *   {"user_field": "department", "op": "eq", "value": "HR"}
 *   "options": {"user_field": "department"}         the field's own choices
 *
 * Guests are in the Guest group (Joomla's guest group setting) and have no
 * user fields.
 */
final class UserFacts
{
    /** Joomla user field types that have their own list of choices. */
    public const LIST_TYPES = ['list', 'radio', 'checkboxes'];

    /** @var array<int, list<int>> */
    private static array $groups = [];

    /** @var array<int, array<string, mixed>> */
    private static array $fields = [];

    /** @var array<string, ?object>|null name => #__fields row */
    private static ?array $defs = null;

    public static function reset(): void
    {
        self::$groups = self::$fields = [];
        self::$defs   = null;
    }

    /**
     * Group ids the person is in, with every parent group (what Joomla uses
     * for permissions), so "Registered" matches a member of a child group.
     *
     * @return list<int>
     */
    public static function groupsOf(int $userId): array
    {
        if (!isset(self::$groups[$userId])) {
            try {
                self::$groups[$userId] = array_values(array_unique(array_map('intval', Access::getGroupsByUser($userId, true) ?: [])));
            } catch (\Throwable) {
                self::$groups[$userId] = [];
            }
        }

        return self::$groups[$userId];
    }

    /** @param string|int|list<string|int> $refs */
    public static function inGroup(int $userId, mixed $refs): bool
    {
        $mine = self::groupsOf($userId);

        foreach ((array) $refs as $ref) {
            $gid = Users::groupId((string) $ref);

            if ($gid !== null && \in_array($gid, $mine, true)) {
                return true;
            }
        }

        return false;
    }

    /** A Joomla user field's raw value for this person (null for guests or an empty field). */
    public static function field(int $userId, string $name): mixed
    {
        if ($userId <= 0 || !class_exists(FieldsHelper::class)) {
            return null;
        }

        if (!isset(self::$fields[$userId])) {
            self::$fields[$userId] = [];

            try {
                $user = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId);

                foreach (FieldsHelper::getFields('com_users.user', $user, false) as $f) {
                    $v = $f->rawvalue ?? $f->value ?? null;

                    // Several choices come back as a list (checkboxes, multiple lists).
                    if (\is_string($v) && $v !== '' && $v[0] === '[' && \is_array($d = json_decode($v, true))) {
                        $v = $d;
                    }

                    self::$fields[$userId][$f->name] = $v;
                }
            } catch (\Throwable) {
                // Custom fields turned off: every field is empty.
            }
        }

        return self::$fields[$userId][$name] ?? null;
    }

    /**
     * The Joomla user fields (published), for the builder and for checks.
     *
     * @return array<string, object> name => {name, title, type, options: list<array{value, label}>}
     */
    public static function definitions(): array
    {
        if (self::$defs !== null) {
            return self::$defs;
        }

        self::$defs = [];

        try {
            $db   = SubmissionStore::db();
            $rows = $db->setQuery(
                $db->createQuery()->select(['name', 'title', 'type', 'fieldparams'])->from($db->quoteName('#__fields'))
                    ->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
                    ->where($db->quoteName('state') . ' = 1')
                    ->order('ordering, title')
            )->loadObjectList();
        } catch (\Throwable) {
            $rows = [];
        }

        foreach ($rows as $r) {
            $params  = json_decode((string) $r->fieldparams, true) ?: [];
            $options = [];

            if (\in_array($r->type, self::LIST_TYPES, true)) {
                foreach ((array) ($params['options'] ?? []) as $o) {
                    $v = trim((string) ($o['value'] ?? ''));
                    $l = trim((string) ($o['name'] ?? $v));

                    if ($v !== '' || $l !== '') {
                        $options[] = ['value' => $v !== '' ? $v : $l, 'label' => $l !== '' ? $l : $v];
                    }
                }
            }

            self::$defs[(string) $r->name] = (object) ['name' => (string) $r->name, 'title' => (string) $r->title, 'type' => (string) $r->type, 'options' => $options];
        }

        return self::$defs;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(string $name): array
    {
        return self::definitions()[$name]->options ?? [];
    }

    /** @return array<int, string> id => title, for the builder */
    public static function groupList(): array
    {
        $db  = SubmissionStore::db();
        $out = [];

        foreach ($db->setQuery($db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__usergroups'))->order('lft'))->loadObjectList() as $g) {
            $out[(int) $g->id] = (string) $g->title;
        }

        return $out;
    }

    /**
     * Problems that need the site's data: a named group or user field that
     * doesn't exist, or a choice list from a field that has no choices.
     *
     * @return list<string>
     */
    public static function problems(Schema $schema): array
    {
        $out  = [];
        $defs = self::definitions();

        $walk = function ($cond, string $where) use (&$walk, &$out, $defs): void {
            if (!\is_array($cond)) {
                return;
            }

            foreach (['all', 'any'] as $g) {
                foreach ((array) ($cond[$g] ?? []) as $c) {
                    $walk($c, $where);
                }
            }

            if (isset($cond['not'])) {
                $walk($cond['not'], $where);
            }

            if (isset($cond['user_group'])) {
                foreach ((array) $cond['user_group'] as $g) {
                    if (Users::groupId((string) $g) === null) {
                        $out[] = $where . ': there is no user group "' . $g . '".';
                    }
                }
            }

            if (isset($cond['user_field']) && !isset($defs[(string) $cond['user_field']])) {
                $out[] = $where . ': there is no published Joomla user field named "' . $cond['user_field'] . '".';
            }
        };

        $fields = function (array $list, string $where) use (&$fields, $walk, &$out, $defs): void {
            foreach ($list as $i => $f) {
                $w = $where . '.fields[' . $i . ']';

                foreach (['show_if', 'disabled_if'] as $c) {
                    if (!empty($f[$c])) {
                        $walk($f[$c], $w . '.' . $c);
                    }
                }

                if (isset($f['options']['user_field'])) {
                    $n = (string) $f['options']['user_field'];

                    if (!isset($defs[$n])) {
                        $out[] = $w . '.options.user_field: there is no published Joomla user field named "' . $n . '".';
                    } elseif ($defs[$n]->options === []) {
                        $out[] = $w . '.options.user_field: "' . $n . '" is a ' . $defs[$n]->type . ' field with no choices; use a List, Radio or Checkboxes user field.';
                    }
                }

                if (!empty($f['fields']) && \is_array($f['fields'])) {
                    $fields($f['fields'], $w);
                }
            }
        };

        foreach ($schema->pages() as $pi => $page) {
            if (!empty($page['show_if'])) {
                $walk($page['show_if'], 'pages[' . $pi . '].show_if');
            }

            $fields((array) ($page['fields'] ?? []), 'pages[' . $pi . ']');

            foreach ((array) ($page['events'] ?? []) as $ev => $list) {
                foreach ((array) $list as $ai => $a) {
                    $w = 'pages[' . $pi . '].events.' . $ev . '[' . $ai . ']';

                    if (!empty($a['when'])) {
                        $walk($a['when'], $w . '.when');
                    }

                    foreach ((array) ($a['config']['routes'] ?? []) as $ri => $r) {
                        if (\is_array($r) && !empty($r['when'])) {
                            $walk($r['when'], $w . '.config.routes[' . $ri . '].when');
                        }
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }
}
