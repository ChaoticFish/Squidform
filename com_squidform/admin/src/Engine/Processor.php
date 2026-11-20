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
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\ValidateContext;

\defined('_JEXEC') or die;

/**
 * Handles a page submit:
 *
 *  0. Rate limit: too many page posts recently → refused (RateLimit)
 *  1. CSRF token (controller) and invisible human checks (FormGuard)
 *  2. Back button: keep what was typed, go to the previous page
 *  3. Normalise this page's fields; re-apply locked prefills
 *  4. Remove values of fields hidden by show_if (server decides, not the browser)
 *     and work out calculations (0.10.0)
 *  5. Validate visible fields; on errors, return to the page with messages
 *  6. Run the page's submit actions inside one database transaction
 *  7. Save the next session state: the next page shown (pages may be
 *     skipped, 0.10.0), the review page, a message or a redirect
 *  8. After commit and state save: send email and other deferred work
 *
 * Sending (the last page, or the review page) checks every page shown
 * again, then the captcha, the rate limit and the limits (under a lock).
 * The form must be open (dates and limits) for any page to be posted.
 *
 * Returns the URL to redirect the browser to.
 */
final class Processor
{
    /**
     * @param   array<string, mixed>  $post  Raw POST data.
     */
    public static function submit(array $post): string
    {
        $alias = (string) preg_replace('/[^a-z0-9\-_]/i', '', (string) ($post['sf_form'] ?? ''));
        $back  = self::returnUrl((string) ($post['sf_return'] ?? ''), $alias);

        try {
            return self::doSubmit($post, $alias, $back);
        } catch (\Throwable $e) {
            // Never show internals to visitors: log the detail, show a generic error.
            Log::add('SquidForm form "' . $alias . '" submit error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), Log::ERROR, 'squidform');

            try {
                $state           = State::get($alias);
                $state['errors'] = ['_form' => Text::_('COM_SQUIDFORM_ERR_SAVE')];
                State::put($alias, $state);
            } catch (\Throwable) {
                // nothing more we can do
            }

            return $back;
        }
    }

    /**
     * The CSRF token failed (often an expired session). Return to the form
     * with a message instead of dropping the person on the home page.
     *
     * @param   array<string, mixed>  $post
     */
    public static function tokenFailed(array $post): string
    {
        $alias = (string) preg_replace('/[^a-z0-9\-_]/i', '', (string) ($post['sf_form'] ?? ''));
        $back  = self::returnUrl((string) ($post['sf_return'] ?? ''), $alias);

        if ($alias !== '') {
            Renderer::loadLanguage();
            $state           = State::get($alias);
            $state['errors'] = ['_form' => Text::_('COM_SQUIDFORM_ERR_SESSION')];
            State::put($alias, $state);
        }

        return $back;
    }

    /**
     * @param   array<string, mixed>  $post
     */
    private static function doSubmit(array $post, string $alias, string $back): string
    {
        $app   = Factory::getApplication();
        $user  = $app->getIdentity() ?? new User();

        Renderer::loadLanguage();

        try {
            $loaded = FormRepository::load($alias, $user);
        } catch (SchemaException) {
            $loaded = null;
        }

        if (!$loaded) {
            $app->enqueueMessage(Text::_('COM_SQUIDFORM_FORM_UNAVAILABLE'), 'warning');

            return $back;
        }

        ['form' => $form, 'schema' => $schema, 'version_id' => $versionId] = $loaded;

        // 0.15.0: the visitor's language; messages from here on are in it.
        $lang = Lang::pick($schema, $alias, \is_string($post['sf_lang'] ?? null) ? $post['sf_lang'] : null);
        Lang::enter($lang);
        $shown = Lang::schemaFor($schema, $lang);

        if ($shown !== $schema) {
            $loaded['original'] = $schema;
            $loaded['schema']   = $schema = $shown;
        }

        $state = State::forVersion(State::get($alias), $versionId);
        $page  = min(max(0, (int) $state['page']), $schema->pageCount() - 1);

        // Rate limit: page posts first (counts every attempt), before any other work.
        $formId = (int) $form['id'];

        if ($wait = RateLimit::postsBlocked($formId, $schema, $user)) {
            Log::add('SquidForm form "' . $alias . '" refused: too many posts (rate limit).', Log::NOTICE, 'squidform');
            $state['errors'] = ['_form' => Text::plural('COM_SQUIDFORM_ERR_RATE_POSTS', $wait)];
            State::put($alias, $state);

            return $back;
        }

        if (RateLimit::limits($schema)) {
            RateLimit::record($formId, 'post', $user);
        }

        // The posted page must be the page we expect (no skipping ahead), and
        // the review page must be the review page (an old tab can't post).
        $inReview = !empty($state['review']);

        if ((int) ($post['sf_page'] ?? -1) !== $page || $inReview !== !empty($post['sf_review'])) {
            $state['errors'] = ['_form' => Text::_('COM_SQUIDFORM_ERR_PAGE_MISMATCH')];
            State::put($alias, $state);

            return $back;
        }

        $reason = FormGuard::check($alias, $post);
        $nonce  = $reason === null ? FormGuard::nonceOf($post) : '';

        // 0.15.0: picking a language straight away isn't "too fast": nothing is sent.
        if ($reason === 'too_fast' && ($post['sf_nav'] ?? '') === 'lang') {
            $reason = null;
        }

        if ($reason !== null) {
            Log::add('SquidForm form "' . $alias . '" refused by human check: ' . $reason, Log::NOTICE, 'squidform');
            $state['errors'] = ['_form' => Text::_($reason === 'expired' ? 'COM_SQUIDFORM_ERR_EXPIRED' : 'COM_SQUIDFORM_ERR_VERIFY')];
            State::put($alias, $state);

            return $back;
        }

        // The minimum fill time counts from the first render from now on.
        $state['guard_t'] = FormGuard::startedOf($post);

        // Closed since the page was shown (0.10.0): dates, the total, per person.
        if (($closed = Limits::closed($schema, $formId, $user)) !== null) {
            $state['errors'] = ['_form' => self::plain($closed)];
            State::put($alias, $state);

            return $back;
        }

        // {url:NAME} in actions reads the address of the page the form is on.
        Placeholders::setQuery(self::queryOf($back));

        // Save and finish later (0.11.0): a guest was asked where to send the link.
        if (!empty($state['resume_ask'])) {
            if (($post['sf_nav'] ?? '') === 'lang') {
                return $back; // 0.15.0: a language tab: stay on this page
            }

            $state['resume_ask'] = false;

            if (($post['sf_nav'] ?? '') === 'resumesend' && Resume::enabled($schema, $user)) {
                $email = \is_scalar($post['sf_resume_email'] ?? null) ? (string) $post['sf_resume_email'] : '';
                $err   = Resume::saveForGuest($form, $versionId, $email, $state, $back, !empty($state['resumed']['guest']) ? (int) $state['resumed']['id'] : null);

                if ($err !== null) {
                    $state['resume_ask']   = true;
                    $state['resume_email'] = mb_substr($email, 0, 255);
                    $state['errors']       = ['_resume' => $err];
                    State::put($alias, $state);

                    return $back;
                }

                $fresh            = State::fresh($versionId);
                $fresh['message'] = ['text' => htmlspecialchars(Text::sprintf('COM_SQUIDFORM_RESUME_SENT', trim($email), Resume::days()), ENT_QUOTES, 'UTF-8'), 'type' => 'success', 'hide_form' => true];
                State::put($alias, $fresh);

                return $back;
            }

            $state['errors'] = [];
            State::put($alias, $state);

            return $back;
        }

        // 0.15.0: a language tab on the review page: nothing else changes.
        if (($post['sf_nav'] ?? '') === 'lang' && $inReview) {
            return $back;
        }

        // Start over (0.11.0): forget the saved draft and what's been typed.
        if (($post['sf_nav'] ?? '') === 'discard') {
            Resume::discard($formId, $user);
            State::clear($alias);

            return $back;
        }

        // The review page (0.10.0): change a page, or send.
        if ($inReview) {
            $nav = (string) ($post['sf_nav'] ?? '');

            if (preg_match('/^goto-(\d{1,3})$/', $nav, $m) && (int) $m[1] < $schema->pageCount() && Flow::visible($schema, (int) $m[1], (array) $state['data'])) {
                $state['page']           = (int) $m[1];
                $state['review']         = false;
                $state['back_to_review'] = true;
                $state['errors']         = [];
                State::put($alias, $state);

                return $back;
            }

            if ($nav !== 'send') {
                return $back;
            }

            return self::finish($post, $schema, $loaded, $user, $alias, $back, $state, (array) $state['data'], $page, $nonce, []);
        }

        $input = \is_array($post['sf'] ?? null) ? $post['sf'] : [];
        $data  = (array) $state['data'];

        // 3. normalise only this page's fields; anything else in the request is ignored.
        $fileErrors = [];
        $files      = \is_array($post['sf_files'] ?? null) ? $post['sf_files'] : [];

        foreach ($schema->pageFields($page) as $field) {
            $type = FieldRegistry::get($field['type']);

            // Uploads (0.9.0): the files come from $_FILES; checked, scanned and stored now.
            if (($field['type'] ?? '') === 'upload') {
                $key   = $field['key'];
                $new   = $files[$key]['new'] ?? [];
                $new   = isset($new['name']) ? [$new] : (\is_array($new) ? array_values($new) : []);
                $keep  = \is_array($input[$key]['keep'] ?? null) ? array_map('strval', $input[$key]['keep']) : [];
                $taken = Uploads::take($formId, $field, $new, $keep, (array) ($state['files'][$key] ?? []));

                $data[$key]           = $taken['uids'];
                $state['files'][$key] = $taken['uids'];

                if ($taken['errors']) {
                    $fileErrors[$key] = implode(' ', $taken['errors']);
                }

                continue;
            }

            if ($type->storesValue()) {
                $data[$field['key']] = $type->normalize($field, $input[$field['key']] ?? null);

                // "Ask for it twice" (0.9.2): the two must match (case doesn't matter).
                if (($field['type'] ?? '') === 'email' && !empty($field['confirm']) && \is_string($data[$field['key']]) && $data[$field['key']] !== '') {
                    $again = \is_array($post['sf_confirm'] ?? null) && \is_scalar($post['sf_confirm'][$field['key']] ?? null) ? trim((string) $post['sf_confirm'][$field['key']]) : '';

                    // 0.13.1: their own account's address needs no second box.
                    $mine = (int) $user->id > 0 ? mb_strtolower(trim((string) $user->email)) : '';

                    if (mb_strtolower($again) !== mb_strtolower($data[$field['key']]) && ($mine === '' || $mine !== mb_strtolower($data[$field['key']]))) {
                        $fileErrors[$field['key']] = Text::sprintf('COM_SQUIDFORM_ERR_EMAIL_AGAIN', (string) ($field['label'] ?? $field['key']));
                    }
                }
            }
        }

        // Locked prefills always come from the server, never from the browser
        // (0.15.0: session-data prefills are locked unless the form says not).
        $ph = new Placeholders($schema, $user, $data, [], [], $form);

        foreach ($schema->pageFields($page) as $field) {
            if (Renderer::prefillLocked($field, $user)) {
                $data[$field['key']] = Renderer::prefillValue($field, $ph);
            }
        }

        // Calculations (0.10.0) are worked out here, from what's left visible.
        $data = self::settle($schema, $data, $page);

        // Save and finish later (0.11.0): nothing is checked yet.
        if (($post['sf_nav'] ?? '') === 'save' && Resume::enabled($schema, $user)) {
            $state['data']   = $data;
            $state['errors'] = [];

            if ((int) $user->id > 0) {
                $until            = Resume::saveForUser($form, $versionId, $user, $state, $back);
                $fresh            = State::fresh($versionId);
                $fresh['message'] = ['text' => htmlspecialchars(Text::sprintf('COM_SQUIDFORM_RESUME_SAVED', \Joomla\CMS\HTML\HTMLHelper::_('date', $until, Text::_('DATE_FORMAT_LC3'))), ENT_QUOTES, 'UTF-8'), 'type' => 'success', 'hide_form' => true];
                State::put($alias, $fresh);

                return $back;
            }

            $state['resume_ask'] = true;
            State::put($alias, $state);

            return $back;
        }

        // 0.15.0: a language tab keeps what's typed on this page, unchecked.
        if (($post['sf_nav'] ?? '') === 'lang') {
            $state['data']   = $data;
            $state['errors'] = [];
            State::put($alias, $state);

            return $back;
        }

        if (($post['sf_nav'] ?? '') === 'back' && $page > 0) {
            $state['data']   = $data;
            $state['page']   = Flow::previous($schema, $page, $data);
            $state['errors'] = [];
            State::put($alias, $state);

            return $back;
        }

        // 5. validate
        $errors = self::validatePage($schema, $user, $form, $data, $page);

        // A refused file says why, before "is required".
        foreach ($fileErrors as $k => $m) {
            if (\array_key_exists($k, $data)) {
                $errors[$k] = $m;
            }
        }

        $last   = $page === $schema->pageCount() - 1;
        $review = (bool) $schema->setting('review', false);

        if ($errors) {
            $state['data']   = $data;
            $state['errors'] = $errors;
            State::put($alias, $state);

            return $back;
        }

        $state['seen'] = array_values(array_unique(array_merge((array) ($state['seen'] ?? []), [$page])));

        // The last page: check your answers first (0.10.0), or send.
        if ($last && $review) {
            $state['data']           = $data;
            $state['review']         = true;
            $state['back_to_review'] = false;
            $state['errors']         = [];
            State::put($alias, $state);

            return $back;
        }

        if ($last) {
            return self::finish($post, $schema, $loaded, $user, $alias, $back, $state, $data, $page, $nonce, $fileErrors);
        }

        // A middle page: its own actions, then the next page shown.
        $next = Flow::next($schema, $page, $data) ?? $schema->pageCount() - 1;

        // Back from the review page: straight back there once every page shown has been seen.
        $toReview = $review && !empty($state['back_to_review'])
            && !array_diff(Flow::pages($schema, $data), (array) $state['seen']);

        // The form's data tables must exist before the transaction: creating
        // or altering a table would commit it (MySQL).
        FormData::ensureLive((int) $form['id'], $schema, (string) $form['alias']);

        $ctx       = new Context($schema, $form, $versionId, $user, (string) $state['run'], $page);
        $ctx->data = $data;
        $ctx->original = $loaded['original'] ?? null;
        $db        = SubmissionStore::db();

        try {
            $db->transactionStart();
            Runner::run($ctx, $schema->actions($page, 'submit'));
            $db->transactionCommit();
        } catch (\Throwable $e) {
            try {
                $db->transactionRollback();
            } catch (\Throwable) {
                // ignore: original error matters
            }

            $state['data'] = $data;

            // 0.15.0: a handler said no (Handler\Refused): show its reason, keep the answers.
            if ($e instanceof Handler\Refused) {
                $field           = $e->field !== '' && $schema->field($e->field) !== null ? $e->field : '_form';
                $state['errors'] = [$field => mb_substr(strip_tags($e->getMessage()), 0, 500)];
                State::put($alias, $state);

                return $back;
            }

            Log::add('SquidForm form "' . $alias . '" submit failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
            $state['errors'] = ['_form' => Text::_('COM_SQUIDFORM_ERR_SAVE')];
            State::put($alias, $state);

            return $back;
        }

        $data = $ctx->data;

        if ($ctx->created && RateLimit::limits($schema)) {
            RateLimit::record($formId, 'save', $user);
        }

        $debug = Renderer::canDebug($schema) ? $ctx->debug : null;
        $jobs  = $ctx->afterCommit;

        if ($ctx->redirect !== null) {
            State::clear($alias);

            if ($ctx->message) {
                $app->enqueueMessage(strip_tags($ctx->message['text']), $ctx->message['type'] === 'error' ? 'error' : 'message');
            }

            self::runJobs($jobs, $alias);

            return $ctx->redirect;
        }

        if ($ctx->message && !empty($ctx->message['hide_form'])) {
            $fresh            = State::fresh($versionId);
            $fresh['message'] = $ctx->message;
            $fresh['debug']   = $debug;
            State::put($alias, $fresh);
            self::runJobs($jobs, $alias);

            return $back;
        }

        $state['data']    = $data;
        $state['page']    = $toReview ? $schema->pageCount() - 1 : $next;
        $state['review']  = $toReview;
        $state['errors']  = [];
        $state['message'] = $ctx->message;
        $state['debug']   = $debug;
        State::put($alias, $state);
        self::runJobs($jobs, $alias);

        return $back;
    }

    /**
     * Send the form: every page shown is checked again (answers on an earlier
     * page may have changed what a later page needs), then the human check,
     * the limits (under a lock) and the last page's actions.
     *
     * @param array{form: array, schema: Schema, version_id: int} $loaded
     * @param array<string, mixed> $state
     * @param array<string, mixed> $data
     * @param array<string, string> $fileErrors
     */
    private static function finish(array $post, Schema $schema, array $loaded, User $user, string $alias, string $back, array $state, array $data, int $page, string $nonce, array $fileErrors): string
    {
        $app       = Factory::getApplication();
        $form      = $loaded['form'];
        $versionId = (int) $loaded['version_id'];
        $formId    = (int) $form['id'];
        $last      = $schema->pageCount() - 1;

        $data = Flow::dropSkipped($schema, $data);

        $data = self::settle($schema, $data);

        $errors = [];

        foreach (Flow::pages($schema, $data) as $p) {
            $errors += self::validatePage($schema, $user, $form, $data, $p);
        }

        foreach ($fileErrors as $k => $m) {
            if (\array_key_exists($k, $data)) {
                $errors[$k] = $m;
            }
        }

        // The human check is on the page that sends: the review page, or the last page.
        if (($cfg = Captcha::config($schema, $user)) && ($err = Captcha::check($alias, $cfg, $post, $nonce)) !== null) {
            Log::add('SquidForm form "' . $alias . '" refused by the captcha (' . $cfg['type'] . ').', Log::NOTICE, 'squidform');
            $errors[Captcha::FIELD] = $err;
        }

        if (!$errors && ($wait = RateLimit::savesBlocked($formId, $schema, $user))) {
            Log::add('SquidForm form "' . $alias . '" refused: too many submissions (rate limit).', Log::NOTICE, 'squidform');
            $errors['_form'] = Text::plural('COM_SQUIDFORM_ERR_RATE_SAVES', $wait);
        }

        if ($errors) {
            return self::backWithErrors($schema, $alias, $back, $state, $data, $errors, $page);
        }

        FormData::ensureLive($formId, $schema, (string) $form['alias']);

        // Limits (0.10.0) are counted under a lock, so two people can't both take the last place.
        $counts = Limits::counts($schema);

        if ($counts && !Limits::lock($formId)) {
            return self::backWithErrors($schema, $alias, $back, $state, $data, ['_form' => Text::_('COM_SQUIDFORM_ERR_BUSY')], $page);
        }

        try {
            if ($counts && ($closed = Limits::closed($schema, $formId, $user)) !== null) {
                return self::backWithErrors($schema, $alias, $back, $state, $data, ['_form' => self::plain($closed)], $page);
            }

            if ($counts && ($full = Limits::capErrors($formId, $schema, $data))) {
                return self::backWithErrors($schema, $alias, $back, $state, $data, $full, $page);
            }

            $ctx        = new Context($schema, $form, $versionId, $user, (string) $state['run'], $last);
            $ctx->data  = $data;
            $ctx->original = $loaded['original'] ?? null;
            $db         = SubmissionStore::db();

            try {
                $db->transactionStart();
                Runner::run($ctx, $schema->actions($last, 'submit'));
                // E-signatures (0.11.0) are recorded with the submission they sign.
                ESign::record($ctx);
                $db->transactionCommit();
            } catch (\Throwable $e) {
                try {
                    $db->transactionRollback();
                } catch (\Throwable) {
                    // ignore: original error matters
                }

                // 0.15.0: a handler said no (Handler\Refused): show its reason, keep the answers.
                if ($e instanceof Handler\Refused) {
                    $field = $e->field !== '' && $schema->field($e->field) !== null ? $e->field : '_form';

                    return self::backWithErrors($schema, $alias, $back, $state, $data, [$field => mb_substr(strip_tags($e->getMessage()), 0, 500)], $page);
                }

                Log::add('SquidForm form "' . $alias . '" submit failed: ' . $e->getMessage(), Log::ERROR, 'squidform');

                return self::backWithErrors($schema, $alias, $back, $state, $data, ['_form' => Text::_('COM_SQUIDFORM_ERR_SAVE')], $page);
            }
        } finally {
            Limits::unlock();
        }

        // A new submission counts towards the limit.
        if ($ctx->created && RateLimit::limits($schema)) {
            RateLimit::record($formId, 'save', $user);
        }

        // Sent: a saved draft (0.11.0) is no longer needed.
        if ($schema->setting('save_later', false)) {
            Resume::finished($formId, $user, isset($state['resumed']['id']) ? (int) $state['resumed']['id'] : null);
        }

        $debug = Renderer::canDebug($schema) ? $ctx->debug : null;
        $jobs  = $ctx->afterCommit;

        if ($ctx->redirect !== null) {
            State::clear($alias);

            if ($ctx->message) {
                $app->enqueueMessage(strip_tags($ctx->message['text']), $ctx->message['type'] === 'error' ? 'error' : 'message');
            }

            self::runJobs($jobs, $alias);

            return $ctx->redirect;
        }

        $fresh            = State::fresh($versionId);
        $fresh['message'] = $ctx->message ?? [
            'text'      => htmlspecialchars((string) $schema->setting('success_message', Text::_('COM_SQUIDFORM_DEFAULT_SUCCESS')), ENT_QUOTES, 'UTF-8'),
            'type'      => 'success',
            'hide_form' => true,
        ];
        $fresh['debug']                = $debug;

        // A copy of the answers (0.11.0): the person who sent it may download it as a PDF this session.
        if ($schema->setting('copy_pdf', false) && $ctx->submissionUid !== null && $ctx->submissionId !== null) {
            $fresh['copy'] = $ctx->submissionUid;
            $app->getSession()->set('squidform.copy.' . $ctx->submissionUid, time());
        }

        State::put($alias, $fresh);
        self::runJobs($jobs, $alias);

        return $back;
    }

    /**
     * Back to the first page with a problem (a field on an earlier page,
     * say), or stay where the person is for a problem with the whole form.
     *
     * @param array<string, string> $errors
     */
    private static function backWithErrors(Schema $schema, string $alias, string $back, array $state, array $data, array $errors, int $page): string
    {
        $to = null;

        foreach (array_keys($errors) as $path) {
            $p = $path === '_form' || $path === Captcha::FIELD ? null : Flow::pageOf($schema, (string) $path);

            if ($p !== null && ($to === null || $p < $to)) {
                $to = $p;
            }
        }

        $state['data']   = $data;
        $state['errors'] = $errors;

        if ($to !== null) {
            $state['page']   = $to;
            $state['review'] = false;

            // Only that page's problems can be shown there; the rest come back on the way.
            $state['errors'] = array_filter($errors, static fn ($p) => $p === '_form' || Flow::pageOf($schema, (string) $p) === $to, ARRAY_FILTER_USE_KEY);
            $state['back_to_review'] = (bool) $schema->setting('review', false);
        }

        State::put($alias, $state);

        return $back;
    }

    /**
     * Errors for one page's fields.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function validatePage(Schema $schema, User $user, array $form, array $data, int $page): array
    {
        $ph     = new Placeholders($schema, $user, $data, [], [], $form);
        $vc     = new ValidateContext($schema, $data, $ph);
        $errors = [];

        foreach ($schema->pageFields($page) as $field) {
            $type = FieldRegistry::get($field['type']);

            if (!$type->storesValue() || !\array_key_exists($field['key'], $data)) {
                continue; // display-only, or hidden by a condition
            }

            $errors += $type->validate($field, $data[$field['key']], $data, $vc);
        }

        return $errors;
    }

    /** A closed message (HTML) as plain text, for the error list. */
    private static function plain(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** The query of the page the form is on: ?dept=HR → ['dept' => 'HR']. @return array<string, mixed> */
    public static function queryOf(string $url): array
    {
        $q = (string) parse_url($url, PHP_URL_QUERY);
        $out = [];

        if ($q !== '') {
            parse_str($q, $out);
        }

        return $out;
    }

    /**
     * Run after-commit work (email). Called only after the session state is
     * saved, so a slow or failing mail server can never make the person
     * resubmit, and each job's failure is logged without affecting the others.
     *
     * @param   list<callable(): void>  $jobs
     */
    private static function runJobs(array $jobs, string $alias): void
    {
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (\Throwable $e) {
                Log::add('SquidForm form "' . $alias . '" after-commit job failed: ' . $e->getMessage(), Log::ERROR, 'squidform');
            }
        }
    }

    /**
     * Remove values of fields whose show_if is false (top level and inside
     * repeater rows). Repeats until stable, so a field that depends on a
     * hidden field is hidden too.
     *
     * @param   array<string, mixed>  $data
     *
     * @return  array<string, mixed>
     */
    /**
     * Calculations and show/hide together: calculations are worked out from
     * what's visible, then fields whose show/hide rule uses a calculation
     * (0.13.0: a named value, say) are judged on its worked-out value, not on
     * the empty one the browser can't send. $page: that page only (and the
     * calculations up to it); null: every page.
     */
    public static function settle(Schema $schema, array $data, ?int $page = null): array
    {
        $pages = $page === null ? range(0, max(0, $schema->pageCount() - 1)) : [$page];
        $strip = static function (array $d) use ($schema, $pages): array {
            foreach ($pages as $p) {
                $d = self::stripHidden($schema, $p, $d);
            }

            return $d;
        };

        $worked = Calc::apply($schema, $strip($data), $page);

        foreach ($worked as $k => $v) {
            if (($schema->field((string) $k)['type'] ?? '') === 'calc') {
                $data[$k] = $v;
            }
        }

        return $strip(Calc::apply($schema, $strip($data), $page));
    }

    public static function stripHidden(Schema $schema, int $page, array $data): array
    {
        for ($pass = 0; $pass < 5; $pass++) {
            $changed = false;

            foreach ($schema->pageFields($page) as $field) {
                $key = $field['key'];

                if (!empty($field['show_if']) && \array_key_exists($key, $data)
                    && !Conditions::evaluate($field['show_if'], static fn ($k) => $data[$k] ?? null)) {
                    unset($data[$key]);
                    $changed = true;
                    continue;
                }

                if ($field['type'] === 'repeater' && \is_array($data[$key] ?? null)) {
                    foreach ($data[$key] as $i => $row) {
                        foreach ($field['fields'] as $child) {
                            $ck = $child['key'];

                            if (!empty($child['show_if']) && \array_key_exists($ck, $row)
                                && !Conditions::evaluate($child['show_if'], static fn ($k) => \array_key_exists($k, $row) ? $row[$k] : ($data[$k] ?? null))) {
                                unset($data[$key][$i][$ck]);
                                $changed = true;
                            }
                        }
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }

        return $data;
    }

    /** Decode and check the posted return URL; only this site's pages are allowed. */
    private static function returnUrl(string $encoded, string $alias): string
    {
        $url = base64_decode($encoded, true);

        if (!\is_string($url) || $url === '' || !Url::isInternal($url)) {
            $url = Uri::root();
        }

        $url = preg_replace('/#.*$/', '', $url);

        return $url . '#sf-' . $alias;
    }

    /**
     * JSON options for a cascading list: field key + current parent value.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $alias, string $fieldKey, string $parentValue): array
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity() ?? new User();

        $loaded = FormRepository::load($alias, $user);

        if (!$loaded) {
            throw new \RuntimeException('Form not available.', 404);
        }

        $loaded['schema'] = Lang::schemaFor($loaded['schema'], Lang::pick($loaded['schema'], $alias)); // 0.15.0
        $field = $loaded['schema']->field($fieldKey);

        if (!$field || Options::parentOf($field) === null) {
            throw new \RuntimeException('Field has no cascading options.', 404);
        }

        $ph = new Placeholders($loaded['schema'], $user, [], [], [], $loaded['form']);

        return Options::resolve($field, $parentValue, $ph);
    }

    /**
     * Suggestions for a look-up field (0.8.1): what matches the typed text,
     * from the field's own option source (with its cascade, if any).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function lookup(string $alias, string $fieldKey, string $q, string $parentValue): array
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity() ?? new User();

        $loaded = FormRepository::load($alias, $user);

        if (!$loaded) {
            throw new \RuntimeException('Form not available.', 404);
        }

        $loaded['schema'] = Lang::schemaFor($loaded['schema'], Lang::pick($loaded['schema'], $alias)); // 0.15.0
        $field = $loaded['schema']->field($fieldKey);

        if (!$field || ($field['type'] ?? '') !== 'lookup') {
            throw new \RuntimeException('Not a look-up field.', 404);
        }

        $ph = new Placeholders($loaded['schema'], $user, [], [], [], $loaded['form']);

        return Field\LookupField::match(Options::resolve($field, Options::parentOf($field) !== null ? $parentValue : null, $ph), mb_substr($q, 0, 100));
    }
}
