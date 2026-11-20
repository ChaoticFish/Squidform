<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * 1.0.2: running counters (#__squidform_counters), one row per form and name,
 * for the Next number and Take turns starters.
 *
 * next() is meant to run inside the transaction that saves the submission:
 * the UPDATE locks the counter's row until that transaction ends, so two
 * people sending at once wait for each other (InnoDB's lock wait, 50 seconds
 * by default) and never get the same value; if the save fails, the count
 * rolls back with it. Counting submissions instead (as 0.15.0 did) gave the
 * same number twice after a deletion, and raced without a lock.
 */
final class Counters
{
    /**
     * The counter's next value. A counter that doesn't exist yet starts
     * from $seed (so the first value is $seed + 1).
     */
    public static function next(int $formId, string $name, int $seed = 0): int
    {
        $db   = SubmissionStore::db();
        $name = substr((string) preg_replace('/[^A-Za-z0-9:_-]/', '', $name), 0, 64);
        $now  = gmdate('Y-m-d H:i:s');
        $seed = max(0, $seed);
        $t    = $db->quoteName('#__squidform_counters');

        // Add one under a row lock. UPDATE first: it takes the row's exclusive lock
        // straight away. (INSERT IGNORE first took a shared lock on the existing
        // row, then the UPDATE needed an exclusive one: two people sending at once
        // each held the first and waited for the second, a deadlock.)
        $db->setQuery(
            $db->createQuery()->update($t)
                ->set($db->quoteName('value') . ' = ' . $db->quoteName('value') . ' + 1')
                ->set($db->quoteName('modified') . ' = :m')
                ->where($db->quoteName('form_id') . ' = :f')->where($db->quoteName('name') . ' = :n')
                ->bind(':m', $now)->bind(':f', $formId, ParameterType::INTEGER)->bind(':n', $name)
        )->execute();

        if ($db->getAffectedRows() < 1) {
            // The first time: make the row at $seed + 1, or, if someone made it a
            // moment ago, add one to theirs. (Raw SQL only because the query
            // builder has no ON DUPLICATE KEY: every value is an integer or the
            // name above, cleaned to [A-Za-z0-9:_-] and quoted.)
            $db->setQuery('INSERT INTO ' . $t . ' (' . implode(', ', $db->quoteName(['form_id', 'name', 'value', 'modified'])) . ') VALUES ('
                . $formId . ', ' . $db->quote($name) . ', ' . ($seed + 1) . ', ' . $db->quote($now) . ') ON DUPLICATE KEY UPDATE '
                . $db->quoteName('value') . ' = ' . $db->quoteName('value') . ' + 1, ' . $db->quoteName('modified') . ' = ' . $db->quote($now))->execute();
        }

        return (int) $db->setQuery(
            $db->createQuery()->select($db->quoteName('value'))->from($t)
                ->where($db->quoteName('form_id') . ' = :f')->where($db->quoteName('name') . ' = :n')
                ->bind(':f', $formId, ParameterType::INTEGER)->bind(':n', $name)
        )->loadResult();
    }
}
