<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use Joomla\CMS\Filter\InputFilter;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;

\defined('_JEXEC') or die;

/**
 * Message: shows a message to the person and, by default, ends the run.
 *
 * config:
 *   text   placeholders allowed, HTML-escaped (e.g. "Thanks, {data:f_name}.");
 *          simple HTML allowed, filtered like the HTML field (no scripts)
 *   style  success (default), info, warning, error
 *   stop   true (default) ends the run; false lets later actions continue
 *   hide_form  true (default) shows only the message after a final-page submit
 */
final class MessageAction implements ActionInterface
{
    public function run(array $config, Context $ctx): ActionResult
    {
        $style = (string) ($config['style'] ?? 'success');

        if (!\in_array($style, ['success', 'info', 'warning', 'error'], true)) {
            $style = 'info';
        }

        $text = Placeholders::forContext($ctx)->replace((string) ($config['text'] ?? ''), 'html');

        // Same rule as the HTML field: safe tags only, no scripts or event handlers.
        $text = \SquidForm\Component\SquidForm\Administrator\Engine\Html::clean((string) $text);

        $ctx->message = ['text' => $text, 'type' => $style, 'hide_form' => (bool) ($config['hide_form'] ?? true)];

        if ($config['stop'] ?? true) {
            $ctx->stop = true;
        }

        return ActionResult::success($style);
    }
}
