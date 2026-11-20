<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;

\defined('_JEXEC') or die;

/**
 * State for server-side validation: the schema, all top-level values, and the
 * path prefix used for error keys inside repeater rows.
 */
final class ValidateContext
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly Schema $schema,
        public readonly array $data,
        public readonly ?Placeholders $placeholders = null,
        public string $pathPrefix = '',
    ) {
    }

    public function forRow(string $repeaterKey, int $index): self
    {
        $c             = clone $this;
        $c->pathPrefix = $this->pathPrefix . $repeaterKey . '.' . $index . '.';

        return $c;
    }

    public function path(string $key): string
    {
        return $this->pathPrefix . $key;
    }
}
