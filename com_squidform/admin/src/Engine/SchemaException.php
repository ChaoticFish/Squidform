<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Thrown when a form definition fails validation. Carries every problem found,
 * so the admin can fix them all in one pass.
 */
final class SchemaException extends \RuntimeException
{
    /**
     * @param string[]  $errors
     * @param array{line: int, column: int}|null  $at  Where a JSON syntax error is (0.6.2).
     */
    public function __construct(private readonly array $errors, public readonly ?array $at = null)
    {
        parent::__construct('Invalid form definition: ' . implode(' | ', $errors));
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
