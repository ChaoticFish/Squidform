<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Turns stored submissions into CSV lines or JSON records.
 *
 * Rules (see docs/ADMIN-GUIDE.md "Exporting submissions"):
 *  - Values are the stored values (option values, not labels).
 *  - CSV: one line per repeater row; the submission's own columns repeat on
 *    each line. With two repeaters picked, their rows sit side by side by row
 *    number (row 1 with row 1), so lines never multiply.
 *  - JSON: one record per submission; repeaters are arrays of objects.
 *  - Only the picked columns are written. The IP address is never available:
 *    it is not in META, and unknown column ids are dropped.
 *  - CSV cells that a spreadsheet would treat as a formula get a leading '.
 */
final class Exporter
{
    /** Metadata columns that may be exported, in output order. IP is deliberately absent. */
    public const META = [
        'id'           => 'COM_SQUIDFORM_EXPORT_COL_ID',
        'uid'          => 'COM_SQUIDFORM_EXPORT_COL_UID',
        'created'      => 'COM_SQUIDFORM_EXPORT_COL_CREATED',
        'modified'     => 'COM_SQUIDFORM_EXPORT_COL_MODIFIED',
        'status'       => 'COM_SQUIDFORM_EXPORT_COL_STATUS',
        'submitted_by' => 'COM_SQUIDFORM_EXPORT_COL_SUBMITTED_BY',
    ];

    /** @var string[] */
    private array $meta = [];

    /** @var array<int, array{key:string,label:string}> */
    private array $plain = [];

    /** @var array<string, array{label:string, children: array<int, array{key:string,label:string}>}> */
    private array $reps = [];

    /** Column order as the definition lists it: ['f', key] or ['r', repKey]. */
    private array $order = [];

    private \DateTimeZone $tz;

    /**
     * @param  array<int, array>  $fieldColumns  from self::fieldColumns()
     * @param  string[]           $picked        column ids: "meta:id", "f:key", "f:rep.child"
     * @param  bool               $useKeys       header row shows field keys instead of labels
     * @param  callable(string):string  $translate  for metadata header labels
     */
    public function __construct(array $fieldColumns, array $picked, private bool $useKeys, private $translate, ?\DateTimeZone $tz = null)
    {
        $picked   = array_flip(array_map('strval', $picked));
        $this->tz = $tz ?? new \DateTimeZone('UTC');

        foreach (array_keys(self::META) as $m) {
            if (isset($picked['meta:' . $m])) {
                $this->meta[] = $m;
            }
        }

        foreach ($fieldColumns as $col) {
            if ($col['type'] === 'repeater') {
                $children = [];

                foreach ($col['children'] as $c) {
                    if (isset($picked['f:' . $col['key'] . '.' . $c['key']])) {
                        $children[] = $c;
                    }
                }

                if ($children) {
                    $this->reps[$col['key']] = ['label' => $col['label'], 'children' => $children];
                    $this->order[]           = ['r', $col['key']];
                }
            } elseif (isset($picked['f:' . $col['key']])) {
                $this->plain[$col['key']] = $col;
                $this->order[]            = ['f', $col['key']];
            }
        }
    }

    public function isEmpty(): bool
    {
        return !$this->meta && !$this->order;
    }

    /** @return string[]  the column ids actually used (for the audit log) */
    public function columnIds(): array
    {
        $out = array_map(static fn ($m) => 'meta:' . $m, $this->meta);

        foreach ($this->order as [$kind, $key]) {
            if ($kind === 'f') {
                $out[] = 'f:' . $key;
            } else {
                foreach ($this->reps[$key]['children'] as $c) {
                    $out[] = 'f:' . $key . '.' . $c['key'];
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ CSV

    /** @return string[] */
    public function csvHeader(): array
    {
        $h = [];

        foreach ($this->meta as $m) {
            $h[] = $this->useKeys ? $m : ($this->translate)(self::META[$m]);
        }

        if ($this->reps) {
            $h[] = $this->useKeys ? 'row' : ($this->translate)('COM_SQUIDFORM_EXPORT_COL_ROW');
        }

        foreach ($this->order as [$kind, $key]) {
            if ($kind === 'f') {
                $h[] = $this->useKeys ? $key : $this->plain[$key]['label'];
            } else {
                foreach ($this->reps[$key]['children'] as $c) {
                    $h[] = $this->useKeys ? $key . '.' . $c['key'] : $this->reps[$key]['label'] . ': ' . $c['label'];
                }
            }
        }

        return array_map([self::class, 'cell'], $h);
    }

    /**
     * @param  object  $row  submission row with id, uid, created, modified, status, user_id, user_name, data (array)
     *
     * @return array<int, string[]>
     */
    public function csvLines(object $row): array
    {
        $data  = (array) ($row->data ?? []);
        $fixed = [];

        foreach ($this->meta as $m) {
            $fixed[] = $this->metaValue($row, $m, false);
        }

        $lines = 1;

        foreach (array_keys($this->reps) as $rk) {
            $lines = max($lines, \count(self::rows($data[$rk] ?? null)));
        }

        $out = [];

        for ($i = 0; $i < $lines; $i++) {
            $line = $fixed;

            if ($this->reps) {
                $any = false;

                foreach (array_keys($this->reps) as $rk) {
                    $any = $any || isset(self::rows($data[$rk] ?? null)[$i]);
                }

                $line[] = $any ? (string) ($i + 1) : '';
            }

            foreach ($this->order as [$kind, $key]) {
                if ($kind === 'f') {
                    $line[] = self::cellText($this->plain[$key]['def'] ?? null, $data[$key] ?? null);
                } else {
                    $r = self::rows($data[$key] ?? null)[$i] ?? null;

                    foreach ($this->reps[$key]['children'] as $c) {
                        $line[] = \is_array($r) ? self::cellText($c['def'] ?? null, $r[$c['key']] ?? null) : '';
                    }
                }
            }

            $out[] = array_map([self::class, 'cell'], $line);
        }

        return $out;
    }

    // ----------------------------------------------------------------- JSON

    /** @return array<string, string>  key => label for every picked field (JSON "fields" header) */
    public function jsonFields(): array
    {
        $out = [];

        foreach ($this->order as [$kind, $key]) {
            if ($kind === 'f') {
                $out[$key] = $this->plain[$key]['label'];
            } else {
                $out[$key] = $this->reps[$key]['label'];

                foreach ($this->reps[$key]['children'] as $c) {
                    $out[$key . '.' . $c['key']] = $c['label'];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonRecord(object $row): array
    {
        $data = (array) ($row->data ?? []);
        $rec  = [];

        foreach ($this->meta as $m) {
            if ($m === 'submitted_by') {
                $rec['submitted_by']    = $this->metaValue($row, $m, true);
                $rec['submitted_by_id'] = (int) $row->user_id;
            } elseif ($m === 'id') {
                $rec['id'] = (int) $row->id;
            } else {
                $rec[$m] = $this->metaValue($row, $m, true);
            }
        }

        if ($this->order) {
            $vals = [];

            foreach ($this->order as [$kind, $key]) {
                if ($kind === 'f') {
                    $vals[$key] = $data[$key] ?? null;
                } else {
                    $vals[$key] = [];

                    foreach (self::rows($data[$key] ?? null) as $r) {
                        $o = [];

                        foreach ($this->reps[$key]['children'] as $c) {
                            $o[$c['key']] = \is_array($r) ? ($r[$c['key']] ?? null) : null;
                        }

                        $vals[$key][] = $o;
                    }
                }
            }

            $rec['data'] = $vals;
        }

        return $rec;
    }

    // -------------------------------------------------------------- helpers

    private function metaValue(object $row, string $m, bool $iso): string
    {
        return match ($m) {
            'id'           => (string) (int) $row->id,
            'uid'          => (string) $row->uid,
            'status'       => (string) $row->status,
            'submitted_by' => (int) $row->user_id ? (string) ($row->user_name ?? ('#' . (int) $row->user_id)) : 'Guest',
            'created', 'modified' => $this->date((string) $row->$m, $iso),
            default        => '',
        };
    }

    private function date(string $sql, bool $iso): string
    {
        if ($sql === '' || str_starts_with($sql, '0000')) {
            return '';
        }

        $d = new \DateTimeImmutable($sql, new \DateTimeZone('UTC'));
        $d = $d->setTimezone($this->tz);

        return $iso ? $d->format(\DATE_ATOM) : $d->format('Y-m-d H:i:s');
    }

    /** @return array<int, mixed> */
    private static function rows(mixed $v): array
    {
        return \is_array($v) ? array_values($v) : [];
    }

    /** A CSV cell: answers made of parts (0.8.1) as text people read; the rest as stored. */
    private static function cellText(?array $def, mixed $v): string
    {
        if ($def && \is_array($v) && $v !== [] && \in_array($def['type'] ?? '', ['signature', 'grid', 'name', 'address', 'upload', 'ranking', 'daterange', 'phone', 'consent', 'imagechoice'], true)) {
            return Display::value($def, $v, '');
        }

        // 0.13.1: an age reads as "34 years, 2 months and 5 days".
        if ($def && ($def['type'] ?? '') === 'calc' && is_numeric($v) && Field\CalcField::isAge($def)) {
            return Field\CalcField::ageText((int) $v);
        }

        return self::flat($v);
    }

    private static function flat(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        if (\is_bool($v)) {
            return $v ? '1' : '0';
        }

        if (\is_array($v)) {
            return implode('; ', array_map(static fn ($x) => \is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v));
        }

        return (string) $v;
    }

    /**
     * Spreadsheet formula guard (CSV injection): a cell that starts with
     * = + - @ or a tab/carriage return is prefixed with ' unless it is a plain
     * number such as -5 or +3.2.
     */
    public static function cell(string $v): string
    {
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^[+-]?\d+(\.\d+)?$/', $v)) {
            return "'" . $v;
        }

        return $v;
    }

    /**
     * Every value field a form has ever had, newest definition first, so
     * columns from older versions are still exportable.
     *
     * @return array<int, array{type:string,key:string,label:string,children?:array}>
     */
    public static function fieldColumns(int $formId): array
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()
            ->select('schema_json')
            ->from($db->quoteName('#__squidform_form_versions'))
            ->where('form_id = :f')
            ->order('version_no DESC')
            ->bind(':f', $formId, \Joomla\Database\ParameterType::INTEGER);

        $cols = [];

        foreach ($db->setQuery($q)->loadColumn() as $json) {
            try {
                $schema = Schema::fromJson((string) $json);
            } catch (\Throwable) {
                continue;
            }

            foreach ($schema->valueFields() as $f) {
                $key = (string) $f['key'];

                if ($f['type'] === 'repeater') {
                    $cols[$key] ??= ['type' => 'repeater', 'key' => $key, 'label' => (string) ($f['label'] ?? $key), 'children' => []];

                    if ($cols[$key]['type'] !== 'repeater') {
                        continue;
                    }

                    foreach ($f['fields'] ?? [] as $c) {
                        if (!FieldRegistry::get($c['type'])->storesValue()) {
                            continue;
                        }

                        $ck = (string) $c['key'];

                        if (!isset($cols[$key]['children'][$ck])) {
                            $cols[$key]['children'][$ck] = ['key' => $ck, 'label' => (string) ($c['label'] ?? $ck), 'def' => $c];
                        }
                    }
                } else {
                    $cols[$key] ??= ['type' => 'field', 'key' => $key, 'label' => (string) ($f['label'] ?? $key), 'def' => $f];
                }
            }
        }

        foreach ($cols as &$c) {
            if ($c['type'] === 'repeater') {
                $c['children'] = array_values($c['children']);
            }
        }

        return array_values($cols);
    }

    public static function userTimezone(): \DateTimeZone
    {
        $app = Factory::getApplication();
        $tz  = (string) ($app->getIdentity()->getParam('timezone') ?: $app->get('offset', 'UTC'));

        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
