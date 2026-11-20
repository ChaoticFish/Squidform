<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Skip pages (0.10.0): which pages a person sees. A page may have a
 * "show_if" that reads answers from earlier pages; Next and Back step over
 * the pages it hides, and their answers are dropped before saving. The first
 * and the last page always show (the last is where the form is sent).
 */
final class Flow
{
    /** @param array<string, mixed> $data */
    public static function visible(Schema $schema, int $page, array $data): bool
    {
        if ($page <= 0 || $page >= $schema->pageCount() - 1) {
            return true;
        }

        $cond = $schema->page($page)['show_if'] ?? null;

        return empty($cond) || Conditions::evaluate($cond, static fn ($k) => $data[$k] ?? null);
    }

    /** @param array<string, mixed> $data */
    public static function next(Schema $schema, int $page, array $data): ?int
    {
        for ($p = $page + 1; $p < $schema->pageCount(); $p++) {
            if (self::visible($schema, $p, $data)) {
                return $p;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public static function previous(Schema $schema, int $page, array $data): int
    {
        for ($p = $page - 1; $p > 0; $p--) {
            if (self::visible($schema, $p, $data)) {
                return $p;
            }
        }

        return 0;
    }

    /**
     * The pages shown, in order.
     *
     * @param  array<string, mixed> $data
     * @return list<int>
     */
    public static function pages(Schema $schema, array $data): array
    {
        $out = [];

        for ($p = 0; $p < $schema->pageCount(); $p++) {
            if (self::visible($schema, $p, $data)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Answers on skipped pages are removed (the person never saw those questions).
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function dropSkipped(Schema $schema, array $data): array
    {
        // Twice: skipping one page can't hide an earlier one, but it can change a later one.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($p = 1; $p < $schema->pageCount() - 1; $p++) {
                if (!self::visible($schema, $p, $data)) {
                    foreach ($schema->pageFields($p) as $f) {
                        unset($data[$f['key']]);
                    }
                }
            }
        }

        return $data;
    }

    /** Which page a field (or an error path "rep.0.child") is on. */
    public static function pageOf(Schema $schema, string $path): ?int
    {
        $key = explode('.', $path)[0];

        for ($p = 0; $p < $schema->pageCount(); $p++) {
            foreach ($schema->pageFields($p) as $f) {
                if (($f['key'] ?? '') === $key) {
                    return $p;
                }
            }
        }

        return null;
    }
}
