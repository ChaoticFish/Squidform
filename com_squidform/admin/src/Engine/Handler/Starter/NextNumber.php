<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter;

use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Counters;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore;

\defined('_JEXEC') or die;

/**
 * Next number: a running number such as REQ-2026-0042, in {var:number}.
 *
 * 1.0.2: kept in a counter row (Counters) instead of counting submissions,
 * so a deleted submission never makes a number come round twice, and two
 * people sending at once queue on the row lock instead of sharing one. A
 * new counter starts after the submissions the form already has, so a form
 * that used the 0.15.0 example carries on where it was. The year is the
 * site's (Global Configuration's time zone). In test mode the numbers come
 * from their own counter and start with TEST-, so tests never use up real
 * ones. It only runs while a page is sent: put it on the last page, before
 * Save data.
 */
final class NextNumber extends Starter
{
    public function label(): string
    {
        return 'Next number';
    }

    public function description(): string
    {
        return 'A running number such as REQ-2026-0042, in {var:number}. Put it on the last page, under “When it’s sent”, before Save data.';
    }

    public function settings(): array
    {
        return [
            'prefix' => 'Starts with (letters and digits, e.g. REQ)',
            'digits' => 'Digits in the number (default 4)',
            'yearly' => 'Start again each year? (yes or no, default yes)',
            'var'    => 'Name for the value (default number)',
            'field'  => 'Save into field (a field key, optional)',
        ];
    }

    public function run(Context $ctx, array $settings): ActionResult
    {
        if (!self::sending($ctx)) {
            return ActionResult::skipped('numbers are given only when a page is sent');
        }

        $formId = (int) ($ctx->form['id'] ?? 0);
        $yearly = self::yes($settings, 'yearly', true);
        $year   = (int) (new \DateTimeImmutable('now', self::siteZone()))->format('Y');
        $test   = self::testMode($ctx);
        $name   = ($test ? 'test:' : '') . 'number:' . ($yearly ? $year : 'all');
        $n      = Counters::next($formId, $name, $test ? 0 : $this->already($formId, $yearly ? $year : null));
        $prefix = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', self::text($settings, 'prefix')), 0, 20)) ?: 'N';
        $digits = max(1, min(10, (int) self::text($settings, 'digits', '4')));
        $value  = ($test ? 'TEST-' : '') . $prefix . '-' . ($yearly ? $year . '-' : '') . str_pad((string) $n, $digits, '0', STR_PAD_LEFT);
        $var    = self::varName($settings, 'var', 'number');

        $ctx->vars[$var] = $value;

        if (($field = self::fieldKey($ctx, $settings, 'field')) !== '') {
            $ctx->data[$field] = $value;
        }

        return ActionResult::success($value, 'number ' . $value);
    }

    /** How many real submissions the form already has (this year, or ever), to start a new counter after them. */
    private function already(int $formId, ?int $year): int
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_submissions'))
            ->where($db->quoteName('form_id') . ' = :f')->bind(':f', $formId, ParameterType::INTEGER);

        try {
            $q->where($db->quoteName('is_test') . ' = 0');

            if ($year !== null) {
                // created is UTC; the year boundary is the site's.
                $start = (new \DateTimeImmutable($year . '-01-01 00:00:00', self::siteZone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $q->where($db->quoteName('created') . ' >= :y')->bind(':y', $start);
            }

            return (int) $db->setQuery($q)->loadResult();
        } catch (\Throwable) {
            return 0;
        }
    }
}
