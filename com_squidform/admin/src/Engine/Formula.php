<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Calculations (0.10.0): a small, safe formula language for Calculation
 * fields. It is parsed here into a tree of plain arrays; the server evaluates
 * the tree (the value that is saved), and the same tree is sent to the
 * browser, which evaluates it the same way while people type. A formula is
 * never run as PHP or JavaScript, and it can only read the form's own answers.
 *
 *   {f_qty} * {f_price}                 fields by key; + - * / %, ( )
 *   round({f_total} * 1.2, 2)           round(x, places), floor, ceil, abs
 *   min(a, b, …)  max(…)  sum(…)  avg(…)  count(…)
 *   sum({items.qty} * {items.price})    a repeating group: per row, then added up
 *   count({items})                      how many rows
 *   if({f_size} = "L", 5, 0)            = != < <= > >=, and, or, not
 *   days({f_from}, {f_to})              days between two dates; days({f_range}) counts a date range
 *   years({f_born}, today())            whole years between two dates
 *
 * Empty answers count as 0. Tick boxes and multi-choice answers add up their
 * numeric values (count() counts them). Dividing by 0 gives no result.
 *
 * Tree nodes: ["num", 1.5] ["str", "L"] ["ref", "key"] ["rref", "rep", "child"]
 * ["op", "+", a, b] ["neg", a] ["not", a] ["fn", "sum", [args]].
 */
final class Formula
{
    public const FUNCTIONS = [
        'round' => [1, 2], 'floor' => [1, 1], 'ceil' => [1, 1], 'abs' => [1, 1],
        'min' => [1, 50], 'max' => [1, 50], 'sum' => [1, 50], 'avg' => [1, 50], 'count' => [1, 50],
        'if' => [3, 3], 'days' => [1, 2], 'years' => [2, 2], 'age' => [2, 2], 'today' => [0, 0],
    ];

    /** Functions that take a repeating group's fields, one value per row. */
    public const AGGREGATES = ['min', 'max', 'sum', 'avg', 'count'];

    private const MAX_LENGTH = 2000;
    private const MAX_NODES  = 400;
    private const MAX_DEPTH  = 40;

    /** @var list<array{0: string, 1: mixed, 2: int}> */
    private array $tokens = [];
    private int $pos = 0;
    private int $nodes = 0;

    private function __construct()
    {
    }

    /**
     * Parse a formula into its tree.
     *
     * @throws \InvalidArgumentException with a message people can act on
     */
    public static function parse(string $text): array
    {
        if (trim($text) === '') {
            throw new \InvalidArgumentException('the formula is empty');
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('the formula is longer than ' . self::MAX_LENGTH . ' characters');
        }

        $p         = new self();
        $p->tokens = self::tokenize($text);
        $tree      = $p->expr(0);

        if ($p->peek()[0] !== 'end') {
            throw new \InvalidArgumentException('unexpected "' . $p->peek()[1] . '" at character ' . ($p->peek()[2] + 1));
        }

        return $tree;
    }

    /** @return list<array{0: string, 1: mixed, 2: int}> */
    private static function tokenize(string $s): array
    {
        $out = [];
        $i   = 0;
        $n   = \strlen($s);

        while ($i < $n) {
            $c = $s[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if (preg_match('/\G(\d+(?:\.\d+)?|\.\d+)/', $s, $m, 0, $i)) {
                $out[] = ['num', (float) $m[1], $i];
                $i += \strlen($m[1]);
                continue;
            }

            if ($c === '{') {
                if (!preg_match('/\G\{\s*([A-Za-z][A-Za-z0-9_]{0,63})(?:\.([A-Za-z][A-Za-z0-9_]{0,63}))?\s*\}/', $s, $m, 0, $i)) {
                    throw new \InvalidArgumentException('a field is written {field_key} (or {group_key.field_key} for a repeating group), at character ' . ($i + 1));
                }

                $out[] = isset($m[2]) && $m[2] !== '' ? ['rref', [$m[1], $m[2]], $i] : ['ref', $m[1], $i];
                $i += \strlen($m[0]);
                continue;
            }

            if ($c === '"' || $c === "'") {
                $end = strpos($s, $c, $i + 1);

                if ($end === false) {
                    throw new \InvalidArgumentException('a text in quotes isn’t closed, at character ' . ($i + 1));
                }

                $out[] = ['str', substr($s, $i + 1, $end - $i - 1), $i];
                $i     = $end + 1;
                continue;
            }

            if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $s, $m, 0, $i)) {
                $word  = strtolower($m[1]);
                $out[] = \in_array($word, ['and', 'or', 'not'], true) ? ['op', $word, $i] : ['name', $word, $i];
                $i += \strlen($m[1]);
                continue;
            }

            if (preg_match('/\G(<=|>=|!=|<>|==|[+\-*\/%()<>=,])/', $s, $m, 0, $i)) {
                $op    = $m[1] === '<>' ? '!=' : ($m[1] === '==' ? '=' : $m[1]);
                $out[] = \in_array($op, ['(', ')', ','], true) ? [$op, $op, $i] : ['op', $op, $i];
                $i += \strlen($m[1]);
                continue;
            }

            throw new \InvalidArgumentException('"' . mb_substr(substr($s, $i), 0, 1) . '" can’t be used in a formula (character ' . ($i + 1) . ')');
        }

        $out[] = ['end', 'the end', $n];

        return $out;
    }

    /** @return array{0: string, 1: mixed, 2: int} */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    /** @return array{0: string, 1: mixed, 2: int} */
    private function next(): array
    {
        return $this->tokens[$this->pos++];
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->peek();

        return $t[0] === 'op' && \in_array($t[1], $ops, true);
    }

    private function node(array $n, int $depth): array
    {
        if (++$this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('the formula is too long or nested too deeply');
        }

        return $n;
    }

    /** Precedence: or < and < not < comparison < + - < * / % < unary. */
    private function expr(int $d): array
    {
        $left = $this->andExpr($d + 1);

        while ($this->isOp('or')) {
            $this->next();
            $left = $this->node(['op', 'or', $left, $this->andExpr($d + 1)], $d);
        }

        return $left;
    }

    private function andExpr(int $d): array
    {
        $left = $this->notExpr($d + 1);

        while ($this->isOp('and')) {
            $this->next();
            $left = $this->node(['op', 'and', $left, $this->notExpr($d + 1)], $d);
        }

        return $left;
    }

    private function notExpr(int $d): array
    {
        if ($this->isOp('not')) {
            $this->next();

            return $this->node(['not', $this->notExpr($d + 1)], $d);
        }

        return $this->cmp($d + 1);
    }

    private function cmp(int $d): array
    {
        $left = $this->add($d + 1);

        if ($this->isOp('=', '!=', '<', '<=', '>', '>=')) {
            $op   = $this->next()[1];
            $left = $this->node(['op', $op, $left, $this->add($d + 1)], $d);

            if ($this->isOp('=', '!=', '<', '<=', '>', '>=')) {
                throw new \InvalidArgumentException('two comparisons in a row; use "and" between them');
            }
        }

        return $left;
    }

    private function add(int $d): array
    {
        $left = $this->mul($d + 1);

        while ($this->isOp('+', '-')) {
            $op   = $this->next()[1];
            $left = $this->node(['op', $op, $left, $this->mul($d + 1)], $d);
        }

        return $left;
    }

    private function mul(int $d): array
    {
        $left = $this->unary($d + 1);

        while ($this->isOp('*', '/', '%')) {
            $op   = $this->next()[1];
            $left = $this->node(['op', $op, $left, $this->unary($d + 1)], $d);
        }

        return $left;
    }

    private function unary(int $d): array
    {
        if ($this->isOp('-')) {
            $this->next();

            return $this->node(['neg', $this->unary($d + 1)], $d);
        }

        if ($this->isOp('+')) {
            $this->next();

            return $this->unary($d + 1);
        }

        return $this->primary($d + 1);
    }

    private function primary(int $d): array
    {
        $t = $this->next();

        switch ($t[0]) {
            case 'num':
                return $this->node(['num', $t[1]], $d);

            case 'str':
                return $this->node(['str', (string) $t[1]], $d);

            case 'ref':
                return $this->node(['ref', $t[1]], $d);

            case 'rref':
                return $this->node(['rref', $t[1][0], $t[1][1]], $d);

            case '(':
                $e = $this->expr($d + 1);

                if ($this->next()[0] !== ')') {
                    throw new \InvalidArgumentException('a "(" isn’t closed');
                }

                return $e;

            case 'name':
                $name = $t[1];

                if (!isset(self::FUNCTIONS[$name])) {
                    throw new \InvalidArgumentException('unknown function "' . $name . '" (use ' . implode(', ', array_keys(self::FUNCTIONS)) . ')');
                }

                if ($this->next()[0] !== '(') {
                    throw new \InvalidArgumentException('"' . $name . '" needs ( ) after it');
                }

                $args = [];

                if ($this->peek()[0] !== ')') {
                    $args[] = $this->expr($d + 1);

                    while ($this->peek()[0] === ',') {
                        $this->next();
                        $args[] = $this->expr($d + 1);
                    }
                }

                if ($this->next()[0] !== ')') {
                    throw new \InvalidArgumentException('"' . $name . '(" isn’t closed, or is missing a comma');
                }

                [$lo, $hi] = self::FUNCTIONS[$name];

                if (\count($args) < $lo || \count($args) > $hi) {
                    throw new \InvalidArgumentException('"' . $name . '" takes ' . ($lo === $hi ? $lo : $lo . ' to ' . $hi) . ' value' . ($hi === 1 ? '' : 's'));
                }

                return $this->node(['fn', $name, $args], $d);

            case 'end':
                throw new \InvalidArgumentException('the formula ends too soon');
        }

        throw new \InvalidArgumentException('unexpected "' . $t[1] . '" at character ' . ($t[2] + 1));
    }

    // -------------------------------------------------------------- checks

    /**
     * Every field the tree reads: ["key"] or ["rep", "child"].
     *
     * @return list<list<string>>
     */
    public static function refs(array $node): array
    {
        $out = [];
        self::walk($node, static function (array $n) use (&$out) {
            if ($n[0] === 'ref') {
                $out[] = [$n[1]];
            } elseif ($n[0] === 'rref') {
                $out[] = [$n[1], $n[2]];
            }
        });

        return $out;
    }

    private static function walk(array $n, callable $fn): void
    {
        $fn($n);

        switch ($n[0]) {
            case 'op':
                self::walk($n[2], $fn);
                self::walk($n[3], $fn);
                break;
            case 'neg':
            case 'not':
                self::walk($n[1], $fn);
                break;
            case 'fn':
                foreach ($n[2] as $a) {
                    self::walk($a, $fn);
                }
                break;
        }
    }

    /**
     * Where repeating-group fields are used: only inside sum/avg/min/max/count,
     * one group per value. Returns a problem, or null.
     */
    public static function rowProblem(array $node, bool $inAggregate = false): ?string
    {
        if ($node[0] === 'rref' && !$inAggregate) {
            return '{' . $node[1] . '.' . $node[2] . '} is in a repeating group: use it inside sum(), avg(), min(), max() or count()';
        }

        if ($node[0] === 'fn' && \in_array($node[1], self::AGGREGATES, true)) {
            foreach ($node[2] as $a) {
                $groups = array_unique(array_map(static fn ($r) => $r[0], array_filter(self::refs($a), static fn ($r) => \count($r) === 2)));

                if (\count($groups) > 1) {
                    return 'one value of ' . $node[1] . '() uses two repeating groups (' . implode(', ', $groups) . ')';
                }

                if ($p = self::rowProblem($a, true)) {
                    return $p;
                }
            }

            return null;
        }

        foreach (match ($node[0]) {
            'op' => [$node[2], $node[3]],
            'neg', 'not' => [$node[1]],
            'fn' => $node[2],
            default => [],
        } as $kid) {
            if ($p = self::rowProblem($kid, $inAggregate)) {
                return $p;
            }
        }

        return null;
    }

    // ---------------------------------------------------------- evaluation

    /**
     * Evaluate a tree. $get(key) gives a top-level (or this row's) value;
     * $rows(rep) the rows of a repeating group, each an array of values.
     *
     * @param callable(string): mixed            $get
     * @param callable(string): ?list<array>     $rows  null when the key isn't a repeating group
     */
    public static function evaluate(array $node, callable $get, callable $rows): float|string|null
    {
        switch ($node[0]) {
            case 'num':
                return (float) $node[1];

            case 'str':
                return (string) $node[1];

            case 'ref':
                $rs = $rows($node[1]);

                return $rs !== null ? (float) \count($rs) : self::scalar($get($node[1]));

            case 'rref':
                return null; // only inside an aggregate (checked on save)

            case 'neg':
                $v = self::evaluate($node[1], $get, $rows);

                return $v === null ? null : -self::num($v);

            case 'not':
                return self::truthy(self::evaluate($node[1], $get, $rows)) ? 0.0 : 1.0;

            case 'op':
                return self::operate($node, $get, $rows);

            case 'fn':
                return self::call($node[1], $node[2], $get, $rows);
        }

        return null;
    }

    private static function operate(array $node, callable $get, callable $rows): float|string|null
    {
        $op = $node[1];

        if ($op === 'and' || $op === 'or') {
            $l = self::truthy(self::evaluate($node[2], $get, $rows));

            if ($op === 'and' && !$l) {
                return 0.0;
            }

            if ($op === 'or' && $l) {
                return 1.0;
            }

            return self::truthy(self::evaluate($node[3], $get, $rows)) ? 1.0 : 0.0;
        }

        $a = self::evaluate($node[2], $get, $rows);
        $b = self::evaluate($node[3], $get, $rows);

        if (\in_array($op, ['=', '!=', '<', '<=', '>', '>='], true)) {
            if ($a === null || $b === null) {
                return 0.0;
            }

            if (self::isNumeric($a) && self::isNumeric($b)) {
                $c = self::num($a) <=> self::num($b);
            } else {
                $c = strcmp(mb_strtolower(trim((string) $a)), mb_strtolower(trim((string) $b)));
                $c = $c <=> 0;
            }

            return match ($op) {
                '='  => $c === 0,
                '!=' => $c !== 0,
                '<'  => $c < 0,
                '<=' => $c <= 0,
                '>'  => $c > 0,
                '>=' => $c >= 0,
            } ? 1.0 : 0.0;
        }

        if ($a === null || $b === null) {
            return null;
        }

        $x = self::num($a);
        $y = self::num($b);

        return self::finite(match ($op) {
            '+' => $x + $y,
            '-' => $x - $y,
            '*' => $x * $y,
            '/' => $y == 0.0 ? null : $x / $y,
            '%' => $y == 0.0 ? null : fmod($x, $y),
        });
    }

    /** @param list<array> $args */
    private static function call(string $fn, array $args, callable $get, callable $rows): float|string|null
    {
        $ev = static fn (array $n) => self::evaluate($n, $get, $rows);

        switch ($fn) {
            case 'if':
                return self::truthy($ev($args[0])) ? $ev($args[1]) : $ev($args[2]);

            case 'round':
                $v = $ev($args[0]);
                $p = isset($args[1]) ? (int) self::num($ev($args[1]) ?? 0) : 0;

                return $v === null ? null : round(self::num($v), max(-6, min(10, $p)));

            case 'floor':
            case 'ceil':
            case 'abs':
                $v = $ev($args[0]);

                return $v === null ? null : $fn(self::num($v));

            case 'today':
                return self::todayString();

            case 'days':
                if (\count($args) === 1) {
                    $r = $args[0][0] === 'ref' ? $get($args[0][1]) : null;

                    if (\is_array($r) && isset($r['from'], $r['to'])) {
                        $d = self::dayDiff((string) $r['from'], (string) $r['to']);

                        return $d === null ? null : $d + 1;
                    }

                    return null;
                }

                $d = self::dayDiff(self::dateOf($ev($args[0])), self::dateOf($ev($args[1])));

                return $d === null ? null : (float) $d;

            case 'years':
                return self::yearDiff(self::dateOf($ev($args[0])), self::dateOf($ev($args[1])));

            case 'age':
                $a = self::ageParts(self::dateOf($ev($args[0])), self::dateOf($ev($args[1])));

                return $a === null ? null : (float) ($a[0] * 10000 + $a[1] * 100 + $a[2]);
        }

        // Aggregates: each value is one number, a list (tick boxes) or, for a
        // repeating group's fields, one value per row.
        $vals = [];

        foreach ($args as $a) {
            $group = null;

            foreach (self::refs($a) as $r) {
                if (\count($r) === 2) {
                    $group = $r[0];
                    break;
                }
            }

            if ($a[0] === 'ref' && ($rs = $rows($a[1])) !== null) {
                // {group}: one per row
                $vals = array_merge($vals, array_fill(0, \count($rs), 1.0));
                continue;
            }

            if ($group !== null) {
                foreach ($rows($group) ?? [] as $row) {
                    $rowGet = static fn (string $k) => \array_key_exists($k, $row) ? $row[$k] : $get($k);
                    $v      = self::evaluate(self::rowTree($a, $group), $rowGet, $rows);

                    if ($v !== null && $v !== '') {
                        $vals[] = $v;
                    }
                }

                continue;
            }

            if ($a[0] === 'ref' && \is_array($raw = $get($a[1])) && array_is_list($raw)) {
                foreach ($raw as $item) {
                    if ($fn === 'count' || self::isNumeric($item)) {
                        $vals[] = $fn === 'count' ? 1.0 : self::num($item);
                    }
                }

                continue;
            }

            $v = $ev($a);

            if ($v !== null && !($fn === 'count' && $v === '')) {
                $vals[] = $v;
            }
        }

        if ($fn === 'count') {
            return (float) \count($vals);
        }

        $nums = array_map(static fn ($v) => self::num($v), $vals);

        if (!$nums) {
            return $fn === 'sum' ? 0.0 : null;
        }

        return self::finite(match ($fn) {
            'sum' => array_sum($nums),
            'avg' => array_sum($nums) / \count($nums),
            'min' => min($nums),
            'max' => max($nums),
        });
    }

    /** The tree with {rep.child} turned into {child}, read from the row. */
    private static function rowTree(array $n, string $group): array
    {
        return match ($n[0]) {
            'rref'  => $n[1] === $group ? ['ref', $n[2]] : $n,
            'op'    => ['op', $n[1], self::rowTree($n[2], $group), self::rowTree($n[3], $group)],
            'neg', 'not' => [$n[0], self::rowTree($n[1], $group)],
            'fn'    => ['fn', $n[1], array_map(static fn ($a) => self::rowTree($a, $group), $n[2])],
            default => $n,
        };
    }

    /** A field's value as one number or text: lists add up, objects don't count. */
    private static function scalar(mixed $v): float|string|null
    {
        if ($v === null || $v === '') {
            return '';
        }

        if (\is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }

        if (\is_array($v)) {
            if (array_is_list($v)) {
                return (float) array_sum(array_map(static fn ($x) => self::isNumeric($x) ? self::num($x) : 0.0, $v));
            }

            return isset($v['from'], $v['to']) ? (string) $v['from'] : '';
        }

        return \is_int($v) || \is_float($v) ? (float) $v : (string) $v;
    }

    private static function isNumeric(mixed $v): bool
    {
        return \is_float($v) || \is_int($v) || (\is_string($v) && is_numeric(trim($v)));
    }

    private static function num(mixed $v): float
    {
        return self::isNumeric($v) ? (float) (\is_string($v) ? trim($v) : $v) : 0.0;
    }

    private static function truthy(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }

        return self::isNumeric($v) ? self::num($v) != 0.0 : $v !== '0';
    }

    private static function finite(?float $v): ?float
    {
        return $v === null || is_nan($v) || is_infinite($v) ? null : $v;
    }

    private static function dateOf(mixed $v): string
    {
        return \is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m) ? $m[1] : '';
    }

    private static function dayDiff(string $a, string $b): ?int
    {
        $da = \DateTimeImmutable::createFromFormat('!Y-m-d', $a, new \DateTimeZone('UTC'));
        $db = \DateTimeImmutable::createFromFormat('!Y-m-d', $b, new \DateTimeZone('UTC'));

        if (!$da || !$db) {
            return null;
        }

        return (int) round(($db->getTimestamp() - $da->getTimestamp()) / 86400);
    }

    /**
     * 0.13.1: whole years, months and days from $a to $b, counted on the
     * calendar (a month borrowed for the days is the one before $b's month).
     * age() keeps them as one sortable number: years × 10000 + months × 100
     * + days (34 years, 2 months and 5 days = 340205).
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function ageParts(string $a, string $b): ?array
    {
        if ($a === '' || $b === '' || $b < $a) {
            return null;
        }

        [$y1, $m1, $d1] = array_map('intval', explode('-', $a));
        [$y2, $m2] = array_map('intval', explode('-', $b));
        // Whole months: step from $a a month at a time (a 31st becomes the
        // month's last day), then count the days left.
        $plus = static function (int $months) use ($y1, $m1, $d1): string {
            $t   = $y1 * 12 + ($m1 - 1) + $months;
            $y   = intdiv($t, 12);
            $m   = $t % 12 + 1;
            $len = (int) gmdate('t', (int) gmmktime(0, 0, 0, $m, 1, $y));

            return sprintf('%04d-%02d-%02d', $y, $m, min($d1, $len));
        };
        $total = ($y2 - $y1) * 12 + ($m2 - $m1);

        if ($plus($total) > $b) {
            $total--;
        }

        $y = intdiv($total, 12);
        $m = $total % 12;
        $d = (int) self::dayDiff($plus($total), $b);

        return [$y, $m, $d];
    }

    private static function yearDiff(string $a, string $b): ?float
    {
        if ($a === '' || $b === '') {
            return null;
        }

        [$y1, $m1, $d1] = array_map('intval', explode('-', $a));
        [$y2, $m2, $d2] = array_map('intval', explode('-', $b));
        $y = $y2 - $y1;

        if ($m2 < $m1 || ($m2 === $m1 && $d2 < $d1)) {
            $y--;
        }

        return (float) $y;
    }

    public static function todayString(): string
    {
        try {
            $tz = new \DateTimeZone((string) \Joomla\CMS\Factory::getApplication()->get('offset', 'UTC'));
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    /** A result as stored: rounded to the field's decimals, no trailing zeros. */
    public static function store(float|string|null $v, ?int $decimals): string
    {
        if ($v === null || $v === '' || !self::isNumeric($v)) {
            return '';
        }

        $v = self::num($v);

        if ($decimals !== null) {
            $v = round($v, $decimals);
        }

        $s = rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
