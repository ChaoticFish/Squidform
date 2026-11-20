<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Date limits beyond fixed dates (0.13.0), for date, datetime and daterange:
 *
 * - rules.min / rules.max: a fixed date ("2027-01-31", or "2027-01-31T09:00" for
 *   datetime) or one relative to today: "today", "today+2", "today-30" (days, in
 *   the site's time zone).
 * - rules.weekdays: the days allowed, 1 (Monday) to 7 (Sunday). For a date range,
 *   both ends must fall on one.
 * - rules.hours: {"from": "09:00", "to": "17:00"}, for datetime: the time of day allowed.
 * - rules.after: {"field": "f_start", "days": 1}: at least that many days after
 *   another date field's answer (0 = the same day or later).
 * - min_days (daterange): the shortest range, alongside max_days.
 *
 * The server checks all of them; the form's script checks them too as people
 * type, and the date boxes carry the worked-out min and max.
 */
final class DateLimits
{
    public const RELATIVE = '/^today([+-]\d{1,4})?$/';

    /** Today in the site's time zone. */
    public static function today(): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone((string) (Factory::getApplication()->get('offset') ?: 'UTC'));
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('now', $tz))->setTime(0, 0);
    }

    /**
     * A rule's value as a date the browser and comparisons understand.
     *
     * @param string $type  date | datetime | daterange
     * @param string $end   min | max (relative datetimes: start or end of that day)
     */
    public static function resolve(mixed $rule, string $type, string $end): ?string
    {
        if (!\is_string($rule) || $rule === '') {
            return null;
        }

        if (preg_match(self::RELATIVE, $rule, $m)) {
            $d = self::today()->modify(((int) ($m[1] ?? 0)) . ' days')->format('Y-m-d');

            return $type === 'datetime' ? $d . ($end === 'min' ? 'T00:00' : 'T23:59') : $d;
        }

        return $rule;
    }

    /** Is this a rule value SquidForm can use? (for Schema) */
    public static function validRule(mixed $v, string $type): bool
    {
        if (!\is_string($v)) {
            return false;
        }

        if (preg_match(self::RELATIVE, $v)) {
            return true;
        }

        return (bool) preg_match($type === 'datetime' ? '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/' : '/^\d{4}-\d{2}-\d{2}$/', $v);
    }

    /** "Monday to Friday", "Tuesday and Thursday", "Monday, Wednesday or Friday". */
    public static function dayNames(array $days): string
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        $names = array_map(static fn ($d) => Text::_(['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'][$d - 1] ?? 'MONDAY'), $days);

        if (\count($days) > 2 && $days === range($days[0], $days[\count($days) - 1])) {
            return Text::sprintf('COM_SQUIDFORM_DAYS_RANGE', $names[0], $names[\count($names) - 1]);
        }

        if (\count($names) === 1) {
            return $names[0];
        }

        return implode(', ', \array_slice($names, 0, -1)) . ' ' . Text::_('COM_SQUIDFORM_DAYS_OR') . ' ' . end($names);
    }

    /**
     * Checks beyond min and max (they're in the fields' own checks, with resolve()).
     *
     * @param string $date  "Y-m-d" or "Y-m-dTH:i"
     *
     * @return string|null an error message
     */
    public static function check(array $field, string $date, string $label, array $scope, string $otherLabel = ''): ?string
    {
        $rules = (array) ($field['rules'] ?? []);
        $day   = substr($date, 0, 10);

        if (!empty($rules['weekdays']) && \is_array($rules['weekdays'])) {
            $dow = (int) (new \DateTimeImmutable($day))->format('N');

            if (!\in_array($dow, array_map('intval', $rules['weekdays']), true)) {
                return Text::sprintf('COM_SQUIDFORM_ERR_WEEKDAY', $label, self::dayNames(array_map('intval', $rules['weekdays'])));
            }
        }

        if (!empty($rules['hours']['from']) && !empty($rules['hours']['to']) && \strlen($date) > 10) {
            $t = substr($date, 11, 5);

            if ($t < $rules['hours']['from'] || $t > $rules['hours']['to']) {
                return Text::sprintf('COM_SQUIDFORM_ERR_HOURS', $label, $rules['hours']['from'], $rules['hours']['to']);
            }
        }

        if (!empty($rules['after']['field'])) {
            $other = $scope[$rules['after']['field']] ?? null;
            $other = \is_array($other) ? ($other['to'] ?? null) : $other;

            if (\is_string($other) && preg_match('/^\d{4}-\d{2}-\d{2}/', $other)) {
                $gap  = max(0, (int) ($rules['after']['days'] ?? 0));
                $from = new \DateTimeImmutable(substr($other, 0, 10));

                if ($day < $from->modify('+' . $gap . ' days')->format('Y-m-d')) {
                    return Text::sprintf($gap === 0 ? 'COM_SQUIDFORM_ERR_AFTER_SAME' : 'COM_SQUIDFORM_ERR_AFTER', $label, $otherLabel !== '' ? $otherLabel : (string) $rules['after']['field'], $gap);
                }
            }
        }

        return null;
    }

    /** data- attributes for the form's script. */
    public static function attrs(array $field, Field\RenderContext $rc): string
    {
        $rules = (array) ($field['rules'] ?? []);
        $out   = '';

        if (!empty($rules['weekdays']) && \is_array($rules['weekdays'])) {
            $days = array_map('intval', $rules['weekdays']);
            $out .= ' data-sf-weekdays="' . implode(',', $days) . '" data-sf-weekdays-msg="' . htmlspecialchars(Text::sprintf('COM_SQUIDFORM_ERR_WEEKDAY', (string) ($field['label'] ?? $field['key']), self::dayNames($days)), ENT_QUOTES, 'UTF-8') . '"';
        }

        if (!empty($rules['hours']['from']) && !empty($rules['hours']['to'])) {
            $out .= ' data-sf-hours="' . htmlspecialchars($rules['hours']['from'] . '-' . $rules['hours']['to'], ENT_QUOTES, 'UTF-8') . '" data-sf-hours-msg="'
                . htmlspecialchars(Text::sprintf('COM_SQUIDFORM_ERR_HOURS', (string) ($field['label'] ?? $field['key']), $rules['hours']['from'], $rules['hours']['to']), ENT_QUOTES, 'UTF-8') . '"';
        }

        if (!empty($rules['after']['field'])) {
            $gap  = max(0, (int) ($rules['after']['days'] ?? 0));
            $out .= ' data-sf-after="' . htmlspecialchars($rc->id((string) $rules['after']['field']), ENT_QUOTES, 'UTF-8') . '" data-sf-after-days="' . $gap . '"';
        }

        return $out;
    }
}
