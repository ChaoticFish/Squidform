<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use Joomla\CMS\Factory;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Handler\AbstractHandler;
use SquidForm\Component\SquidForm\Administrator\Engine\TestMode;

\defined('_JEXEC') or die;

/**
 * 1.0.2: what the built-in starter handlers share. Starters are part of
 * SquidForm (covered by updates and the checksum list); each is off until
 * an admin ticks it in Options → Developers → Starter handlers.
 *
 * Settings come from the form's definition, written by form editors, so
 * every one is treated as untrusted text: cleaned, length-limited, and
 * never put into SQL, a path or a regular expression as it stands.
 */
abstract class Starter extends AbstractHandler
{
    /** Every starter, by the name forms use, in the order Options lists them. */
    public const ALL = [
        'BlockedList'  => BlockedList::class,
        'LookUpPerson' => LookUpPerson::class,
        'NextNumber'   => NextNumber::class,
        'SessionUser'  => SessionUser::class,
        'TakeTurns'    => TakeTurns::class,
        'TidyText'     => TidyText::class,
        'WorkingDays'  => WorkingDays::class,
        'WriteFile'    => WriteFile::class,
    ];

    /** A setting as plain, trimmed text of at most $max characters. */
    protected static function text(array $settings, string $name, string $default = '', int $max = 200): string
    {
        $v = $settings[$name] ?? '';
        $v = \is_scalar($v) ? trim((string) $v) : '';
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);

        return $v === '' ? $default : mb_substr($v, 0, $max);
    }

    /** "yes" or "no" (anything else is the default). */
    protected static function yes(array $settings, string $name, bool $default): bool
    {
        $v = strtolower(self::text($settings, $name));

        return \in_array($v, ['yes', 'y', 'true', '1', 'on'], true) ? true : (\in_array($v, ['no', 'n', 'false', '0', 'off'], true) ? false : $default);
    }

    /** A name for {var:…}: lower case letters, digits and _. */
    protected static function varName(array $settings, string $name, string $default): string
    {
        return substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower(self::text($settings, $name))), 0, 64) ?: $default;
    }

    /** A field key the form really has (top level), or '' when it doesn't. */
    protected static function fieldKey(Context $ctx, array $settings, string $name): string
    {
        $key = self::text($settings, $name, '', 64);

        return $key !== '' && $ctx->schema->field($key) !== null && $ctx->schema->parentOf($key) === null ? $key : '';
    }

    /** Field keys from a comma-separated setting ("group.child" for a repeating group's field). */
    protected static function keys(array $settings, string $name): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', self::text($settings, $name, '', 2000))),
            static fn ($k) => (bool) preg_match('/^[A-Za-z0-9_]{1,64}(\.[A-Za-z0-9_]{1,64})?$/', $k))));
    }

    /** True while a page is being sent (not when it opens). */
    protected static function sending(Context $ctx): bool
    {
        return $ctx->event !== 'load';
    }

    protected static function testMode(Context $ctx): bool
    {
        return TestMode::on($ctx->schema);
    }

    protected static function siteZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) (Factory::getApplication()->get('offset', 'UTC') ?: 'UTC'));
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
