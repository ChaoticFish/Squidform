<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler;

\defined('_JEXEC') or die;

/** Defaults for a handler: its class name as the label, no description, no settings. */
abstract class AbstractHandler implements HandlerInterface
{
    public function label(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    public function description(): string
    {
        return '';
    }

    public function settings(): array
    {
        return [];
    }

    /**
     * 0.15.0: share a value worked out on the server as {handler:NAME}
     * (trusted: it may choose email recipients) and {var:NAME}. Use it for
     * values your code looked up, never for something a person typed.
     */
    protected function share(\SquidForm\Component\SquidForm\Administrator\Engine\Context $ctx, string $name, string $value): void
    {
        $name = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($name));

        if ($name !== '') {
            $ctx->vars[$name]              = $value;
            $ctx->meta['handler'][$name]   = $value;
        }
    }
}
