<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Test mode (0.15.0): settings.test_mode on a form.
 *
 * - The form shows a "Test mode" banner to everyone.
 * - Emails (Email actions and approval notices) go only to the tester,
 *   with [TEST] in the subject and a line saying who they'd have gone to.
 *   The tester is the signed-in person sending it, else settings.test_email,
 *   else the site's From address. Confirmation emails go to the address
 *   typed in, as normal, with [TEST].
 * - Submissions are marked as tests (#__squidform_submissions.is_test): a
 *   badge in Submissions, left out of site lists, My submissions and
 *   exports, and removed together with Clear test submissions.
 */
final class TestMode
{
    public static function on(Schema $schema): bool
    {
        return !empty($schema->setting('test_mode'));
    }

    /** Where test emails go. */
    public static function tester(Schema $schema, int $userId): string
    {
        if ($userId > 0) {
            $email = (string) (Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId)->email ?? '');

            if (MailHelper::isEmailAddress($email)) {
                return $email;
            }
        }

        $set = trim((string) $schema->setting('test_email', ''));

        return MailHelper::isEmailAddress($set) ? $set : (string) Factory::getApplication()->get('mailfrom');
    }

    public static function subject(string $subject): string
    {
        return str_starts_with($subject, '[TEST] ') ? $subject : '[TEST] ' . $subject;
    }

    /** First line of a redirected email: who it would have gone to. */
    public static function note(array $intended, bool $html): string
    {
        $text = Text::sprintf('COM_SQUIDFORM_TEST_MAIL_NOTE', implode(', ', array_unique(array_filter($intended))) ?: '—');

        return $html ? '<p style="background:#fff3cd;border:1px solid #997404;color:#664d03;padding:8px">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>' : $text . "\n\n";
    }

    /** Is this saved submission a test? */
    public static function isTest(object $item): bool
    {
        return !empty($item->is_test);
    }

    /**
     * Permanently remove a form's test submissions (and their files and
     * rows in the form's tables), as Permanent delete does. Needs Permanently
     * Delete Submissions on the form. Returns how many.
     */
    public static function clear(int $formId, \Joomla\CMS\User\User $user): int
    {
        $db  = SubmissionStore::db();
        $ids = array_map('intval', $db->setQuery(
            $db->createQuery()->select('id')->from($db->quoteName('#__squidform_submissions'))
                ->where('form_id = :f')->where('is_test = 1')->bind(':f', $formId, ParameterType::INTEGER)
        )->loadColumn());

        if (!$ids) {
            return 0;
        }

        // The usual permanent delete (archive, then purge): same permission, same
        // clean-up of files and rows; signed records keep their retention.
        SubmissionService::setArchived($ids, 1, $user);
        $n = SubmissionService::purge($ids, $user);
        Audit::log(null, $formId, (int) $user->id, 'test_clear', ['count' => $n]);

        return $n;
    }
}
