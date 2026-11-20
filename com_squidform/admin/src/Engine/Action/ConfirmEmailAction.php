<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Pdf\Pdf;
use SquidForm\Component\SquidForm\Administrator\Engine\Lang;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionCsv;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * Confirmation email (0.11.0): a copy for the person who sent the form, to
 * the address they typed in one Email field, with its own settings.
 *
 * config:
 *   to_field    the Email field's key (required; one address)
 *   subject     default "Your answers: <form title>"; placeholders allowed
 *   body        HTML; default a thank-you line and {data:_all}
 *   from_name   default: Global Configuration "From name" (never from input)
 *   reply_to    e.g. the office's address (default: none)
 *   attach_csv  (0.13.0) attach the answers as a CSV file (the sender's copy, as for the PDF)
 *   attach_pdf  attach a PDF of the answers (the sender's copy: no hidden
 *               fields, "Hide what's typed" answers as dots)
 *
 * The address comes from the form, so anyone can make it go to any address:
 * it only ever goes to that one address, the sender is always the site's
 * own (Global Configuration), it's limited by the form's rate limit, and an
 * admin can turn confirmation emails off (Options → Email). Sent after the
 * database work commits, like every email.
 */
final class ConfirmEmailAction implements ActionInterface
{
    public function run(array $config, Context $ctx): ActionResult
    {
        // 0.15.0: in the sender's language, except the fun ones (those stay on screen).
        if ($ctx->original !== null && Lang::isJoke(Lang::current())) {
            return Lang::run(Lang::default(), fn () => $this->send($config, $ctx, $ctx->original));
        }

        return $this->send($config, $ctx, $ctx->schema);
    }

    private function send(array $config, Context $ctx, \SquidForm\Component\SquidForm\Administrator\Engine\Schema $schema): ActionResult
    {
        if (!(bool) ComponentHelper::getParams('com_squidform')->get('allow_confirmation', 1)) {
            return ActionResult::fail('Confirmation emails are turned off in SquidForm Options.');
        }

        if (Placeholders::readsInput((string) ($config['from_name'] ?? ''))) {
            return ActionResult::fail('"from_name" may not use form input.');
        }

        $to = str_replace(["\r", "\n", ',', ';', ' '], '', \is_scalar($ctx->value((string) ($config['to_field'] ?? ''))) ? (string) $ctx->value((string) $config['to_field']) : '');

        if ($to === '') {
            return ActionResult::success('', 'no address given; nothing sent');
        }

        if (!MailHelper::isEmailAddress($to)) {
            return ActionResult::fail('The address typed isn’t a valid email address.');
        }

        $app      = Factory::getApplication();
        $ph       = Placeholders::forContext($ctx, $schema);
        $title    = (string) ($ctx->form['title'] ?? '');
        $subject  = str_replace(["\r", "\n"], ' ', $ph->replace((string) ($config['subject'] ?? '') !== '' ? (string) $config['subject'] : Text::sprintf('COM_SQUIDFORM_CONFIRM_SUBJECT_DEFAULT', $title), 'text'));
        $body     = $ph->replace((string) ($config['body'] ?? '') !== '' ? (string) $config['body'] : '<p>' . htmlspecialchars(Text::_('COM_SQUIDFORM_CONFIRM_BODY_DEFAULT'), ENT_QUOTES, 'UTF-8') . '</p>{data:_all}', 'html');
        $fromMail = (string) $app->get('mailfrom');
        $fromName = trim($ph->replace((string) ($config['from_name'] ?? ''), 'text')) ?: (string) $app->get('fromname');
        $replyTo  = Placeholders::readsInput((string) ($config['reply_to'] ?? '')) ? [] : EmailAction::addresses($ph->replace((string) ($config['reply_to'] ?? ''), 'text'));
        $attach   = !empty($config['attach_pdf']);
        $withCsv  = !empty($config['attach_csv']);
        $alias    = (string) ($ctx->form['alias'] ?? '');

        // The PDF is made after commit from what was saved (or from the answers, without Save Data).
        $sid    = $ctx->submissionId;
        $pseudo = (object) [
            'id' => 0, 'uid' => $ctx->runUid, 'form_id' => (int) $ctx->form['id'], 'form_title' => $title, 'form_alias' => $alias,
            'created' => Factory::getDate()->toSql(), 'schema' => $schema, 'data' => $ctx->data, 'status' => '', 'user_id' => (int) $ctx->user->id,
        ];

        if (\SquidForm\Component\SquidForm\Administrator\Engine\TestMode::on($ctx->schema)) {
            $subject = \SquidForm\Component\SquidForm\Administrator\Engine\TestMode::subject($subject);
        }

        $ctx->afterCommit[] = static function () use ($to, $subject, $body, $fromMail, $fromName, $replyTo, $attach, $withCsv, $sid, $pseudo, $alias): void {
            try {
                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->setSender($fromMail, $fromName);
                $mailer->addRecipient($to);

                foreach ($replyTo as $a) {
                    $mailer->addReplyTo($a);
                }

                $mailer->setSubject($subject);
                $mailer->setBody($body);
                $mailer->isHtml(true);

                $item = ($attach || $withCsv) ? (($sid ? SubmissionService::load((int) $sid) : null) ?? $pseudo) : null;

                if ($attach) {
                    $mailer->addStringAttachment(Pdf::bytes(Pdf::submission($item, 'sender')), Pdf::fileName($item), 'base64', 'application/pdf');
                }

                // 0.13.0
                if ($withCsv) {
                    $mailer->addStringAttachment(SubmissionCsv::bytes($item, 'sender'), SubmissionCsv::fileName($item), 'base64', 'text/csv');
                }

                $mailer->send();
            } catch (\Throwable $e) {
                Log::add('SquidForm confirmation email for form "' . $alias . '" failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
            }
        };

        return ActionResult::success($to, 'queued for after commit' . ($attach ? ' (with PDF)' : '') . ($withCsv ? ' (with CSV)' : ''));
    }
}
