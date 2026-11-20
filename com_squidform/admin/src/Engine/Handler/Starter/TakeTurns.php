<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Counters;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;
use SquidForm\Component\SquidForm\Administrator\Engine\Users;

\defined('_JEXEC') or die;

/**
 * Take turns: picks the next member of a user group, in turn, so requests
 * are shared out evenly. Their email goes in {handler:assignee_email}
 * (usable as an Email action's To: it's server data, not something typed)
 * and their name in {handler:assignee_name}. Read only: it never changes a
 * user or a group.
 *
 * 1.0.2: whose turn it is comes from a counter row (Counters), locked while
 * the submission is saved, so two people sending at once go to different
 * members. Blocked accounts and accounts not yet activated are skipped. Test
 * mode keeps its own turn, so tests don't move the real one on. It only runs
 * while a page is sent.
 */
final class TakeTurns extends Starter
{
    public function label(): string
    {
        return 'Take turns';
    }

    public function description(): string
    {
        return 'Picks the next member of a user group in turn; their email is {handler:assignee_email} (usable as an email’s To), their name {handler:assignee_name}.';
    }

    public function settings(): array
    {
        return [
            'group' => 'User group (its title)',
            'field' => 'Save their name into field (a field key, optional)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        if (!self::sending($ctx)) {
            return ActionResult::skipped('turns are given only when a page is sent');
        }

        $group = self::text($settings, 'group', '', 100);
        $gid   = $group === '' ? null : Users::groupId($group);

        if ($gid === null) {
            return ActionResult::fail('There’s no user group "' . $group . '".');
        }

        $db      = SubmissionStore::db();
        $members = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('u.id'), $db->quoteName('u.name'), $db->quoteName('u.email')])
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
                ->where($db->quoteName('m.group_id') . ' = :g')
                ->where($db->quoteName('u.block') . ' = 0')
                ->where('(' . $db->quoteName('u.activation') . ' = ' . $db->quote('') . ' OR ' . $db->quoteName('u.activation') . ' = ' . $db->quote('0') . ')')
                ->bind(':g', $gid, ParameterType::INTEGER)
                ->order($db->quoteName('u.id'))
        )->loadObjectList();

        if (!$members) {
            return ActionResult::fail('The group has no active members.');
        }

        $formId = (int) ($ctx->form['id'] ?? 0);
        $test   = self::testMode($ctx);
        $n      = Counters::next($formId, ($test ? 'test:' : '') . 'turn:' . $gid, 0);
        $who    = $members[($n - 1) % \count($members)];

        $this->share($ctx, 'assignee_email', (string) $who->email);
        $this->share($ctx, 'assignee_name', (string) $who->name);

        if (($field = self::fieldKey($ctx, $settings, 'field')) !== '') {
            $ctx->data[$field] = (string) $who->name;
        }

        return ActionResult::success((int) $who->id, 'assigned to ' . $who->name);
    }
}
