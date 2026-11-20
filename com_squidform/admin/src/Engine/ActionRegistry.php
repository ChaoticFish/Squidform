<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\ActionInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\ConfirmEmailAction;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\EmailAction;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\MessageAction;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\ReadDataAction;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\RunPhpAction;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\RedirectAction;
use SquidForm\Component\SquidForm\Administrator\Engine\Action\SaveDataAction;

\defined('_JEXEC') or die;

/**
 * Maps action type names to implementations.
 *
 * Phase 1 ships save_data, email, message and redirect. Plugins in the
 * "squidform" group add more by listening to `onSquidformActionTypes`:
 *
 *     $event->getArgument('registry')['csv_export'] = new CsvExportAction();
 */
final class ActionRegistry
{
    /** @var array<string, ActionInterface>|null */
    private static ?array $types = null;

    /** @return array<string, ActionInterface> */
    public static function all(): array
    {
        if (self::$types === null) {
            $types = [
                'save_data' => new SaveDataAction(),
                'email'     => new EmailAction(),
                'message'   => new MessageAction(),
                'redirect'  => new RedirectAction(),
                // 0.10.0
                'read_data' => new ReadDataAction(),
                'run_php'   => new RunPhpAction(),
                // 0.11.0
                'confirm_email' => new ConfirmEmailAction(),
            ];

            $registry = new \ArrayObject();

            try {
                PluginHelper::importPlugin('squidform');
                Factory::getApplication()->getDispatcher()->dispatch(
                    'onSquidformActionTypes',
                    new Event('onSquidformActionTypes', ['registry' => $registry])
                );
            } catch (\Throwable) {
                // Built-ins only.
            }

            foreach ($registry as $name => $impl) {
                if (\is_string($name) && $impl instanceof ActionInterface && !isset($types[$name])) {
                    $types[$name] = $impl;
                }
            }

            self::$types = $types;
        }

        return self::$types;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function get(string $type): ActionInterface
    {
        return self::all()[$type] ?? throw new \InvalidArgumentException('Unknown action type: ' . $type);
    }
}
