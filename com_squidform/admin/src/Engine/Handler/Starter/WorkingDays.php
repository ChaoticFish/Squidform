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
 * Working days: a due date N working days after today (the site's today) or
 * after a date the person picked, skipping Saturdays, Sundays and listed
 * holidays, in {var:due}.
 *
 * 1.0.2: a picked date must be a real calendar date (2026-02-31 is ignored,
 * not rolled into March); a date-and-time answer counts from its date; the
 * display format is limited to date letters, so a setting can't print
 * anything else.
 */
final class WorkingDays extends Starter
{
    public function label(): string
    {
        return 'Working days';
    }

    public function description(): string
    {
        return 'A due date N working days on (no weekends or listed holidays), in {var:due}.';
    }

    public function settings(): array
    {
        return [
            'days'     => 'How many working days (e.g. 5)',
            'from'     => 'Count from this date field (a field key; empty = today)',
            'holidays' => 'Holidays, as YYYY-MM-DD separated by commas',
            'format'   => 'Date format (PHP date letters, default j F Y)',
            'var'      => 'Name for the value (default due)',
            'field'    => 'Save into field (a date field key, optional; saved as YYYY-MM-DD)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        $days = max(0, min(365, (int) self::text($settings, 'days', '0')));
        $tz   = self::siteZone();
        $date = new \DateTimeImmutable('today', $tz);
        $from = self::fieldKey($ctx, $settings, 'from');

        if ($from !== '' && \is_string($ctx->data[$from] ?? null)) {
            $picked = self::date(substr($ctx->data[$from], 0, 10), $tz);
            $date   = $picked ?? $date;
        }

        $off = [];

        foreach (preg_split('/[\s,;]+/', self::text($settings, 'holidays', '', 4000), -1, PREG_SPLIT_NO_EMPTY) as $h) {
            if (self::date($h, $tz) !== null) {
                $off[$h] = true;
            }
        }

        for ($left = $days; $left > 0;) {
            $date = $date->modify('+1 day');

            if ((int) $date->format('N') < 6 && !isset($off[$date->format('Y-m-d')])) {
                $left--;
            }
        }

        // Only date letters, spaces and simple punctuation (no escapes, no times).
        $format = self::text($settings, 'format', 'j F Y', 40);
        $format = preg_match('/^[djDlNSwzWFmMntLoYy ,.\/\-]+$/', $format) ? $format : 'j F Y';
        $var    = self::varName($settings, 'var', 'due');

        $ctx->vars[$var] = $date->format($format);

        if (($field = self::fieldKey($ctx, $settings, 'field')) !== '') {
            $ctx->data[$field] = $date->format('Y-m-d');
        }

        return ActionResult::success($date->format('Y-m-d'), 'due ' . $date->format('Y-m-d'));
    }

    /** A real YYYY-MM-DD date, or null. */
    private static function date(string $v, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($v, $tz);
    }
}
