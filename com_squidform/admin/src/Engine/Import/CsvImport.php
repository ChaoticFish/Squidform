<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Import;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Import submissions from a CSV file (0.12.0), for example ChronoForms 8's
 * data log export or a spreadsheet. The person picks the SquidForm form
 * the answers belong to; columns are matched to its fields by heading,
 * field key, and (for a form imported from ChronoForms) the old field names
 * and labels; they can change any match before importing.
 *
 * The file is kept in Joomla's temporary folder only between the steps, and
 * deleted after the import (or when another file is chosen).
 */
final class CsvImport
{
    public const MAX_BYTES = 10485760;

    /** Special targets besides fields. */
    public const CREATED = '_created';

    public const USER = '_user';

    /**
     * Read a CSV: the headings and every row (UTF-8; Windows-1252 is converted).
     *
     * @return array{head: list<string>, rows: list<list<string>>}
     */
    public static function read(string $path): array
    {
        $raw = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if (\strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('The file is larger than 10 MB.');
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? '';

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $first = strtok($raw, "\n") ?: '';
        $delim = ',';

        foreach ([';', "\t", '|'] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delim)) {
                $delim = $d;
            }
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $head = fgetcsv($fh, 0, $delim, '"', '');
        $rows = [];

        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null] || $r === ['']) {
                continue;
            }

            $rows[] = array_map(static fn ($v) => (string) $v, $r);

            if (\count($rows) > SubmissionLoader::MAX_ROWS) {
                break;
            }
        }

        fclose($fh);

        if (!\is_array($head) || !array_filter($head, static fn ($h) => trim((string) $h) !== '')) {
            throw new \RuntimeException('The file has no heading row.');
        }

        return ['head' => array_map(static fn ($h) => trim((string) $h), $head), 'rows' => $rows];
    }

    /**
     * Fields a column can go into: top-level value fields that can be filled from text.
     *
     * @return array<string, string> key → label
     */
    public static function targets(Schema $schema): array
    {
        $out = [];

        foreach ($schema->valueFields() as $f) {
            if (!\in_array($f['type'], ['upload', 'repeater', 'calc'], true)) {
                $out[$f['key']] = (string) ($f['label'] ?? $f['key']) . ' (' . $f['key'] . ')';
            }
        }

        return $out;
    }

    /**
     * The best guess for each column.
     *
     * @param list<string> $head
     *
     * @return list<string> a target key, CREATED, USER or '' (don't import)
     */
    public static function guess(array $head, Schema $schema, int $formId): array
    {
        $norm   = static fn (string $s) => preg_replace('/[^a-z0-9]+/', '', strtolower(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'))) ?? '';
        $lookup = [];

        foreach ($schema->valueFields() as $f) {
            if (\in_array($f['type'], ['upload', 'repeater', 'calc'], true)) {
                continue;
            }

            $lookup[$norm((string) $f['key'])]                    ??= $f['key'];
            $lookup[$norm((string) ($f['label'] ?? ''))]          ??= $f['key'];
        }

        // A form imported from ChronoForms: its old labels, names and element ids.
        if ($rec = ChronoImport::recordFor($formId)) {
            foreach ((array) json_decode((string) $rec->map_json, true) as $m) {
                foreach (['cf_label', 'cf_name', 'cf_id'] as $k) {
                    if (($m[$k] ?? '') !== '' && isset($m['key'])) {
                        $lookup[$norm((string) $m[$k])] ??= $m['key'];
                    }
                }
            }
        }

        foreach (['created', 'date', 'submitted', 'submittedon', 'datesubmitted', 'createdat', 'timestamp'] as $w) {
            $lookup[$w] ??= self::CREATED;
        }

        foreach (['user', 'userid', 'username', 'submittedby'] as $w) {
            $lookup[$w] ??= self::USER;
        }

        unset($lookup['']);
        $used = [];
        $out  = [];

        foreach ($head as $h) {
            $t = $lookup[$norm($h)] ?? '';

            // One column per target.
            if ($t !== '' && isset($used[$t])) {
                $t = '';
            }

            $used[$t] = true;
            $out[]    = $t;
        }

        return $out;
    }

    /**
     * Rows for SubmissionLoader, from the CSV and the chosen matches.
     *
     * @param list<list<string>> $rows
     * @param list<string>       $match  one per column
     *
     * @return list<array{data: array<string, mixed>, created: ?string, user_id: int}>
     */
    public static function rows(array $rows, array $match): array
    {
        $out   = [];
        $users = [];

        foreach ($rows as $r) {
            $item = ['data' => [], 'created' => null, 'user_id' => 0];

            foreach ($match as $i => $t) {
                $v = $r[$i] ?? '';

                if ($t === '' || $v === '') {
                    continue;
                }

                if ($t === self::CREATED) {
                    $item['created'] = $v;
                } elseif ($t === self::USER) {
                    $item['user_id'] = $users[$v] ??= self::userId($v);
                } else {
                    $item['data'][$t] = $v;
                }
            }

            $out[] = $item;
        }

        return $out;
    }

    /** A user id from an id, a username or an email address (0 if none). */
    private static function userId(string $v): int
    {
        $db = SubmissionStore::db();
        $v  = trim($v);
        $q  = $db->createQuery()->select('id')->from($db->quoteName('#__users'));

        if (ctype_digit($v)) {
            $q->where('id = ' . (int) $v);
        } else {
            $q->where('(username = :a OR email = :b)')->bind(':a', $v)->bind(':b', $v);
        }

        return (int) $db->setQuery($q, 0, 1)->loadResult();
    }

    /** A temporary copy of the uploaded file. */
    public static function keep(string $tmp): string
    {
        $dir  = rtrim((string) Factory::getApplication()->get('tmp_path'), '/\\');
        $path = $dir . '/squidform-csv-' . bin2hex(random_bytes(12)) . '.csv';

        // 1.0.2: an import someone walked away from leaves its file behind; files
        // more than a day old are removed whenever a new one comes in.
        foreach (glob($dir . '/squidform-csv-*.csv') ?: [] as $old) {
            if (preg_match('/\/squidform-csv-[0-9a-f]{24}\.csv$/', $old) && @filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }

        if (!move_uploaded_file($tmp, $path) && !copy($tmp, $path)) {
            throw new \RuntimeException('The file couldn\'t be kept for the next step (Joomla\'s temporary folder isn\'t writable).');
        }

        return basename($path);
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^squidform-csv-[0-9a-f]{24}\.csv$/', $name)) {
            return null;
        }

        $p = rtrim((string) Factory::getApplication()->get('tmp_path'), '/\\') . '/' . $name;

        return is_file($p) ? $p : null;
    }

    public static function forget(?string $name): void
    {
        if ($name !== null && ($p = self::path($name)) !== null) {
            @unlink($p);
        }
    }

    /** Forms this person may bring submissions into (Edit Submissions on the form). @return array<int, string> */
    public static function forms(User $user): array
    {
        $db  = SubmissionStore::db();
        $out = [];

        foreach ($db->setQuery($db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__squidform_forms'))->where('state <> 2')->order('title'))->loadObjectList() as $r) {
            if (\SquidForm\Component\SquidForm\Administrator\Engine\Acl::can('squidform.edit', (int) $r->id, $user)) {
                $out[(int) $r->id] = (string) $r->title;
            }
        }

        return $out;
    }
}
