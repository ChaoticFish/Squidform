<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Import;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use SquidForm\Component\SquidForm\Administrator\Engine\Audit;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ValidateContext;
use SquidForm\Component\SquidForm\Administrator\Engine\FieldRegistry;
use SquidForm\Component\SquidForm\Administrator\Engine\FormData;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Old answers in as submissions of a SquidForm form (0.12.0): from a CSV
 * file or ChronoForms 8's data log. Each value goes through the field's own
 * clean-up (as a typed answer does); answers that wouldn't pass the field's
 * checks are still kept (they're history) and listed. Uploaded files can't
 * come across (only their names were in the data), and repeating groups
 * aren't filled. Every imported submission says so in its history.
 */
final class SubmissionLoader
{
    public const MAX_ROWS = 20000;

    /**
     * @param list<array{data: array<string, mixed>, created?: ?string, user_id?: int, ip?: string}> $rows  data keyed by SquidForm field key
     *
     * @return array{added: int, problems: list<string>, skipped_fields: list<string>}
     */
    public static function load(int $formId, array $rows, User $by, bool $dryRun = false): array
    {
        $loaded = FormRepository::load($formId, $by, false);

        if (!$loaded) {
            return ['added' => 0, 'problems' => ['The form wasn\'t found.'], 'skipped_fields' => []];
        }

        /** @var Schema $schema */
        $schema    = $loaded['schema'];
        $versionId = (int) $loaded['version_id'];
        $alias     = (string) $loaded['form']['alias'];
        $fields    = [];
        $skipped   = [];

        foreach ($schema->valueFields() as $f) {
            if (\in_array($f['type'], ['upload', 'repeater', 'calc'], true)) {
                $skipped[$f['key']] = (string) ($f['label'] ?? $f['key']);
                continue;
            }

            $fields[$f['key']] = $f;
        }

        $problems = [];
        $clean    = [];

        foreach (\array_slice($rows, 0, self::MAX_ROWS) as $i => $row) {
            $data = [];

            foreach ((array) ($row['data'] ?? []) as $key => $raw) {
                if (!isset($fields[$key]) || $raw === null || $raw === '') {
                    continue;
                }

                $data[$key] = FieldRegistry::get($fields[$key]['type'])->normalize($fields[$key], self::shape($fields[$key], $raw));
            }

            $vc = new ValidateContext($schema, $data);

            foreach ($data as $key => $v) {
                foreach (FieldRegistry::get($fields[$key]['type'])->validate($fields[$key], $v, $data, $vc) as $msg) {
                    if (\count($problems) < 200) {
                        $problems[] = 'Row ' . ($i + 1) . ': ' . $msg;
                    }
                }
            }

            $clean[] = ['data' => $data, 'created' => self::when($row['created'] ?? null), 'user_id' => (int) ($row['user_id'] ?? 0), 'ip' => substr((string) ($row['ip'] ?? ''), 0, 45)];
        }

        if (\count($rows) > self::MAX_ROWS) {
            $problems[] = 'Only the first ' . number_format(self::MAX_ROWS) . ' rows are imported at a time.';
        }

        if ($dryRun) {
            return ['added' => \count($clean), 'problems' => $problems, 'skipped_fields' => array_values($skipped)];
        }

        $fd = FormData::forForm($formId);
        $fd->ensure($schema, $alias);
        $db    = SubmissionStore::db();
        $added = 0;

        foreach (array_chunk($clean, 100) as $chunk) {
            $db->transactionStart();
            $inChunk = 0;

            try {
                foreach ($chunk as $r) {
                    $now = $r['created'] ?? Factory::getDate()->toSql();
                    $sub = (object) [
                        'form_id' => $formId, 'version_id' => $versionId, 'uid' => SubmissionStore::uuid(), 'status' => 'submitted', 'current_step' => '',
                        'user_id' => $r['user_id'], 'ip' => $r['ip'], 'page' => '0', 'created' => $now, 'modified' => $now, 'modified_by' => (int) $by->id, 'archived' => 0,
                    ];
                    $db->insertObject('#__squidform_submissions', $sub, 'id');
                    $fd->write((int) $sub->id, $schema, $r['data']);
                    Audit::log((int) $sub->id, $formId, (int) $by->id, 'import');
                    $inChunk++;
                }

                $db->transactionCommit();
                $added += $inChunk;
            } catch (\Throwable $e) {
                $db->transactionRollback();
                $problems[] = 'Rows from ' . ($added + 1) . ' on couldn\'t be saved (' . $e->getMessage() . '); the ' . $added . ' before them were.';
                break;
            }
        }

        return ['added' => $added, 'problems' => $problems, 'skipped_fields' => array_values($skipped)];
    }

    /** A CSV or log value into the shape a field expects (lists for multiple choices). */
    private static function shape(array $field, mixed $raw): mixed
    {
        $multi = \in_array($field['type'], ['checkboxes', 'multiselect', 'ranking'], true);

        if (\is_string($raw) && $raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) {
            $j = json_decode($raw, true);

            if (\is_array($j)) {
                $raw = $j;
            }
        }

        if ($multi && \is_string($raw)) {
            $raw = array_values(array_filter(array_map('trim', preg_split('/\s*[,;|]\s*|\R/', $raw) ?: []), static fn ($v) => $v !== ''));
        }

        if ($field['type'] === 'checkbox' && \is_string($raw)) {
            $raw = \in_array(strtolower(trim($raw)), ['1', 'yes', 'y', 'true', 'on', 'x', 'checked'], true) ? '1' : '';
        }

        if ($field['type'] === 'date' && \is_string($raw) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            $raw = $m[1];
        }

        if ($field['type'] === 'datetime' && \is_string($raw) && preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $raw, $m)) {
            $raw = $m[1] . 'T' . $m[2];
        }

        if ($field['type'] === 'signature' && \is_string($raw)) {
            $raw = str_starts_with($raw, 'data:image/png') ? ['png' => $raw] : ['name' => $raw];
        }

        return $raw;
    }

    /** A date and time from the source, as SQL, or null for "now". */
    private static function when(mixed $v): ?string
    {
        if (!\is_string($v) || trim($v) === '') {
            return null;
        }

        try {
            return Factory::getDate(trim($v))->toSql();
        } catch (\Throwable) {
            return null;
        }
    }
}
