<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use SquidForm\Component\SquidForm\Administrator\Engine\Handler\HandlerInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter\Starter;

\defined('_JEXEC') or die;

/**
 * Finds PHP handlers (0.10.0): one class per file in the handler folder
 * (Options → Developers; administrator/squidform-handlers by default), and
 * any a "squidform" plugin adds on onSquidformHandlers:
 *
 *     $event->getArgument('registry')['Invoice'] = new InvoiceHandler();
 *
 * A file that doesn't load (a typo in it) is skipped and named in Check
 * this server, never breaking the forms that don't use it.
 *
 * 1.0.2: the starter handlers are built in (Handler\Starter), each off
 * until an admin ticks it in Options → Developers → Starter handlers. A
 * file in the folder with a starter's name is skipped while that starter is
 * ticked (the built-in one is used), and used as before while it isn't.
 */
final class Handlers
{
    public const DEFAULT_FOLDER = 'administrator/squidform-handlers';

    private const NAME = '/^[A-Z][A-Za-z0-9_]{0,63}$/';

    /** @var array<string, HandlerInterface>|null */
    private static ?array $all = null;

    /** @var array<string, string> file => why it wasn't loaded */
    private static array $problems = [];

    /** @return list<string> the starters an admin ticked */
    public static function starters(): array
    {
        $v = ComponentHelper::getParams('com_squidform')->get('starters', []);
        $v = \is_string($v) ? preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) : (array) $v;

        return array_values(array_intersect(array_keys(Starter::ALL), array_map('strval', $v)));
    }

    public static function folder(): string
    {
        $f = trim((string) ComponentHelper::getParams('com_squidform')->get('handler_folder', self::DEFAULT_FOLDER));
        $f = $f === '' ? self::DEFAULT_FOLDER : $f;

        return rtrim(preg_match('#^(/|[A-Za-z]:[\\\\/])#', $f) ? $f : JPATH_ROOT . '/' . ltrim($f, '/'), '/\\');
    }

    /** @return array<string, HandlerInterface> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $out = [];
        $dir = self::folder();
        $on  = self::starters();

        foreach ($on as $name) {
            $class      = Starter::ALL[$name];
            $out[$name] = new $class();
        }

        // Handler classes load the usual PHP way: an autoloader for the
        // SquidFormHandler namespace, from this one folder, by checked name.
        spl_autoload_register(static function (string $class) use ($dir): void {
            $name = str_starts_with($class, 'SquidFormHandler\\') ? substr($class, 17) : '';

            if ($name !== '' && preg_match(self::NAME, $name) && is_file($dir . '/' . $name . '.php')) {
                include_once $dir . '/' . $name . '.php';
            }
        });

        foreach (is_dir($dir) ? (glob($dir . '/*.php') ?: []) : [] as $file) {
            $name = basename($file, '.php');

            if (!preg_match(self::NAME, $name)) {
                // 1.0.0: an example renamed only half way (NextNumber-example.php).
                // 1.0.2: a starter's copy needs no renaming: it's built in now.
                self::$problems[basename($file)] = preg_match('/^([A-Z][A-Za-z0-9_]{0,63})-example$/', $name, $m)
                    ? (isset(Starter::ALL[$m[1]])
                        ? 'not needed: ' . $m[1] . ' is built into SquidForm now. Tick it in Options → Developers → Starter handlers, then delete this file'
                        : 'rename it to ' . $m[1] . '.php: the file name must be the class name, without "-example"')
                    : 'the file name must be the class name: a capital letter first, then letters, digits or _';
                continue;
            }

            if (isset($out[$name])) {
                self::$problems[basename($file)] = 'skipped: the built-in ' . $name . ' is switched on (Options → Developers → Starter handlers). Delete this copy, or rename it to use both';
                continue;
            }

            $class = 'SquidFormHandler\\' . $name;

            try {
                if (!class_exists($class)) {
                    self::$problems[basename($file)] = 'it has no class ' . $class;
                    continue;
                }

                $h = (new \ReflectionClass($class))->newInstance();

                if (!$h instanceof HandlerInterface) {
                    self::$problems[basename($file)] = $class . ' doesn’t implement HandlerInterface';
                    continue;
                }

                $out[$name] = $h;
            } catch (\Throwable $e) {
                self::$problems[basename($file)] = $e->getMessage() . ' (line ' . $e->getLine() . ')';
            }
        }

        $registry = new \ArrayObject();

        try {
            PluginHelper::importPlugin('squidform');
            Factory::getApplication()->getDispatcher()->dispatch('onSquidformHandlers', new Event('onSquidformHandlers', ['registry' => $registry]));
        } catch (\Throwable) {
            // files only
        }

        foreach ($registry as $name => $h) {
            if (\is_string($name) && preg_match(self::NAME, $name) && $h instanceof HandlerInterface && !isset($out[$name])) {
                $out[$name] = $h;
            }
        }

        ksort($out);

        return self::$all = $out;
    }

    public static function get(string $name): ?HandlerInterface
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, string> */
    public static function problems(): array
    {
        self::all();

        return self::$problems;
    }

    /**
     * For the builder's list.
     *
     * @return list<array{name: string, label: string, description: string, settings: array<string, string>}>
     */
    public static function catalog(): array
    {
        $out = [];

        foreach (self::all() as $name => $h) {
            try {
                $out[] = ['name' => $name, 'label' => $h->label(), 'description' => $h->description(), 'settings' => $h->settings()];
            } catch (\Throwable) {
                $out[] = ['name' => $name, 'label' => $name, 'description' => '', 'settings' => []];
            }
        }

        return $out;
    }
}
