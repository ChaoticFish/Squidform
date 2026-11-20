<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Save and finish later (0.11.0).
 *
 * "settings": {"save_later": true, "save_later_guests": false}
 *
 * - Signed-in people: "Save and finish later" keeps what they've typed (any
 *   page, nothing checked yet) under their account. Opening the form again
 *   fills it back in, with "Start over" to throw it away. One draft per
 *   person and form.
 * - Guests, only where the form allows it: they're asked for an email
 *   address and sent a link. Only the link's SHA-256 is stored. The link
 *   works until the draft expires (not just once: work email systems open
 *   links to scan them, which would use up a one-time link); saving again
 *   sends a new link and the old one stops working.
 * - Drafts are deleted after Options → Forms → "Keep saved drafts for" days
 *   (30 by default), when the form is sent, or on Start over. Their uploaded
 *   files go with them.
 * - A draft of an older version of the form keeps the answers to fields
 *   that still exist and starts again from the first page.
 */
final class Resume
{
    public static function enabled(Schema $schema, User $user): bool
    {
        return (bool) $schema->setting('save_later', false)
            && ((int) $user->id > 0 || (bool) $schema->setting('save_later_guests', false));
    }

    public static function days(): int
    {
        return max(1, min(365, (int) ComponentHelper::getParams('com_squidform')->get('resume_days', 30)));
    }

    private static function db(): \Joomla\Database\DatabaseInterface
    {
        return SubmissionStore::db();
    }

    /** The owner value that keeps a draft's uploaded files from being swept. */
    public static function fileOwner(int $id): string
    {
        return 'resume:' . $id;
    }

    public static function forUser(int $formId, int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        $db  = self::db();
        $now = Factory::getDate()->toSql();

        return $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__squidform_resume'))
                ->where('form_id = :f')->where('user_id = :u')->where('expires > :now')->order('id DESC')
                ->bind(':f', $formId, ParameterType::INTEGER)->bind(':u', $userId, ParameterType::INTEGER)->bind(':now', $now),
            0,
            1
        )->loadObject() ?: null;
    }

    /**
     * Save a signed-in person's draft (replacing an earlier one).
     *
     * @param array<string, mixed> $state
     *
     * @return string when it expires (SQL date)
     */
    public static function saveForUser(array $form, int $versionId, User $user, array $state, string $returnUrl): string
    {
        $old = self::forUser((int) $form['id'], (int) $user->id);
        $row = self::row($form, $versionId, $state, $returnUrl) + ['user_id' => (int) $user->id];

        if ($old) {
            $row['id']      = (int) $old->id;
            $row['created'] = $old->created;
            self::db()->updateObject('#__squidform_resume', (object) $row, 'id');
            $id = (int) $old->id;
        } else {
            $obj = (object) $row;
            self::db()->insertObject('#__squidform_resume', $obj, 'id');
            $id = (int) $obj->id;
        }

        self::keepFiles($id, $state);

        return $row['expires'];
    }

    /**
     * Save a guest's draft and email them a one-time link.
     *
     * @param array<string, mixed> $state
     *
     * @return string|null an error message, or null when sent
     */
    public static function saveForGuest(array $form, int $versionId, string $email, array $state, string $returnUrl, ?int $previous = null): ?string
    {
        $email = trim(str_replace(["\r", "\n"], '', $email));

        if (!MailHelper::isEmailAddress($email)) {
            return Text::_('COM_SQUIDFORM_RESUME_ERR_EMAIL');
        }

        // Saving again replaces the draft the guest came back with (its link stops working).
        if ($previous) {
            $db  = self::db();
            $old = $db->setQuery($db->createQuery()->select('id')->from($db->quoteName('#__squidform_resume'))->where('id = ' . (int) $previous)->where('user_id = 0'))->loadResult();

            if ($old) {
                // Files still in use move to the new draft first (keepFiles below), so only dropped ones go.
                $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_resume'))->where('id = ' . (int) $old))->execute();
                $prevOwner = self::fileOwner((int) $old);
            }
        }

        $token = bin2hex(random_bytes(32));
        $row   = (object) (self::row($form, $versionId, $state, $returnUrl) + ['user_id' => 0, 'token_hash' => hash('sha256', $token), 'email' => $email]);
        self::db()->insertObject('#__squidform_resume', $row, 'id');
        self::keepFiles((int) $row->id, $state);

        if (isset($prevOwner)) {
            $db    = self::db();
            $stale = $db->setQuery($db->createQuery()->select('uid')->from($db->quoteName('#__squidform_submission_files'))
                ->where('submission_id = 0')->where($db->quoteName('owner') . ' = :o')->bind(':o', $prevOwner))->loadColumn();
            $stale && Uploads::removeOwned($stale, $prevOwner);
        }

        if (!self::mailLink((string) $form['title'], $email, $token, (string) $row->expires, false)) {
            self::delete((int) $row->id);

            return Text::_('COM_SQUIDFORM_RESUME_ERR_MAIL');
        }

        return null;
    }

    /** Email a guest their link (or a new one, sent by the site's staff). */
    private static function mailLink(string $title, string $email, string $token, string $expires, bool $resent): bool
    {
        $app  = Factory::getApplication();
        $link = Route::link('site', 'index.php?option=com_squidform&task=form.resume&t=' . $token, false, Route::TLS_IGNORE, true);

        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->setSender((string) $app->get('mailfrom'), (string) $app->get('fromname'));
            $mailer->addRecipient($email);
            $mailer->setSubject(Text::sprintf('COM_SQUIDFORM_RESUME_MAIL_SUBJECT', $title));
            $mailer->setBody(($resent ? Text::_('COM_SQUIDFORM_RESUME_MAIL_RESENT') . "\n\n" : '')
                . Text::sprintf('COM_SQUIDFORM_RESUME_MAIL_BODY', $title, $link, HTMLHelper::_('date', $expires, Text::_('DATE_FORMAT_LC2')), (string) $app->get('sitename')));
            $mailer->isHtml(false);
            $mailer->send();

            return true;
        } catch (\Throwable $e) {
            Log::add('SquidForm resume link for "' . $title . '" failed: ' . $e->getMessage(), Log::ERROR, 'squidform');

            return false;
        }
    }

    // ── For staff: Saved for later (0.11.1) ────────────────────────────────

    /**
     * Saved drafts of these forms, newest first, without their answers.
     *
     * @param int[] $formIds
     *
     * @return list<object> id, form_id, form_title, user_id, user_name, user_email, email, page, pages, modified, expires
     */
    public static function listFor(array $formIds, int $formId = 0, string $search = ''): array
    {
        $formIds = array_values(array_filter(array_map('intval', $formIds)));

        if ($formId > 0) {
            $formIds = \in_array($formId, $formIds, true) ? [$formId] : [];
        }

        if (!$formIds) {
            return [];
        }

        $db  = self::db();
        $now = Factory::getDate()->toSql();
        $q   = $db->createQuery()
            ->select(['r.id', 'r.form_id', 'r.version_id', 'r.user_id', 'r.email', 'r.page', 'r.created', 'r.modified', 'r.expires',
                $db->quoteName('f.title', 'form_title'), $db->quoteName('u.name', 'user_name'), $db->quoteName('u.email', 'user_email')])
            ->from($db->quoteName('#__squidform_resume', 'r'))
            ->join('INNER', $db->quoteName('#__squidform_forms', 'f'), 'f.id = r.form_id')
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = r.user_id')
            ->whereIn('r.form_id', $formIds)->where('r.expires > :now')->bind(':now', $now)
            ->order('r.modified DESC');

        if (($search = trim($search)) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $q->where('(r.email LIKE :s1 OR u.name LIKE :s2 OR u.email LIKE :s3 OR u.username LIKE :s4)')
                ->bind(':s1', $like)->bind(':s2', $like)->bind(':s3', $like)->bind(':s4', $like);
        }

        $rows  = $db->setQuery($q, 0, 500)->loadObjectList();
        $pages = [];

        foreach ($rows as $r) {
            $pages[(int) $r->version_id] ??= FormRepository::version((int) $r->version_id)?->pageCount() ?? 0;
            $r->pages = $pages[(int) $r->version_id];
        }

        return $rows;
    }

    public static function byId(int $id): ?object
    {
        $db = self::db();

        return $db->setQuery(
            $db->createQuery()->select(['r.*', $db->quoteName('f.title', 'form_title'), $db->quoteName('u.name', 'user_name'), $db->quoteName('u.email', 'user_email')])
                ->from($db->quoteName('#__squidform_resume', 'r'))
                ->join('INNER', $db->quoteName('#__squidform_forms', 'f'), 'f.id = r.form_id')
                ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = r.user_id')
                ->where('r.id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject() ?: null;
    }

    /**
     * Send the person their way back in again. A guest gets a new link (the
     * old one stops working: only a link's SHA-256 is kept); a signed-in
     * person gets the form's address. Either way the draft's time starts again.
     *
     * @return string|null an error, or null when sent
     */
    public static function resend(object $row, User $by): ?string
    {
        $db      = self::db();
        $id      = (int) $row->id;
        $expires = Factory::getDate('+' . self::days() . ' days')->toSql();
        $title   = (string) $row->form_title;

        if ((int) $row->user_id > 0) {
            $to = (string) ($row->user_email ?? '');

            if (!MailHelper::isEmailAddress($to)) {
                return Text::_('COM_SQUIDFORM_RESUME_ERR_NO_ADDRESS');
            }

            $app = Factory::getApplication();
            $url = Url::isInternal((string) $row->return_url) && $row->return_url !== '' ? (string) $row->return_url : \Joomla\CMS\Uri\Uri::root();

            try {
                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->setSender((string) $app->get('mailfrom'), (string) $app->get('fromname'));
                $mailer->addRecipient($to);
                $mailer->setSubject(Text::sprintf('COM_SQUIDFORM_RESUME_MAIL_SUBJECT', $title));
                $mailer->setBody(Text::sprintf('COM_SQUIDFORM_RESUME_MAIL_USER', (string) $row->user_name, $title, $url, HTMLHelper::_('date', $expires, Text::_('DATE_FORMAT_LC2')), (string) $app->get('sitename')));
                $mailer->isHtml(false);
                $mailer->send();
            } catch (\Throwable $e) {
                Log::add('SquidForm resume reminder for "' . $title . '" failed: ' . $e->getMessage(), Log::ERROR, 'squidform');

                return Text::_('COM_SQUIDFORM_RESUME_ERR_MAIL');
            }

            $db->setQuery($db->createQuery()->update($db->quoteName('#__squidform_resume'))->set('expires = :e')->where('id = :id')
                ->bind(':e', $expires)->bind(':id', $id, ParameterType::INTEGER))->execute();
        } else {
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            $old   = (string) $row->token_hash;

            // The new link replaces the old one before it's sent; if sending fails, the old one comes back.
            $db->setQuery($db->createQuery()->update($db->quoteName('#__squidform_resume'))->set('token_hash = :h')->set('expires = :e')->where('id = :id')
                ->bind(':h', $hash)->bind(':e', $expires)->bind(':id', $id, ParameterType::INTEGER))->execute();

            if (!self::mailLink($title, (string) $row->email, $token, $expires, true)) {
                $was = (string) $row->expires;
                $db->setQuery($db->createQuery()->update($db->quoteName('#__squidform_resume'))->set('token_hash = :h')->set('expires = :e')->where('id = :id')
                    ->bind(':h', $old)->bind(':e', $was)->bind(':id', $id, ParameterType::INTEGER))->execute();

                return Text::_('COM_SQUIDFORM_RESUME_ERR_MAIL');
            }
        }

        Audit::log(null, (int) $row->form_id, (int) $by->id, 'resume_resend', ['draft' => $id, 'to' => (int) $row->user_id > 0 ? 'account ' . (int) $row->user_id : (string) $row->email]);

        return null;
    }

    /**
     * 0.14.0: a person's own saved forms, for "My submissions": newest first,
     * not expired, with the form's title and alias.
     *
     * @param int[] $formIds  only these forms ([] = all)
     *
     * @return list<object>
     */
    public static function mine(int $userId, array $formIds = []): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db  = self::db();
        $now = Factory::getDate()->toSql();
        $q   = $db->createQuery()
            ->select(['r.id', 'r.form_id', 'r.page', 'r.created', 'r.modified', 'r.expires', 'r.return_url', $db->quoteName('f.title', 'form_title'), $db->quoteName('f.alias', 'form_alias')])
            ->from($db->quoteName('#__squidform_resume', 'r'))
            ->join('INNER', $db->quoteName('#__squidform_forms', 'f'), 'f.id = r.form_id')
            ->where('r.user_id = :u')->where('r.expires > :now')->where('f.state = 1')
            ->bind(':u', $userId, ParameterType::INTEGER)->bind(':now', $now)
            ->order('r.modified DESC');

        if ($formIds) {
            $q->whereIn($db->quoteName('r.form_id'), array_map('intval', $formIds));
        }

        return $db->setQuery($q)->loadObjectList();
    }

    /** Where Continue goes: the page the form was saved from. */
    public static function continueUrl(object $row): string
    {
        $back = (string) $row->return_url;

        return ($back !== '' && Url::isInternal($back) ? $back : Route::_('index.php?option=com_squidform&view=form&form=' . rawurlencode((string) $row->form_alias), false)) . '#sf-' . $row->form_alias;
    }

    /** The person deletes their own saved form (My submissions). */
    public static function removeOwn(int $id, User $user): bool
    {
        $row = self::byId($id);

        if (!$row || (int) $user->id <= 0 || (int) $row->user_id !== (int) $user->id) {
            return false;
        }

        self::delete($id);
        Audit::log(null, (int) $row->form_id, (int) $user->id, 'resume_delete', ['draft' => $id, 'who' => 'account ' . (int) $user->id, 'own' => true]);

        return true;
    }

    /**
     * 0.14.0: a signed-in person opened a guest "finish later" link that was
     * emailed to them: move that saved form into their account. The link
     * proves they can read that inbox. It stops working (the hash is cleared),
     * the expiry date stays, and it's written to Activity. When they already
     * have a saved form for that form, $replace must be true: that one goes.
     *
     * @return string '' when moved, else 'bad' (no such link) or 'exists'
     */
    public static function claim(string $token, User $user, bool $replace): string
    {
        $row = self::byToken($token);

        if (!$row || (int) $user->id <= 0) {
            return 'bad';
        }

        $old = self::forUser((int) $row->form_id, (int) $user->id);

        if ($old && !$replace) {
            return 'exists';
        }

        if ($old) {
            self::delete((int) $old->id);
        }

        $db  = self::db();
        $uid = (int) $user->id;
        $id  = (int) $row->id;
        $db->setQuery($db->createQuery()->update($db->quoteName('#__squidform_resume'))
            ->set('user_id = :u')->set($db->quoteName('token_hash') . " = ''")->set($db->quoteName('email') . " = ''")
            ->where('id = :id')->where('user_id = 0')
            ->bind(':u', $uid, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER))->execute();
        Audit::log(null, (int) $row->form_id, $uid, 'resume_claim', ['draft' => $id, 'who' => 'account ' . $uid, 'replaced' => $old ? (int) $old->id : 0]);

        return '';
    }

    /** Staff delete a saved draft (and its uploaded files). */
    public static function remove(object $row, User $by): void
    {
        self::delete((int) $row->id);
        Audit::log(null, (int) $row->form_id, (int) $by->id, 'resume_delete', ['draft' => (int) $row->id, 'who' => (int) $row->user_id > 0 ? 'account ' . (int) $row->user_id : (string) $row->email]);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private static function row(array $form, int $versionId, array $state, string $returnUrl): array
    {
        $now = Factory::getDate();

        return [
            'form_id'    => (int) $form['id'],
            'version_id' => $versionId,
            'page'       => max(0, (int) ($state['page'] ?? 0)),
            'data_json'  => (string) json_encode([
                'data'  => (array) ($state['data'] ?? []),
                'seen'  => array_values(array_map('intval', (array) ($state['seen'] ?? []))),
                'files' => (array) ($state['files'] ?? []),
            ], JSON_UNESCAPED_UNICODE),
            'files_json' => (string) json_encode(self::uids($state)),
            'return_url' => substr(preg_replace('/#.*$/', '', $returnUrl) ?? '', 0, 2048),
            'created'    => $now->toSql(),
            'modified'   => $now->toSql(),
            'expires'    => Factory::getDate('+' . self::days() . ' days')->toSql(),
        ];
    }

    /** @return list<string> uploaded-file ids in the state */
    private static function uids(array $state): array
    {
        $out = [];

        foreach ((array) ($state['files'] ?? []) as $list) {
            foreach ((array) $list as $u) {
                if (\is_string($u) && preg_match('/^[0-9a-f]{32}$/', $u)) {
                    $out[] = $u;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** The draft's files belong to the draft now, so the daily sweep leaves them. */
    private static function keepFiles(int $id, array $state): void
    {
        $uids = self::uids($state);

        if (!$uids) {
            return;
        }

        $db    = self::db();
        $owner = self::fileOwner($id);
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__squidform_submission_files'))
                ->set($db->quoteName('owner') . ' = :o')->where('submission_id = 0')
                ->whereIn($db->quoteName('uid'), $uids, ParameterType::STRING)->bind(':o', $owner)
        )->execute();
    }

    /**
     * Put a draft back into a pass through the form.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed> the state, with "resumed" (when it was saved) set
     */
    public static function into(array $state, object $row, Schema $schema, int $versionId): array
    {
        $saved = json_decode((string) $row->data_json, true) ?: [];
        $data  = (array) ($saved['data'] ?? []);
        $same  = (int) $row->version_id === $versionId;

        // Answers to fields that no longer exist are dropped; a changed form starts at page 1.
        $keys = array_flip(array_map(static fn ($f) => (string) $f['key'], $schema->valueFields()));
        $data = array_intersect_key($data, $keys);

        $state['data']    = $data;
        $state['files']   = array_intersect_key((array) ($saved['files'] ?? []), $keys);
        $state['page']    = $same ? min((int) $row->page, $schema->pageCount() - 1) : 0;
        $state['seen']    = $same ? (array) ($saved['seen'] ?? []) : [];
        $state['review']  = false;
        $state['resumed'] = ['at' => (string) $row->modified, 'changed' => !$same, 'id' => (int) $row->id, 'guest' => (int) $row->user_id === 0];

        return $state;
    }

    /** A guest's link: the draft, if the link is real and not expired. */
    public static function byToken(string $token): ?object
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $db   = self::db();
        $hash = hash('sha256', $token);
        $now  = Factory::getDate()->toSql();

        return $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__squidform_resume'))
                ->where('token_hash = :h')->where('user_id = 0')->where('expires > :now')
                ->bind(':h', $hash)->bind(':now', $now),
            0,
            1
        )->loadObject() ?: null;
    }

    /** The form was sent: the person's draft is no longer needed (its files now belong to the submission). */
    public static function finished(int $formId, User $user, ?int $draftId): void
    {
        $db = self::db();

        try {
            if ((int) $user->id > 0) {
                $uid = (int) $user->id;
                $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_resume'))
                    ->where('form_id = :f')->where('user_id = :u')->bind(':f', $formId, ParameterType::INTEGER)->bind(':u', $uid, ParameterType::INTEGER))->execute();
            } elseif ($draftId) {
                $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_resume'))->where('id = ' . (int) $draftId))->execute();
            }
        } catch (\Throwable $e) {
            Log::add('SquidForm: removing a saved draft failed: ' . $e->getMessage(), Log::WARNING, 'squidform');
        }
    }

    /** Start over: the signed-in person's draft and its files go. */
    public static function discard(int $formId, User $user): void
    {
        if ($row = self::forUser($formId, (int) $user->id)) {
            self::delete((int) $row->id);
        }
    }

    private static function delete(int $id): void
    {
        $db    = self::db();
        $owner = self::fileOwner($id);
        $files = $db->setQuery(
            $db->createQuery()->select('uid')->from($db->quoteName('#__squidform_submission_files'))
                ->where('submission_id = 0')->where($db->quoteName('owner') . ' = :o')->bind(':o', $owner)
        )->loadColumn();

        if ($files) {
            Uploads::removeOwned($files, $owner);
        }

        $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_resume'))->where('id = ' . $id))->execute();
    }

    /** Expired drafts and their files (a few at a time, on form pages). */
    public static function sweep(): void
    {
        try {
            $db   = self::db();
            $now  = Factory::getDate()->toSql();
            $rows = $db->setQuery(
                $db->createQuery()->select('id')->from($db->quoteName('#__squidform_resume'))->where('expires <= :now')->bind(':now', $now),
                0,
                50
            )->loadColumn();

            foreach ($rows as $id) {
                self::delete((int) $id);
            }
        } catch (\Throwable) {
            // before the 0.11.0 table exists
        }
    }
}
