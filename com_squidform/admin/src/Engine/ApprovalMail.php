<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Built-in approval emails, sent through Joomla's mailer (Global
 * Configuration → Server → Mail), so an internal mail server works.
 *
 *  - to the approvers, when a request reaches their step (or is reassigned)
 *  - to the submitter, when it's approved, rejected or returned
 *
 * Plain text, one message per person (no one sees the other addresses).
 * Answers are never included: the mail names the form, the request number,
 * the step and who submitted, and links to the request. The approver's or
 * returner's comment is included for the submitter. Use on_approve /
 * on_reject / on_return email actions for anything else.
 *
 * Each form can switch either kind off (approval.notify).
 */
final class ApprovalMail
{
    /** @var list<array{to: string, subject: string}> sent this request (tests and debug) */
    public static array $sent = [];

    public static function toApprovers(int $id, string $note = ''): void
    {
        $item = SubmissionService::load($id);
        $flow = $item ? Approvals::flow($item) : null;

        if (!$item || !$flow || !$flow['notify']['approvers'] || (string) $item->status !== 'pending') {
            return;
        }

        $step  = ApprovalFlow::step($flow, (string) $item->current_step);
        $title = (string) ($step['title'] ?? $item->current_step);
        $ref   = Ref::short((string) $item->uid);
        $who   = (int) $item->user_id ? Users::name((int) $item->user_id) : Text::_('COM_SQUIDFORM_GUEST');

        foreach (Approvals::eligible($item) as $u) {
            $link = self::approverLink($id, (string) $item->uid, (int) $u->id);
            $body = Text::sprintf('COM_SQUIDFORM_MAIL_APPROVER_BODY', $u->name, (string) $item->form_title, $ref, $title, $who, $link);

            if ($u->behalf) {
                $body .= "\n\n" . Text::sprintf('COM_SQUIDFORM_MAIL_APPROVER_BEHALF', Users::name((int) $u->behalf));
            }

            if ($note !== '') {
                $body .= "\n\n" . Text::sprintf('COM_SQUIDFORM_MAIL_COMMENT', $note);
            }

            self::send((string) $u->email, Text::sprintf('COM_SQUIDFORM_MAIL_APPROVER_SUBJECT', (string) $item->form_title, $ref), $body, (string) $item->form_alias, $item);
        }
    }

    public static function toSubmitter(int $id, string $outcome, string $comment, string $stepKey): void
    {
        $item = SubmissionService::load($id);
        $flow = $item ? Approvals::flow($item) : null;

        if (!$item || !$flow || !$flow['notify']['submitter']) {
            return;
        }

        $to = '';

        if (($k = $flow['notify']['submitter_email']) !== '' && \is_string($item->data[$k] ?? null)) {
            $to = trim($item->data[$k]);
        }

        if ($to === '' && (int) $item->user_id) {
            $to = (string) (Users::get((int) $item->user_id)->email ?? '');
        }

        if ($to === '') {
            return;
        }

        $step  = ApprovalFlow::step($flow, $stepKey);
        $name  = (int) $item->user_id ? Users::name((int) $item->user_id) : '';
        $link  = self::submitterLink((int) $item->form_id, $id, (string) $item->uid);
        $ref   = Ref::short((string) $item->uid);
        $up    = strtoupper($outcome);
        $body  = Text::sprintf('COM_SQUIDFORM_MAIL_SUBMITTER_' . $up, $name, (string) $item->form_title, $ref, (string) ($step['title'] ?? ''));

        if ($comment !== '') {
            $body .= "\n\n" . Text::sprintf('COM_SQUIDFORM_MAIL_COMMENT', $comment);
        }

        if ($link !== '') {
            $body .= "\n\n" . Text::sprintf('COM_SQUIDFORM_MAIL_LINK', $link);
        }

        self::send($to, Text::sprintf('COM_SQUIDFORM_MAIL_SUBMITTER_SUBJECT_' . $up, (string) $item->form_title, $ref), $body, (string) $item->form_alias, $item);
    }

    /**
     * Link for an approver:
     *  - the site's "Awaiting my approval" menu item, if there is one;
     *  - otherwise the admin detail, for people who can sign in to the admin;
     *  - otherwise the site's approval page without a menu item, so approvers
     *    who aren't site administrators never get an admin link.
     */
    public static function approverLink(int $id, string $uid, int $userId = 0): string
    {
        $itemId = self::menuItem('index.php?option=com_squidform&view=approvals');

        if ($itemId) {
            return Route::link('site', 'index.php?option=com_squidform&view=approval&uid=' . $uid . '&Itemid=' . $itemId, false, 0, true);
        }

        if ($userId > 0 && Users::canAdminLogin($userId)) {
            return rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_squidform&view=submission&id=' . $id;
        }

        return Route::link('site', 'index.php?option=com_squidform&view=approval&uid=' . $uid, false, 0, true);
    }

    /** Whether the site has a published "Awaiting my approval" menu item. */
    public static function hasApprovalsMenuItem(): bool
    {
        return self::menuItem('index.php?option=com_squidform&view=approvals') > 0;
    }

    /** Link for the submitter: a viewer menu item for the form, if one exists. */
    public static function submitterLink(int $formId, int $id, string $uid): string
    {
        $itemId = self::menuItem('index.php?option=com_squidform&view=submissions&form_id=' . $formId);

        return $itemId ? Route::link('site', 'index.php?option=com_squidform&view=submission&uid=' . $uid . '&Itemid=' . $itemId, false, 0, true) : '';
    }

    /** First published site menu item whose link starts with $link (exact for form_id). */
    private static function menuItem(string $link): int
    {
        $db   = SubmissionStore::db();
        $like = $link . '%';
        $rows = $db->setQuery(
            $db->createQuery()->select(['id', 'link'])->from($db->quoteName('#__menu'))
                ->where('client_id = 0')->where('published = 1')->where($db->quoteName('link') . ' LIKE :l')
                ->bind(':l', $like)->order('id')
        )->loadObjectList();

        foreach ($rows as $r) {
            // "form_id=1" must not match "form_id=12".
            if (str_contains($link, 'form_id=') && !preg_match('/form_id=' . preg_quote(substr($link, strpos($link, 'form_id=') + 8), '/') . '(&|$)/', (string) $r->link)) {
                continue;
            }

            return (int) $r->id;
        }

        return 0;
    }

    private static function send(string $to, string $subject, string $body, string $alias, ?object $item = null): void
    {
        if (!MailHelper::isEmailAddress($to)) {
            return;
        }

        // Language strings write line breaks as \n.
        $body = str_replace(['\\n', '\\"'], ["\n", '"'], $body);

        // 0.15.0: a test submission's notices go only to the person who sent it.
        if ($item !== null && TestMode::isTest($item)) {
            $schema  = $item->schema ?? null;
            $tester  = $schema instanceof Schema ? TestMode::tester($schema, (int) ($item->user_id ?? 0)) : (string) Factory::getApplication()->get('mailfrom');
            $body    = TestMode::note([$to], false) . $body;
            $to      = $tester;
            $subject = TestMode::subject($subject);
        }

        $app = Factory::getApplication();

        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->setSender((string) $app->get('mailfrom'), (string) $app->get('fromname'));
            $mailer->addRecipient($to);
            $mailer->setSubject(str_replace(["\r", "\n"], ' ', $subject));
            $mailer->setBody($body);

            if (method_exists($mailer, 'isHtml')) {
                $mailer->isHtml(false);
            }

            $mailer->send();
            self::$sent[] = ['to' => $to, 'subject' => $subject];
        } catch (\Throwable $e) {
            Log::add('SquidForm approval email for form "' . $alias . '" to ' . $to . ' failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
        }
    }
}
