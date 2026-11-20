/**
 * SquidForm builder: form Settings (button text, messages, human check,
 * rate limit, your own values) and the Viewer block (list columns, filters,
 * sort, page size).
 */
export function install(B, which) {
  const { h } = B;
  const S = B.state;
  const KNOWN = ['submit_label', 'success_message', 'show_title', 'intro', 'rate_limit', 'captcha', 'debug', 'css', 'js',
    'opens', 'closes', 'max_submissions', 'max_per_user', 'before_message', 'closed_message', 'full_message', 'user_message', 'review', 'review_title', 'review_intro',
    'save_later', 'save_later_guests', 'copy_pdf', 'look', 'test_mode', 'test_email', 'languages', 'shared_fields'];
  B.KNOWN_SETTINGS = KNOWN;

  const setting = (k, v, o) => B.update((m) => {
    m.settings ||= {};
    if (v === undefined || v === '' || v === false) delete m.settings[k]; else m.settings[k] = v;
  }, o);
  const typing = (k) => (v) => setting(k, v, { structural: false, merge: 'set.' + k });

  function settings() {
    const st = S.model.settings || {};
    const wrap = h('div.sfb-wide', h('h3.h5', 'Form settings'));
    const probs = B.state.problems.filter((p) => /^(settings|schema|"settings")/.test(p.path || '') || /^"?settings/.test(p.message));
    if (probs.length) wrap.append(h('div.alert.alert-danger.py-2.small', probs.map((p) => h('div', p.message))));

    wrap.append(h('fieldset', h('legend.h6', 'Text'),
      B.row('Button on the last page', B.input(st.submit_label, typing('submit_label'), { placeholder: 'Submit', dataset: { fid: 'st-submit' } })),
      B.row('Message after sending', B.textarea(st.success_message, typing('success_message'), { rows: 2, placeholder: 'Thank you. Your form has been sent.', dataset: { fid: 'st-success' } }),
        'Shown when no “Show a message” action runs.'),
      B.row('Text above the form', B.textarea(st.intro, typing('intro'), { rows: 3, dataset: { fid: 'st-intro' } }), 'Placeholders and simple HTML are allowed.'), B.htmlInfo(),
      B.check('Show the form’s title above it', st.show_title, (v) => setting('show_title', v || undefined))));

    // Test mode (0.15.0)
    {
      const n = B.catalog.testCount || 0;
      const clear = n && B.catalog.canPurge ? B.button(`Clear ${n} test submission${n === 1 ? '' : 's'}`, () => {
        if (!window.confirm(`Permanently delete this form’s ${n} test submission${n === 1 ? '' : 's'}? This can’t be undone.`)) return;
        const f = document.createElement('form');
        f.method = 'post';
        f.action = 'index.php?option=com_squidform&task=form.cleartests&id=' + encodeURIComponent(B.opts.id || 0);
        f.innerHTML = `<input type="hidden" name="${Joomla.getOptions('csrf.token')}" value="1">`;
        document.body.append(f);
        f.submit();
      }, 'btn-outline-danger btn-sm', { dataset: { fid: 'st-test-clear' } }) : null;
      wrap.append(h('fieldset.mt-3', h('legend.h6', 'Test mode'),
        B.check('Test mode', st.test_mode, (v) => setting('test_mode', v || undefined),
          'Everyone sees a “Test mode” banner. Emails go only to the person testing (marked [TEST], saying who they would have gone to); confirmation emails go to the address typed in. Submissions are marked as tests: left out of site lists and exports.'),
        st.test_mode ? B.row('Test emails when nobody is signed in', B.input(st.test_email || '', (v) => setting('test_email', v.trim() || undefined), { type: 'email', dataset: { fid: 'st-test-email' } }), 'Empty: the site’s From address.') : null,
        n ? h('p.form-text', `${n} test submission${n === 1 ? '' : 's'} saved. `, clear) : null));
    }

    // Looks (0.14.1)
    const LOOKS = { simple: 'Simple', elegant: 'Elegant', chaotic: 'Chaotic', civic: 'Civic', friendly: 'Friendly' };
    const site = LOOKS[B.catalog.defaultLook] || 'Simple';
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Look'),
      B.row('Look', B.select(st.look === 'plain' ? 'simple' : (st.look || ''), [{ value: '', label: `The site’s default (${site})` }].concat(Object.entries(LOOKS).map(([value, label]) => ({ value, label }))),
        (v) => setting('look', v || undefined), { dataset: { fid: 'st-look' } }),
        'Simple is your template’s own look. Elegant, Chaotic, Civic and Friendly add their own style inside the form, in light and dark. Press Preview to try each one on this form. Your own CSS still applies on top.')));

    // Languages (0.15.0)
    {
      const all = (B.catalog.languages || []).filter((l) => l.tag !== B.catalog.langBase);
      const on = new Set(Array.isArray(st.languages) ? st.languages : []);
      const base = (B.catalog.languages || []).find((l) => l.tag === B.catalog.langBase);
      const pick = (tag, yes) => {
        const next = all.map((l) => l.tag).filter((t) => (t === tag ? yes : on.has(t)));
        setting('languages', next.length ? next : undefined);
      };
      const box = (l) => h('div', { dataset: { fid: `st-lang-${l.tag}` } }, B.check(l.joke ? `${l.name} (“${l.own}”)` : (l.own !== l.name ? `${l.name} (${l.own})` : l.name), on.has(l.tag), (v) => pick(l.tag, v)));
      const real = all.filter((l) => !l.joke);
      const fun = all.filter((l) => l.joke);
      wrap.append(h('fieldset.mt-3', h('legend.h6', 'Languages'),
        all.length ? h('p.form-text.mt-0', `Visitors get ${base ? base.name : 'the site’s language'} (or the page’s own language, if it’s one of these). Tick more and they get language tabs above the form. SquidForm’s own words (buttons, errors, help) come translated; this form’s words come from the Translations tab, and anything left empty there stays as you wrote it.`)
          : h('p.form-text.mt-0', 'Options → Languages for visitors offers no other languages.'),
        ...real.map(box),
        fun.length ? h('p.form-text.mb-1.mt-2', 'Just for fun: these make up their own words for everything, labels and choices too. Emails stay in the site’s language.') : null,
        ...fun.map(box)));
    }

    // Sharing (1.0.3): fields other forms may offer as choices.
    {
      const can = B.allFields().filter((x) => !x.parent && x.f.key && !['section', 'repeater', 'html', 'divider', 'esign', 'file', 'signature', 'password'].includes(x.f.type));
      const on = new Set(Array.isArray(st.shared_fields) ? st.shared_fields : []);
      const pick = (key, yes) => {
        const next = can.map((x) => x.f.key).filter((k) => (k === key ? yes : on.has(k)));
        setting('shared_fields', next.length ? next : undefined);
      };
      wrap.append(h('fieldset.mt-3', h('legend.h6', 'Share as choices'),
        h('p.form-text.mt-0', 'Other forms can offer this form’s answers as choices (a dropdown of event categories, say): only the fields ticked here, and only from submissions that were sent or approved, aren’t archived and aren’t tests. Nothing is shared until you tick something.'),
        can.length ? h('div.sfb-cols', ...can.map((x) => h('div', { dataset: { fid: `st-share-${x.f.key}` } }, B.check(`${x.f.label || x.f.key} (${x.f.key})`, on.has(x.f.key), (v) => pick(x.f.key, v)))))
          : h('p.form-text', 'Add some fields first.')));
    }

    // Check your answers (0.10.0)
    const rv = [B.check('Show “Check your answers” before sending', st.review, (v) => setting('review', v || undefined),
      'After the last page, people see every answer with a Change button for each page, then send. The human check moves there.')];
    if (st.review) {
      rv.push(B.row('Heading', B.input(st.review_title, typing('review_title'), { placeholder: 'Check your answers', dataset: { fid: 'st-rv-title' } })));
      rv.push(B.row('Text under the heading', B.textarea(st.review_intro, typing('review_intro'), { rows: 2, placeholder: 'Check your answers before you send them. To change something, choose Change next to it.', dataset: { fid: 'st-rv-intro' } })));
    }
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Before sending'), ...rv));

    // Save and finish later, and a copy after sending (0.11.0)
    const sl = [B.check('Save and finish later', st.save_later, (v) => B.update((m) => {
      m.settings ||= {};
      if (v) m.settings.save_later = true; else { delete m.settings.save_later; delete m.settings.save_later_guests; }
    }), 'A “Save and finish later” button on every page. Signed-in people come back to the form and carry on; nothing is checked until they send it.')];
    if (st.save_later) {
      sl.push(B.check('Guests too, with an emailed link', st.save_later_guests, (v) => setting('save_later_guests', v || undefined),
        'Guests type an email address and get a link to carry on. Anyone with the link can see what was typed, so leave this off for forms that ask for anything sensitive.'));
      sl.push(h('p.form-text', 'Drafts and their files are deleted after the days set in Options → Saving and PDFs (30 by default), or when the form is sent.'));
    }
    sl.push(B.check('Offer a PDF copy after sending', st.copy_pdf, (v) => setting('copy_pdf', v || undefined),
      'After sending, a “Download a copy of your answers” button (for an hour, in the same browser). Needs a Save Data action. Hidden fields and “Hide what’s typed” answers aren’t in the copy.'));
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Saving and copies'), ...sl));

    // Open and close, limits (0.10.0)
    const num = (k, label, help, attrs) => B.row(label, B.number(st[k], (v) => setting(k, v === undefined ? undefined : Math.trunc(v), { structural: false, merge: 'set.' + k }), { min: 1, dataset: { fid: 'st-' + k }, ...attrs }), help);
    const when = (k, label) => B.row(label, B.input(st[k], (v) => setting(k, v || undefined, { structural: false, merge: 'set.' + k }), { type: 'datetime-local', dataset: { fid: 'st-' + k } }));
    const words = (k, label, ph) => B.row(label, B.textarea(st[k], typing(k), { rows: 2, placeholder: ph, dataset: { fid: 'st-' + k } }));
    const anyLimit = st.opens || st.closes || st.max_submissions || st.max_per_user;
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Open, close and limits'),
      h('div.sfb-inline', when('opens', 'Opens'), when('closes', 'Closes')),
      h('p.form-text', 'In the site’s time zone (System → Global Configuration → Server). Empty: always open.'),
      h('div.sfb-inline',
        num('max_submissions', 'Most responses in total', 'Every saved submission counts, archived ones too. Delete one to free a place.', { max: 1000000 }),
        num('max_per_user', 'Most per signed-in person', 'Guests aren’t counted: to limit everyone, make the form for signed-in people only.', { max: 1000 })),
      h('details.mt-2', { open: !!(st.before_message || st.closed_message || st.full_message || st.user_message) }, h('summary.small', 'Your own words for these messages'),
        anyLimit ? null : h('p.form-text', 'Set a date or a limit above first.'),
        words('before_message', 'Before it opens', 'This form opens on 1 December 2026 09:00.'),
        words('closed_message', 'After it closes', 'This form is closed.'),
        words('full_message', 'When it’s full', 'This form has had all the responses it can take.'),
        words('user_message', 'When someone has sent it already', 'You’ve already sent this form.'),
        h('p.form-text', 'Simple HTML is allowed. Choices can hold a number of places too: see a choice field’s Choices.'))));

    // Link helper (0.10.2): a link that fills in fields, for articles, emails and other sites.
    wrap.append(linkHelper());

    // Human check
    const c = st.captcha;
    const ctype = !c ? '' : (typeof c === 'string' ? c : c.type || 'pow');
    const cobj = typeof c === 'object' && c ? c : {};
    const capKids = [B.row('Check that a guest is a person', B.select(ctype, [
      { value: '', label: 'No extra check (the hidden checks still run)' },
      { value: 'pow', label: 'Invisible check (works offline, nothing to click)' },
      { value: 'joomla', label: 'The site’s captcha plugin' },
      { value: 'question', label: 'A question' },
    ], (v) => B.update((m) => {
      m.settings ||= {};
      if (!v) delete m.settings.captcha;
      else if (v === 'question') m.settings.captcha = { type: 'question', question: 'What colour is grass?', answers: ['green'] };
      else m.settings.captcha = v;
    }), { dataset: { fid: 'st-cap' } }))];
    const capObj = (fn, o) => B.update((m) => {
      let cur = m.settings.captcha;
      cur = typeof cur === 'string' ? { type: cur } : { ...cur };
      fn(cur);
      const keys = Object.keys(cur).filter((k) => k !== 'type');
      m.settings.captcha = keys.length ? cur : cur.type;
    }, o);
    if (ctype === 'joomla') {
      capKids.push(B.row('Plugin', B.select(cobj.plugin || '', [{ value: '', label: 'The site default (Global Configuration)' }, ...B.catalog.captchas.map((p) => ({ value: p.element, label: p.name }))],
        (v) => capObj((x) => { if (v) x.plugin = v; else delete x.plugin; }), { dataset: { fid: 'st-cap-plugin' } }), 'If no captcha is set up, the invisible check is used instead.'));
    }
    if (ctype === 'question') {
      capKids.push(B.row('Question', B.input(cobj.question, (v) => capObj((x) => { x.question = v; }, { structural: false, merge: 'capq' }), { dataset: { fid: 'st-cap-q' } })));
      capKids.push(B.row('Accepted answers', B.input((cobj.answers || []).join(', '), (v) => capObj((x) => { x.answers = v.split(',').map((s) => s.trim()).filter(Boolean); }, { structural: false, merge: 'capa' }), { dataset: { fid: 'st-cap-a' } }),
        'Separate with commas. Capitals, extra spaces and a final full stop don’t matter.'));
    }
    if (ctype) capKids.push(B.check('Also for signed-in people', cobj.guests_only === false, (v) => capObj((x) => { if (v) x.guests_only = false; else delete x.guests_only; })));
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Human check'), ...capKids));

    // Rate limit
    const rl = st.rate_limit;
    const rmode = rl === false ? 'off' : (rl && typeof rl === 'object' ? 'custom' : 'site');
    const rKids = [B.row('How often one person may send it', B.select(rmode, [
      { value: 'site', label: 'The site default (Options → Security)' }, { value: 'custom', label: 'A limit for this form' }, { value: 'off', label: 'No limit (for example a staff data-entry form)' },
    ], (v) => B.update((m) => {
      m.settings ||= {};
      if (v === 'site') delete m.settings.rate_limit;
      if (v === 'off') m.settings.rate_limit = false;
      if (v === 'custom') m.settings.rate_limit = { max: 5, minutes: 10 };
    }), { dataset: { fid: 'st-rate' } }))];
    if (rmode === 'custom') {
      const upd = (k) => (v) => B.update((m) => { m.settings.rate_limit[k] = v; }, { structural: false, merge: 'rate.' + k });
      rKids.push(h('div.sfb-inline', B.row('Times', B.number(rl.max, upd('max'), { min: 1, max: 1000, dataset: { fid: 'st-rate-max' } })),
        B.row('In minutes', B.number(rl.minutes, upd('minutes'), { min: 1, max: 1440, dataset: { fid: 'st-rate-min' } }))));
    }
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Rate limit'), ...rKids));

    // Your own CSS and JavaScript (0.9.0), for everyone who edits forms, marked advanced.
    const sel = `#sf-${B.opts.alias || 'your-alias'}`;
    const code = (k, label, help, ph) => B.row(label, B.textarea(st[k], typing(k), { rows: 8, class: 'font-monospace', spellcheck: 'false', placeholder: ph, dataset: { fid: 'st-' + k } }), help);
    wrap.append(h('details.sfb-sec.mt-3', { open: !!(st.css || st.js) },
      h('summary', 'Your own CSS and JavaScript (advanced)'),
      h('div.sfb-sec-body',
        h('p.form-text', 'Most forms never need these. They’re here for small touches the builder doesn’t have.'),
        code('css', 'CSS', h('span', 'Added to every page that shows this form. Start each rule with ', h('code', sel), ' so it changes only this form, not the rest of the site.'),
          `${sel} .sf-section-box { border-color: #2a69b8; }`),
        code('js', 'JavaScript', h('span', 'Runs in visitors’ browsers when the page is ready, with ', h('code', 'form'), ' (the form) and ', h('code', 'root'), ' (', h('code', sel), '). A mistake is logged to the browser console and doesn’t stop the form. ',
          h('strong', 'It runs on the site only, never in Preview. '), 'The server still checks every answer, so it can’t loosen a rule.'),
          "form.addEventListener('change', () => { /* … */ });"),
        h('div.alert.alert-warning.small.py-2.mb-0', 'Anyone who can edit this form can add JavaScript that runs on your site’s pages. Only let people you trust edit forms.'))));

    // Your own values
    const own = Object.entries(st).filter(([k]) => !KNOWN.includes(k));
    const list = h('div.sfb-own');
    own.forEach(([k, v]) => {
      const simple = ['string', 'number', 'boolean'].includes(typeof v);
      list.append(h('div.sfb-inline.align-items-end',
        B.row('Name', B.input(k, () => {}, { class: 'font-monospace', readonly: true, dataset: { fid: `own-${k}-k` } })),
        simple ? B.row('Value', B.input(String(v), (nv) => setting(k, nv === '' ? undefined : nv, { structural: false, merge: 'own.' + k }), { dataset: { fid: `own-${k}-v` } }))
          : h('p.small.text-body-secondary', 'A list or object: edit it in JSON.'),
        B.iconButton('✕', `Remove ${k}`, () => setting(k, undefined), { dataset: { fid: `own-${k}-x` } })));
    });
    const nk = B.input('', () => {}, { class: 'font-monospace', placeholder: 'admin_email', 'aria-label': 'New value name', dataset: { fid: 'own-new' } });
    const msg = h('div.form-text');
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Your own values'),
      h('p.form-text', 'Use them anywhere with {setting:name}, for example an address to email.'), list,
      h('div.sfb-rep-add', nk, B.button('Add', () => {
        const k = nk.value.trim();
        if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(k) || KNOWN.includes(k) || k in st) { msg.textContent = 'A new name: a letter first, then letters, digits or _.'; msg.classList.add('text-danger'); return; }
        setting(k, 'change-me');
      }, 'btn-outline-secondary', { dataset: { fid: 'own-add' } })), msg));

    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Developers'),
      B.check('Show the action trail under the form', st.debug, (v) => setting('debug', v || undefined), 'Only Super Users and people with Developer Tools see it.')));
    return wrap;
  }

  /* ---------------------------------------------------------- link helper */

  const linkValues = {};
  let linkBase = null;

  function linkHelper() {
    const fs = h('fieldset.mt-3.sfb-linkhelper', h('legend.h6', 'Links that fill in the form'));
    const fields = B.allFields().filter((x) => !x.parent && typeof x.f.url_param === 'string' && x.f.url_param !== '');
    if (!fields.length) {
      fs.append(h('p.form-text', 'No field can be filled from a link yet. On a field, tick “Can be filled from the web address” (under “Where the value comes from”), then come back here to build a link.'));
      return fs;
    }
    const bases = (B.opts.formLinks || []).slice();
    if (!bases.length) bases.push({ label: 'The form’s direct address (save the form first for its menu items)', url: `${(B.catalog.siteRoot || '/')}index.php?option=com_squidform&view=form&form=${encodeURIComponent(B.opts.alias || 'your-alias')}` });
    if (linkBase === null || !bases.some((b) => b.url === linkBase)) linkBase = bases[0].url;

    const out = B.input('', () => {}, { readonly: true, class: 'font-monospace', 'aria-label': 'The link', dataset: { fid: 'lk-out' } });
    const open = h('a.btn.btn-sm.btn-outline-secondary', { target: '_blank', rel: 'noopener', dataset: { fid: 'lk-open' } }, 'Try it', h('span.visually-hidden', ' (opens in a new tab)'));
    const status = h('span.small.text-body-secondary', { 'aria-live': 'polite' });
    const build = () => {
      const parts = [];
      fields.forEach((x) => {
        const v = linkValues[x.f.key];
        const list = Array.isArray(v) ? v.filter((y) => y !== '') : (v === undefined || v === '' ? [] : [v]);
        if (list.length) parts.push(`${encodeURIComponent(x.f.url_param)}=${list.map((y) => encodeURIComponent(y)).join(',')}`);
      });
      const url = linkBase + (parts.length ? (linkBase.includes('?') ? '&' : '?') + parts.join('&') : '');
      out.value = url;
      open.href = url;
      status.textContent = '';
    };

    fs.append(B.row('Starting from', B.select(linkBase, bases.map((b) => ({ value: b.url, label: b.label })), (v) => { linkBase = v; build(); }, { dataset: { fid: 'lk-base' } }),
      'A menu item’s address is the friendly one. Links work from articles, emails and other sites.'));

    fields.forEach((x) => {
      const f = x.f;
      const set = (v) => { linkValues[f.key] = v; build(); };
      const name = `${f.label || f.key} (?${f.url_param}=)`;
      const opts = B.staticOptions(f);
      let control;
      if (B.MULTI.includes(f.type) && opts.length) {
        const cur = new Set(linkValues[f.key] || []);
        control = h('div', ...opts.map((o) => B.check(o.label + (o.value !== o.label ? ` (${o.value})` : ''), cur.has(o.value), (on) => {
          const now = new Set(linkValues[f.key] || []);
          if (on) now.add(o.value); else now.delete(o.value);
          set(opts.map((y) => y.value).filter((y) => now.has(y)));
        })));
        fs.append(h('fieldset.mb-2', h('legend.form-label.small.mb-1', name), control));
        return;
      }
      if (opts.length) {
        control = B.select(linkValues[f.key] || '', [{ value: '', label: '— leave it empty —' }, ...opts.map((o) => ({ value: o.value, label: o.label + (o.value !== o.label ? ` (${o.value})` : '') }))], set, { dataset: { fid: `lk-${f.key}` } });
      } else if (f.type === 'checkbox') {
        control = B.select(linkValues[f.key] || '', [{ value: '', label: '— leave it empty —' }, { value: '1', label: 'Ticked' }], set, { dataset: { fid: `lk-${f.key}` } });
      } else {
        control = B.input(linkValues[f.key] || '', set, { type: ['number', 'date', 'time'].includes(f.type) ? f.type : 'text', dataset: { fid: `lk-${f.key}` } });
      }
      fs.append(B.row(name, control, f.options && !opts.length ? 'Its choices come from a file or table: type the stored value.' : null));
    });

    const copy = B.button('Copy the link', async () => {
      try { await navigator.clipboard.writeText(out.value); status.textContent = 'Copied.'; } catch (e) { out.select(); status.textContent = 'Press Ctrl+C (⌘C) to copy it.'; }
    }, 'btn-primary', { dataset: { fid: 'lk-copy' } });
    fs.append(B.row('The link', out), h('div.d-flex.flex-wrap.align-items-center.gap-2', copy, open, status),
      h('p.form-text.mt-2', 'Only fields that are empty are filled, and each value is still checked like a typed answer: a choice that isn’t in the list is left empty. Anyone can change a link, so don’t rely on it for anything that must be trusted.'));
    build();
    return fs;
  }

  /* ---------------------------------------------------------- viewer */

  const DETAILS = [['uid', 'Reference'], ['id', 'Number'], ['created', 'Sent'], ['modified', 'Changed'], ['status', 'Status'], ['submitted_by', 'Sent by']];
  function viewer() {
    const v = S.model.viewer;
    const wrap = h('div.sfb-wide', h('h3.h5', 'Viewer'), h('p.form-text', 'What the site’s submissions list shows for this form. A viewer menu item can change each of these.'));
    const probs = B.state.problems.filter((p) => /^viewer/.test(p.path || ''));
    if (probs.length) wrap.append(h('div.alert.alert-danger.py-2.small', probs.map((p) => h('div', p.message))));
    wrap.append(B.check('Set this form’s own list settings', !!v, (on) => B.update((m) => {
      if (on) m.viewer = { columns: ['uid', ...B.allFields().filter((x) => !x.parent && B.valueType(x.f.type)).slice(0, 3).map((x) => x.f.key), 'status', 'created'] };
      else delete m.viewer;
    }), 'Off: the defaults (reference, the first few fields, status, date).'));
    if (!v) return wrap;

    const top = B.allFields().filter((x) => !x.parent && B.valueType(x.f.type) || (!x.parent && x.f.type === 'repeater'));
    const nameOf = (n) => (DETAILS.find((d) => d[0] === n)?.[1]) || B.fieldName(n);
    const cols = (v.columns || []).map((c) => (typeof c === 'string' ? { name: c } : { ...c }));
    const writeCols = (o) => B.update((m) => { m.viewer.columns = cols.map((c) => (c.heading ? { name: c.name, heading: c.heading } : c.name)); }, o);
    const ol = h('ol.sfb-optlist');
    cols.forEach((c, i) => ol.append(h('li.sfb-opt',
      h('span.sfb-colname', nameOf(c.name)),
      B.input(c.heading, (hv) => { cols[i].heading = hv || undefined; writeCols({ structural: false, merge: `vh.${i}` }); }, { placeholder: 'Heading', 'aria-label': `Heading for ${nameOf(c.name)}`, dataset: { fid: `vc-${i}-h` } }),
      B.iconButton('↑', `Move ${nameOf(c.name)} left`, () => { cols.splice(i - 1, 0, cols.splice(i, 1)[0]); writeCols(); }, { disabled: i === 0, dataset: { fid: `vc-${i}-up` } }),
      B.iconButton('↓', `Move ${nameOf(c.name)} right`, () => { cols.splice(i + 1, 0, cols.splice(i, 1)[0]); writeCols(); }, { disabled: i === cols.length - 1, dataset: { fid: `vc-${i}-dn` } }),
      B.iconButton('✕', `Remove ${nameOf(c.name)}`, () => { cols.splice(i, 1); writeCols(); }, { dataset: { fid: `vc-${i}-x` } }))));
    const unused = [...DETAILS.map(([value, label]) => ({ value, label })), ...top.map((x) => ({ value: x.f.key, label: x.f.label || x.f.key }))].filter((o) => !cols.some((c) => c.name === o.value));
    const addSel = B.select(unused[0]?.value || '', unused, () => {}, { 'aria-label': 'Column to add', dataset: { fid: 'vc-addsel' } });
    wrap.append(h('fieldset', h('legend.h6', 'Columns, in order'), ol, unused.length ? h('div.sfb-rep-add', addSel, B.button('Add column', () => { cols.push({ name: addSel.value }); writeCols(); }, 'btn-outline-secondary', { dataset: { fid: 'vc-add' } })) : null));

    const filterable = [['status', 'Status'], ['created', 'Date sent (range)'], ...top.filter((x) => B.CHOICE.includes(x.f.type)).map((x) => [x.f.key, x.f.label || x.f.key])];
    const filters = new Set(v.filters || []);
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Filters'), ...filterable.map(([k, l]) => B.check(l, filters.has(k), (on) => B.update((m) => {
      const f = new Set(m.viewer.filters || []);
      if (on) f.add(k); else f.delete(k);
      if (f.size) m.viewer.filters = filterable.map((x) => x[0]).filter((x) => f.has(x)); else delete m.viewer.filters;
    })))));

    const sortable = [['created', 'Date sent'], ['modified', 'Date changed'], ['id', 'Number'], ['status', 'Status'],
      ...top.filter((x) => !['textarea', 'editor', 'checkboxes', 'multiselect', 'repeater'].includes(x.f.type)).map((x) => [x.f.key, x.f.label || x.f.key])];
    const [scol, sdir] = String(v.sort || 'created desc').split(/\s+/);
    const setSort = (c, d) => B.update((m) => { const s = `${c} ${d}`; if (s === 'created desc') delete m.viewer.sort; else m.viewer.sort = s; });
    wrap.append(h('fieldset.mt-3', h('legend.h6', 'Order and size'), h('div.sfb-inline',
      B.row('Sort by', B.select(scol, sortable.map(([value, label]) => ({ value, label })), (c) => setSort(c, sdir || 'desc'), { dataset: { fid: 'v-sort' } })),
      B.row('Direction', B.select(sdir || 'desc', [{ value: 'desc', label: 'Newest / Z first' }, { value: 'asc', label: 'Oldest / A first' }], (d) => setSort(scol, d), { dataset: { fid: 'v-dir' } })),
      B.row('Per page', B.number(v.per_page, (n) => B.update((m) => { if (n) m.viewer.per_page = n; else delete m.viewer.per_page; }, { structural: false, merge: 'vpp' }), { min: 5, max: 100, dataset: { fid: 'v-pp' } })))));
    return wrap;
  }

  return { render: which === 'viewer' ? viewer : settings, refresh() {} };
}
