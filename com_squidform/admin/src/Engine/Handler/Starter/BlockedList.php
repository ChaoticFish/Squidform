<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Handler\Refused;

\defined('_JEXEC') or die;

/**
 * Blocked list: refuses to send the form when an answer is on your list: an
 * exact value ("1234567"), an email domain ("@example.com", which also
 * catches its sub-domains) or a pattern with * ("temp*"). Nothing is saved
 * or sent; the person keeps their answers and sees your message by that
 * field. With "Only these are allowed", the answer must be on the list
 * instead (only "@agency.gov" addresses, say).
 *
 * 1.0.2: answers are compared the way people read them: case doesn't
 * matter, accents and full-width letters are normalised (NFKC), and
 * invisible characters (zero-width spaces, soft hyphens) are removed, so
 * "bad@exam​ple.com" is still caught. A field with several answers
 * (tick boxes) is checked answer by answer. A * matches any run of
 * characters; nothing else in the list is a pattern.
 */
final class BlockedList extends Starter
{
    public function label(): string
    {
        return 'Blocked list';
    }

    public function description(): string
    {
        return 'Stops the form being sent when an answer is (or, if you choose, isn’t) on your list, with your own message. Put it first under “When it’s sent”.';
    }

    public function settings(): array
    {
        return [
            'field'   => 'Field to check (a field key)',
            'list'    => 'The list: values, @domains or patterns with *, separated by commas',
            'allow'   => 'Only these are allowed? (yes or no, default no)',
            'message' => 'What the person sees (default: That answer can’t be used here.)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        if (!self::sending($ctx)) {
            return ActionResult::skipped('checks run when a page is sent');
        }

        $field = self::fieldKey($ctx, $settings, 'field');

        if ($field === '') {
            return ActionResult::fail('“Field to check” isn’t a field on this form.');
        }

        $raw    = $ctx->data[$field] ?? null;
        $values = array_values(array_filter(array_map(static fn ($v) => \is_scalar($v) ? self::norm((string) $v) : '', \is_array($raw) ? $raw : [$raw]), static fn ($v) => $v !== ''));

        if (!$values) {
            return ActionResult::skipped('nothing to check');
        }

        $list  = array_values(array_filter(array_map([self::class, 'norm'], preg_split('/\s*,\s*|\R/', self::text($settings, 'list', '', 20000)) ?: []), static fn ($i) => $i !== ''));
        $allow = self::yes($settings, 'allow', false);

        foreach ($values as $v) {
            if (self::onList($v, $list) !== $allow) {
                throw new Refused(self::text($settings, 'message', 'That answer can’t be used here.', 300), $field);
            }
        }

        return ActionResult::success(null, 'allowed');
    }

    private static function onList(string $value, array $list): bool
    {
        $at = str_contains($value, '@') ? substr((string) strrchr($value, '@'), 1) : '';

        foreach ($list as $item) {
            if ($item[0] === '@') {
                $domain = ltrim(substr($item, 1), '.');

                if ($domain !== '' && $at !== '' && ($at === $domain || str_ends_with($at, '.' . $domain))) {
                    return true;
                }
            } elseif (str_contains($item, '*')) {
                // Built from the item with everything but * escaped; capped, so it stays cheap.
                $re = '/^' . str_replace('\*', '.*', preg_quote(mb_substr($item, 0, 200), '/')) . '$/us';

                if (preg_match($re, $value) === 1) {
                    return true;
                }
            } elseif ($value === $item) {
                return true;
            }
        }

        return false;
    }

    /** Lower case, NFKC, no invisible characters, single spaces, trimmed. */
    private static function norm(string $v): string
    {
        if (class_exists(\Normalizer::class)) {
            $v = (string) \Normalizer::normalize($v, \Normalizer::FORM_KC);
        }

        $v = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $v);
        $v = (string) preg_replace('/\s+/u', ' ', $v);

        return mb_strtolower(trim($v));
    }
}
