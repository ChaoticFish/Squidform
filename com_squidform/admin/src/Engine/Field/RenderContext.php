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
 * Per-render state passed to field types: naming, errors, and the values used
 * for cascading options and conditions.
 */
final class RenderContext
{
    /** @var array<string, array<string, int>> places left per capped choice: key => value => left (0.10.0) */
    public array $capacity = [];

    /**
     * @param   array<string, string>  $errors   Field path => message.
     * @param   array<string, mixed>   $data     All current top-level values.
     * @param   array<string, true>    $locked   Field keys made read-only by prefill_lock.
     */
    public function __construct(
        public readonly Schema $schema,
        public readonly string $formAlias,
        public readonly Placeholders $placeholders,
        public readonly array $errors = [],
        public readonly array $data = [],
        public readonly array $locked = [],
        public string $namePrefix = 'sf',
        public string $idPrefix = '',
        /** @var array<string, mixed> values of the current repeater row (for cascades inside rows) */
        public array $scope = [],
        public string $pathPrefix = '',
    ) {
        if ($this->idPrefix === '') {
            $this->idPrefix = 'sf-' . preg_replace('/[^a-z0-9_-]/i', '', $formAlias);
        }
    }

    public function name(string $key): string
    {
        return $this->namePrefix . '[' . $key . ']';
    }

    public function id(string $key): string
    {
        return $this->idPrefix . '-' . $key;
    }

    public function path(string $key): string
    {
        return $this->pathPrefix . $key;
    }

    public function error(string $key): ?string
    {
        return $this->errors[$this->path($key)] ?? null;
    }

    /** A copy scoped to one repeater row. */
    public function forRow(string $repeaterKey, int|string $index, array $rowValues): self
    {
        $c             = clone $this;
        $c->namePrefix = $this->namePrefix . '[' . $repeaterKey . '][' . $index . ']';
        $c->idPrefix   = $this->idPrefix . '-' . $repeaterKey . '-' . $index;
        $c->pathPrefix = $this->pathPrefix . $repeaterKey . '.' . $index . '.';
        $c->scope      = $rowValues;

        return $c;
    }

    /** Value of a field visible to this scope (row siblings first, then top level). */
    public function lookup(string $key): mixed
    {
        return \array_key_exists($key, $this->scope) ? $this->scope[$key] : ($this->data[$key] ?? null);
    }
}
