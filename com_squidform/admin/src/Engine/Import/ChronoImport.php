<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Import;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\FormTransfer;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Import from ChronoForms (0.12.0; ChronoForms 7 since 0.12.1): forms from this
 * site's own tables, or from a backup file (.cf8bak / .cf7bak), into new
 * unpublished SquidForm forms. CF7 forms go through Cf7Adapter first.
 *
 * - Listing forms (a Table of saved data) become the viewer settings of the
 *   form whose data they listed, when that form is imported too.
 * - Forms with nothing to fill in (a PDF, a PHP page, a list of something
 *   else) aren't made into forms; the report says why.
 * - Each form's field map (ChronoForms field → SquidForm field) and report
 *   are kept, for Import submissions (CSV) and to read again later.
 * - From this site, CF8's data log can come across as submissions.
 *
 * ChronoForms itself is only read, never changed.
 */
final class ChronoImport
{
    private static function db(): \Joomla\Database\DatabaseInterface
    {
        return SubmissionStore::db();
    }

    private static function hasTable(string $name): bool
    {
        try {
            $db     = self::db();
            $prefix = $db->getPrefix();

            return \in_array($prefix . $name, $db->getTableList(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Is ChronoForms 8 on this site? */
    public static function cf8Here(): bool
    {
        return self::hasTable('chronoforms8');
    }

    /** Is ChronoForms 7 on this site? */
    public static function cf7Here(): bool
    {
        return self::hasTable('chronog3_forms7');
    }

    /** @return list<object> id, title, alias, published, logs */
    public static function cf7Forms(): array
    {
        if (!self::cf7Here()) {
            return [];
        }

        $db   = self::db();
        $rows = $db->setQuery($db->createQuery()->select(['id', 'title', 'alias', 'published'])->from($db->quoteName('#__chronog3_forms7'))->order('title'))->loadObjectList();
        $logs = [];

        if (self::hasTable('chronog3_forms7_datalog')) {
            foreach ($db->setQuery($db->createQuery()->select(['form_id', 'COUNT(*) AS n'])->from($db->quoteName('#__chronog3_forms7_datalog'))->group('form_id'))->loadObjectList() as $r) {
                $logs[(int) $r->form_id] = (int) $r->n;
            }
        }

        foreach ($rows as $r) {
            $r->logs = $logs[(int) $r->id] ?? 0;
        }

        return $rows;
    }

    /** @param int[] $ids @return list<array<string, mixed>> CF7 forms ("connections"); Cf7Adapter decodes their parts */
    public static function cf7FromSite(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids || !self::cf7Here()) {
            return [];
        }

        $db = self::db();

        return $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__chronog3_forms7'))->whereIn($db->quoteName('id'), $ids)->order('id'))->loadAssocList();
    }

    /** @return list<object> id, title, alias, published, logs */
    public static function cf8Forms(): array
    {
        if (!self::cf8Here()) {
            return [];
        }

        $db   = self::db();
        $rows = $db->setQuery($db->createQuery()->select(['id', 'title', 'alias', 'published'])->from($db->quoteName('#__chronoforms8'))->order('title'))->loadObjectList();
        $logs = [];

        if (self::hasTable('chronoforms8_datalog')) {
            foreach ($db->setQuery($db->createQuery()->select(['form_id', 'COUNT(*) AS n'])->from($db->quoteName('#__chronoforms8_datalog'))->group('form_id'))->loadObjectList() as $r) {
                $logs[(int) $r->form_id] = (int) $r->n;
            }
        }

        foreach ($rows as $r) {
            $r->logs = $logs[(int) $r->id] ?? 0;
        }

        return $rows;
    }

    /** @param int[] $ids @return list<array<string, mixed>> forms with elements and params decoded */
    public static function cf8FromSite(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids || !self::cf8Here()) {
            return [];
        }

        $db  = self::db();
        $out = [];

        foreach ($db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__chronoforms8'))->whereIn($db->quoteName('id'), $ids)->order('id'))->loadAssocList() as $r) {
            $r['elements'] = json_decode((string) $r['elements'], true) ?: [];
            $r['params']   = json_decode((string) $r['params'], true) ?: [];
            $out[]         = $r;
        }

        return $out;
    }

    /**
     * A backup file's forms.
     *
     * @return array{version: string, forms: list<array<string, mixed>>}
     */
    public static function parseFile(string $json): array
    {
        $data = json_decode($json, true);

        if (!\is_array($data)) {
            throw new \RuntimeException('That file isn\'t a ChronoForms backup (it isn\'t JSON).');
        }

        $data = array_is_list($data) ? $data : [$data];

        if ($data && \is_array($data[0]) && isset($data[0]['elements'])) {
            foreach ($data as &$f) {
                foreach (['elements', 'params'] as $k) {
                    if (\is_string($f[$k] ?? null)) {
                        $f[$k] = json_decode($f[$k], true) ?: [];
                    }
                }
            }
            unset($f);

            return ['version' => 'cf8', 'forms' => $data];
        }

        if ($data && \is_array($data[0]) && isset($data[0]['Connection'])) {
            return ['version' => 'cf7', 'forms' => array_values(array_filter(array_map(static fn ($i) => \is_array($i['Connection'] ?? null) ? $i['Connection'] : null, $data)))];
        }

        throw new \RuntimeException('That file isn\'t a ChronoForms 8 backup (.cf8bak) or ChronoForms 7 backup (.cf7bak).');
    }

    /**
     * Convert and create.
     *
     * @param list<array<string, mixed>> $forms
     *
     * @return list<array<string, mixed>> one line per ChronoForms form: title, ok, id, alias, message, report, logs
     */
    public static function run(array $forms, string $source, bool $withLog, User $by): array
    {
        $conv = [];

        $seven = str_starts_with($source, 'cf7');

        foreach ($forms as $f) {
            if ($seven) {
                // ChronoForms 7: shaped like a CF8 form first, so both convert the same way.
                $a = Cf7Adapter::adapt($f);
                $c = Cf8Converter::convert($a['form'], 'ChronoForms 7');
                $c['report'] = array_merge($a['notes'], $c['report']);
            } else {
                $c = Cf8Converter::convert($f);
            }

            $c['cf']    = ['id' => (int) ($f['id'] ?? 0), 'title' => trim((string) ($f['title'] ?? '')) ?: 'Imported form', 'alias' => (string) ($f['alias'] ?? '')];
            $c['skip']  = null;
            $conv[]     = $c;
        }

        self::listings($conv);

        $lines = [];

        foreach ($conv as $c) {
            $line = ['title' => $c['cf']['title'], 'ok' => false, 'id' => 0, 'alias' => '', 'message' => '', 'report' => $c['report'], 'logs' => 0];

            if ($c['skip'] !== null) {
                $line['message'] = $c['skip'];
                $lines[]         = $line;
                continue;
            }

            $made = self::create($c, $source);
            $line = array_merge($line, array_intersect_key($made, array_flip(['ok', 'id', 'alias', 'message'])));
            $line['report'] = $made['report'] ?? $c['report'];

            if ($line['ok'] && $withLog && \in_array($source, ['cf8-site', 'cf7-site'], true) && $c['cf']['id'] > 0) {
                $line['logs'] = self::dataLog((int) $line['id'], $c['cf']['id'], $c['map'], $by, $line['report'], $seven);
            }

            if ($line['ok']) {
                self::record((int) $line['id'], $source, $c, $line['report'], $by);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** Listing forms → the viewer of the form they listed; forms with nothing to fill in aren't made. */
    private static function listings(array &$conv): void
    {
        foreach ($conv as $i => &$c) {
            $values = array_filter($c['map'], static fn ($m) => $m['key'] !== '');

            if (!$c['listing']) {
                if (!$values && !self::hasContent($c['definition'])) {
                    $c['skip'] = 'Nothing to fill in (it ' . self::what($c) . '), so it wasn\'t made into a form.';
                }

                continue;
            }

            $el    = $c['listing']['element'];
            $table = (string) ($el['dbtable'] ?? '');

            if ($table === '' && preg_match('/^\{var:(read_data\d+)\}$/', (string) ($el['datasource'] ?? ''), $m)) {
                $table = (string) ($c['listing']['reads'][$m[1]]['dbtable'] ?? '');
            }

            $target = null;

            foreach ($conv as $j => $o) {
                if ($j !== $i && $table !== '' && \in_array($table, $o['saves'], true)) {
                    $target = $j;
                }
            }

            if ($target === null) {
                $c['report'][] = ['level' => 'todo', 'what' => 'Table listing', 'text' => 'Listed ' . ($table !== '' ? $table : 'data') . ', which isn\'t the saved data of a form imported with it. SquidForm lists the submissions of its own forms: import the form that saved this data at the same time, or use a Submissions viewer menu item on the SquidForm form.'];

                if (!$values) {
                    $c['skip'] = 'A list of ' . ($table !== '' ? $table : 'data') . ' with nothing to fill in, so it wasn\'t made into a form. See the report.';
                }

                continue;
            }

            $names = [];

            foreach ($conv[$target]['map'] as $m) {
                $names[$m['cf_name']] = $m['key'];
            }

            $cols = [];
            $miss = [];

            foreach ((array) ($el['columns'] ?? []) as $col) {
                $path = (string) ($col['path'] ?? '');
                $name = $names[$path] ?? match ($path) { 'id' => 'id', 'created' => 'created', 'user_id' => 'submitted_by', default => null };

                if ($name === null) {
                    $miss[] = $path;
                    continue;
                }

                $cols[] = trim((string) ($col['header'] ?? '')) !== '' ? ['name' => $name, 'heading' => (string) $col['header']] : $name;
            }

            if ($cols) {
                $conv[$target]['definition']['viewer'] = ['columns' => $cols];
            }

            $conv[$target]['report'][] = ['level' => 'changed', 'what' => 'Viewer', 'text' => 'The ChronoForms listing "' . $c['cf']['title'] . '" became this form\'s viewer settings (' . \count($cols) . ' columns'
                . ($miss ? '; not found: ' . implode(', ', $miss) : '') . '). Add a "SquidForm » Submissions viewer" menu item for it, and give the right groups View Submissions.'];

            if (!$values) {
                $c['skip'] = 'A listing: it became the viewer settings of "' . $conv[$target]['cf']['title'] . '".';
            }
        }
        unset($c);
    }

    private static function hasContent(array $def): bool
    {
        foreach ($def['pages'] as $p) {
            if ($p['fields'] || $p['events']['submit']) {
                return true;
            }
        }

        return false;
    }

    private static function what(array $c): string
    {
        $acts = array_map(static fn ($r) => trim((string) preg_replace('/\s+\d+$/', '', $r['what'])), array_filter($c['report'], static fn ($r) => $r['level'] === 'todo'));

        return $acts ? 'only ran ' . implode(', ', array_unique($acts)) : 'had no fields';
    }

    /** @return array<string, mixed> */
    private static function create(array $c, string $source): array
    {
        $note   = 'Imported from ChronoForms ' . (str_starts_with($source, 'cf7') ? '7' : '8') . (str_ends_with($source, '-site') ? ' on this site' : ' (backup file)') . ($c['cf']['id'] ? ', form ' . $c['cf']['id'] : '');
        $alias  = $c['cf']['alias'] !== '' ? $c['cf']['alias'] : $c['cf']['title'];
        $report = $c['report'];
        $def    = $c['definition'];
        $made   = FormTransfer::create($c['cf']['title'], $alias, '', (string) json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $note);

        // Refused: try once more without the parts most likely to upset the checks.
        if (!$made['ok']) {
            $why = $made['message'];

            foreach ($def['pages'] as &$p) {
                $p['fields'] = self::strip($p['fields']);
            }
            unset($p);

            unset($def['viewer']);
            $made = FormTransfer::create($c['cf']['title'], $alias, '', (string) json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $note);

            if ($made['ok']) {
                $report[] = ['level' => 'todo', 'what' => 'Conditions', 'text' => 'The show/hide conditions and viewer settings were left out because SquidForm refused them (' . mb_substr($why, 0, 200) . '). Add them in the builder.'];
            }
        }

        if ($made['ok']) {
            foreach (FormTransfer::missing($def) as $w) {
                $report[] = ['level' => 'todo', 'what' => 'Site', 'text' => $w];
            }
        }

        $made['report'] = $report;

        return $made;
    }

    /** @param list<array<string, mixed>> $fields @return list<array<string, mixed>> */
    private static function strip(array $fields): array
    {
        foreach ($fields as &$f) {
            unset($f['show_if'], $f['disabled_if']);

            if (\is_array($f['fields'] ?? null)) {
                $f['fields'] = self::strip($f['fields']);
            }
        }

        return $fields;
    }

    /** CF8's data log → submissions. @param list<array<string, mixed>> $map */
    private static function dataLog(int $formId, int $cfId, array $map, User $by, array &$report, bool $seven = false): int
    {
        // CF8: #__chronoforms8_datalog (id, ip); CF7: #__chronog3_forms7_datalog (aid, ipaddress). Both keep data by element id.
        $table = $seven ? 'chronog3_forms7_datalog' : 'chronoforms8_datalog';

        if (!self::hasTable($table)) {
            return 0;
        }

        $db   = self::db();
        $ids  = [];

        foreach ($map as $m) {
            $ids[(string) $m['cf_id']] = $m['key'];
        }

        $rows = [];

        foreach ($db->setQuery(
            $db->createQuery()->select(['data', 'created', 'user_id', $seven ? $db->quoteName('ipaddress', 'ip') : 'ip'])->from($db->quoteName('#__' . $table))
                ->where('form_id = :f')->order($seven ? 'aid' : 'id')->bind(':f', $cfId, ParameterType::INTEGER),
            0,
            SubmissionLoader::MAX_ROWS + 1
        )->loadObjectList() as $r) {
            $data = [];

            foreach ((array) json_decode((string) $r->data, true) as $elId => $v) {
                if (isset($ids[(string) $elId])) {
                    $data[$ids[(string) $elId]] = $v;
                }
            }

            $rows[] = ['data' => $data, 'created' => $r->created, 'user_id' => (int) $r->user_id, 'ip' => (string) $r->ip];
        }

        if (!$rows) {
            return 0;
        }

        $res = SubmissionLoader::load($formId, $rows, $by);
        $report[] = ['level' => 'changed', 'what' => 'Submissions', 'text' => ($res['added'] === 1 ? '1 submission' : $res['added'] . ' submissions') . ' came across from the ChronoForms data log, with their dates, users and IP addresses.'
            . ($res['skipped_fields'] ? ' Not filled: ' . implode(', ', $res['skipped_fields']) . ' (files and repeating groups can\'t come across).' : '')];

        foreach (\array_slice($res['problems'], 0, 20) as $p) {
            $report[] = ['level' => 'info', 'what' => 'Submissions', 'text' => $p . ' (kept as it was)'];
        }

        return $res['added'];
    }

    private static function record(int $formId, string $source, array $c, array $report, User $by): void
    {
        $row = (object) [
            'form_id'     => $formId,
            'source'      => substr($source, 0, 16),
            'source_ref'  => mb_substr(($c['cf']['id'] ? '#' . $c['cf']['id'] . ' ' : '') . $c['cf']['alias'], 0, 255),
            'title'       => mb_substr($c['cf']['title'], 0, 255),
            'map_json'    => (string) json_encode($c['map'], JSON_UNESCAPED_UNICODE),
            'report_json' => (string) json_encode($report, JSON_UNESCAPED_UNICODE),
            'created'     => Factory::getDate()->toSql(),
            'created_by'  => (int) $by->id,
        ];
        self::db()->insertObject('#__squidform_imports', $row);
    }

    /** The latest import record of a form, if it came from ChronoForms. */
    public static function recordFor(int $formId): ?object
    {
        try {
            $db = self::db();

            return $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__squidform_imports'))
                ->where('form_id = :f')->order('id DESC')->bind(':f', $formId, ParameterType::INTEGER), 0, 1)->loadObject() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
