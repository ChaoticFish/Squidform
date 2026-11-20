<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Look up a person: finds one active site user from an answer (their email,
 * username, or a Joomla user field such as an employee number); their name
 * and email go in {var:person_name} and {var:person_email}. Read only.
 *
 * {var:}, not {handler:}: who is found depends on what the person typed, so
 * (like a typed address) it can't choose email recipients on its own.
 *
 * 1.0.2: by default it only runs for people who are signed in, because it
 * tells whoever fills in the form a user's name and email; "Guests too" is
 * a setting for forms that need it. Only published user fields are
 * searched, the answer is limited to 255 characters, and exactly one match
 * is needed (none or several is a quiet "failed", so a later action can run
 * only when someone was found). Matching follows the database's collation,
 * which ignores case.
 */
final class LookUpPerson extends Starter
{
    public function label(): string
    {
        return 'Look up a person';
    }

    public function description(): string
    {
        return 'Finds a site user by an answer (email, username or a user field such as an employee number); their name and email go in {var:person_name} and {var:person_email}. Signed-in visitors only, unless you allow guests.';
    }

    public function settings(): array
    {
        return [
            'from'       => 'The answer to look up (a field key)',
            'by'         => 'Match it against: email, username, or a Joomla user field’s name (e.g. employeenumber)',
            'name_into'  => 'Put their name into field (key, optional)',
            'email_into' => 'Put their email into field (key, optional)',
            'guests'     => 'Guests too? (yes or no, default no)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        if ($ctx->user->guest && !self::yes($settings, 'guests', false)) {
            return ActionResult::skipped('only for people who are signed in');
        }

        $from  = self::fieldKey($ctx, $settings, 'from');
        $value = $from !== '' && \is_scalar($ctx->data[$from] ?? null) ? trim((string) $ctx->data[$from]) : '';
        $by    = strtolower(self::text($settings, 'by', 'email', 100));

        if ($value === '' || mb_strlen($value) > 255) {
            return ActionResult::skipped('nothing to look up');
        }

        $db = SubmissionStore::db();
        $q  = $db->createQuery()->select([$db->quoteName('u.id'), $db->quoteName('u.name'), $db->quoteName('u.email')])
            ->from($db->quoteName('#__users', 'u'))->where($db->quoteName('u.block') . ' = 0');

        if ($by === 'email' || $by === 'username') {
            // $by is one of these two words here, never the setting's text.
            $q->where($db->quoteName($by === 'email' ? 'u.email' : 'u.username') . ' = :v')->bind(':v', $value);
        } elseif (preg_match('/^[a-z0-9_-]{1,100}$/', $by)) {
            $context = 'com_users.user';
            $q->join('INNER', $db->quoteName('#__fields_values', 'v'), $db->quoteName('v.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR)')
                ->join('INNER', $db->quoteName('#__fields', 'f'), $db->quoteName('f.id') . ' = ' . $db->quoteName('v.field_id'))
                ->where($db->quoteName('f.context') . ' = :c')->where($db->quoteName('f.state') . ' = 1')
                ->where($db->quoteName('f.name') . ' = :n')->where($db->quoteName('v.value') . ' = :v')
                ->bind(':c', $context)->bind(':n', $by)->bind(':v', $value);
        } else {
            return ActionResult::fail('“Match it against” must be email, username or a user field’s name.');
        }

        $found = $db->setQuery($q, 0, 2)->loadObjectList();

        if (\count($found) !== 1) {
            return ActionResult::fail($found ? 'more than one person matches' : 'nobody matches', null, true);
        }

        $p = $found[0];
        $ctx->vars['person_name']  = (string) $p->name;
        $ctx->vars['person_email'] = (string) $p->email;

        foreach (['name_into' => $p->name, 'email_into' => $p->email] as $s => $v) {
            if (($key = self::fieldKey($ctx, $settings, $s)) !== '') {
                $ctx->data[$key] = (string) $v;
            }
        }

        return ActionResult::success((int) $p->id, 'found ' . $p->name);
    }
}
