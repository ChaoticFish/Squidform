<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * An expected, recoverable action failure ("this action did not do its job").
 * The Runner records it as a fail and continues with the next action.
 *
 * Anything else thrown inside an action (database errors included) is NOT
 * caught by the Runner: it propagates and the whole page submit rolls back.
 */
class ActionException extends \Exception
{
}
