<?php

/**
 * @package     SquidForm
 * @subpackage  plg_system_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Plugin\System\SquidForm\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;

\defined('_JEXEC') or die;

/**
 * Replaces {squidform ALIAS} in the page's main content with the form.
 *
 * Runs on onAfterDispatch, after the component has produced its output. This
 * keeps working when Joomla's component/view caching is on (the cached
 * article still contains the shortcode; the live form is swapped in fresh on
 * every request), and the renderer turns page caching off for that request.
 *
 * Only the components listed in the plugin options are scanned
 * (default: com_content). Forms in module positions use mod_squidform.
 */
final class SquidForm extends CMSPlugin implements SubscriberInterface
{
    private const PATTERN = '/\{squidform\s+([A-Za-z0-9_\-]+)\s*\}/';

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch'       => 'onAfterDispatch',
            'onPageCacheIsExcluded' => 'onPageCacheIsExcluded',
        ];
    }

    /**
     * Keep pages with a form out of Joomla's page cache ("System - Page Cache").
     * That plugin ignores allowCache(), so without this every guest would get
     * the same cached tokens, and one visitor's typed values and errors could
     * be cached and shown to others.
     */
    public function onPageCacheIsExcluded(Event $event): void
    {
        if (class_exists(Renderer::class) && Renderer::hasRendered()) {
            if (method_exists($event, 'addResult')) {
                $event->addResult(true);
            } else {
                $result   = (array) $event->getArgument('result', []);
                $result[] = true;
                $event->setArgument('result', $result);
            }
        }
    }

    public function onAfterDispatch(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site') || !class_exists(Renderer::class)) {
            return;
        }

        $doc = $app->getDocument();

        if (!$doc || $doc->getType() !== 'html') {
            return;
        }

        $input = $app->getInput();

        // Never touch editors (front-end article editing).
        $task = (string) $input->getCmd('task', '');

        if ($input->getCmd('layout') === 'edit' || str_contains($task, 'edit')) {
            return;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) $this->params->get('components', 'com_content'))));

        if (!\in_array($input->getCmd('option'), $allowed, true)) {
            return;
        }

        $buffer = (string) $doc->getBuffer('component');

        if (!str_contains($buffer, '{squidform')) {
            return;
        }

        $buffer = (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m) => Renderer::render($m[1]),
            $buffer
        );

        $doc->setBuffer($buffer, ['type' => 'component']);
    }
}
