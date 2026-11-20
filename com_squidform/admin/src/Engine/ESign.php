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
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use SquidForm\Component\SquidForm\Administrator\Engine\Pdf\Pdf;
use SquidForm\Component\SquidForm\Administrator\Engine\Pdf\PdfDocument;

\defined('_JEXEC') or die;

/**
 * E-signatures (0.11.0): "good enough for ordinary business and internal
 * forms" (ESIGN and UETA in the US). Not PIV/CAC or agency PKI signing, and
 * not a substitute for counsel's review. See the disclaimer.
 *
 * A signature field becomes an e-signature with an "esign" block:
 *
 *   "esign": {
 *     "statement":    "I agree to sign this electronically.",   (shown with a tick box; stored exactly)
 *     "disclosure":   "<p>…</p>",    optional consumer disclosure (paper copies, withdrawing, records) with its own tick box
 *     "after_edit":   "lock" | "void",   a signed submission can't be edited, or an edit voids the signature (default lock)
 *     "retain_years": 7,             signed submissions can't be permanently deleted before then (0 = no hold)
 *     "send_copy":    true           email the signer a PDF of the signed record and its audit trail
 *   }
 *
 * Only signed-in people can sign; how they signed in is recorded. Only form
 * editors in a group given "Use e-signatures" can add or change one.
 *
 * What's kept, per signing: exactly what was signed (the answers as stored,
 * the form version, the statement and disclosure shown, the signer, the
 * server time, the sign-in method, IP address and browser), its SHA-256
 * fingerprint, and a seal (an HMAC of the fingerprint with the site's
 * secret), so a changed record or changed answers show as such. Every step
 * (opened, consented, signed, copy sent, voided, signed again) goes in an
 * append-only trail. No outside timestamp service is used: SquidForm stays
 * offline, so the time is the server's.
 */
final class ESign
{
    public const DEFAULTS = ['statement' => '', 'disclosure' => '', 'after_edit' => 'lock', 'retain_years' => 7, 'send_copy' => true];

    /** @return list<array<string, mixed>> the e-signature fields of a form */
    public static function fields(Schema $schema): array
    {
        return array_values(array_filter($schema->valueFields(), static fn ($f) => self::is($f)));
    }

    /** @param array<string, mixed> $field */
    public static function is(array $field): bool
    {
        return ($field['type'] ?? '') === 'signature' && \is_array($field['esign'] ?? null);
    }

    /**
     * @param  array<string, mixed> $field
     *
     * @return array{statement: string, disclosure: string, after_edit: string, retain_years: int, send_copy: bool}
     */
    public static function config(array $field): array
    {
        $c = (array) ($field['esign'] ?? []) + self::DEFAULTS;

        return [
            'statement'    => trim((string) $c['statement']) !== '' ? trim((string) $c['statement']) : Text::_('COM_SQUIDFORM_ESIGN_STATEMENT_DEFAULT'),
            'disclosure'   => (string) $c['disclosure'],
            'after_edit'   => $c['after_edit'] === 'void' ? 'void' : 'lock',
            'retain_years' => max(0, min(100, (int) $c['retain_years'])),
            'send_copy'    => (bool) $c['send_copy'],
        ];
    }

    /** The disclaimer shown where an e-signature is turned on, and in the docs. */
    public static function disclaimer(): string
    {
        return Text::_('COM_SQUIDFORM_ESIGN_DISCLAIMER');
    }

    // ── The trail ──────────────────────────────────────────────────────────

    public static function event(string $run, int $formId, string $fieldKey, int $userId, string $event, string $detail = ''): void
    {
        try {
            $row = (object) [
                'run'        => substr($run, 0, 36),
                'form_id'    => $formId,
                'field_key'  => substr($fieldKey, 0, 64),
                'user_id'    => $userId,
                'event'      => substr($event, 0, 20),
                'detail'     => mb_substr($detail, 0, 255),
                'ip'         => substr(SubmissionStore::clientIp(), 0, 45),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'created'    => Factory::getDate()->toSql(),
            ];
            SubmissionStore::db()->insertObject('#__squidform_esign_events', $row);
        } catch (\Throwable $e) {
            Log::add('SquidForm e-signature trail: ' . $e->getMessage(), Log::ERROR, 'squidform');
        }
    }

    /** A page with an e-signature was shown to a signed-in person (once per pass and field). */
    public static function opened(Schema $schema, int $formId, string $run, int $page, User $user): void
    {
        if ((int) $user->id <= 0) {
            return;
        }

        $session = Factory::getApplication()->getSession();

        foreach ($schema->pageFields($page) as $f) {
            if (self::is($f) && !$session->get('squidform.esign.opened.' . $run . '.' . $f['key'])) {
                $session->set('squidform.esign.opened.' . $run . '.' . $f['key'], 1);
                self::event($run, $formId, (string) $f['key'], (int) $user->id, 'opened');
            }
        }
    }

    /** @return list<object> the trail for one pass (oldest first), with the person's name */
    public static function trail(string $run): array
    {
        $db = SubmissionStore::db();

        return $db->setQuery(
            $db->createQuery()->select(['e.*', $db->quoteName('u.name', 'user_name')])
                ->from($db->quoteName('#__squidform_esign_events', 'e'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = e.user_id')
                ->where('e.run = :run')->order('e.id')->bind(':run', $run)
        )->loadObjectList();
    }

    // ── Signing ────────────────────────────────────────────────────────────

    /** How the signer signed in: "mfa" when they passed multi-factor this session, else "password". */
    public static function method(User $user): string
    {
        try {
            $db  = SubmissionStore::db();
            $uid = (int) $user->id;
            $has = (int) $db->setQuery(
                $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__user_mfa'))
                    ->where('user_id = :u')->where($db->quoteName('method') . ' <> ' . $db->quote('backupcodes'))
                    ->bind(':u', $uid, ParameterType::INTEGER)
            )->loadResult();

            if ($has > 0 && (int) Factory::getApplication()->getSession()->get('com_users.mfa_checked', 0) === 1) {
                return 'mfa';
            }
        } catch (\Throwable) {
            // no MFA table: password
        }

        return 'password';
    }

    /**
     * Record the e-signatures of a pass that has just been saved. Runs inside
     * the sending transaction, after the actions (so the submission exists).
     * The signed copies are emailed after commit.
     */
    public static function record(Context $ctx): void
    {
        $fields = self::fields($ctx->schema);

        if (!$fields || $ctx->submissionId === null) {
            return;
        }

        $user    = $ctx->user;
        $formId  = (int) $ctx->form['id'];
        $stored  = FormData::forForm($formId)->read((int) $ctx->submissionId, $ctx->schema);
        $copies  = [];

        foreach ($fields as $f) {
            $v = $stored[$f['key']] ?? null;

            if (!\is_array($v) || empty($v['intent'])) {
                continue; // not signed (a skipped page, or an optional field left empty)
            }

            $cfg = self::config($f);

            if ($cfg['disclosure'] !== '') {
                self::event($ctx->runUid, $formId, (string) $f['key'], (int) $user->id, 'consented', 'sha256:' . hash('sha256', $cfg['disclosure']));
            }

            self::write($ctx->submissionId, $formId, $ctx->runUid, $ctx->versionId, $f, $cfg, $stored, $user, 'signed');

            if ($cfg['send_copy']) {
                $copies[] = $f['key'];
            }
        }

        if ($copies) {
            $sid = (int) $ctx->submissionId;
            $ctx->afterCommit[] = static fn () => self::sendCopy($sid, (int) $user->id);
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $answers  as stored
     */
    private static function write(int $submissionId, int $formId, string $run, int $versionId, array $field, array $cfg, array $answers, User $user, string $event): void
    {
        $now    = Factory::getDate();
        $method = self::method($user);
        $record = [
            'kind'       => 'squidform-esign/1',
            'site'       => (string) Factory::getApplication()->get('sitename'),
            'form_id'    => $formId,
            'version_id' => $versionId,
            'submission' => $run,
            'field'      => (string) $field['key'],
            'field_label' => (string) ($field['label'] ?? $field['key']),
            'statement'  => $cfg['statement'],
            'disclosure' => $cfg['disclosure'],
            'signer'     => ['id' => (int) $user->id, 'username' => (string) $user->username, 'name' => (string) $user->name, 'email' => (string) $user->email],
            'signed_at'  => $now->format('Y-m-d\TH:i:s\Z'),
            'method'     => $method,
            'ip'         => SubmissionStore::clientIp(),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'answers'    => $answers,
        ];
        $json   = self::canonical($record);
        $sha    = hash('sha256', $json);

        $row = (object) [
            'submission_id' => $submissionId,
            'form_id'       => $formId,
            'run'           => substr($run, 0, 36),
            'field_key'     => (string) $field['key'],
            'user_id'       => (int) $user->id,
            'signed_at'     => $now->toSql(),
            'method'        => $method,
            'record_json'   => $json,
            'record_sha256' => $sha,
            'seal'          => self::seal($sha),
            'status'        => 'valid',
            'retain_until'  => $cfg['retain_years'] > 0 ? Factory::getDate('+' . $cfg['retain_years'] . ' years')->format('Y-m-d') : null,
        ];
        SubmissionStore::db()->insertObject('#__squidform_signatures', $row);

        self::event($run, $formId, (string) $field['key'], (int) $user->id, $event, 'sha256:' . $sha);
    }

    /** JSON with keys sorted at every level, so the same record always gives the same fingerprint. */
    public static function canonical(mixed $v): string
    {
        $sort = static function (mixed $x) use (&$sort): mixed {
            if (!\is_array($x)) {
                return $x;
            }

            if (!array_is_list($x)) {
                ksort($x, SORT_STRING);
            }

            return array_map($sort, $x);
        };

        return (string) json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function seal(string $sha256): string
    {
        $secret = (string) Factory::getApplication()->get('secret');

        return hash_hmac('sha256', $sha256, hash_hmac('sha256', 'squidform-esign-seal', $secret));
    }

    // ── Reading and checking ───────────────────────────────────────────────

    /** @return list<object> signatures of a submission, newest first */
    public static function forSubmission(int $submissionId): array
    {
        if ($submissionId <= 0) {
            return [];
        }

        try {
            $db = SubmissionStore::db();

            return $db->setQuery(
                $db->createQuery()->select(['s.*', $db->quoteName('u.name', 'user_name')])
                    ->from($db->quoteName('#__squidform_signatures', 's'))
                    ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = s.user_id')
                    ->where('s.submission_id = :id')->order('s.id DESC')->bind(':id', $submissionId, ParameterType::INTEGER)
            )->loadObjectList();
        } catch (\Throwable) {
            return []; // before the 0.11.0 tables exist
        }
    }

    /**
     * Is the signature still good?
     *  "intact"  the record, its seal and the answers all match
     *  "changed" the answers were changed after signing
     *  "broken"  the stored record or its seal doesn't match (altered outside SquidForm)
     *  "void"    voided by an edit
     */
    public static function verify(object $sig, ?object $item = null): string
    {
        if ((string) $sig->status === 'void') {
            return 'void';
        }

        if (!hash_equals((string) $sig->record_sha256, hash('sha256', (string) $sig->record_json)) || !hash_equals((string) $sig->seal, self::seal((string) $sig->record_sha256))) {
            return 'broken';
        }

        if ($item !== null) {
            $rec = json_decode((string) $sig->record_json, true);

            if (!\is_array($rec) || self::canonical($rec['answers'] ?? null) !== self::canonical((array) $item->data)) {
                return 'changed';
            }
        }

        return 'intact';
    }

    /** A signature that locks its submission against edits. */
    public static function locks(object $item): bool
    {
        if (!$item->schema instanceof Schema) {
            return false;
        }

        $lockKeys = array_map(static fn ($f) => $f['key'], array_filter(self::fields($item->schema), static fn ($f) => self::config($f)['after_edit'] === 'lock'));

        foreach (self::forSubmission((int) $item->id) as $s) {
            if ((string) $s->status === 'valid' && \in_array((string) $s->field_key, $lockKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /** Void the valid signatures of a submission (an edit changed what was signed). */
    public static function voidFor(object $item, User $user, string $reason): void
    {
        $db  = SubmissionStore::db();
        $id  = (int) $item->id;
        $now = Factory::getDate()->toSql();
        $uid = (int) $user->id;

        foreach (self::forSubmission($id) as $s) {
            if ((string) $s->status !== 'valid') {
                continue;
            }

            $sid = (int) $s->id;
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_signatures'))
                    ->set($db->quoteName('status') . ' = ' . $db->quote('void'))->set('voided_at = :now')->set('voided_by = :uid')->set('void_reason = :why')
                    ->where('id = :id')->bind(':now', $now)->bind(':uid', $uid, ParameterType::INTEGER)->bind(':why', $reason)->bind(':id', $sid, ParameterType::INTEGER)
            )->execute();
            self::event((string) $s->run, (int) $s->form_id, (string) $s->field_key, $uid, 'voided', $reason);
        }
    }

    /** Is a permanent delete held back by a signed record's retention period? */
    public static function retained(int $submissionId): ?string
    {
        $today = Factory::getDate()->format('Y-m-d');

        foreach (self::forSubmission($submissionId) as $s) {
            if (!empty($s->retain_until) && (string) $s->retain_until > $today) {
                return (string) $s->retain_until;
            }
        }

        return null;
    }

    /** Ids among these submissions whose signed records are still within their retention period. */
    public static function retainedIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        try {
            $db    = SubmissionStore::db();
            $today = Factory::getDate()->format('Y-m-d');

            return array_map('intval', $db->setQuery(
                $db->createQuery()->select('DISTINCT submission_id')->from($db->quoteName('#__squidform_signatures'))
                    ->whereIn($db->quoteName('submission_id'), $ids)->where('retain_until > :t')->bind(':t', $today)
            )->loadColumn());
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Signing again (void mode) ──────────────────────────────────────────

    /** Can this person sign this submission's voided e-signature again? Only its signer. */
    public static function canResign(object $item, ?User $user = null): ?array
    {
        $user ??= Factory::getApplication()->getIdentity();

        if (!$item->schema instanceof Schema || (int) ($user->id ?? 0) <= 0 || !empty($item->archived)) {
            return null;
        }

        $valid = [];
        $void  = [];

        foreach (self::forSubmission((int) $item->id) as $s) {
            if ((string) $s->status === 'valid') {
                $valid[(string) $s->field_key] = true;
            } elseif ((int) $s->user_id === (int) $user->id) {
                $void[(string) $s->field_key] ??= $s;
            }
        }

        foreach (self::fields($item->schema) as $f) {
            if (isset($void[$f['key']]) && !isset($valid[$f['key']])) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Sign again after an edit voided the signature: a new drawing or name and
     * the statement ticked again. Saves the new signature into the
     * submission (with an edit history line) and records a new signing.
     *
     * @param array<string, mixed> $input the posted sf[...] array
     *
     * @return string|null an error message, or null when signed
     */
    public static function resign(object $item, array $input, User $user): ?string
    {
        $field = self::canResign($item, $user);

        if ($field === null) {
            return Text::_('JERROR_ALERTNOAUTHOR');
        }

        $type  = FieldRegistry::get('signature');
        $value = $type->normalize($field, $input[$field['key']] ?? null);

        if (!\is_array($value) || empty($value['intent']) || $value === []) {
            return Text::_('COM_SQUIDFORM_ESIGN_ERR_RESIGN');
        }

        $cfg = self::config($field);

        if ($cfg['disclosure'] !== '' && empty($value['consent'])) {
            return Text::_('COM_SQUIDFORM_ESIGN_ERR_CONSENT');
        }

        $db   = SubmissionStore::db();
        $id   = (int) $item->id;
        $fd   = FormData::forForm((int) $item->form_id);
        $data = (array) $item->data;
        $old  = $data[$field['key']] ?? null;
        $data[$field['key']] = $value;
        $now  = Factory::getDate()->toSql();

        try {
            $db->transactionStart();
            $fd->write($id, $item->schema, $data);
            $fd->historyAdd($id, (int) $user->id, 'edit', [$field['key'] => ['old' => $old, 'new' => $value]], $now);
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_submissions'))->set('modified = :now')->set('modified_by = :u')
                    ->where('id = :id')->bind(':now', $now)->bind(':u', $user->id, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER)
            )->execute();

            if ($cfg['disclosure'] !== '') {
                self::event((string) $item->uid, (int) $item->form_id, (string) $field['key'], (int) $user->id, 'consented', 'sha256:' . hash('sha256', $cfg['disclosure']));
            }

            self::write($id, (int) $item->form_id, (string) $item->uid, (int) $item->version_id, $field, $cfg, $fd->read($id, $item->schema), $user, 'signed_again');
            $db->transactionCommit();
        } catch (\Throwable $e) {
            try {
                $db->transactionRollback();
            } catch (\Throwable) {
            }

            Log::add('SquidForm signing again (submission ' . $id . ') failed: ' . $e->getMessage(), Log::ERROR, 'squidform');

            return Text::_('COM_SQUIDFORM_ERR_SAVE');
        }

        if ($cfg['send_copy']) {
            self::sendCopy($id, (int) $user->id);
        }

        return null;
    }

    // ── The signed copy ────────────────────────────────────────────────────

    /** Email the signer a PDF of the signed submission with its signature record and trail. */
    public static function sendCopy(int $submissionId, int $userId): void
    {
        try {
            $item   = SubmissionService::load($submissionId);
            $signer = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId);

            if (!$item || !$signer->id || !\Joomla\CMS\Mail\MailHelper::isEmailAddress((string) $signer->email)) {
                return;
            }

            $app    = Factory::getApplication();
            $pdf    = Pdf::bytes(Pdf::submission($item, 'sender'));
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->setSender((string) $app->get('mailfrom'), (string) $app->get('fromname'));
            $mailer->addRecipient((string) $signer->email, (string) $signer->name);
            $mailer->setSubject(Text::sprintf('COM_SQUIDFORM_ESIGN_COPY_SUBJECT', (string) $item->form_title, Ref::short((string) $item->uid)));
            $mailer->setBody(Text::sprintf('COM_SQUIDFORM_ESIGN_COPY_BODY', (string) $signer->name, (string) $item->form_title, (string) $app->get('sitename')));
            $mailer->addStringAttachment($pdf, Pdf::fileName($item, 'signed'), 'base64', 'application/pdf');
            $mailer->send();
            self::event((string) $item->uid, (int) $item->form_id, '', $userId, 'copy_sent', (string) $signer->email);
        } catch (\Throwable $e) {
            Log::add('SquidForm signed copy for submission ' . $submissionId . ' failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
        }
    }

    /** The signature record and trail, as a section of a submission's PDF. */
    public static function addToPdf(PdfDocument $doc, object $sig, object $item): void
    {
        $rec    = json_decode((string) $sig->record_json, true) ?: [];
        $state  = self::verify($sig, $item);
        $date   = static fn (string $sql) => HTMLHelper::_('date', $sql, Text::_('DATE_FORMAT_LC2')) . ' (' . Factory::getDate($sql)->format('Y-m-d H:i:s') . ' UTC)';

        $doc->heading(Text::sprintf('COM_SQUIDFORM_ESIGN_PDF_HEADING', (string) ($rec['field_label'] ?? $sig->field_key)));
        $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_STATUS'), Text::_('COM_SQUIDFORM_ESIGN_STATE_' . strtoupper($state)));
        $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_SIGNER'), trim((string) ($rec['signer']['name'] ?? '') . ' (' . (string) ($rec['signer']['username'] ?? '') . ', ' . (string) ($rec['signer']['email'] ?? '') . ')'));
        $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_SIGNED_AT'), $date((string) $sig->signed_at));
        $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_METHOD'), Text::_('COM_SQUIDFORM_ESIGN_METHOD_' . strtoupper((string) $sig->method)));
        $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_STATEMENT'), (string) ($rec['statement'] ?? ''));

        if (($rec['disclosure'] ?? '') !== '') {
            $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_DISCLOSURE'), Display::plain((string) $rec['disclosure']));
        }

        $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_FINGERPRINT'), (string) $sig->record_sha256);

        if ((string) $sig->status === 'void') {
            $doc->pair(Text::_('COM_SQUIDFORM_ESIGN_VOIDED'), $date((string) $sig->voided_at) . ($sig->void_reason !== '' ? ' — ' . $sig->void_reason : ''));
        }

        $rows = [];

        foreach (self::trail((string) $sig->run) as $e) {
            if ($e->field_key !== '' && $e->field_key !== $sig->field_key) {
                continue;
            }

            $rows[] = [
                Factory::getDate((string) $e->created)->format('Y-m-d H:i:s') . ' UTC',
                Text::_('COM_SQUIDFORM_ESIGN_EVENT_' . strtoupper((string) $e->event)),
                (string) ($e->user_name ?? ''),
                mb_strlen((string) $e->user_agent) > 60 ? mb_substr((string) $e->user_agent, 0, 59) . '…' : (string) $e->user_agent,
            ];
        }

        if ($rows) {
            $doc->table(Text::_('COM_SQUIDFORM_ESIGN_TRAIL'), [Text::_('COM_SQUIDFORM_ESIGN_COL_TIME'), Text::_('COM_SQUIDFORM_ESIGN_COL_EVENT'), Text::_('COM_SQUIDFORM_ESIGN_COL_WHO'), Text::_('COM_SQUIDFORM_ESIGN_COL_BROWSER')], $rows);
        }

        $doc->text(Text::_('COM_SQUIDFORM_ESIGN_PDF_NOTE'), true);
    }

    // ── Permission ─────────────────────────────────────────────────────────

    /**
     * A fingerprint of a definition's e-signature settings: adding, removing
     * or changing one needs "Use e-signatures".
     *
     * @param array<string, mixed>|null $def
     */
    public static function settingsPrint(?array $def): string
    {
        if (!$def) {
            return '';
        }

        $found = [];
        $walk  = static function (array $fields) use (&$walk, &$found): void {
            foreach ($fields as $f) {
                if (!\is_array($f)) {
                    continue;
                }

                if (($f['type'] ?? '') === 'signature' && isset($f['esign'])) {
                    $found[(string) ($f['key'] ?? '')] = $f['esign'];
                }

                if (\is_array($f['fields'] ?? null)) {
                    $walk($f['fields']);
                }
            }
        };

        foreach ((array) ($def['pages'] ?? []) as $p) {
            $walk((array) ($p['fields'] ?? []));
        }

        ksort($found);

        return $found ? hash('sha256', self::canonical($found)) : '';
    }

    /** May this person add, change or remove e-signatures on this form (0 = a new form)? */
    public static function mayChange(int $formId, ?User $user = null): bool
    {
        $user ??= Factory::getApplication()->getIdentity();

        return $formId > 0 ? Acl::can('squidform.esign', $formId, $user) : (bool) $user->authorise('squidform.esign', 'com_squidform');
    }

}
