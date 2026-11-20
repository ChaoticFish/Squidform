<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Log\Log;

\defined('_JEXEC') or die;

/**
 * Runs a list of actions in order against a Context.
 *
 * - An action whose `when` condition is false is recorded as skipped.
 *   `when` may test fields ({"field": ...}) or an earlier action's outcome
 *   ({"action": "a_save", "is": "success"}).
 * - Message and Redirect set $ctx->stop; nothing after them runs.
 * - An ActionException inside an action is recorded as a fail and the run
 *   continues. Anything else (including every database error, which Joomla
 *   throws as \RuntimeException subclasses) propagates so the caller rolls
 *   the whole submit back and nothing is saved or sent.
 */
final class Runner
{
    /**
     * @param   list<array<string, mixed>>  $actions
     */
    public static function run(Context $ctx, array $actions): void
    {
        foreach ($actions as $action) {
            if ($ctx->stop) {
                break;
            }

            $key  = (string) $action['key'];
            $type = (string) $action['type'];

            if (!empty($action['disabled'])) {
                $ctx->record($key, ActionResult::skipped('disabled'));
                $ctx->log($key, $type, ActionResult::SKIPPED, 'disabled');
                continue;
            }

            // Schema validation guarantees `when` is an object; never treat a malformed one as "always".
            if (isset($action['when']) && !\is_array($action['when'])) {
                throw new \UnexpectedValueException('Action "' . $key . '" has an invalid when condition.');
            }

            if (!empty($action['when'])) {
                $ok = Conditions::evaluate(
                    $action['when'],
                    static fn (string $k) => $ctx->value($k),
                    static fn (string $a) => $ctx->outcome($a)
                );

                if (!$ok) {
                    $ctx->record($key, ActionResult::skipped());
                    $ctx->log($key, $type, ActionResult::SKIPPED, 'condition false');
                    continue;
                }
            }

            $start = microtime(true);

            try {
                $result = ActionRegistry::get($type)->run((array) ($action['config'] ?? []), $ctx);
            } catch (ActionException $e) {
                $result = ActionResult::fail($e->getMessage());
            }

            $ctx->record($key, $result);
            $ctx->log($key, $type, $result->status, $result->note, (microtime(true) - $start) * 1000);

            if ($result->status === ActionResult::FAIL && !$result->quiet) {
                Log::add(
                    'SquidForm form "' . ($ctx->form['alias'] ?? '?') . '" action "' . $key . '" (' . $type . ') failed: ' . $result->note,
                    Log::WARNING,
                    'squidform'
                );
            }
        }
    }
}
