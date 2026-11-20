<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Open and close dates and limits (0.10.0). Form settings:
 *
 *   "opens": "2026-12-01T09:00"     the site's time zone (Global Configuration)
 *   "closes": "2026-12-15T17:00"
 *   "max_submissions": 50           in total (every saved submission counts, archived too)
 *   "max_per_user": 1               each signed-in person (guests aren't counted)
 *   "before_message", "closed_message", "full_message", "user_message"   your own words (simple HTML)
 *
 * Per choice: a static option or image choice with "max": 20 holds that
 * many; full choices show as "(full)" and can't be picked. "show_left": true
 * on the field shows "(3 left)". Counting is done under a database lock, so
 * two people sending at once can't both take the last place.
 */
final class Limits
{
    public const DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';

    private static ?string $locked = null;

    private static function tz(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    public static function at(string $local): ?\DateTimeImmutable
    {
        if (!preg_match(self::DATETIME, $local)) {
            return null;
        }

        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, self::tz());

        return $d ?: null;
    }

    /** A setting's own words, or the default text. */
    private static function words(Schema $schema, string $setting, string $default): string
    {
        $own = trim((string) $schema->setting($setting, ''));

        return $own !== '' ? Html::clean($own) : htmlspecialchars($default, ENT_QUOTES, 'UTF-8');
    }

    /** Why the form can't be filled in now (HTML), or null when it's open. */
    public static function closed(Schema $schema, int $formId, User $user, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable('now', self::tz());
        $opens  = self::at((string) $schema->setting('opens', ''));
        $closes = self::at((string) $schema->setting('closes', ''));

        if ($opens && $now < $opens) {
            $when = HTMLHelper::_('date', $opens->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC2'));

            return self::words($schema, 'before_message', Text::sprintf('COM_SQUIDFORM_LIMIT_BEFORE', $when));
        }

        if ($closes && $now >= $closes) {
            return self::words($schema, 'closed_message', Text::_('COM_SQUIDFORM_LIMIT_CLOSED'));
        }

        $max = (int) $schema->setting('max_submissions', 0);

        if ($max > 0 && self::count($formId) >= $max) {
            return self::words($schema, 'full_message', Text::_('COM_SQUIDFORM_LIMIT_FULL'));
        }

        $per = (int) $schema->setting('max_per_user', 0);

        if ($per > 0 && (int) $user->id > 0 && self::count($formId, (int) $user->id) >= $per) {
            return self::words($schema, 'user_message', Text::plural('COM_SQUIDFORM_LIMIT_USER', $per));
        }

        return null;
    }

    private static function count(int $formId, ?int $userId = null): int
    {
        $db = SubmissionStore::db();
        $q  = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_submissions'))
            ->where($db->quoteName('form_id') . ' = :f')->bind(':f', $formId, ParameterType::INTEGER);

        if ($userId !== null) {
            $q->where($db->quoteName('user_id') . ' = :u')->bind(':u', $userId, ParameterType::INTEGER);
        }

        return (int) $db->setQuery($q)->loadResult();
    }

    /**
     * The places a field's choices hold: value => max.
     *
     * @return array<string, int>
     */
    public static function caps(array $field): array
    {
        $items = ($field['type'] ?? '') === 'imagechoice' ? ($field['choices'] ?? []) : ($field['options']['static'] ?? []);
        $out   = [];

        foreach (\is_array($items) ? $items : [] as $o) {
            if (\is_array($o) && isset($o['max']) && \is_int($o['max']) && $o['max'] >= 0) {
                $out[(string) ($o['value'] ?? $o['label'] ?? '')] = $o['max'];
            }
        }

        return $out;
    }

    /** Whether the form has any limit that needs counting under the lock. */
    public static function counts(Schema $schema): bool
    {
        if ((int) $schema->setting('max_submissions', 0) > 0 || (int) $schema->setting('max_per_user', 0) > 0) {
            return true;
        }

        foreach ($schema->valueFields() as $f) {
            if (self::caps($f)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many submissions chose each capped value.
     *
     * @return array<string, int>
     */
    private static function taken(int $formId, array $field, array $caps): array
    {
        $fd    = FormData::forForm($formId);
        $table = $fd->mainTable();
        $col   = $table ? $fd->column($field) : null;
        $out   = array_fill_keys(array_map('strval', array_keys($caps)), 0);

        if (!$table || !$col) {
            return $out;
        }

        $db   = SubmissionStore::db();
        $list = FormData::classOf($field['type']) === 'list';

        foreach (array_keys($out) as $v) {
            $q = $db->createQuery()->select('COUNT(*)')->from($db->quoteName($table));

            if ($list) {
                $like = '%' . addcslashes(json_encode((string) $v, JSON_UNESCAPED_UNICODE), '%_\\') . '%';
                $q->where($db->quoteName($col) . ' LIKE :v')->bind(':v', $like);
            } else {
                $q->where($db->quoteName($col) . ' = :v')->bind(':v', $v);
            }

            $out[$v] = (int) $db->setQuery($q)->loadResult();
        }

        return $out;
    }

    /**
     * Places left for each capped choice of these fields: key => value => left.
     *
     * @param  list<array<string, mixed>> $fields
     * @return array<string, array<string, int>>
     */
    public static function capacity(int $formId, array $fields): array
    {
        $out = [];

        foreach ($fields as $f) {
            $caps = self::caps($f);

            if (!$caps) {
                continue;
            }

            try {
                $taken = self::taken($formId, $f, $caps);
            } catch (\Throwable) {
                continue; // no table yet
            }

            foreach ($caps as $v => $max) {
                $out[$f['key']][(string) $v] = max(0, $max - ($taken[(string) $v] ?? 0));
            }
        }

        return $out;
    }

    /**
     * Chosen values that are already full: path => message.
     *
     * @param  array<string, mixed> $data
     * @return array<string, string>
     */
    public static function capErrors(int $formId, Schema $schema, array $data): array
    {
        $errors = [];
        $fields = array_filter($schema->valueFields(), static fn ($f) => self::caps($f) && \array_key_exists($f['key'], $data));
        $left   = self::capacity($formId, array_values($fields));

        foreach ($fields as $f) {
            foreach ((array) $data[$f['key']] as $v) {
                if (isset($left[$f['key']][(string) $v]) && $left[$f['key']][(string) $v] <= 0) {
                    $label = (string) $v;

                    foreach (($f['type'] === 'imagechoice' ? $f['choices'] : $f['options']['static']) as $o) {
                        if (\is_array($o) && (string) ($o['value'] ?? $o['label'] ?? '') === (string) $v) {
                            $label = (string) ($o['label'] ?? $v);
                        }
                    }

                    $errors[$f['key']] = Text::sprintf('COM_SQUIDFORM_ERR_CHOICE_FULL', (string) ($f['label'] ?? $f['key']), $label);
                }
            }
        }

        return $errors;
    }

    /**
     * Hold the form's limits lock (MySQL/MariaDB GET_LOCK) while a submission
     * is counted and saved. Returns false if it couldn't be had in 10 seconds.
     */
    public static function lock(int $formId): bool
    {
        $db   = SubmissionStore::db();
        $name = 'squidform_limits_' . $formId . '_' . substr(md5($db->getPrefix()), 0, 8);

        try {
            $got = $db->setQuery('SELECT GET_LOCK(' . $db->quote($name) . ', 10)')->loadResult();
        } catch (\Throwable $e) {
            // A database without named locks: count without one (a rare race), and say so in the log.
            \Joomla\CMS\Log\Log::add('SquidForm: the database has no GET_LOCK, so limits are counted without a lock: ' . $e->getMessage(), \Joomla\CMS\Log\Log::NOTICE, 'squidform');

            return true;
        }

        if ((int) $got === 1) {
            self::$locked = $name;

            return true;
        }

        return false;
    }

    public static function unlock(): void
    {
        if (self::$locked !== null) {
            try {
                $db = SubmissionStore::db();
                $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote(self::$locked) . ')')->execute();
            } catch (\Throwable) {
                // released when the connection ends anyway
            }

            self::$locked = null;
        }
    }
}
