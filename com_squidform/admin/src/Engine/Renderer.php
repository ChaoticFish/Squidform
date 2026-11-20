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
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\RenderContext;

\defined('_JEXEC') or die;

/**
 * Renders a form for the browser. The same entry point is used by the menu
 * item, the module and the {squidform alias} shortcode.
 *
 * Flow (post/redirect/get): the form posts to the component's submit task,
 * which stores progress, errors and messages in the session under the form's
 * alias and redirects back to the page the form was on. This renderer reads
 * that state, so it works identically wherever the form is placed.
 */
final class Renderer
{
    /** @var array<string, true> forms already on this page */
    private static array $rendered = [];

    /** True once any form was rendered in this request (used to exclude the page from the page cache). */
    public static function hasRendered(): bool
    {
        return self::$rendered !== [];
    }

    public static function render(string $alias, ?string $returnUrl = null): string
    {
        try {
            return self::doRender($alias, $returnUrl);
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('SquidForm form "' . $alias . '" render error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), \Joomla\CMS\Log\Log::ERROR, 'squidform');

            return self::notice(Text::_('COM_SQUIDFORM_FORM_BROKEN'), 'warning');
        }
    }

    private static function doRender(string $alias, ?string $returnUrl, ?array $pre = null, ?string $tag = null): string
    {
        // A form can appear once per page: a second copy (e.g. module + article)
        // would duplicate element ids and share one session state.
        if ($pre === null) {
            if (isset(self::$rendered[$alias])) {
                return '<!-- SquidForm: "' . self::e($alias) . '" is already on this page -->';
            }

            self::$rendered[$alias] = true;
        }

        $app  = Factory::getApplication();
        $user = $app->getIdentity() ?? new User();

        self::loadLanguage();

        // Forms carry one-time tokens, so the page must never be served from the page cache.
        if (method_exists($app, 'allowCache')) {
            $app->allowCache(false);
        }

        try {
            $loaded = $pre ?? FormRepository::load($alias, $user);
        } catch (SchemaException $e) {
            return self::notice(Text::_('COM_SQUIDFORM_FORM_BROKEN'), 'warning');
        }

        if (!$loaded) {
            return ''; // Unpublished, not allowed, or missing: show nothing to visitors.
        }

        ['form' => $form, 'schema' => $schema, 'version_id' => $versionId] = $loaded;

        // 0.15.0: everything below in the visitor's language (and back after).
        if ($tag === null) {
            $chosen = Factory::getApplication()->getInput()->get->getCmd('sf_lang', '');
            $tag    = Lang::pick($schema, $alias, $chosen !== '' ? $chosen : null);

            return (string) Lang::run($tag, fn () => self::dress(self::doRender($alias, $returnUrl, $loaded, $tag), $schema, $alias, $tag));
        }

        $schema = Lang::schemaFor($schema, $tag);
        $look   = Looks::classes(Looks::of($schema));

        $state = State::get($alias);
        $state = State::forVersion($state, $versionId);
        $out   = '';

        // Save and finish later (0.11.0): a signed-in person's draft comes back by itself.
        if (Resume::enabled($schema, $user)) {
            if (random_int(1, 20) === 1) {
                Resume::sweep();
            }

            if ((int) $user->id > 0 && empty($state['resume_checked']) && empty($state['message']) && !$state['data'] && (int) $state['page'] === 0 && empty($state['review'])) {
                $state['resume_checked'] = true;

                if ($draft = Resume::forUser((int) $form['id'], (int) $user->id)) {
                    $state = Resume::into($state, $draft, $schema, $versionId);
                }

                State::put($alias, $state);
            }
        }

        // A finished run shows its message once.
        if (!empty($state['message'])) {
            $msg = $state['message'];
            $out .= self::notice($msg['text'], $msg['type'], true);
            $debug = $state['debug'] ?? null;

            // A copy of the answers (0.11.0), for this session.
            if (!empty($state['copy']) && \is_string($state['copy'])) {
                $out .= '<p class="sf-copy"><a class="btn btn-outline-primary" href="' . self::e(Route::_('index.php?option=com_squidform&task=form.copy&uid=' . rawurlencode($state['copy']), false)) . '">'
                    . self::e(Text::_('COM_SQUIDFORM_COPY_PDF')) . '</a></p>';
            }

            if (!empty($msg['hide_form'])) {
                State::clear($alias);

                return '<div class="squidform' . $look . '" id="sf-' . self::e($alias) . '">' . $out . self::debugTable($debug) . '</div>';
            }

            // Message shown above the next page: keep the person's progress.
            $state['message'] = null;
            State::put($alias, $state);
        }

        $page   = min(max(0, (int) $state['page']), $schema->pageCount() - 1);
        $data   = (array) $state['data'];
        $errors = (array) $state['errors'];

        // {url:NAME} and "Can be filled from the web address" (0.10.0).
        Placeholders::setQuery(Uri::getInstance()->getQuery(true));

        // Not open yet, closed, or full (0.10.0): say so instead of showing the form.
        if (($closed = Limits::closed($schema, (int) $form['id'], $user)) !== null) {
            return '<div class="squidform' . $look . '" id="sf-' . self::e($alias) . '">' . $out . self::notice($closed, 'info') . '</div>';
        }

        // Save and finish later (0.11.0): where should a guest's link go?
        if (!empty($state['resume_ask'])) {
            return self::resumeAsk($schema, $alias, $state, $page, $returnUrl, $out);
        }

        // Check your answers (0.10.0).
        if (!empty($state['review'])) {
            return self::review($schema, $form, $alias, $state, $user, $returnUrl, $out);
        }

        // Reached the limit of saved submissions: say when to come back instead of showing the form.
        if ($page === 0 && !$errors && ($wait = RateLimit::savesBlocked((int) $form['id'], $schema, $user))) {
            return '<div class="squidform' . $look . '" id="sf-' . self::e($alias) . '">' . $out
                . self::notice(self::e(Text::plural('COM_SQUIDFORM_ERR_RATE_SAVES', $wait)), 'warning') . '</div>';
        }

        // Load-event actions (e.g. a welcome message); no database transaction needed.
        $ctx        = new Context($schema, $form, $versionId, $user, (string) $state['run'], $page);
        $ctx->data  = $data;
        $ctx->event = 'load';
        Runner::run($ctx, $schema->actions($page, 'load'));

        // Read data (0.10.0) may have filled empty fields.
        $data = $ctx->data;

        if ($ctx->message) {
            $out .= self::notice($ctx->message['text'], $ctx->message['type']);
        }

        // Fill empty fields: the web address (0.10.0), the user profile, the
        // default; and remember the locked ones.
        $ph     = new Placeholders($schema, $user, $data, $ctx->vars, $ctx->outputs, $form);
        $locked = self::prefill($schema->pageFields($page), $data, $ph, $user);
        $data   = Calc::apply($schema, $data, $page);

        $ph = new Placeholders($schema, $user, $data, $ctx->vars, $ctx->outputs, $form);
        $ph->onPage = true;
        $rc = new RenderContext($schema, $alias, $ph, $errors, $data, $locked);
        $rc->capacity = Limits::capacity((int) $form['id'], array_values(array_filter($schema->pageFields($page), static fn ($f) => Limits::caps($f) !== [])));

        $fieldsHtml = Layout::render(
            $schema->pageFields($page),
            static fn (array $f) => FieldRegistry::get($f['type'])->render($f, $data[$f['key']] ?? null, $rc),
            static fn (string $k) => isset($errors[$k]) || (bool) array_filter(array_keys($errors), static fn ($p) => str_starts_with((string) $p, $k . '.')),
            'sf-' . $alias
        );

        $out .= self::errorSummary($errors, $schema, 'sf-' . $alias);

        // Back from a saved draft (0.11.0): say so once, with Start over.
        $resumed = '';

        if (!empty($state['resumed']) && empty($state['resumed']['shown'])) {
            $r       = $state['resumed'];
            $resumed = '<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 sf-resumed" role="status"><span>'
                . self::e(Text::sprintf('COM_SQUIDFORM_RESUMED', HTMLHelper::_('date', (string) $r['at'], Text::_('DATE_FORMAT_LC2'))))
                . (!empty($r['changed']) ? ' ' . self::e(Text::_('COM_SQUIDFORM_RESUMED_CHANGED')) : '')
                . '</span>'
                . '<button type="submit" name="sf_nav" value="discard" class="btn btn-sm btn-outline-dark ms-auto" formnovalidate>' . self::e(Text::_('COM_SQUIDFORM_START_OVER')) . '</button></div>';
            $state['resumed']['shown'] = true;
            State::put($alias, $state);
        }

        $pageDef  = $schema->page($page);
        $isLast   = $page === $schema->pageCount() - 1;
        $review   = (bool) $schema->setting('review', false);
        $submitTx = (string) ($pageDef['submit_label'] ?? ($isLast && !$review ? $schema->setting('submit_label', Text::_('COM_SQUIDFORM_SUBMIT')) : Text::_('COM_SQUIDFORM_NEXT')));

        // Steps count the pages shown (0.10.0: some may be skipped).
        $shown    = Flow::pages($schema, $data);
        $count    = \count($shown);
        $at       = (int) array_search($page, $shown, true) + 1;
        $progress = '';

        if ($schema->pageCount() > 1) {
            $progress = '<p class="sf-progress text-body-secondary" aria-live="polite">'
                . self::e(Text::sprintf('COM_SQUIDFORM_STEP_OF', $at, $count))
                . (!empty($pageDef['title']) ? ' — <strong>' . self::e($pageDef['title']) . '</strong>' : '') . '</p>';
        }

        // The render token first: the captcha's proof of work is bound to its nonce.
        $guard   = FormGuard::fields($alias, (int) ($state['guard_t'] ?? 0));
        $captcha = '';

        if ($isLast && !$review && ($cfg = Captcha::config($schema, $user))) {
            $captcha = Captcha::render($alias, $cfg, FormGuard::$lastNonce, $errors[Captcha::FIELD] ?? null);
        }

        $return = base64_encode($returnUrl ?? Uri::getInstance()->toString());
        $action = Route::_('index.php?option=com_squidform&task=form.submit', false);
        $optUrl = Route::_('index.php?option=com_squidform&task=form.options&format=json', false);

        $buttons = '<div class="sf-actions d-flex gap-2">';

        if ($page > 0) {
            $buttons .= '<button type="submit" name="sf_nav" value="back" class="btn btn-secondary" formnovalidate>'
                . self::e(Text::_('COM_SQUIDFORM_BACK')) . '</button>';
        }

        $buttons .= '<button type="submit" name="sf_nav" value="next" class="btn btn-primary">' . self::e($submitTx) . '</button>';

        if (Resume::enabled($schema, $user)) {
            $buttons .= '<button type="submit" name="sf_nav" value="save" class="btn btn-outline-secondary ms-auto" formnovalidate>' . self::e(Text::_('COM_SQUIDFORM_SAVE_LATER')) . '</button>';
        }

        $buttons .= '</div>';

        $title = !empty($schema->setting('show_title', false)) ? '<h2 class="sf-title">' . self::e($form['title']) . '</h2>' : '';
        $ph->onPage = true;
        $intro = $schema->setting('intro') ? '<div class="sf-intro mb-3">' . self::safeHtml($ph->replace((string) $schema->setting('intro'))) . '</div>' : '';

        $html = '<div class="squidform' . $look . '" id="sf-' . self::e($alias) . '">' . self::testBanner($schema) . $title . $out . $progress . $intro
            . '<form class="sf-form" method="post" action="' . self::e($action) . '" novalidate data-sf-today="' . Formula::todayString() . '"'
            . (array_filter($schema->pageFields($page), static fn ($f) => ($f['type'] ?? '') === 'upload') ? ' enctype="multipart/form-data"' : '')
            . ' data-sf-alias="' . self::e($alias) . '" data-sf-options-url="' . self::e($optUrl) . '"'
            . ' data-sf-known="' . self::e(json_encode(self::otherPageValues($schema, $page, $data), JSON_UNESCAPED_UNICODE)) . '"'
            . ' aria-label="' . self::e($form['title']) . '">'
            . $resumed
            . $fieldsHtml
            . $captcha
            . $guard
            . '<input type="hidden" name="sf_form" value="' . self::e($alias) . '">'
            . '<input type="hidden" name="sf_page" value="' . $page . '">'
            . '<input type="hidden" name="sf_return" value="' . self::e($return) . '">'
            . HTMLHelper::_('form.token')
            . $buttons
            . '</form>'
            . self::debugTable($state['debug'] ?? null)
            . '</div>';

        // Errors and debug are shown once.
        if ($errors || !empty($state['debug'])) {
            $state['errors'] = [];
            $state['debug']  = null;
            State::put($alias, $state);
        }

        self::assets();
        self::ownCode($schema, $alias, true);

        // E-signature trail (0.11.0): the signer opened the page with it.
        ESign::opened($schema, (int) $form['id'], (string) $state['run'], $page, $user);

        return $html;
    }

    /** 0.15.0: the banner every visitor sees while a form is in test mode. */
    private static function testBanner(Schema $schema): string
    {
        return TestMode::on($schema) ? '<div class="alert alert-warning sf-test-banner" role="note"><strong>' . self::e(Text::_('COM_SQUIDFORM_TEST_BANNER_TITLE')) . '</strong> ' . self::e(Text::_('COM_SQUIDFORM_TEST_BANNER')) . '</div>' : '';
    }

    /**
     * 0.15.0: what every language needs on the form's root: lang="" when it
     * isn't the page's, the language tabs (when the form offers more than
     * one), and which language each post comes from.
     */
    private static function dress(string $html, Schema $schema, string $alias, string $tag): string
    {
        $root = '<div class="squidform';
        $at   = strpos($html, $root);

        if ($at === false) {
            return $html;
        }

        $end  = strpos($html, '>', $at);
        $lang = $tag !== Lang::default() ? ' lang="' . self::e(Lang::htmlLang($tag)) . '"' : '';
        $html = substr($html, 0, $at) . '<div' . $lang . substr($html, $at + 4, $end - $at - 3) . self::languageTabs($schema, $alias, $tag) . substr($html, $end + 1);

        return str_replace('<input type="hidden" name="sf_form"', '<input type="hidden" name="sf_lang" value="' . self::e($tag) . '"><input type="hidden" name="sf_form"', $html);
    }

    /** The row of language tabs: links (JavaScript posts the page first, so nothing typed is lost). */
    public static function languageTabs(Schema $schema, string $alias, string $tag, bool $preview = false): string
    {
        $list = Lang::forForm($schema);

        if (\count($list) < 2) {
            return '';
        }

        $items = '';

        foreach ($list as $t) {
            [, $own, $html, $joke] = Lang::packs()[$t];
            $uri = clone Uri::getInstance();
            $uri->setVar($preview ? 'lang' : 'sf_lang', $t);
            $cur = $t === $tag;
            $items .= '<li class="nav-item"><a class="nav-link sf-lang-link' . ($cur ? ' active' : '') . '" href="' . self::e($uri->toString(['path', 'query'])) . '"'
                . ' lang="' . self::e($html) . '"' . ($joke ? '' : ' hreflang="' . self::e($html) . '"') . ' data-sf-lang="' . self::e($t) . '"' . ($cur ? ' aria-current="true"' : '') . '>'
                . self::e($own) . '</a></li>';
        }

        return '<nav class="sf-langs mb-3" aria-label="' . self::e(Text::_('COM_SQUIDFORM_LANG_SWITCH')) . '"><ul class="nav nav-tabs">' . $items . '</ul></nav>';
    }

    /**
     * Save and finish later for a guest (0.11.0): ask where to send the link.
     *
     * @param array<string, mixed> $state
     */

    private static function resumeAsk(Schema $schema, string $alias, array $state, int $page, ?string $returnUrl, string $out): string
    {
        $look = Looks::classes(Looks::of($schema));
        $err   = (string) ($state['errors']['_resume'] ?? '');
        $email = (string) ($state['resume_email'] ?? '');

        // Suggest the first email address they've typed.
        if ($email === '') {
            foreach ($schema->valueFields() as $f) {
                if (($f['type'] ?? '') === 'email' && \is_string($state['data'][$f['key']] ?? null) && $state['data'][$f['key']] !== '') {
                    $email = $state['data'][$f['key']];
                    break;
                }
            }
        }

        if ($err !== '') {
            $state['errors'] = [];
            State::put($alias, $state);
        }

        $id   = 'sf-' . self::e($alias) . '-resume-email';
        $html = '<div class="squidform' . $look . '" id="sf-' . self::e($alias) . '">' . self::testBanner($schema) . $out
            . '<form class="sf-form sf-resume-ask" method="post" action="' . self::e(Route::_('index.php?option=com_squidform&task=form.submit', false)) . '" novalidate>'
            . '<h2 class="h4" tabindex="-1" data-sf-focus>' . self::e(Text::_('COM_SQUIDFORM_RESUME_ASK_TITLE')) . '</h2>'
            . '<p>' . self::e(Text::plural('COM_SQUIDFORM_RESUME_ASK_INTRO', Resume::days())) . '</p>'
            . ($err !== '' ? '<div class="alert alert-danger" role="alert" id="' . $id . '-err">' . self::e($err) . '</div>' : '')
            . '<div class="mb-3"><label class="form-label" for="' . $id . '">' . self::e(Text::_('COM_SQUIDFORM_RESUME_EMAIL')) . '</label>'
            . '<input type="email" class="form-control' . ($err !== '' ? ' is-invalid' : '') . '" id="' . $id . '" name="sf_resume_email" value="' . self::e($email) . '" autocomplete="email" required'
            . ($err !== '' ? ' aria-invalid="true" aria-describedby="' . $id . '-err"' : '') . '></div>'
            . FormGuard::fields($alias, (int) ($state['guard_t'] ?? 0))
            . '<input type="hidden" name="sf_form" value="' . self::e($alias) . '">'
            . '<input type="hidden" name="sf_page" value="' . $page . '">'
            . '<input type="hidden" name="sf_return" value="' . self::e(base64_encode($returnUrl ?? Uri::getInstance()->toString())) . '">'
            . HTMLHelper::_('form.token')
            . '<div class="sf-actions d-flex gap-2">'
            . '<button type="submit" name="sf_nav" value="resumesend" class="btn btn-primary">' . self::e(Text::_('COM_SQUIDFORM_RESUME_SEND')) . '</button>'
            . '<button type="submit" name="sf_nav" value="resumecancel" class="btn btn-secondary" formnovalidate>' . self::e(Text::_('COM_SQUIDFORM_RESUME_CANCEL')) . '</button>'
            . '</div></form></div>';

        self::assets();

        return $html;
    }

    /**
     * Fill empty fields of a page: from the web address when the field allows
     * it ("url_param", 0.10.0), else the profile placeholder ("prefill"), else
     * the default. A locked prefill always wins. Returns the locked keys.
     *
     * @param  list<array<string, mixed>> $fields
     * @param  array<string, mixed>       $data
     * @return array<string, true>
     */
    private static function prefill(array $fields, array &$data, Placeholders $ph, User $user): array
    {
        $locked = [];

        foreach ($fields as $f) {
            $key  = $f['key'] ?? '';
            $lock = self::prefillLocked($f, $user);

            if ($lock) {
                $locked[$key] = true;
            }

            if (\array_key_exists($key, $data)) {
                continue;
            }

            $fromUrl = !$lock && \is_string($f['url_param'] ?? null) ? Placeholders::query($f['url_param']) : null;

            if ($fromUrl !== null && $fromUrl !== '' && $fromUrl !== []) {
                $multi = \in_array($f['type'] ?? '', ['checkboxes', 'multiselect'], true);
                $vals  = \is_array($fromUrl) ? $fromUrl : ($multi ? explode(',', (string) $fromUrl) : [(string) $fromUrl]);
                $vals  = array_values(array_filter(array_map(static fn ($v) => \is_scalar($v) ? mb_substr(trim((string) $v), 0, 1000) : '', $vals), static fn ($v) => $v !== ''));

                if ($vals) {
                    $data[$key] = $multi ? \array_slice($vals, 0, 50) : $vals[0];
                    continue;
                }
            }

            if (isset($f['prefill'])) {
                $data[$key] = self::prefillValue($f, $ph);
            } elseif (\array_key_exists('default', $f)) {
                $data[$key] = $f['default'];
            }
        }

        return $locked;
    }

    /**
     * Is this field's prefill locked (shown, but always filled in by the
     * server)? "prefill_lock": true for someone signed in; and (0.15.0) a
     * prefill from session data is locked unless "prefill_lock": false.
     */
    public static function prefillLocked(array $f, User $user): bool
    {
        if (!isset($f['prefill'])) {
            return false;
        }

        $session = str_contains((string) $f['prefill'], '{session:');

        if (\array_key_exists('prefill_lock', $f)) {
            return !empty($f['prefill_lock']) && ((int) $user->id > 0 || $session);
        }

        return $session;
    }

    /**
     * A field's prefill. A prefill that is exactly one {session:…} list fills
     * Checkboxes / several-choice dropdowns with every entry, and anything
     * else with one entry per line (0.15.0).
     */
    public static function prefillValue(array $f, Placeholders $ph): mixed
    {
        $p = (string) $f['prefill'];

        if (preg_match('/^\{session:([A-Za-z0-9_.-]+)\}$/', $p, $m) && \is_array($v = SessionData::get($m[1]))) {
            $list = array_values(array_filter(array_map(static fn ($x) => \is_scalar($x) ? (string) $x : '', $v), static fn ($x) => $x !== ''));

            return \in_array($f['type'] ?? '', ['checkboxes', 'multiselect'], true) ? $list : implode("\n", $list);
        }

        return $ph->replace($p, 'text');
    }

    /**
     * Check your answers (0.10.0): every page shown, its answers and a Change
     * button that goes back to that page; then Send. The human check is here,
     * since this is the page that sends.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $state
     */
    private static function review(Schema $schema, array $form, string $alias, array $state, User $user, ?string $returnUrl, string $out): string
    {
        $look = Looks::classes(Looks::of($schema));
        $data   = (array) $state['data'];
        $errors = (array) $state['errors'];
        $last   = $schema->pageCount() - 1;
        $ph     = new Placeholders($schema, $user, $data, [], [], $form);
        $guard  = FormGuard::fields($alias, (int) ($state['guard_t'] ?? 0));
        $title  = trim((string) $schema->setting('review_title', '')) ?: Text::_('COM_SQUIDFORM_REVIEW_TITLE');
        $intro  = trim((string) $schema->setting('review_intro', '')) ?: Text::_('COM_SQUIDFORM_REVIEW_INTRO');
        $body   = '';

        foreach (Flow::pages($schema, $data) as $n => $p) {
            $pd    = $schema->page($p);
            $name  = !empty($pd['title']) ? (string) $pd['title'] : Text::sprintf('COM_SQUIDFORM_REVIEW_STEP', $n + 1);
            $rows  = '';

            foreach ($schema->pageFields($p) as $f) {
                if (!FieldRegistry::get($f['type'])->storesValue() || !\array_key_exists($f['key'], $data)
                    || Display::isPrivate($f)) {
                    continue;
                }

                $rows .= '<dt class="col-sm-4">' . self::e($f['label'] ?? $f['key']) . '</dt><dd class="col-sm-8">' . self::reviewValue($f, $data[$f['key']]) . '</dd>';
            }

            $body .= '<section class="sf-review-page mb-4" aria-labelledby="sf-' . self::e($alias) . '-rv-' . $p . '">'
                . '<div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 border-bottom mb-2">'
                . '<h3 class="h5 mb-1" id="sf-' . self::e($alias) . '-rv-' . $p . '">' . self::e($name) . '</h3>'
                . '<button type="submit" name="sf_nav" value="goto-' . $p . '" class="btn btn-link p-0 sf-review-change" formnovalidate>'
                . self::e(Text::_('COM_SQUIDFORM_REVIEW_CHANGE')) . '<span class="visually-hidden"> ' . self::e($name) . '</span></button></div>'
                . ($rows !== '' ? '<dl class="row mb-0 sf-review-list">' . $rows . '</dl>' : '<p class="text-body-secondary">' . self::e(Text::_('COM_SQUIDFORM_REVIEW_NONE')) . '</p>')
                . '</section>';
        }

        $captcha = ($cfg = Captcha::config($schema, $user)) ? Captcha::render($alias, $cfg, FormGuard::$lastNonce, $errors[Captcha::FIELD] ?? null) : '';
        $action  = Route::_('index.php?option=com_squidform&task=form.submit', false);
        $return  = base64_encode($returnUrl ?? Uri::getInstance()->toString());
        $send    = (string) ($schema->setting('submit_label', '') ?: Text::_('COM_SQUIDFORM_SUBMIT'));
        $head    = !empty($schema->setting('show_title', false)) ? '<h2 class="sf-title">' . self::e($form['title']) . '</h2>' : '';

        if ($errors) {
            $state['errors'] = [];
            State::put($alias, $state);
        }

        self::assets();
        self::ownCode($schema, $alias, true);

        $ph->onPage = true;

        return '<div class="squidform sf-review' . $look . '" id="sf-' . self::e($alias) . '">' . self::testBanner($schema) . $head . $out
            . self::errorSummary($errors, $schema, 'sf-' . $alias)
            . '<h2 class="h4 sf-review-title" tabindex="-1"' . ($errors ? '' : ' data-sf-focus="1"') . '>' . self::e($title) . '</h2>'
            . '<div class="sf-review-intro mb-3">' . self::safeHtml($ph->replace($intro)) . '</div>'
            . '<form class="sf-form" method="post" action="' . self::e($action) . '" novalidate data-sf-alias="' . self::e($alias) . '" aria-label="' . self::e($form['title']) . '">'
            . $body . $captcha . $guard
            . '<input type="hidden" name="sf_form" value="' . self::e($alias) . '">'
            . '<input type="hidden" name="sf_page" value="' . $last . '">'
            . '<input type="hidden" name="sf_review" value="1">'
            . '<input type="hidden" name="sf_return" value="' . self::e($return) . '">'
            . HTMLHelper::_('form.token')
            . '<div class="sf-actions d-flex gap-2"><button type="submit" name="sf_nav" value="send" class="btn btn-primary">' . self::e($send) . '</button></div>'
            . '</form></div>';
    }

    /** One answer on the review page; a repeating group lists its rows. */
    private static function reviewValue(array $field, mixed $value): string
    {
        if (($field['type'] ?? '') !== 'repeater') {
            return Display::html($field, $value);
        }

        $rows = \is_array($value) ? array_values($value) : [];

        if (!$rows) {
            return '—';
        }

        $out = '<ol class="mb-0 ps-3">';

        foreach ($rows as $row) {
            $parts = [];

            foreach ($field['fields'] ?? [] as $c) {
                if (\is_array($row) && \array_key_exists($c['key'], $row) && FieldRegistry::get($c['type'])->storesValue() && ($c['type'] ?? '') !== 'hidden') {
                    $parts[] = self::e($c['label'] ?? $c['key']) . ': ' . Display::html($c, $row[$c['key']]);
                }
            }

            $out .= '<li>' . implode('; ', $parts) . '</li>';
        }

        return $out . '</ol>';
    }

    /**
     * The form's own CSS and JavaScript (0.9.0, settings.css and settings.js),
     * added to the page through Joomla's asset manager (so a CSP nonce
     * applies). The JavaScript runs once the page is ready, with "form" (the
     * <form>) and "root" (its wrapper, #sf-ALIAS); a mistake in it is logged
     * to the browser console and doesn't stop the form.
     *
     * The preview shows the CSS but never runs the JavaScript: the preview is
     * an admin page, and code written by a form editor must not run there.
     */
    public static function ownCode(Schema $schema, string $alias, bool $withJs): string
    {
        $css = trim((string) $schema->setting('css', ''));
        $js  = trim((string) $schema->setting('js', ''));
        $doc = Factory::getApplication()->getDocument();
        $wa  = $doc && method_exists($doc, 'getWebAssetManager') ? $doc->getWebAssetManager() : null;

        if ($css !== '' && $wa && $withJs) {
            $wa->addInlineStyle('/* SquidForm: ' . $alias . " */\n" . $css);
        }

        if ($js !== '' && $wa && $withJs) {
            $id = json_encode('sf-' . $alias);
            // 0.14.0: sf.set('name', value) fills {js:name} wherever it's written in
            // the form's text (labels, help, text blocks, intro). Plain text only,
            // and only on this page: it never reaches emails, saved data or checks.
            $sf = 'const sf = { set(name, value) { name = String(name); if (!/^[a-z][a-z0-9_]{0,39}$/.test(name)) return;'
                . ' const tag = "{js:" + name + "}"; const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT); const hits = [];'
                . ' while (walker.nextNode()) { if (walker.currentNode.nodeValue.includes(tag)) hits.push(walker.currentNode); }'
                . ' hits.forEach((t) => { const parts = t.nodeValue.split(tag); const frag = document.createDocumentFragment();'
                . ' parts.forEach((p, i) => { if (i) { const s = document.createElement("span"); s.className = "sf-js-value"; s.dataset.sfJs = name; frag.append(s); } frag.append(p); }); t.replaceWith(frag); });'
                . ' root.querySelectorAll("[data-sf-js]").forEach((s) => { if (s.dataset.sfJs === name) s.textContent = value == null ? "" : String(value); }); } };';
            $wa->addInlineScript('(() => { const run = () => { const root = document.getElementById(' . $id . '); if (!root) return; const form = root.querySelector("form.sf-form"); ' . $sf
                . " try { (function (form, root, sf) {\n" . $js . "\n}).call(root, form, root, sf); } catch (e) { console.error('SquidForm: this form\'s own JavaScript failed (" . addslashes($alias) . ")', e); } };"
                . " document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', run) : run(); })();", ['position' => 'after']);
        }

        // The preview gets the CSS in the page itself.
        return !$withJs && $css !== '' ? '<style>' . $css . '</style>' : '';
    }

    /**
     * Preview (0.7.0): one page of a definition that isn't published, as people
     * will see it, in the admin. No token, no submit and nothing saved; the
     * browser script still shows and hides fields and adds repeater rows.
     *
     * @param array{id: int, title: string, alias: string} $form
     */
    public static function preview(Schema $schema, array $form, int $page, User $user, string $optionsUrl, ?string $lookOverride = null, ?string $lang = null): string
    {
        self::loadLanguage();
        $look = Looks::classes(Looks::of($schema, $lookOverride));

        $alias  = $form['alias'];
        $page   = min(max(0, $page), $schema->pageCount() - 1);
        $data   = [];
        $errors = [];
        $ph    = new Placeholders($schema, $user, $data, [], [], $form);
        $locked = [];

        foreach ($schema->pageFields($page) as $f) {
            if (isset($f['prefill'])) {
                $data[$f['key']] = self::prefillValue($f, $ph);
            } elseif (\array_key_exists('default', $f)) {
                $data[$f['key']] = $f['default'];
            }

            if (self::prefillLocked($f, $user)) {
                $locked[$f['key']] = true;
            }
        }

        $ph = new Placeholders($schema, $user, $data, [], [], $form);
        $ph->onPage = true;
        $rc = new RenderContext($schema, $alias, $ph, [], $data, $locked);

        $fieldsHtml = Layout::render(
            $schema->pageFields($page),
            static fn (array $f) => FieldRegistry::get($f['type'])->render($f, $data[$f['key']] ?? null, $rc),
            static fn (string $k) => isset($errors[$k]) || (bool) array_filter(array_keys($errors), static fn ($p) => str_starts_with((string) $p, $k . '.')),
            'sf-' . $alias
        );

        $pageDef  = $schema->page($page);
        $count    = $schema->pageCount();
        $isLast   = $page === $count - 1;
        $submitTx = (string) ($pageDef['submit_label'] ?? ($isLast ? $schema->setting('submit_label', Text::_('COM_SQUIDFORM_SUBMIT')) : Text::_('COM_SQUIDFORM_NEXT')));

        $progress = $count > 1 ? '<p class="sf-progress text-body-secondary">'
            . self::e(Text::sprintf('COM_SQUIDFORM_STEP_OF', $page + 1, $count))
            . (!empty($pageDef['title']) ? ' — <strong>' . self::e($pageDef['title']) . '</strong>' : '') . '</p>' : '';

        // The human check is described rather than run: there's no token here.
        $captcha = '';

        if ($isLast && ($cfg = Captcha::config($schema, $user))) {
            $captcha = '<div class="alert alert-secondary small">' . self::e(Text::_('COM_SQUIDFORM_PREVIEW_CAPTCHA_' . strtoupper((string) $cfg['type'])))
                . ($cfg['type'] === 'question' ? ' <strong>' . self::e((string) ($cfg['question'] ?? '')) . '</strong>' : '') . '</div>';
        }

        $buttons = '<div class="sf-actions d-flex gap-2">'
            . ($page > 0 ? '<button type="button" class="btn btn-secondary" disabled>' . self::e(Text::_('COM_SQUIDFORM_BACK')) . '</button>' : '')
            . '<button type="button" class="btn btn-primary" disabled>' . self::e($submitTx) . '</button></div>';

        $title = !empty($schema->setting('show_title', false)) ? '<h2 class="sf-title">' . self::e($form['title']) . '</h2>' : '';
        $ph->onPage = true;
        $intro = $schema->setting('intro') ? '<div class="sf-intro mb-3">' . self::safeHtml($ph->replace((string) $schema->setting('intro'))) . '</div>' : '';

        self::assets();
        $own = self::ownCode($schema, $alias, false);

        if (trim((string) $schema->setting('js', '')) !== '') {
            $own .= '<p class="alert alert-info small">' . self::e(Text::_('COM_SQUIDFORM_PREVIEW_NO_JS')) . '</p>';
        }

        return $own . '<div' . ($lang !== null && $lang !== Lang::default() ? ' lang="' . self::e(Lang::htmlLang($lang)) . '"' : '') . ' class="squidform' . $look . '" id="sf-' . self::e($alias) . '">' . self::testBanner($schema) . $title . $progress . $intro
            . '<form class="sf-form" action="#" novalidate onsubmit="return false"'
            . ' data-sf-alias="' . self::e($alias) . '" data-sf-options-url="' . self::e($optionsUrl) . '"'
            . ' data-sf-known="{}" aria-label="' . self::e($form['title']) . '">'
            . $fieldsHtml . $captcha . $buttons
            . '</form></div>';
    }

    /** Load component strings for the site wherever the form is placed. */
    public static function loadLanguage(): void
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_squidform', JPATH_ADMINISTRATOR . '/components/com_squidform')
            || $lang->load('com_squidform', JPATH_ADMINISTRATOR);
        $lang->load('com_squidform', JPATH_SITE . '/components/com_squidform')
            || $lang->load('com_squidform', JPATH_SITE);
    }

    public static function assets(): void
    {
        $doc = Factory::getApplication()->getDocument();

        if (!$doc || !method_exists($doc, 'getWebAssetManager')) {
            return;
        }

        $wa = $doc->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_squidform');
        $wa->useStyle('com_squidform.form')->useScript('com_squidform.form');

        // Keeps the session alive while someone fills a long form.
        if ($wa->assetExists('script', 'keepalive')) {
            $wa->useScript('keepalive');
        }

        foreach (['SIG_DRAWN', 'SIG_CLEARED', 'LOOKUP_N', 'LOOKUP_1', 'LOOKUP_NONE', 'LOOKUP_MORE', 'FILE_TOO_BIG', 'FILE_TOO_MANY', 'RANK_MOVED', 'RANK_UP', 'RANK_DOWN', 'SEARCH_ALL', 'SEARCH_N', 'SEARCH_N_MORE', 'SEARCH_CHOSEN', 'REVEAL_SHOW', 'REVEAL_HIDE', 'DATE_FROM', 'AGE_YEARS', 'AGE_YEARS_1', 'AGE_MONTHS', 'AGE_MONTHS_1', 'AGE_DAYS', 'AGE_DAYS_1', 'AGE_JOIN'] as $s) {
            Text::script('COM_SQUIDFORM_' . $s);
        }

        Text::script('COM_SQUIDFORM_ROW_ADDED');
        Text::script('COM_SQUIDFORM_ROW_REMOVED');
        Text::script('COM_SQUIDFORM_MAX_ROWS_REACHED');
        Text::script('COM_SQUIDFORM_OPTIONS_LOADED');
        Text::script('COM_SQUIDFORM_SELECT');
        Text::script('COM_SQUIDFORM_COUNTER_OF');
        Text::script('COM_SQUIDFORM_COUNTER_N');
        Text::script('COM_SQUIDFORM_COUNTER_N_1');
        Text::script('COM_SQUIDFORM_COUNTER_LEFT');
        Text::script('COM_SQUIDFORM_COUNTER_FULL');
    }

    /**
     * Error list linking to each control. $idPrefix is the RenderContext id
     * prefix ("sf-<alias>" on forms, "sf-edit" on the edit screen).
     *
     * @param array<string, string> $errors
     */
    public static function errorSummary(array $errors, Schema $schema, string $idPrefix): string
    {
        if (!$errors) {
            return '';
        }

        $items = '';

        foreach ($errors as $path => $msg) {
            if ($path === '_form') {
                $items .= '<li>' . self::e($msg) . '</li>';
                continue;
            }

            // The human check: its container (the question's input for a question).
            if ($path === Captcha::FIELD) {
                $items .= '<li><a href="#' . self::e($idPrefix . '-' . $path . '-group') . '">' . self::e($msg) . '</a></li>';
                continue;
            }

            // Link to the control so keyboard and screen-reader users can jump to it.
            $target = $idPrefix . '-' . str_replace('.', '-', $path);
            $field  = $schema->field((string) substr($path, (int) strrpos('.' . $path, '.')));
            $grouped = $field && \in_array($field['type'] ?? '', ['radios', 'checkboxes', 'repeater', 'rating', 'signature', 'grid', 'name', 'address', 'ranking', 'daterange', 'phone', 'consent', 'imagechoice'], true);
            $items .= '<li><a href="#' . self::e($target . ($grouped ? '-group' : '')) . '">' . self::e($msg) . '</a></li>';
        }

        return '<div class="alert alert-danger sf-error-summary" role="alert" tabindex="-1">'
            . '<p class="fw-semibold mb-1">' . self::e(Text::_('COM_SQUIDFORM_ERRORS_TITLE')) . '</p><ul class="mb-0">' . $items . '</ul></div>';
    }

    private static function notice(string $html, string $type, bool $focus = false): string
    {
        $cls = ['success' => 'alert-success', 'info' => 'alert-info', 'warning' => 'alert-warning', 'error' => 'alert-danger'][$type] ?? 'alert-info';

        return '<div class="alert ' . $cls . ' sf-message" role="' . ($type === 'error' ? 'alert' : 'status') . '"'
            . ($focus ? ' tabindex="-1" data-sf-focus="1"' : '') . '>' . $html . '</div>';
    }

    /** @param list<array<string, mixed>>|null $debug */
    private static function debugTable(?array $debug): string
    {
        if (!$debug) {
            return '';
        }

        $rows = '';

        foreach ($debug as $d) {
            $rows .= '<tr><td>' . self::e($d['action']) . '</td><td>' . self::e($d['type']) . '</td><td>' . self::e($d['status'])
                . '</td><td>' . self::e($d['note']) . '</td><td>' . self::e($d['ms']) . '</td></tr>';
        }

        return '<details class="sf-debug mt-3"><summary>SquidForm debug</summary><table class="table table-sm">'
            . '<thead><tr><th scope="col">Action</th><th scope="col">Type</th><th scope="col">Result</th><th scope="col">Note</th><th scope="col">ms</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></details>';
    }

    /**
     * Values entered on other pages, for browser-side conditions that refer
     * to an earlier page (the server already knows them), and calculations
     * that add up a repeating group on an earlier page (0.10.0). Top-level
     * fields only; the person's own data.
     *
     * @param   array<string, mixed>  $data
     *
     * @return  array<string, mixed>
     */
    private static function otherPageValues(Schema $schema, int $page, array $data): array
    {
        $own = [];

        foreach ($schema->pageFields($page) as $f) {
            $own[$f['key']] = true;
        }

        $out = [];

        foreach ($data as $k => $v) {
            if (!isset($own[$k])) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Admin-authored HTML: safe tags only, no scripts or event handlers. */
    private static function safeHtml(string $html): string
    {
        return \SquidForm\Component\SquidForm\Administrator\Engine\Html::clean((string) $html);
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** Whether the current user may see debug output. */
    public static function canDebug(Schema $schema): bool
    {
        $user = Factory::getApplication()->getIdentity();

        return $user && (bool) $schema->setting('debug', false)
            && ($user->authorise('squidform.dev', 'com_squidform') || $user->authorise('core.admin'));
    }

    public static function params(): \Joomla\Registry\Registry
    {
        return ComponentHelper::getParams('com_squidform');
    }
}
