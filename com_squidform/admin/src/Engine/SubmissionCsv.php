<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * One submission as a CSV file (0.13.0), for "Attach the answers as a CSV" on
 * the Email and Confirmation email actions.
 *
 * Two lines: the headings (Reference, Submitted, then each field's label) and
 * the answers, as the submission's page shows them. A repeater is one cell,
 * one line per row. Every cell gets the spreadsheet formula guard. No IP
 * address, no user details. "Hide what's typed" answers stay as dots; the
 * sender's copy also leaves out hidden fields and those answers altogether.
 */
final class SubmissionCsv
{
    /**
     * @param object $item      from SubmissionService::load(), or the same shape
     * @param string $audience  "staff" or "sender"
     */
    public static function bytes(object $item, string $audience = 'staff'): string
    {
        $staff = $audience === 'staff';
        $head  = [Text::_('COM_SQUIDFORM_PDF_REFERENCE'), Text::_('COM_SQUIDFORM_HEADING_CREATED')];
        $line  = [Ref::short((string) $item->uid), HTMLHelper::_('date', $item->created, 'Y-m-d H:i')];
        $data  = (array) $item->data;

        if ($item->schema) {
            foreach ($item->schema->valueFields() as $field) {
                if (!$staff && self::private($field)) {
                    continue;
                }

                $head[] = (string) ($field['label'] ?? $field['key']);
                $line[] = self::value($field, $data[$field['key']] ?? null, $staff);
            }
        }

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([Exporter::class, 'cell'], $head), ',', '"', '', "\r\n");
        fputcsv($out, array_map([Exporter::class, 'cell'], $line), ',', '"', '', "\r\n");
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** "visitor-request-ca9a4d1b.csv" */
    public static function fileName(object $item): string
    {
        return substr(Pdf\Pdf::fileName($item), 0, -4) . '.csv';
    }

    private static function value(array $field, mixed $value, bool $staff): string
    {
        if ($field['type'] !== 'repeater') {
            return Display::value($field, $value, '');
        }

        $rows = [];

        foreach (array_values((array) $value) as $n => $row) {
            $parts = [];

            foreach ($field['fields'] ?? [] as $child) {
                if (!$staff && self::private($child)) {
                    continue;
                }

                $parts[] = (string) ($child['label'] ?? $child['key']) . ': ' . Display::value($child, $row[$child['key']] ?? null, '');
            }

            $rows[] = ($n + 1) . '. ' . implode('; ', $parts);
        }

        return implode("\n", $rows);
    }

    private static function private(array $field): bool
    {
        return Display::isPrivate($field);
    }
}
