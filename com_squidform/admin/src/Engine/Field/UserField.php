<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Language\Text;
use SquidForm\Component\SquidForm\Administrator\Engine\Users;

\defined('_JEXEC') or die;

/**
 * User picker: choose a site account from one or more user groups.
 *
 *   { "key": "f_supervisor", "type": "user", "label": "Supervisor",
 *     "groups": ["Supervisors"], "exclude_self": true, "rules": { "required": true } }
 *
 * - "groups" is required (titles or ids), so the list never shows every
 *   account on the site. Child groups are included. Blocked accounts are not.
 * - "exclude_self" (default true) leaves the person filling in the form out
 *   of the list, so a request cannot name its own submitter as approver.
 * - The stored value is the account id. Lists and exports show the name.
 * - The server re-checks the choice, so a tampered request cannot post an
 *   account outside the groups.
 */
final class UserField extends AbstractField
{
    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key   = $field['key'];
        $id    = $rc->id($key);
        $bad   = $rc->error($key) !== null;
        $users = self::choices($field);
        $cur   = (int) $value;

        // Keep a stored choice visible even if that person has since left the groups.
        if ($cur > 0 && !isset($users[$cur])) {
            $users[$cur] = Users::name($cur);
        }

        $attrs = self::controlAttrs($field, $rc);

        if (isset($rc->locked[$key])) {
            $attrs = str_replace(' readonly aria-readonly="true"', ' disabled', $attrs);
        }

        $html = (isset($rc->locked[$key]) ? '<input type="hidden" name="' . self::e($rc->name($key)) . '" value="' . ($cur ?: '') . '">' : '')
            . '<select class="form-select' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . self::e($rc->name($key)) . '"' . $attrs . '>'
            . '<option value="">' . self::e($field['empty_label'] ?? Text::_('COM_SQUIDFORM_SELECT')) . '</option>';

        foreach ($users as $uid => $label) {
            $html .= '<option value="' . (int) $uid . '"' . ($uid === $cur ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }

        return self::wrap($field, $rc, $html . '</select>');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $v = \is_scalar($raw) ? trim((string) $raw) : '';

        return ctype_digit($v) && (int) $v > 0 ? (string) (int) $v : '';
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $path  = $vc->path($field['key']);

        if (self::isEmpty($value)) {
            return !empty($field['rules']['required']) ? [$path => Text::sprintf('COM_SQUIDFORM_ERR_REQUIRED', $label)] : [];
        }

        if (!isset(self::choices($field)[(int) $value])) {
            return [$path => Text::sprintf('COM_SQUIDFORM_ERR_OPTION', $label)];
        }

        return [];
    }

    /**
     * Accounts offered: id => label.
     *
     * @return array<int, string>
     */
    public static function choices(array $field): array
    {
        $gids = [];

        foreach ((array) ($field['groups'] ?? []) as $g) {
            if (($gid = Users::groupId((string) $g)) !== null) {
                $gids[] = $gid;
            }
        }

        $users = Users::inGroups($gids);

        if ($field['exclude_self'] ?? true) {
            $me    = (int) Users::current()->id;
            $users = array_values(array_filter($users, static fn ($u) => $u->id !== $me));
        }

        return Users::labels($users);
    }
}
