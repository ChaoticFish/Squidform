<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;

\defined('_JEXEC') or die;

/**
 * A PHP handler (0.10.0): your own server code, in a file in the handler
 * folder (Options → Developers), run by a "Run PHP handler" action. Code
 * is never stored in a form, so only someone who can put files on the
 * server can add a handler; form editors only pick one from a list.
 *
 * A handler file is named after its class (Invoice.php holds
 * SquidFormHandler\Invoice), starts with defined('_JEXEC') or die;, and the
 * class implements this interface (extending AbstractHandler is easiest).
 */
interface HandlerInterface
{
    /** The name form editors see in the list. */
    public function label(): string;

    /** One or two sentences on what it does, shown in the builder. */
    public function description(): string;

    /**
     * The settings form editors fill in for it: name => label.
     *
     * @return array<string, string>
     */
    public function settings(): array;

    /**
     * Do the work. $ctx->data holds the answers (change them to change what's
     * saved), $ctx->vars your own values ({var:NAME}), $ctx->user, $ctx->form.
     * On a page that's sent, it runs inside the database transaction: throw to
     * undo the whole submission, and put email or file work in
     * $ctx->afterCommit[] so it happens only once everything is saved.
     *
     * @param array<string, string> $settings
     */
    public function run(Context $ctx, array $settings): ActionResult;
}
