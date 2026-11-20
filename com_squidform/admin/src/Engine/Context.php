<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Everything an action can see while a page's events run.
 *
 * - data:     cleaned values for the form's own fields only (hidden fields removed)
 * - vars:     values set by the Variables action (phase 3)
 * - outputs:  each earlier action's result, keyed by the action's key
 * - flags:    stop / redirect / message set by Message and Redirect
 */
final class Context
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $vars = [];

    /** @var array<string, array{status: string, output: mixed}> */
    public array $outputs = [];

    /** "load", "submit" or "hook" (0.10.0: Read data fills only empty fields on load). */
    public string $event = 'submit';

    public bool $stop = false;

    public ?string $redirect = null;

    /** @var array{text: string, type: string}|null */
    public ?array $message = null;

    /** Set once Save Data has stored the submission. */
    public ?int $submissionId = null;

    public ?string $submissionUid = null;

    /** True once Save Data stored a new submission in this run (not a duplicate). */
    public bool $created = false;

    /** @var list<callable(): void> work to run only after the database transaction commits (email, file moves) */
    public array $afterCommit = [];

    /**
     * Extra placeholder namespaces for hooks: 'approval' (decision, step,
     * step_title, comment, by, by_email) and 'submitter' (id, name, username, email).
     *
     * @var array<string, array<string, string>>
     */
    public array $meta = [];

    /** 0.15.0: the form as written, when the visitor sees it in another language (emails to staff use this). */
    public ?Schema $original = null;

    /** @var list<array<string, mixed>> debug trail, shown only to squidform.dev users when debug is on */
    public array $debug = [];

    /**
     * @param   array<string, mixed>  $form  The form row: id, alias, title.
     */
    public function __construct(
        public readonly Schema $schema,
        public readonly array $form,
        public readonly int $versionId,
        public readonly User $user,
        public readonly string $runUid,
        public int $pageIndex = 0,
    ) {
    }

    public function value(string $fieldKey): mixed
    {
        return $this->data[$fieldKey] ?? null;
    }

    public function outcome(string $actionKey): ?string
    {
        return $this->outputs[$actionKey]['status'] ?? null;
    }

    public function record(string $actionKey, ActionResult $result): void
    {
        $this->outputs[$actionKey] = ['status' => $result->status, 'output' => $result->output];
    }

    public function log(string $actionKey, string $type, string $status, string $note = '', float $ms = 0.0): void
    {
        $this->debug[] = ['action' => $actionKey, 'type' => $type, 'status' => $status, 'note' => $note, 'ms' => round($ms, 2)];
    }
}
