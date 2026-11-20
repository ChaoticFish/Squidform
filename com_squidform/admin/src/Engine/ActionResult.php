<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * What an action returns: success or fail, plus an optional output that later
 * actions and placeholders can read as {action:KEY}.
 */
final class ActionResult
{
    public const SUCCESS = 'success';
    public const FAIL    = 'fail';
    public const SKIPPED = 'skipped';

    private function __construct(
        public readonly string $status,
        public readonly mixed $output = null,
        public readonly string $note = '',
        public readonly bool $quiet = false,
    ) {
    }

    public static function success(mixed $output = null, string $note = ''): self
    {
        return new self(self::SUCCESS, $output, $note);
    }

    /** $quiet: an expected outcome (Read data found nothing), not written to the log. */
    public static function fail(string $note, mixed $output = null, bool $quiet = false): self
    {
        return new self(self::FAIL, $output, $note, $quiet);
    }

    public static function skipped(string $note = 'condition false'): self
    {
        return new self(self::SKIPPED, null, $note);
    }

    public function ok(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
