<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;

\defined('_JEXEC') or die;

/**
 * Contract for an action. Actions run in the order listed under a page's
 * `load` or `submit` event. Each gets its own `config` object from the form
 * definition and the shared Context.
 *
 * Rules for implementers:
 * - Never execute code or SQL taken from the form definition or the request.
 * - Use $ctx->afterCommit[] for side effects that must not happen if the
 *   submission is rolled back (sending email, moving files).
 * - Return ActionResult::fail() with a short note rather than throwing for
 *   expected problems; throw only for programming errors.
 */
interface ActionInterface
{
    /**
     * @param   array<string, mixed>  $config
     */
    public function run(array $config, Context $ctx): ActionResult;
}
