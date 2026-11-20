<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Where a form definition goes wrong (0.6.2).
 *
 * PHP's json_decode() only says "Syntax error". This scanner reads the same
 * JSON and reports the first mistake with its line, column and a plain
 * reason ("a comma is missing after this value"). It also records the line of
 * every value by its path ("pages[0].fields[2].type"), so validation errors
 * can say where they are, and lists keys written twice in one object (JSON
 * keeps only the last one, silently).
 *
 * It never decides what is valid: json_decode() does. The scanner only
 * explains a failure, or adds line numbers.
 */
final class JsonCheck
{
    private const MAX_DEPTH = 64;

    private string $s;
    private int $n;
    private int $i = 0;

    /** @var int[] byte offset where each line starts */
    private array $lineStarts = [0];

    /** @var array<string, int> path => line */
    private array $paths = [];

    /** @var list<array{path: string, key: string, line: int, first: int}> */
    private array $duplicates = [];

    /** @var array{line: int, column: int, message: string}|null */
    private ?array $error = null;

    private function __construct(string $json)
    {
        $this->s = $json;
        $this->n = \strlen($json);

        for ($p = strpos($json, "\n"); $p !== false; $p = strpos($json, "\n", $p + 1)) {
            $this->lineStarts[] = $p + 1;
        }
    }

    /**
     * @return array{error: ?array{line: int, column: int, message: string},
     *               paths: array<string, int>,
     *               duplicates: list<array{path: string, key: string, line: int, first: int}>}
     */
    public static function scan(string $json): array
    {
        $c = new self($json);
        $c->run();

        return ['error' => $c->error, 'paths' => $c->paths, 'duplicates' => $c->duplicates];
    }

    /**
     * Line of the value an error message talks about, from the path it starts
     * with ("pages[0].fields[2]: …", "\"settings\" must be …"). The longest
     * recorded path that the message's path starts with wins. 0 when unknown.
     *
     * @param array<string, int> $paths
     */
    public static function lineFor(string $message, array $paths): int
    {
        // Nested places name each key on the way: pages[0].fields[3] ("f_rows").fields[2] ("f_who")
        $message = (string) preg_replace('/ \("[^"]*"\)/', '', $message);

        if (!preg_match('/^"?([A-Za-z_][A-Za-z0-9_]*(?:\[\d+\]|\.[A-Za-z_][A-Za-z0-9_]*)*)"?/', $message, $m)) {
            return 0;
        }

        $path = $m[1];

        while ($path !== '') {
            if (isset($paths[$path])) {
                return $paths[$path];
            }

            $cut = max((int) strrpos($path, '.'), (int) strrpos($path, '['));

            if ($cut <= 0) {
                break;
            }

            $path = substr($path, 0, $cut);
        }

        return $paths[$path] ?? 0;
    }

    private function run(): void
    {
        if (!mb_check_encoding($this->s, 'UTF-8')) {
            $this->fail($this->firstBadUtf8(), 'the text contains characters that are not valid UTF-8 (paste it as plain text)');

            return;
        }

        // A byte-order mark is harmless to a person but not to JSON.
        if (str_starts_with($this->s, "\xEF\xBB\xBF")) {
            $this->i = 3;
        }

        if (!$this->ws()) {
            return;
        }

        if ($this->i >= $this->n) {
            $this->fail($this->i, 'the definition is empty');

            return;
        }

        if (!$this->value('', 0)) {
            return;
        }

        if (!$this->ws()) {
            return;
        }

        if ($this->i < $this->n) {
            $c = $this->s[$this->i];
            $this->fail($this->i, $c === '}' || $c === ']'
                ? 'there is an extra ' . $c . ' here; the definition already ended'
                : 'there is more after the end of the definition');
        }
    }

    /** Skip whitespace; refuse comments. */
    private function ws(): bool
    {
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];

            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->i++;
                continue;
            }

            if ($c === '/' && ($this->s[$this->i + 1] ?? '') !== '' && \in_array($this->s[$this->i + 1], ['/', '*'], true)) {
                return $this->fail($this->i, 'JSON doesn\'t allow comments (// or /* */); remove it');
            }

            break;
        }

        return true;
    }

    private function value(string $path, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return $this->fail($this->i, 'the definition is nested too deeply');
        }

        if ($path !== '' && !isset($this->paths[$path])) {
            $this->paths[$path] = $this->line($this->i);
        }

        if ($this->i >= $this->n) {
            return $this->fail($this->i, 'the definition ends where a value should be');
        }

        $c = $this->s[$this->i];

        return match (true) {
            $c === '{'                    => $this->object($path, $depth),
            $c === '['                    => $this->array($path, $depth),
            $c === '"'                    => $this->string(),
            $c === '-' || ctype_digit($c) => $this->number(),
            $c === "'"                    => $this->fail($this->i, 'use double quotes ("), not single quotes (\')'),
            $c === ',' => $this->fail($this->i, 'a value is missing before this comma'),
            $c === '}' || $c === ']' => $this->fail($this->i, 'a value is missing before this ' . $c),
            ctype_alpha($c) || $c === '_' => $this->word(),
            default => $this->fail($this->i, $this->isCurly($this->i)
                ? 'use straight double quotes ("), not curly quotes (“ ”), which word processors and email add'
                : 'unexpected "' . $this->charAt($this->i) . '": a value should start here'),
        };
    }

    private function object(string $path, int $depth): bool
    {
        $open  = $this->i++;
        $keys  = [];
        $comma = -1;

        if (!$this->ws()) {
            return false;
        }

        if (($this->s[$this->i] ?? '') === '}') {
            $this->i++;

            return true;
        }

        while (true) {
            if ($this->i >= $this->n) {
                return $this->fail($open, 'this { is never closed (a } is missing)');
            }

            $c = $this->s[$this->i];

            if ($c === '}' && $comma >= 0) {
                return $this->fail($comma, 'remove this comma: nothing follows it before }');
            }

            if ($c === "'") {
                return $this->fail($this->i, 'keys need double quotes ("key"), not single quotes');
            }

            if ($c !== '"' && $this->isCurly($this->i)) {
                return $this->fail($this->i, 'use straight double quotes ("), not curly quotes (“ ”), which word processors and email add');
            }

            if ($c !== '"') {
                return $this->fail($this->i, ctype_alpha($c) || $c === '_'
                    ? 'keys need double quotes, for example "' . $this->wordAt($this->i) . '"'
                    : 'a key in double quotes should start here');
            }

            $keyAt = $this->i;

            if (!$this->string()) {
                return false;
            }

            $key   = (string) json_decode(substr($this->s, $keyAt, $this->i - $keyAt));
            $child = $path === '' ? $key : $path . '.' . $key;

            if (isset($keys[$key])) {
                $this->duplicates[] = ['path' => $child, 'key' => $key, 'line' => $this->line($keyAt), 'first' => $keys[$key]];
            } else {
                $keys[$key] = $this->line($keyAt);
            }

            if (!$this->ws()) {
                return false;
            }

            if (($this->s[$this->i] ?? '') !== ':') {
                return $this->fail($this->i, 'a colon (:) is missing after "' . $key . '"');
            }

            $this->i++;

            if (!$this->ws()) {
                return false;
            }

            if (!isset($this->paths[$child])) {
                $this->paths[$child] = $this->line($keyAt);
            }

            if (!$this->value($child, $depth + 1)) {
                return false;
            }

            $end = $this->i;

            if (!$this->ws()) {
                return false;
            }

            $c = $this->s[$this->i] ?? '';

            if ($c === ',') {
                $comma = $this->i++;

                if (!$this->ws()) {
                    return false;
                }

                continue;
            }

            if ($c === '}') {
                $this->i++;

                return true;
            }

            if ($c === '') {
                return $this->fail($open, 'this { is never closed (a } is missing)');
            }

            if ($c === ']') {
                return $this->fail($this->i, 'this ] closes a list, but the { opened on line ' . $this->line($open) . ' is still open');
            }

            return $this->fail($end, 'a comma (,) is missing after this value');
        }
    }

    private function array(string $path, int $depth): bool
    {
        $open  = $this->i++;
        $index = 0;
        $comma = -1;

        if (!$this->ws()) {
            return false;
        }

        if (($this->s[$this->i] ?? '') === ']') {
            $this->i++;

            return true;
        }

        while (true) {
            if ($this->i >= $this->n) {
                return $this->fail($open, 'this [ is never closed (a ] is missing)');
            }

            if ($this->s[$this->i] === ']' && $comma >= 0) {
                return $this->fail($comma, 'remove this comma: nothing follows it before ]');
            }

            if (!$this->value($path . '[' . $index . ']', $depth + 1)) {
                return false;
            }

            $index++;
            $end = $this->i;

            if (!$this->ws()) {
                return false;
            }

            $c = $this->s[$this->i] ?? '';

            if ($c === ',') {
                $comma = $this->i++;

                if (!$this->ws()) {
                    return false;
                }

                continue;
            }

            if ($c === ']') {
                $this->i++;

                return true;
            }

            if ($c === '') {
                return $this->fail($open, 'this [ is never closed (a ] is missing)');
            }

            if ($c === '}') {
                return $this->fail($this->i, 'this } closes an object, but the [ opened on line ' . $this->line($open) . ' is still open');
            }

            return $this->fail($end, 'a comma (,) is missing after this value');
        }
    }

    private function string(): bool
    {
        $start = $this->i++;

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];

            if ($c === '"') {
                $this->i++;

                return true;
            }

            if ($c === '\\') {
                $e = $this->s[$this->i + 1] ?? '';

                if ($e === 'u') {
                    if (!preg_match('/\G[0-9A-Fa-f]{4}/', $this->s, $m, 0, $this->i + 2)) {
                        return $this->fail($this->i, '\\u must be followed by 4 hex digits');
                    }

                    $this->i += 6;
                    continue;
                }

                if ($e === '' || !str_contains('"\\/bfnrt', $e)) {
                    return $this->fail($this->i, '"\\' . $e . '" isn\'t allowed in a string; write \\\\ for a backslash');
                }

                $this->i += 2;
                continue;
            }

            if ($c === "\n" || $c === "\r") {
                return $this->fail($start, 'this text isn\'t closed: a closing " is missing on this line');
            }

            if (\ord($c) < 0x20) {
                return $this->fail($this->i, $c === "\t"
                    ? 'a tab inside a string must be written as \\t'
                    : 'a control character inside a string isn\'t allowed');
            }

            $this->i++;
        }

        return $this->fail($start, 'this text isn\'t closed: a closing " is missing');
    }

    private function number(): bool
    {
        if (!preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->s, $m, 0, $this->i)) {
            return $this->fail($this->i, 'this isn\'t a valid number');
        }

        $next = $this->s[$this->i + \strlen($m[0])] ?? '';

        if ($next !== '' && (ctype_alnum($next) || $next === '.' || $next === '_')) {
            return $this->fail($this->i, $m[0] === '0' || $m[0] === '-0'
                ? 'numbers can\'t start with 0 (write it in double quotes if it is text)'
                : 'this isn\'t a valid number (write it in double quotes if it is text)');
        }

        $this->i += \strlen($m[0]);

        return true;
    }

    private function word(): bool
    {
        $w = $this->wordAt($this->i);

        if (\in_array($w, ['true', 'false', 'null'], true)) {
            $this->i += \strlen($w);

            return true;
        }

        $lower = strtolower($w);

        return $this->fail($this->i, \in_array($lower, ['true', 'false', 'null'], true)
            ? 'write "' . $lower . '" in lower case'
            : '"' . $w . '" needs double quotes if it is text; only true, false and null can be written without them');
    }

    private function wordAt(int $at): string
    {
        preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $this->s, $m, 0, $at);

        return $m[0] ?? '';
    }

    private function isCurly(int $at): bool
    {
        return \in_array($this->charAt($at), ['“', '”', '‘', '’', '„', '«', '»'], true);
    }

    private function charAt(int $at): string
    {
        return mb_substr(substr($this->s, $at, 4), 0, 1);
    }

    private function fail(int $at, string $message): bool
    {
        $this->error ??= ['line' => $this->line($at), 'column' => $this->column($at), 'message' => $message];

        return false;
    }

    private function line(int $at): int
    {
        $lo = 0;
        $hi = \count($this->lineStarts) - 1;

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);

            if ($this->lineStarts[$mid] <= $at) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo + 1;
    }

    /** Column in characters (not bytes), from 1. */
    private function column(int $at): int
    {
        $start = $this->lineStarts[$this->line($at) - 1];

        return mb_strlen(substr($this->s, $start, max(0, $at - $start)), 'UTF-8') + 1;
    }

    private function firstBadUtf8(): int
    {
        for ($at = 0; $at < $this->n; $at++) {
            $b   = \ord($this->s[$at]);
            $len = $b < 0x80 ? 1 : ($b >= 0xC2 && $b <= 0xDF ? 2 : ($b >= 0xE0 && $b <= 0xEF ? 3 : ($b >= 0xF0 && $b <= 0xF4 ? 4 : 0)));

            if ($len === 0 || !mb_check_encoding(substr($this->s, $at, $len), 'UTF-8')) {
                return $at;
            }

            $at += $len - 1;
        }

        return 0;
    }
}
