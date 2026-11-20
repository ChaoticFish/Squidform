<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler;

\defined('_JEXEC') or die;

/**
 * 0.15.0: a handler's way to say no. Throw it from run() on a page that's
 * being sent: nothing is saved or sent, the person's answers stay in the
 * form, and the message shows by the field named (or at the top). The
 * message is shown as plain text.
 *
 *   throw new Refused('Please use your work email address.', 'f_email');
 */
final class Refused extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
