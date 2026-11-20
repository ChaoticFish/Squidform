<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Conditions;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Lang;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;
use SquidForm\Component\SquidForm\Administrator\Engine\TestMode;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionCsv;
use SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService;

\defined('_JEXEC') or die;

/**
 * Email: sends a message with Joomla's mailer (Global Configuration → Server → Mail).
 *
 * config:
 *   to, cc, bcc   comma-separated addresses; placeholders allowed
 *   from_email    default: Global Configuration "From email"
 *   from_name     default: Global Configuration "From name"
 *   reply_to      e.g. "{data:f_email}" so replies reach the submitter
 *   subject       placeholders allowed (plain text)
 *   body          HTML; placeholders are escaped; {data:_all} gives a table of every field
 *   format        "html" (default) or "text"
 *   routes        (0.11.0) who it goes to depends on the answers:
 *                 [{"when": {"field": "f_dept", "op": "eq", "value": "hr"}, "to": "hr@example.org", "cc": "…"}, …]
 *                 Matching routes' addresses are used instead of "to" (and
 *                 added to "cc"); "to" is used when no route matches, and if
 *                 it's empty nothing is sent.
 *   attach_csv    (0.13.0) attach the answers as a CSV file: one line of
 *                 headings, one of answers; no IP address. If any recipient
 *                 comes from the answers, it is the sender's copy (no hidden
 *                 fields, "Hide what's typed" answers or named values).
 *   route_match   "first" (default: the first route that matches) or "all"
 *
 * Safety: if to/cc/bcc use input-controlled placeholders ({data:...},
 * {var:...}, {action:...}, {user.field:...}) the action fails unless "Allow
 * recipients from form input" is on in the SquidForm options. from_email and
 * from_name may never use them. reply_to may always use submitted data.
 *
 * The mail is sent only after the database work commits, so a rolled-back
 * submission never sends mail about data that was not saved.
 */
final class EmailAction implements ActionInterface
{
    public function run(array $config, Context $ctx): ActionResult
    {
        // 0.15.0: emails to staff stay in the site's language, with the form's own labels.
        if ($ctx->original !== null) {
            return Lang::run(Lang::default(), fn () => $this->send($config, $ctx, $ctx->original));
        }

        return $this->send($config, $ctx, $ctx->schema);
    }

    private function send(array $config, Context $ctx, \SquidForm\Component\SquidForm\Administrator\Engine\Schema $schema): ActionResult
    {
        $ph      = Placeholders::forContext($ctx, $schema);
        $allowIn = (bool) ComponentHelper::getParams('com_squidform')->get('allow_input_recipients', 0);

        $routes = \is_array($config['routes'] ?? null) ? $config['routes'] : [];

        foreach (['to', 'cc', 'bcc'] as $f) {
            if (!$allowIn && Placeholders::readsInput((string) ($config[$f] ?? ''))) {
                return ActionResult::fail('Recipients from form input are turned off in SquidForm Options.');
            }
        }

        foreach ($routes as $r) {
            if (!$allowIn && (Placeholders::readsInput((string) ($r['to'] ?? '')) || Placeholders::readsInput((string) ($r['cc'] ?? '')))) {
                return ActionResult::fail('Recipients from form input are turned off in SquidForm Options.');
            }
        }

        // The sender must never come from input: it would let anyone make mail
        // appear to come from any address. Use reply_to for the submitter.
        foreach (['from_email', 'from_name'] as $f) {
            if (Placeholders::readsInput((string) ($config[$f] ?? ''))) {
                return ActionResult::fail('"' . $f . '" may not use form input; use reply_to instead.');
            }
        }

        $lists = [];

        foreach (['to', 'cc', 'bcc'] as $f) {
            $lists[$f] = self::addresses($ph->replace((string) ($config[$f] ?? ''), 'text'));
        }

        // Routes (0.11.0): the answers choose who it goes to.
        $routed = null;

        if ($routes) {
            $all = ($config['route_match'] ?? 'first') === 'all';

            foreach ($routes as $i => $r) {
                if (!\is_array($r) || !\is_array($r['when'] ?? null)) {
                    continue;
                }

                if (Conditions::evaluate($r['when'], static fn (string $k) => $ctx->value($k), static fn (string $a) => $ctx->outcome($a))) {
                    $routed ??= ['to' => [], 'cc' => [], 'n' => []];
                    $routed['to'] = array_merge($routed['to'], self::addresses($ph->replace((string) ($r['to'] ?? ''), 'text')));
                    $routed['cc'] = array_merge($routed['cc'], self::addresses($ph->replace((string) ($r['cc'] ?? ''), 'text')));
                    $routed['n'][] = $i + 1;

                    if (!$all) {
                        break;
                    }
                }
            }

            if ($routed !== null) {
                $lists['to'] = array_values(array_unique($routed['to']));
                $lists['cc'] = array_values(array_unique(array_merge($lists['cc'], $routed['cc'])));
            } elseif (!$lists['to']) {
                return ActionResult::success('', 'no route matched; nothing sent');
            }
        }

        if (!$lists['to']) {
            return ActionResult::fail('No valid "to" address.');
        }

        $app      = Factory::getApplication();
        $html     = ($config['format'] ?? 'html') !== 'text';
        $subject  = str_replace(["\r", "\n"], ' ', $ph->replace((string) ($config['subject'] ?? ''), 'text'));
        $body     = $ph->replace((string) ($config['body'] ?? '{data:_all}'), $html ? 'html' : 'text');
        $fromMail = trim($ph->replace((string) ($config['from_email'] ?? ''), 'text')) ?: (string) $app->get('mailfrom');
        $fromName = trim($ph->replace((string) ($config['from_name'] ?? ''), 'text')) ?: (string) $app->get('fromname');
        $replyTo  = self::addresses($ph->replace((string) ($config['reply_to'] ?? ''), 'text'));

        if (!MailHelper::isEmailAddress($fromMail)) {
            return ActionResult::fail('Invalid "from" address.');
        }

        $formAlias = (string) ($ctx->form['alias'] ?? '');
        $withCsv   = !empty($config['attach_csv']);
        // Sent to an address from the answers (only with "Allow recipients from
        // form input" on)? Then the CSV is the sender's copy, without hidden fields.
        $fromInput = false;

        foreach (array_merge([$config], $routes) as $c) {
            foreach (['to', 'cc', 'bcc'] as $f) {
                $fromInput = $fromInput || Placeholders::readsInput((string) ($c[$f] ?? ''));
            }
        }

        $csvFor = $fromInput ? 'sender' : 'staff';
        $sid       = $ctx->submissionId;
        $pseudo    = (object) [
            'id' => 0, 'uid' => $ctx->runUid, 'form_id' => (int) $ctx->form['id'], 'form_title' => (string) ($ctx->form['title'] ?? ''), 'form_alias' => $formAlias,
            'created' => Factory::getDate()->toSql(), 'schema' => $schema, 'data' => $ctx->data, 'status' => '', 'user_id' => (int) $ctx->user->id,
        ];

        // 0.15.0: test mode sends it only to the tester, saying who it would have gone to.
        if (TestMode::on($ctx->schema)) {
            $intended = array_merge($lists['to'], $lists['cc'], $lists['bcc']);
            $lists    = ['to' => [TestMode::tester($ctx->schema, (int) $ctx->user->id)], 'cc' => [], 'bcc' => []];
            $subject  = TestMode::subject($subject);
            $body     = TestMode::note($intended, $html) . $body;
        }

        $ctx->afterCommit[] = static function () use ($lists, $subject, $body, $html, $fromMail, $fromName, $replyTo, $formAlias, $withCsv, $csvFor, $sid, $pseudo): void {
            try {
                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->setSender($fromMail, $fromName);

                foreach ($lists['to'] as $a) {
                    $mailer->addRecipient($a);
                }

                foreach ($lists['cc'] as $a) {
                    $mailer->addCc($a);
                }

                foreach ($lists['bcc'] as $a) {
                    $mailer->addBcc($a);
                }

                foreach ($replyTo as $a) {
                    $mailer->addReplyTo($a);
                }

                $mailer->setSubject($subject);
                $mailer->setBody($body);

                if (method_exists($mailer, 'isHtml')) {
                    $mailer->isHtml($html);
                }

                if ($withCsv) {
                    $item = ($sid ? SubmissionService::load((int) $sid) : null) ?? $pseudo;
                    $mailer->addStringAttachment(SubmissionCsv::bytes($item, $csvFor), SubmissionCsv::fileName($item), 'base64', 'text/csv');
                }

                $mailer->send();
            } catch (\Throwable $e) {
                Log::add('SquidForm email for form "' . $formAlias . '" failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
            }
        };

        return ActionResult::success(implode(', ', $lists['to']), ($routed !== null ? 'route ' . implode(', ', $routed['n']) . '; ' : '') . 'queued for after commit');
    }

    /** @return string[] valid, de-duplicated addresses */
    public static function addresses(string $list): array
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $a) {
            $a = str_replace(["\r", "\n"], '', $a);

            if (MailHelper::isEmailAddress($a)) {
                $out[strtolower($a)] = $a;
            }
        }

        return array_values($out);
    }
}
