<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Streams a CSV or JSON export (admin Export screen and front-end viewer).
 * The caller has already checked Export permission on the form and chosen
 * the submission ids. Every download is audited. IP addresses are never
 * exported (see Exporter).
 */
final class ExportService
{
    public const STATE_PICKS = 'com_squidform.export.picks.';

    /**
     * Stream the file and end the request. Returns a language key only when
     * nothing was sent (no columns ticked, no submissions).
     *
     * @param  object    $form   id, title, alias
     * @param  int[]     $ids
     * @param  string[]  $picks  column ids
     */
    public static function run(object $form, array $ids, string $format, bool $keys, array $picks, string $scope, string $source): ?string
    {
        $formId   = (int) $form->id;
        $exporter = new Exporter(Exporter::fieldColumns($formId), $picks, $keys, [Text::class, '_'], Exporter::userTimezone());

        if ($exporter->isEmpty()) {
            return 'COM_SQUIDFORM_EXPORT_ERR_NO_COLUMNS';
        }

        if (!$ids) {
            return 'COM_SQUIDFORM_EXPORT_ERR_NONE';
        }

        $app  = Factory::getApplication();
        $user = $app->getIdentity();
        $app->setUserState(self::STATE_PICKS . $formId, $exporter->columnIds());

        Audit::log(null, $formId, (int) $user->id, 'export', [
            'format'  => $format,
            'scope'   => $scope,
            'source'  => $source,
            'count'   => \count($ids),
            'columns' => $exporter->columnIds(),
            'ids'     => array_values($ids),
        ]);

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $form->alias) ?: 'form';
        $file = 'squidform-' . $name . '-' . gmdate('Ymd-His') . '.' . $format;

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, max-age=0');

        $out = fopen('php://output', 'wb');

        if ($format === 'csv') {
            // UTF-8 byte order mark so Excel reads accented characters correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $exporter->csvHeader(), ',', '"', '', "\r\n");

            foreach (self::rows($ids, $formId) as $row) {
                foreach ($exporter->csvLines($row) as $line) {
                    fputcsv($out, $line, ',', '"', '', "\r\n");
                }
            }
        } else {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
            $head  = [
                'form'        => ['id' => $formId, 'alias' => (string) $form->alias, 'title' => (string) $form->title],
                'exported'    => (new \DateTimeImmutable('now', Exporter::userTimezone()))->format(\DATE_ATOM),
                'exported_by' => (string) $user->name,
                'count'       => \count($ids),
                'values'      => 'stored',
                'fields'      => (object) $exporter->jsonFields(),
            ];

            $json = json_encode($head, $flags | JSON_PRETTY_PRINT);
            fwrite($out, substr($json, 0, -2) . ",\n    \"submissions\": [");

            $first = true;

            foreach (self::rows($ids, $formId) as $row) {
                fwrite($out, ($first ? "\n" : ",\n") . '        ' . json_encode($exporter->jsonRecord($row), $flags));
                $first = false;
            }

            fwrite($out, "\n    ]\n}\n");
        }

        fclose($out);
        $app->close();

        return null;
    }

    /** @return string[]|null last picks for this form in this session */
    public static function picks(int $formId): ?array
    {
        $p = Factory::getApplication()->getUserState(self::STATE_PICKS . $formId);

        return \is_array($p) ? $p : null;
    }

    /** First time: every column ticked. @return string[] */
    public static function defaultPicks(array $fieldColumns): array
    {
        $out = array_map(static fn ($m) => 'meta:' . $m, array_keys(Exporter::META));

        foreach ($fieldColumns as $c) {
            if ($c['type'] === 'repeater') {
                foreach ($c['children'] as $ch) {
                    $out[] = 'f:' . $c['key'] . '.' . $ch['key'];
                }
            } else {
                $out[] = 'f:' . $c['key'];
            }
        }

        return $out;
    }

    /**
     * Rows for the given ids of one form, in id order, read in chunks to keep
     * memory flat. Each row carries ->data (the answers).
     *
     * @param   int[]  $ids
     *
     * @return  \Generator<object>
     */
    public static function rows(array $ids, int $formId, int $chunk = 500): \Generator
    {
        $db       = SubmissionStore::db();
        $fd       = FormData::forForm($formId);
        $versions = [];
        sort($ids);

        foreach (array_chunk($ids, $chunk) as $part) {
            $q = $db->createQuery()
                ->select(['s.id', 's.uid', 's.created', 's.modified', 's.status', 's.user_id', 's.version_id', $db->quoteName('u.name', 'user_name')])
                ->from($db->quoteName('#__squidform_submissions', 's'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = s.user_id')
                ->whereIn($db->quoteName('s.id'), array_map('intval', $part))
                ->where('s.form_id = ' . $formId)
                ->order('s.id');

            $rows = $db->setQuery($q)->loadObjectList('id');
            $data = $fd->readMany(array_keys($rows), static function (int $id) use ($rows, &$versions) {
                $v = (int) $rows[$id]->version_id;

                return $versions[$v] ??= FormRepository::version($v);
            });

            foreach ($rows as $id => $row) {
                $row->data = $data[$id] ?? [];
                yield $row;
            }
        }
    }
}
