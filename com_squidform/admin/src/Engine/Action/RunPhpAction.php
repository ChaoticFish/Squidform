<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionException;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Handlers;

\defined('_JEXEC') or die;

/**
 * Run PHP handler (0.10.0): { "handler": "Invoice", "settings": { "rate": "0.2" } }.
 * The handler is a class in a file on the server (see Handlers); the form
 * only names it. A handler that isn't there any more fails the action.
 */
final class RunPhpAction implements ActionInterface
{
    public function run(array $config, Context $ctx): ActionResult
    {
        $name = (string) ($config['handler'] ?? '');
        $h    = Handlers::get($name);

        if ($h === null) {
            throw new ActionException('There’s no PHP handler called "' . $name . '" (check the handler folder in Check this server).');
        }

        $settings = [];

        foreach ((array) ($config['settings'] ?? []) as $k => $v) {
            if (\is_string($k) && \is_scalar($v)) {
                $settings[$k] = (string) $v;
            }
        }

        $result = $h->run($ctx, $settings);

        return $result instanceof ActionResult ? $result : ActionResult::success($result);
    }
}
