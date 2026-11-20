<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;

\defined('_JEXEC') or die;

/**
 * Tidy text: cleans answers before they're saved. Trims and squeezes spaces
 * (including non-breaking and zero-width ones), writes names in Proper Case
 * (keeping Mc, O', hyphens and small words like "de" and "van"), lower-cases
 * email addresses, and writes 10-digit US/Canada numbers as (555) 123-4567.
 *
 * 1.0.2: only touches what it should. Text answers, and the text parts of
 * Name and Address fields (never a state, ZIP, postal code or country, or a
 * choice's stored value). Phone formatting is for the plain Phone field
 * only: a Phone with country code is already stored in its own checked form.
 * A repeating group's field is written "group.field". Mixed case that
 * someone typed on purpose ("DeShawn") is kept.
 */
final class TidyText extends Starter
{
    private const SMALL = ['de', 'del', 'della', 'der', 'di', 'du', 'la', 'le', 'van', 'von', 'da', 'dos', 'das', 'y', 'bin', 'ibn'];

    /** Field types whose answer is plain text. */
    private const TEXT = ['text', 'textarea', 'email', 'tel', 'hidden', 'lookup'];

    /** The parts of a Name or Address that are text. */
    private const PARTS = ['name' => null, 'address' => ['line1', 'line2', 'city', 'region']];

    public function label(): string
    {
        return 'Tidy text';
    }

    public function description(): string
    {
        return 'Trims spaces, writes names in Proper Case, lower-cases emails and formats US/Canada phone numbers. Put it before Save data.';
    }

    public function settings(): array
    {
        return [
            'trim'   => 'Trim spaces in these fields (keys, or all)',
            'names'  => 'Proper Case in these fields (keys; a Name field covers every part)',
            'emails' => 'Lower-case these email fields (keys)',
            'phones' => 'Format these Phone fields (keys; US/Canada numbers)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        $done  = 0;
        $space = static fn (string $v): string => trim((string) preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $v));

        $trim = self::text($settings, 'trim', '', 2000);
        $all  = strtolower($trim) === 'all';
        foreach ($all ? array_map(static fn ($f) => (string) $f['key'], $ctx->schema->valueFields()) : self::keys($settings, 'trim') as $k) {
            $done += $this->each($ctx, $k, $space, ['text', 'textarea', 'email', 'tel', 'hidden', 'lookup', 'name', 'address']);
        }

        foreach (self::keys($settings, 'names') as $k) {
            $done += $this->each($ctx, $k, fn (string $v) => $this->proper($space($v)), ['text', 'name', 'address', 'hidden']);
        }

        foreach (self::keys($settings, 'emails') as $k) {
            $done += $this->each($ctx, $k, static fn (string $v) => mb_strtolower($space($v)), ['email', 'text', 'hidden']);
        }

        foreach (self::keys($settings, 'phones') as $k) {
            $done += $this->each($ctx, $k, static function (string $v) use ($space): string {
                $d = (string) preg_replace('/\D/', '', $v);
                if (\strlen($d) === 11 && $d[0] === '1') {
                    $d = substr($d, 1);
                }

                return \strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)) : $space($v);
            }, ['tel', 'text', 'hidden']);
        }

        return ActionResult::success($done, $done . ' values tidied');
    }

    /**
     * Applies $fn to a field's text: the answer itself, the text parts of a
     * Name or Address, or "group.field" in every row of a repeating group.
     *
     * @param list<string> $types the field types this change suits
     */
    private function each(Context $ctx, string $key, callable $fn, array $types): int
    {
        [$top, $child] = array_pad(explode('.', $key, 2), 2, null);
        $field = $ctx->schema->field($top);

        if ($field === null || !\array_key_exists($top, $ctx->data)) {
            return 0;
        }

        if ($child !== null) {
            $sub = $ctx->schema->field($child);

            if (($field['type'] ?? '') !== 'repeater' || $sub === null || $ctx->schema->parentOf($child) !== $top || !\is_array($ctx->data[$top])) {
                return 0;
            }

            $n    = 0;
            $rows = $ctx->data[$top];

            foreach ($rows as $i => $row) {
                if (\is_array($row) && \array_key_exists($child, $row)) {
                    $rows[$i][$child] = $this->apply($row[$child], $sub, $fn, $types, $n);
                }
            }

            $ctx->data[$top] = $rows;

            return $n;
        }

        if ($ctx->schema->parentOf($top) !== null) {
            return 0;
        }

        $n = 0;
        $ctx->data[$top] = $this->apply($ctx->data[$top], $field, $fn, $types, $n);

        return $n;
    }

    private function apply(mixed $v, array $field, callable $fn, array $types, int &$n): mixed
    {
        $type = (string) ($field['type'] ?? '');

        if (!\in_array($type, $types, true)) {
            return $v;
        }

        if (\is_string($v) && \in_array($type, self::TEXT, true)) {
            $n++;

            return $fn($v);
        }

        if (\is_array($v) && \array_key_exists($type, self::PARTS) && !array_is_list($v)) {
            foreach ($v as $part => $pv) {
                if (\is_string($pv) && (self::PARTS[$type] === null || \in_array($part, self::PARTS[$type], true))) {
                    $v[$part] = $fn($pv);
                }
            }

            $n++;
        }

        return $v;
    }

    private function proper(string $v): string
    {
        // Leave mixed case alone (someone typed "DeShawn" or "McAllister" on purpose).
        if ($v === '' || ($v !== mb_strtolower($v) && $v !== mb_strtoupper($v))) {
            return $v;
        }

        $words = explode(' ', mb_strtolower($v));

        foreach ($words as $i => $w) {
            if ($i > 0 && \in_array($w, self::SMALL, true)) {
                continue;
            }

            $w = (string) preg_replace_callback('/(^|[-\'’])(\p{L})/u', static fn ($m) => $m[1] . mb_strtoupper($m[2]), $w);
            $w = (string) preg_replace_callback('/^(Mc)(\p{L})/u', static fn ($m) => $m[1] . mb_strtoupper($m[2]), $w);
            $words[$i] = $w;
        }

        return implode(' ', $words);
    }
}
