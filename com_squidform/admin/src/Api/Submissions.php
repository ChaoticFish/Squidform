<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Api;

use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\Audit;
use SquidForm\Component\SquidForm\Administrator\Engine\FormData;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * 1.0.3: reading a form's answers from another extension (a module, an add-on).
 *
 * The one supported way in: the form tables' names and columns are SquidForm's
 * business and can change. Reads only, plus archive/restore for add-ons that
 * tidy up (Tide Table archives past events). No permission checks here: the
 * caller is trusted PHP and decides who sees what. Nothing here takes SQL:
 * field keys are looked up in the form's definition and values are bound.
 *
 *   $api = Submissions::form('tidetable-events');          // null if there's no such form
 *   $rows = $api->find([
 *       'where' => [['f_date', '>=', '2026-11-20']],
 *       'order' => [['f_date', 'asc'], ['f_time', 'asc']],
 *       'limit' => 50,
 *   ]);
 *   foreach ($rows as $r) { $r['uid'], $r['status'], $r['created'], $r['data']['f_title'] … }
 */
final class Submissions
{
    public const VERSION = 1;

    /** Submissions that count as finished: sent (no approvals) or approved. */
    public const FINAL = ['submitted', 'approved'];

    private const OPS = ['=', '!=', '<', '<=', '>', '>='];

    /** @var array<string, mixed> */
    private array $form;

    private function __construct(array $form, private Schema $schema)
    {
        $this->form = $form;
    }

    /** The form with this alias (published or not), or null. */
    public static function form(string $alias): ?self
    {
        try {
            $f = FormRepository::load($alias, null, false);
        } catch (\Throwable) {
            return null;
        }

        return $f ? new self($f['form'], $f['schema']) : null;
    }

    public function id(): int
    {
        return (int) $this->form['id'];
    }

    public function alias(): string
    {
        return (string) $this->form['alias'];
    }

    public function title(): string
    {
        return (string) $this->form['title'];
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    /** @return array<string, array{type: string, label: string}> the fields that keep an answer */
    public function fields(): array
    {
        $out = [];

        foreach ($this->schema->valueFields() as $f) {
            $out[(string) $f['key']] = ['type' => (string) $f['type'], 'label' => (string) ($f['label'] ?? $f['key'])];
        }

        return $out;
    }

    /**
     * Submissions with their answers.
     *
     * Query keys (all optional):
     *  - status    list of statuses (default FINAL; see SubmissionStore::STATUSES)
     *  - archived  0 (default), 1, or null for both
     *  - test      false (default) leaves test-mode submissions out
     *  - where     list of [field key, op, value]; op one of = != < <= > >=. Fields
     *              inside a repeating group can't be used. All must hold.
     *  - order     list of [field key | "created" | "modified", "asc"|"desc"] (default created asc)
     *  - limit     1–1000 (default 500), offset ≥ 0
     *  - uids      only these submissions
     *
     * @param  array<string, mixed>  $q
     *
     * @return list<array{id: int, uid: string, status: string, archived: bool, created: string, modified: string, user_id: int, data: array<string, mixed>}>
     */
    public function find(array $q = []): array
    {
        $fd = FormData::forForm($this->id());

        if (!$fd->hasTables()) {
            return [];
        }

        $db    = SubmissionStore::db();
        $main  = $fd->mainTable();
        $query = $db->createQuery()
            ->select(['s.id', 's.uid', 's.status', 's.archived', 's.created', 's.modified', 's.user_id', 's.version_id'])
            ->from($db->quoteName('#__squidform_submissions', 's'))
            ->join('LEFT', $db->quoteName($main, 'm'), 'm.submission_id = s.id')
            ->where('s.form_id = :form')
            ->bind(':form', $this->form['id'], ParameterType::INTEGER);

        $status = array_values(array_intersect(array_map('strval', (array) ($q['status'] ?? self::FINAL)), SubmissionStore::STATUSES));

        if (!$status) {
            return [];
        }

        $query->whereIn($db->quoteName('s.status'), $status, ParameterType::STRING);

        $arch = \array_key_exists('archived', $q) ? $q['archived'] : 0;

        if ($arch !== null) {
            $a = $arch ? 1 : 0;
            $query->where('s.archived = :arch')->bind(':arch', $a, ParameterType::INTEGER);
        }

        if (empty($q['test'])) {
            $query->where('s.is_test = 0');
        }

        if (isset($q['uids'])) {
            $uids = array_values(array_filter(array_map('strval', (array) $q['uids']), static fn ($u) => (bool) preg_match('/^[0-9a-f-]{36}$/', $u)));

            if (!$uids) {
                return [];
            }

            $query->whereIn($db->quoteName('s.uid'), $uids, ParameterType::STRING);
        }

        $i = 0;

        foreach ((array) ($q['where'] ?? []) as $w) {
            [$key, $op, $value] = array_pad(array_values((array) $w), 3, null);
            $op  = (string) $op;
            $col = $this->col((string) $key);

            if ($col === false || !\in_array($op, self::OPS, true)) {
                throw new \InvalidArgumentException('SquidForm Submissions::find: can\'t filter on "' . $key . '" with "' . $op . '".');
            }

            // A field nobody has answered yet has no column: every answer is empty.
            $col ??= 'NULL';

            $class = FormData::classOf((string) $this->schema->field((string) $key)['type']);
            $v     = FormData::toDb($class, $class === 'bool' ? ($value ? '1' : '0') : $value);
            $p     = ':w' . $i++;

            if ($v === null) {
                $query->where($op === '!=' ? $col . ' IS NOT NULL' : $col . ' IS NULL');
                continue;
            }

            $query->where($col . ' ' . ($op === '!=' ? '<>' : $op) . ' ' . $p)->bind($p, $v);
        }

        $orders = (array) ($q['order'] ?? [['created', 'asc']]);

        foreach ($orders as $o) {
            [$key, $dir] = array_pad(array_values((array) $o), 2, 'asc');
            $dir = strtolower((string) $dir) === 'desc' ? 'DESC' : 'ASC';
            $col = \in_array($key, ['created', 'modified'], true) ? 's.' . $key : $this->col((string) $key);

            if ($col === false) {
                throw new \InvalidArgumentException('SquidForm Submissions::find: can\'t sort by "' . $key . '".');
            }

            if ($col !== null) {
                $query->order($col . ' ' . $dir);
            }
        }

        $query->order('s.id ASC');

        $limit  = max(1, min(1000, (int) ($q['limit'] ?? 500)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $query->setLimit($limit, $offset);

        $rows = $db->setQuery($query)->loadObjectList();

        if (!$rows) {
            return [];
        }

        $versions = [];

        foreach ($rows as $r) {
            $versions[(int) $r->id] = (int) $r->version_id;
        }

        $data = $fd->readMany(array_keys($versions), static fn (int $id) => FormRepository::version($versions[$id]));
        $out  = [];

        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r->id,
                'uid'      => (string) $r->uid,
                'status'   => (string) $r->status,
                'archived' => (bool) $r->archived,
                'created'  => (string) $r->created,
                'modified' => (string) $r->modified,
                'user_id'  => (int) $r->user_id,
                'data'     => $data[(int) $r->id] ?? [],
            ];
        }

        return $out;
    }

    /** One submission by its uid (any status, archived or not, test or not), or null. */
    public function get(string $uid): ?array
    {
        $rows = $this->find(['uids' => [$uid], 'status' => SubmissionStore::STATUSES, 'archived' => null, 'test' => true, 'limit' => 1]);

        return $rows[0] ?? null;
    }

    /** How many submissions match (same keys as find(), without order/limit). */
    public function count(array $q = []): int
    {
        $n = 0;

        for ($off = 0; ; $off += 1000) {
            $got = \count($this->find(['limit' => 1000, 'offset' => $off] + $q));
            $n  += $got;

            if ($got < 1000) {
                return $n;
            }
        }
    }

    /**
     * Archive (or restore) submissions of this form. Logged in Activity as done
     * by $byUser (0 = SquidForm itself, e.g. a scheduled task).
     *
     * @param  int[]  $ids
     *
     * @return int how many changed
     */
    public function archive(array $ids, bool $archive = true, int $byUser = 0): int
    {
        $db   = SubmissionStore::db();
        $flag = $archive ? 1 : 0;
        $n    = 0;
        $now  = Factory::getDate()->toSql();

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_submissions'))
                    ->set('archived = :flag')->set('modified = :now')->set('modified_by = :by')
                    ->where('id = :id')->where('form_id = :form')->where('archived <> :flag2')
                    ->bind(':flag', $flag, ParameterType::INTEGER)->bind(':flag2', $flag, ParameterType::INTEGER)
                    ->bind(':now', $now)->bind(':by', $byUser, ParameterType::INTEGER)
                    ->bind(':id', $id, ParameterType::INTEGER)->bind(':form', $this->form['id'], ParameterType::INTEGER)
            )->execute();

            if ($db->getAffectedRows() > 0) {
                Audit::log($id, $this->id(), $byUser, $archive ? 'archive' : 'restore');
                $n++;
            }
        }

        return $n;
    }

    /**
     * Main-table column for a field key, quoted; null when the field has no
     * column yet; false when it isn't a field that can be filtered or sorted
     * (unknown, a repeating group or inside one, or one that keeps no answer).
     */
    private function col(string $key): string|false|null
    {
        $f = $this->schema->field($key);

        if ($f === null || $this->schema->parentOf($key) !== null || $f['type'] === 'repeater'
            || !\in_array($key, array_column($this->schema->valueFields(), 'key'), true)) {
            return false;
        }

        $c = FormData::forForm($this->id())->column($f);

        return $c === null ? null : SubmissionStore::db()->quoteName('m.' . $c);
    }
}
