<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Exporter;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;

\defined('_JEXEC') or die;

/**
 * Write a file: adds each submission, once it's saved, to a CSV or
 * JSON-lines file, one file per form per month (visitor-request-2026-11.csv).
 *
 * 1.0.2: the folder is the admin's, not the form's: Options → Developers →
 * "Folder Write a file may use" (a full path outside the website). Form
 * editors can't point it anywhere else, and the file name comes only from
 * the form's alias and the month. The folder is checked again (it must
 * exist, be writable and lie outside the website, links resolved) every
 * time. CSV cells get the spreadsheet formula guard; new files are made
 * private to the web server's user and group (0640). In test mode lines go to
 * a "-test" file. A write that fails is logged (category squidform); the
 * submission itself is already safe by then.
 */
final class WriteFile extends Starter
{
    public function label(): string
    {
        return 'Write a file';
    }

    public function description(): string
    {
        return 'Adds each submission to a CSV or JSON-lines file in the folder your admin set (outside the website). Put it after Save data.';
    }

    public function settings(): array
    {
        return [
            'format' => 'csv or json (default csv)',
            'fields' => 'Only these fields (keys separated by commas; empty = all)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        if (!self::sending($ctx)) {
            return ActionResult::skipped('files are written only when a page is sent');
        }

        $folder = self::folder();

        if ($folder === null) {
            return ActionResult::fail('Write a file has no folder yet: an admin sets one in Options → Developers (outside the website).');
        }

        $json = strtolower(self::text($settings, 'format', 'csv')) === 'json';
        $only = self::keys($settings, 'fields');
        $row  = ['reference' => $ctx->submissionUid ? Ref::short($ctx->submissionUid) : '', 'sent' => gmdate('Y-m-d H:i:s') . 'Z'];

        foreach ($ctx->schema->valueFields() as $f) {
            $key = (string) $f['key'];

            if ($only && !\in_array($key, $only, true)) {
                continue;
            }

            $v         = $ctx->data[$key] ?? '';
            $row[$key] = $json ? $v : self::flat($v);
        }

        $alias = substr((string) preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($ctx->form['alias'] ?? 'form'))), 0, 100) ?: 'form';
        $file  = $folder . DIRECTORY_SEPARATOR . $alias . '-' . gmdate('Y-m') . (self::testMode($ctx) ? '-test' : '') . ($json ? '.jsonl' : '.csv');

        // Only once the submission is safely saved.
        $ctx->afterCommit[] = static function () use ($file, $row, $json): void {
            $new = !is_file($file);
            $h   = @fopen($file, 'ab');

            if (!$h) {
                Log::add('Write a file: couldn’t open ' . basename($file) . ' for writing.', Log::WARNING, 'squidform');

                return;
            }

            try {
                flock($h, LOCK_EX);

                if ($json) {
                    fwrite($h, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                } else {
                    if (fstat($h)['size'] === 0) {
                        fputcsv($h, array_map([Exporter::class, 'cell'], array_map('strval', array_keys($row))), ',', '"', '', "\r\n");
                    }

                    fputcsv($h, array_map([Exporter::class, 'cell'], array_values($row)), ',', '"', '', "\r\n");
                }

                fflush($h);
                flock($h, LOCK_UN);
            } finally {
                fclose($h);
            }

            if ($new) {
                @chmod($file, 0640);
            }
        };

        return ActionResult::success(basename($file), 'will write ' . basename($file));
    }

    /** The admin's folder, if it exists, is writable and is outside the website. */
    public static function folder(): ?string
    {
        $set = trim((string) ComponentHelper::getParams('com_squidform')->get('writefile_folder', ''));

        if ($set === '' || !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $set)) {
            return null;
        }

        $folder = realpath($set);
        $site   = realpath(JPATH_ROOT);

        if ($folder === false || !is_dir($folder) || !is_writable($folder)) {
            return null;
        }

        if ($site !== false && ($folder === $site || str_starts_with($folder . DIRECTORY_SEPARATOR, $site . DIRECTORY_SEPARATOR))) {
            return null;
        }

        return $folder;
    }

    private static function flat(mixed $v): string
    {
        if (!\is_array($v)) {
            return \is_scalar($v) ? (string) $v : '';
        }

        return implode('; ', array_map(static fn ($x) => \is_array($x) ? implode(' ', array_map(static fn ($y) => \is_scalar($y) ? (string) $y : json_encode($y), $x)) : (string) $x, $v));
    }
}
