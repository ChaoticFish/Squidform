/**
 * SquidForm builder: Logic. What happens when a page opens or is sent
 * (save, email, message, go to a page), as cards in the order they run, each
 * with a plain-English summary. Plus the form-level events that run after an
 * edit or an approval decision.
 */
export function install(B) {
  const { h } = B;
  const S = B.state;
  let listsEl = null;

  const ACTIONS = {
    save_data: { label: 'Save the answers', icon: '💾' },
    email: { label: 'Send an email', icon: '✉' },
    confirm_email: { label: 'Send a confirmation to the sender', icon: '📨' },
    message: { label: 'Show a message', icon: '💬' },
    redirect: { label: 'Go to a page', icon: '↪' },
    read_data: { label: 'Read data', icon: '🔎' },
    run_php: { label: 'Run PHP handler', icon: '⚙' },
  };
  const HOOKS = [
    ['on_edit', 'After someone edits a submission'], ['on_approve', 'After the last approval'],
    ['on_reject', 'After a rejection'], ['on_return', 'After it’s returned for changes'],
  ];
  // 0.13.1: add-on actions say how they show (BuilderInfoInterface).
  for (const [t, i] of Object.entries(B.catalog?.addonActions || {})) if (!ACTIONS[t]) ACTIONS[t] = { label: i.label, icon: i.icon || '•', defaults: i.defaults || {} };
  const actionLabel = (t) => ACTIONS[t]?.label || t;

  /* ---------------------------------------------------------- locate */

  const listFor = (sel, create = false) => {
    const m = S.model;
    if (sel.kind === 'hook') {
      if (!Array.isArray(m[sel.hook])) { if (!create) return []; m[sel.hook] = []; }
      return m[sel.hook];
    }
    const p = m.pages[sel.page];
    if (!p.events || typeof p.events !== 'object') { if (!create) return []; p.events = {}; }
    if (!Array.isArray(p.events[sel.event])) { if (!create) return []; p.events[sel.event] = []; }
    return p.events[sel.event];
  };
  const actionAt = (sel) => listFor(sel)[sel.index] || null;
  const sameSel = (a, b) => a && b && a.kind === b.kind && a.index === b.index && (a.kind === 'hook' ? a.hook === b.hook : a.page === b.page && a.event === b.event);

  /** "Save the answers", or the action's key, for "when" rules. */
  B.actionName = (key) => {
    for (const p of S.model.pages || []) for (const ev of ['load', 'submit']) {
      const a = (p.events?.[ev] || []).find((x) => x && x.key === key);
      if (a) return summary(a, true);
    }
    return key;
  };

  /** Plain English. */
  function summary(a, short = false) {
    const c = a.config || {};
    let s;
    switch (a.type) {
      case 'save_data': s = 'Save the answers' + (c.status ? ` as “${c.status}”` : ''); break;
      case 'email': s = (Array.isArray(c.routes) && c.routes.length
        ? `Email by answer (${c.routes.length} route${c.routes.length === 1 ? '' : 's'}${c.to ? ', else ' + B.readable(c.to) : ''})`
        : `Email ${B.readable(c.to) || '…'}`) + (!short && c.subject ? `: “${B.readable(c.subject)}”` : '') + (c.attach_csv ? ' (with CSV)' : ''); break;
      case 'confirm_email': s = `Email a copy to ${B.allValueFields().find((x) => x.key === c.to_field)?.label || c.to_field || '…'}` + (c.attach_pdf ? ' (with PDF)' : '') + (c.attach_csv ? ' (with CSV)' : ''); break;
      case 'message': s = `Show “${B.readable(String(c.text || '').replace(/<[^>]+>/g, '')).slice(0, 80)}”`; break;
      case 'redirect': s = `Go to ${B.readable(c.url) || '…'}`; break;
      case 'read_data': s = `Read ${c.mode === 'count' ? 'how many rows' : (c.mode === 'all' ? 'rows' : 'a row')} from ${c.table || '…'}`; break;
      case 'run_php': s = `Run ${(B.catalog.handlers || []).find((x) => x.name === c.handler)?.label || c.handler || '…'}`; break;
      default: s = `${a.type} action`;
    }
    if (!short && a.when) s += ` — when ${B.condText(a.when)}`;
    if (!short && a.disabled) s += ' (switched off)';
    return s;
  }

  /* ---------------------------------------------------------- changes */

  function add(sel0, type) {
    const keys = B.allKeys();
    const base = { save_data: 'a_save', email: 'a_email', message: 'a_message', redirect: 'a_redirect' }[type] || 'a_' + type;
    const a = { key: B.uniqueKey(base, keys), type, config: JSON.parse(JSON.stringify(ACTIONS[type]?.defaults || {})) };
    if (type === 'save_data') a.config.target = 'submissions';
    if (type === 'email') {
      a.config = { to: '{setting:admin_email}', subject: '{form:title} {submission:ref}', body: '{data:_all}' };
      const save = listFor(sel0).find((x) => x.type === 'save_data');
      if (save) a.when = { action: save.key, is: 'success' };
    }
    if (type === 'confirm_email') {
      a.config = { to_field: B.allValueFields().find((x) => x.type === 'email' && !x.label.includes('(any row'))?.key || '' };
      const save = listFor(sel0).find((x) => x.type === 'save_data');
      if (save) a.when = { action: save.key, is: 'success' };
    }
    if (type === 'message') a.config = { text: 'Thank you. Your form has been sent.', style: 'success' };
    if (type === 'redirect') a.config = { url: '/' };
    if (type === 'read_data') {
      const tb = B.catalog.tables[0];
      a.config = { table: tb?.name || '', columns: tb ? tb.columns.slice(0, 2) : [], mode: 'one' };
    }
    if (type === 'run_php') a.config = { handler: (B.catalog.handlers || [])[0]?.name || '' };
    B.update(() => {
      const list = listFor(sel0, true);
      list.push(a);
      S.sel = { ...sel0, index: list.length - 1 };
    });
    B.announce(`${actionLabel(type)} added`);
  }
  function move(sel, d) {
    const list = listFor(sel);
    const j = sel.index + d;
    if (j < 0 || j >= list.length) return;
    B.update(() => { list.splice(j, 0, list.splice(sel.index, 1)[0]); S.sel = { ...sel, index: j }; });
    B.announce(`Moved to position ${j + 1}`);
  }
  function remove(sel) {
    B.update(() => { listFor(sel).splice(sel.index, 1); S.sel = null; });
    B.announce('Action deleted. Undo brings it back.');
  }

  /* ---------------------------------------------------------- render */

  function render() {
    S.logicPage = Math.min(S.logicPage, S.model.pages.length - 1);
    const wrap = h('div.sfb-2col');
    listsEl = h('div.sfb-canvas');
    fill();
    wrap.append(listsEl, inspector());
    return wrap;
  }
  function refresh() {
    if (!listsEl) return B.render();
    const fid = document.activeElement?.closest?.('.sfb-canvas [data-fid]')?.dataset.fid;
    listsEl.textContent = '';
    fill();
    if (fid) listsEl.querySelector(`[data-fid="${CSS.escape(fid)}"]`)?.focus({ preventScroll: true });
  }

  function fill() {
    const tabs = h('div.sfb-pages', { role: 'group', 'aria-label': 'Pages' });
    S.model.pages.forEach((p, i) => tabs.append(h('button.btn.btn-sm.sfb-pagetab', {
      type: 'button', 'aria-pressed': String(!S.hooks && i === S.logicPage), dataset: { fid: 'lpage-' + i },
      onclick: () => { S.hooks = false; S.logicPage = i; S.sel = null; B.render(); },
    }, `${i + 1}. ${p.title || p.key}`)));
    tabs.append(h('button.btn.btn-sm.sfb-pagetab', { type: 'button', 'aria-pressed': String(!!S.hooks), dataset: { fid: 'lpage-hooks' },
      onclick: () => { S.hooks = true; S.sel = null; B.render(); } }, 'After a decision or edit'));
    listsEl.append(tabs);

    if (S.hooks) {
      listsEl.append(h('p.small.text-body-secondary', 'These run on saved submissions: send an email, read data, or run a PHP handler.'));
      HOOKS.forEach(([hook, title]) => listsEl.append(block(title, { kind: 'hook', hook }, ['email', 'read_data', 'run_php'])));
      return;
    }
    const pi = S.logicPage;
    const last = pi === S.model.pages.length - 1;
    listsEl.append(block('When the page opens', { kind: 'action', page: pi, event: 'load' }, ['message', 'read_data', 'run_php'], 'Each time it’s shown: a message, Read data (it fills empty fields), or a PHP handler. Nothing is saved or sent here.'));
    listsEl.append(block('When the page is sent', { kind: 'action', page: pi, event: 'submit' }, Object.keys(ACTIONS).concat(B.catalog.actionTypes.filter((t) => !ACTIONS[t])),
      last ? 'After its fields pass their checks, top to bottom. Save the answers here, on the last page.' : 'After its fields pass their checks. The answers are usually saved on the last page.'));
  }

  function block(title, base, types, help) {
    const list = listFor(base);
    const sec = h('section.sfb-logic', h('h3.h6', title), help ? h('p.form-text', help) : null);
    const ol = h('ol.sfb-list.sfb-actions', { 'aria-label': title });
    list.forEach((a, i) => {
      const sel = { ...base, index: i };
      const selected = sameSel(S.sel, sel);
      const bad = base.kind === 'action' ? B.problemsFor((s) => s.kind === 'action' && sameSel(s, sel)).length : B.problemsFor((s) => s.kind === 'hook' && sameSel(s, sel)).length;
      const fid = `act-${base.hook || base.event}-${a.key}`;
      ol.append(h('li.sfb-card', { class: (selected ? 'is-selected' : '') + (bad ? ' has-problem' : '') + (a.disabled ? ' is-off' : '') },
        h('span.sfb-step', { 'aria-hidden': 'true' }, String(i + 1)),
        h('button.sfb-card-main', { type: 'button', 'aria-pressed': String(!!selected), dataset: { fid: fid + '-main' }, onclick: () => B.pick(sel),
          onkeydown: (e) => { if (e.altKey && e.key === 'ArrowUp') { e.preventDefault(); move(sel, -1); } if (e.altKey && e.key === 'ArrowDown') { e.preventDefault(); move(sel, 1); } } },
          h('span.sfb-ico', { 'aria-hidden': 'true' }, ACTIONS[a.type]?.icon || '⚙'), ' ', h('span.sfb-card-label', summary(a)),
          bad ? h('span.sfb-dot', { 'aria-label': 'has problems' }, '!') : null, h('span.sfb-card-key', a.key)),
        h('span.sfb-card-tools',
          B.iconButton('↑', 'Run earlier', () => move(sel, -1), { disabled: i === 0, dataset: { fid: fid + '-up' } }),
          B.iconButton('↓', 'Run later', () => move(sel, 1), { disabled: i === list.length - 1, dataset: { fid: fid + '-dn' } }),
          B.iconButton('🗑', `Delete: ${summary(a, true)}`, () => remove(sel), { dataset: { fid: fid + '-del' } }))));
    });
    if (!list.length) ol.append(h('li.text-body-secondary.small.sfb-empty', 'Nothing yet.'));
    sec.append(ol);
    const typeSel = B.select(types[0], types.map((t) => ({ value: t, label: actionLabel(t) })), () => {}, { 'aria-label': `Action to add: ${title}`, dataset: { fid: `add-${base.hook || base.event}-t` } });
    sec.append(h('div.sfb-rep-add', types.length > 1 ? typeSel : null,
      B.button(types.length > 1 ? 'Add' : `+ ${actionLabel(types[0])}`, () => add(base, types.length > 1 ? typeSel.value : types[0]), 'btn-outline-secondary', { dataset: { fid: `add-${base.hook || base.event}` } })));
    return sec;
  }

  /* ---------------------------------------------------------- inspector */

  function inspector() {
    const box = h('div.sfb-inspector', { role: 'region', 'aria-label': 'Settings for the selected action' });
    const sel = S.sel;
    const a = sel && (sel.kind === 'action' || sel.kind === 'hook') ? actionAt(sel) : null;
    if (!a) {
      box.append(h('h3.h6', 'Settings'), h('p.small.text-body-secondary', 'Select an action to change it. Actions run top to bottom; a message or a page change ends the run.'));
      return box;
    }
    const cfg = (k) => (v) => B.update(() => { const x = actionAt(sel); x.config ||= {}; if (v === '' || v === undefined) delete x.config[k]; else x.config[k] = v; }, { structural: false, merge: `${a.key}.${k}` });
    const cfgNow = (k) => (v) => B.update(() => { const x = actionAt(sel); x.config ||= {}; if (v === '' || v === undefined) delete x.config[k]; else x.config[k] = v; });
    const c = a.config || {};
    box.append(h('h3.h6', actionLabel(a.type), h('span.sfb-card-key.ms-2', a.key)));
    const probs = B.problemsFor((s) => (s.kind === 'action' || s.kind === 'hook') && sameSel(s, sel));
    if (probs.length) box.append(h('div.alert.alert-danger.py-2.small', probs.map((p) => h('div', p.message))));

    const kids = [];
    if (a.type === 'save_data') {
      kids.push(B.row('Save as', B.select(c.status || '', [
        { value: '', label: S.model.approval ? 'Pending approval (this form has approvals)' : 'Submitted' },
        ...['submitted', 'pending', 'draft', 'approved'].map((v) => ({ value: v, label: v[0].toUpperCase() + v.slice(1) }))],
      cfgNow('status'), { dataset: { fid: 'ins-a-status' } })));
      kids.push(h('p.form-text', 'Saves into this form’s own tables. {submission:ref} can be used by the actions after it.'));
    }
    if (a.type === 'email') {
      kids.push(withInsert('To', c.to, cfg('to'), 'Addresses separated by commas. Addresses typed into the form are refused unless the Options allow it.', 'to'));
      kids.push(withInsert('Cc', c.cc, cfg('cc'), null, 'cc'), withInsert('Bcc', c.bcc, cfg('bcc'), null, 'bcc'));
      kids.push(withInsert('Reply to', c.reply_to, cfg('reply_to'), 'Often the email field, so replies reach the person who filled it in.', 'reply'));
      kids.push(withInsert('Subject', c.subject, cfg('subject'), null, 'subject'));
      kids.push(withInsert('Message', c.body, cfg('body'), '{data:_all} is a table of every answer.', 'body', true));
      kids.push(B.row('Format', B.select(c.format || 'html', [{ value: 'html', label: 'HTML' }, { value: 'text', label: 'Plain text' }], (v) => cfgNow('format')(v === 'html' ? undefined : v), { dataset: { fid: 'ins-a-format' } })));
      kids.push(h('details', h('summary.small', 'Sender'), B.row('From address', B.input(c.from_email, cfg('from_email'), { dataset: { fid: 'ins-a-from' } }), 'Empty: the site’s Global Configuration.'), B.row('From name', B.input(c.from_name, cfg('from_name'), { dataset: { fid: 'ins-a-fromname' } }))));
      kids.push(routes(a, sel, c));
      kids.push(B.check('Attach the answers as a CSV file', c.attach_csv, (v) => cfgNow('attach_csv')(v || undefined), 'One line of headings and one of answers, to open in a spreadsheet. No IP address; “Hide what’s typed” answers stay as dots.'));
    }
    if (a.type === 'confirm_email') {
      const emails = B.allValueFields().filter((x) => x.type === 'email' && !x.label.includes('(any row'));
      kids.push(h('p.form-text', 'A copy for the person who filled in the form, sent to the address they typed. It only ever goes to that one address, from the site’s own address.'));
      kids.push(B.row('Send to the address in', B.select(c.to_field || '', [{ value: '', label: emails.length ? 'Choose an Email field' : 'Add an Email field first' }, ...emails.map((x) => ({ value: x.key, label: x.label }))], cfgNow('to_field'), { dataset: { fid: 'ins-a-tofield' } })));
      kids.push(withInsert('Subject', c.subject, cfg('subject'), 'Empty: “Your answers: ” and the form’s title.', 'csubject'));
      kids.push(withInsert('Message', c.body, cfg('body'), 'Empty: a thank-you line and {data:_all} (every answer except hidden fields and “Hide what’s typed” ones).', 'cbody', true));
      kids.push(B.check('Attach a PDF of the answers', c.attach_pdf, (v) => cfgNow('attach_pdf')(v || undefined)));
      kids.push(B.check('Attach the answers as a CSV file', c.attach_csv, (v) => cfgNow('attach_csv')(v || undefined)));
      kids.push(h('details', h('summary.small', 'Sender'), B.row('From name', B.input(c.from_name, cfg('from_name'), { dataset: { fid: 'ins-a-cfromname' } }), 'Empty: the site’s Global Configuration. The address is always the site’s.'),
        withInsert('Reply to', c.reply_to, cfg('reply_to'), 'For example your office’s address, so replies reach a person.', 'creply')));
    }
    if (a.type === 'message') {
      kids.push(withInsert('Message', c.text, cfg('text'), 'Simple HTML is allowed.', 'text', true), B.htmlInfo());
      kids.push(B.row('Style', B.select(c.style || 'success', ['success', 'info', 'warning', 'error'].map((v) => ({ value: v, label: v[0].toUpperCase() + v.slice(1) })), cfgNow('style'), { dataset: { fid: 'ins-a-style' } })));
      kids.push(B.check('Stop here (later actions don’t run)', c.stop !== false, (v) => cfgNow('stop')(v ? undefined : false)));
      if (c.stop === false) kids.push(B.check('Show only the message after the last page', c.hide_form !== false, (v) => cfgNow('hide_form')(v ? undefined : false)));
    }
    if (a.type === 'redirect') {
      kids.push(withInsert('Address', c.url, cfg('url'), 'This site’s pages only (unless the Options allow others), for example /thanks or index.php?option=com_content&view=article&id=12.', 'url'));
    }
    if (a.type === 'read_data') kids.push(...readData(a, sel, c, cfgNow));
    if (a.type === 'run_php') kids.push(...runPhp(a, sel, c, cfgNow));
    if (!ACTIONS[a.type]) {
      const ta = B.textarea(JSON.stringify(c, null, 2), () => {}, { rows: 8, class: 'font-monospace', 'aria-label': 'Settings (JSON)' });
      const msg = h('div.form-text');
      kids.push(h('p.small', 'This action comes from a plugin; its settings are edited as JSON.'), ta, msg, B.button('Apply', () => {
        try { const v = JSON.parse(ta.value); B.update(() => { actionAt(sel).config = v; }); } catch (e) { msg.textContent = e.message; msg.classList.add('text-danger'); }
      }, 'btn-outline-primary mt-1'));
    }
    box.append(h('div.sfb-sec-body', ...kids));

    // When
    const earlier = sel.kind === 'action' ? listFor(sel).slice(0, sel.index).map((x) => ({ key: x.key, label: summary(x, true) })) : [];
    const fieldsAvail = sel.kind === 'action' ? B.fieldsUpTo(sel.page) : B.allValueFields();
    box.append(h('details.sfb-sec', { open: !!a.when }, h('summary', 'Run only when…'), h('div.sfb-sec-body', B.conditionEditor({
      value: a.when, fields: fieldsAvail, actions: earlier, noneLabel: 'Always run it', someLabel: 'Only when', fid: `ins-when-${a.key}`,
      onChange: (cnd, o) => B.update(() => { const x = actionAt(sel); if (cnd) x.when = cnd; else delete x.when; }, o?.typing ? { structural: false, merge: `when.${a.key}` } : {}),
    }), h('p.form-text', 'When the condition is false, the action is skipped and the next one runs.'))));

    box.append(h('details.sfb-sec', h('summary', 'Advanced'), h('div.sfb-sec-body',
      B.check('Switched off (kept, but never runs)', a.disabled, (v) => B.update(() => { const x = actionAt(sel); if (v) x.disabled = true; else delete x.disabled; })),
      B.row('Key', B.input(a.key, () => {}, { class: 'font-monospace', readonly: true, dataset: { fid: 'ins-a-key' } }), `Its result is {action:${a.key}}.`))));
    return box;
  }

  /* ---------------------------------------------------------- routes (0.11.0) */

  /** Email routes: who it goes to depends on the answers. */
  function routes(a, sel, c) {
    const list = Array.isArray(c.routes) ? c.routes : [];
    const write = (fn, o) => B.update(() => { const x = actionAt(sel); x.config ||= {}; fn(x.config); if (Array.isArray(x.config.routes) && !x.config.routes.length) { delete x.config.routes; delete x.config.route_match; } }, o);
    const fieldsAvail = sel.kind === 'action' ? B.fieldsUpTo(sel.page) : B.allValueFields();
    const body = h('div.sfb-sec-body', h('p.form-text', 'Send to different people depending on the answers, for example by department. The first route that matches is used'
      + ' (or all that match); “To” above is used when none match, and if it’s empty nothing is sent.'));
    list.forEach((r, i) => {
      const upd = (k) => (v) => write((x) => { if (v === '' || v === undefined) delete x.routes[i][k]; else x.routes[i][k] = v; }, { structural: false, merge: `${a.key}.route${i}.${k}` });
      body.append(h('div.border-start.ps-2.mb-3.sfb-route',
        h('div.d-flex.align-items-center.gap-2', h('strong.small', `Route ${i + 1}`),
          B.iconButton('✕', `Remove route ${i + 1}`, () => write((x) => { x.routes.splice(i, 1); }), { dataset: { fid: `ins-rt${i}-x` } })),
        B.conditionEditor({ value: r.when, fields: fieldsAvail, noneLabel: 'Choose when…', someLabel: 'When', fid: `ins-rt${i}-when`,
          onChange: (cnd, o) => write((x) => { x.routes[i].when = cnd || {}; }, o?.typing ? { structural: false, merge: `${a.key}.route${i}.when` } : {}) }),
        withInsert('Send to', r.to, upd('to'), null, `rt${i}-to`),
        withInsert('Cc', r.cc, upd('cc'), null, `rt${i}-cc`)));
    });
    body.append(h('div.sfb-inline.align-items-end',
      B.button('+ Add a route', () => write((x) => { (x.routes ||= []).push({ when: {}, to: '' }); }), 'btn-outline-secondary', { dataset: { fid: 'ins-rt-add' } }),
      list.length > 1 ? B.row('Use', B.select(c.route_match || 'first', [{ value: 'first', label: 'The first route that matches' }, { value: 'all', label: 'Every route that matches' }],
        (v) => write((x) => { if (v === 'first') delete x.route_match; else x.route_match = v; }), { dataset: { fid: 'ins-rt-match' } })) : null));
    return h('details.sfb-sec', { open: list.length > 0 }, h('summary', 'Send to depends on the answers'), body);
  }

  /* ---------------------------------------------------------- read data (0.10.0) */

  const OPS = [['eq', 'is'], ['neq', 'is not'], ['gt', 'more than'], ['gte', 'at least'], ['lt', 'less than'], ['lte', 'at most'], ['like', 'contains'], ['empty', 'is empty'], ['filled', 'is not empty']];

  function readData(a, sel, c, cfgNow) {
    const kids = [];
    const tables = B.catalog.tables || [];
    const write = (fn, o) => B.update(() => { const x = actionAt(sel); x.config ||= {}; fn(x.config); }, o);
    if (!tables.length) {
      kids.push(h('div.alert.alert-warning.small.py-2', 'No tables can be read yet. Add tables (or database views) to SquidForm → Options → Data Sources → Tables that can be read.'));
    }
    const info = (name) => tables.find((t) => t.name === name);
    const tableChoices = tables.map((t) => ({ value: t.name, label: t.name }));
    if (c.table && !info(c.table)) tableChoices.unshift({ value: c.table, label: `${c.table} (not on the list)` });
    kids.push(B.row('Table', B.select(c.table || '', tableChoices, (v) => write((x) => { x.table = v; x.columns = (info(v)?.columns || []).slice(0, 2); delete x.where; delete x.order; delete x.fill; delete x.join; }), { dataset: { fid: 'ins-rd-table' } }),
      'A table or database view on the read allow-list. For several tables, join them below, or make a view.'));
    kids.push(B.row('Read', B.select(c.mode || 'one', [{ value: 'one', label: 'One row (the first that matches)' }, { value: 'all', label: 'All rows that match' }, { value: 'count', label: 'How many rows match' }],
      (v) => write((x) => { if (v === 'one') delete x.mode; else x.mode = v; if (v !== 'all') delete x.limit; }), { dataset: { fid: 'ins-rd-mode' } })));

    // Joined tables
    const joins = Array.isArray(c.join) ? c.join : [];
    const allCols = () => [...(info(c.table)?.columns || []).map((x) => x), ...joins.flatMap((j) => (info(j.table)?.columns || []).map((x) => `${j.as}.${x}`))];
    const jl = h('div');
    joins.forEach((j, i) => {
      const upd = (k) => (v) => write((x) => { x.join[i][k] = v; });
      const left = [...(info(c.table)?.columns || []), ...joins.slice(0, i).flatMap((jj) => (info(jj.table)?.columns || []).map((x) => `${jj.as}.${x}`))];
      jl.append(h('div.sfb-inline.align-items-end.border-start.ps-2.mb-2',
        B.row(`Join ${i + 1}: table`, B.select(j.table, tables.map((t) => ({ value: t.name, label: t.name })), upd('table'), { dataset: { fid: `ins-rd-j${i}-t` } })),
        B.row('Short name', B.input(j.as, (v) => write((x) => { x.join[i].as = v; }, { structural: false, merge: `rdj${i}` }), { class: 'font-monospace', dataset: { fid: `ins-rd-j${i}-as` } })),
        B.row('Where', B.select(j.on?.[0] || '', left.map((x) => ({ value: x, label: x })), (v) => write((x) => { x.join[i].on = [v, x.join[i].on?.[1] || '']; }), { dataset: { fid: `ins-rd-j${i}-l` } })),
        B.row('equals its', B.select(j.on?.[1] || '', (info(j.table)?.columns || []).map((x) => ({ value: x, label: x })), (v) => write((x) => { x.join[i].on = [x.join[i].on?.[0] || '', v]; }), { dataset: { fid: `ins-rd-j${i}-r` } })),
        B.row('Rows without a match', B.select(j.type || 'left', [{ value: 'left', label: 'Keep them' }, { value: 'inner', label: 'Leave them out' }], (v) => write((x) => { if (v === 'left') delete x.join[i].type; else x.join[i].type = v; }), { dataset: { fid: `ins-rd-j${i}-ty` } })),
        B.iconButton('✕', `Remove join ${i + 1}`, () => write((x) => { x.join.splice(i, 1); if (!x.join.length) delete x.join; }), { dataset: { fid: `ins-rd-j${i}-x` } })));
    });
    if (c.table && joins.length < 3 && tables.length > 1) {
      jl.append(B.button('+ Join another table', () => write((x) => {
        const used = ['t', ...(x.join || []).map((jj) => jj.as)];
        let as = 'j' + ((x.join || []).length + 1);
        while (used.includes(as)) as += 'x';
        const other = tables.find((t) => t.name !== x.table) || tables[0];
        (x.join ||= []).push({ table: other.name, as, on: [(info(x.table)?.columns || [])[0] || 'id', other.columns[0] || 'id'] });
      }), 'btn-outline-secondary', { dataset: { fid: 'ins-rd-join' } }));
    }
    kids.push(h('details.sfb-sec', { open: joins.length > 0 }, h('summary', 'Other tables (joins)'), h('div.sfb-sec-body', jl,
      h('p.form-text', 'A joined table’s columns are written short.column, for example c.title.'))));

    // Columns
    if ((c.mode || 'one') !== 'count') {
      const chosen = new Set(c.columns || []);
      kids.push(h('fieldset', h('legend.small.fw-semibold', 'Columns to read'), h('div.sfb-cols', ...allCols().map((col) => B.check(col, chosen.has(col), (on) => write((x) => {
        const set = new Set(x.columns || []);
        if (on) set.add(col); else set.delete(col);
        x.columns = allCols().filter((k) => set.has(k));
      }))))));
    }

    // Where
    const where = c.where && (c.where.all || c.where.any) ? c.where : null;
    const glue = where?.any ? 'any' : 'all';
    const conds = where ? where[glue] : [];
    const wl = h('div');
    const placeholders = [{ value: '', label: 'Insert…' }, { group: 'Answers', items: B.fieldsUpTo(sel.kind === 'hook' ? S.model.pages.length - 1 : sel.page).map((x) => ({ value: `{data:${x.key}}`, label: x.label })) },
      { group: 'The person', items: [{ value: '{user:id}', label: 'Their user id' }, { value: '{user:email}', label: 'Their email' }, { value: '{user:username}', label: 'Their username' }] },
      { group: 'The web address', items: [{ value: '{url:id}', label: '?id=…' }] }];
    conds.forEach((cd, i) => {
      const upd = (k, o) => (v) => write((x) => { x.where[glue][i][k] = v; }, o);
      const val = B.input(cd.value ?? '', upd('value', { structural: false, merge: `rdw${i}` }), { 'aria-label': `Rule ${i + 1}: value`, dataset: { fid: `ins-rd-w${i}-v` } });
      const ins = B.select('', placeholders, (v) => { if (v) { val.value += v; val.dispatchEvent(new Event('input')); ins.value = ''; } }, { 'aria-label': `Insert into rule ${i + 1}`, class: 'sfb-insert', dataset: { fid: `ins-rd-w${i}-ins` } });
      wl.append(h('div.sfb-inline.align-items-end.mb-1',
        B.select(cd.col || '', allCols().map((x) => ({ value: x, label: x })), upd('col'), { 'aria-label': `Rule ${i + 1}: column`, dataset: { fid: `ins-rd-w${i}-c` } }),
        B.select(cd.op || 'eq', OPS.map(([value, label]) => ({ value, label })), upd('op'), { 'aria-label': `Rule ${i + 1}: comparison`, dataset: { fid: `ins-rd-w${i}-o` } }),
        ['empty', 'filled'].includes(cd.op) ? null : val, ['empty', 'filled'].includes(cd.op) ? null : ins,
        B.iconButton('✕', `Remove rule ${i + 1}`, () => write((x) => { x.where[glue].splice(i, 1); if (!x.where[glue].length) delete x.where; }), { dataset: { fid: `ins-rd-w${i}-x` } })));
    });
    kids.push(h('fieldset.mt-2', h('legend.small.fw-semibold', 'Which rows'),
      conds.length > 1 ? B.select(glue, [{ value: 'all', label: 'Every rule matches' }, { value: 'any', label: 'Any rule matches' }], (v) => write((x) => { x.where = { [v]: x.where[glue] }; }), { 'aria-label': 'How the rules combine', dataset: { fid: 'ins-rd-glue' } }) : null,
      wl, c.table ? B.button('+ Add a rule', () => write((x) => { x.where ||= { all: [] }; const g = x.where.any ? 'any' : 'all'; x.where[g].push({ col: allCols()[0] || 'id', op: 'eq', value: '' }); }), 'btn-outline-secondary', { dataset: { fid: 'ins-rd-addw' } }) : null,
      h('p.form-text', conds.length ? 'Values can use placeholders; they’re always sent to the database as values, never as SQL. Anyone can change a web address, so don’t rely on {url:…} to keep rows private.' : 'No rules: every row (the first, for “One row”).')));

    // Rows people shouldn't see (0.10.2): state/published or access columns that no rule uses.
    const ruleCols = new Set(conds.map((cd) => String(cd.col || '').toLowerCase()));
    const risky = [];
    [[c.table, ''], ...joins.map((j) => [j.table, j.as + '.'])].forEach(([tb, pre]) => {
      const cols = (info(tb)?.columns || []).map((x) => x.toLowerCase());
      ['state', 'published', 'access'].forEach((k) => { if (cols.includes(k) && !ruleCols.has(pre + k)) risky.push(pre + k); });
    });
    if (risky.length) {
      kids.push(h('div.alert.alert-warning.small.py-2.mt-2', { dataset: { fid: 'ins-rd-risky' } },
        h('strong', 'This may read rows visitors shouldn’t see. '),
        `The table has ${risky.join(', ')}, and no rule uses ${risky.length > 1 ? 'them' : 'it'}, so it also reads unpublished, trashed or members-only rows. Read data doesn’t apply Joomla’s access levels. `,
        'Add a rule (for example state is 1, access is 1), or allow-list a database view of only what visitors may see.'));
    }

    // Order and limit
    if ((c.mode || 'one') !== 'count') {
      const [ocol, odir] = String(c.order || '').split(/\s+/);
      const setOrder = (col, dir) => write((x) => { if (col) x.order = `${col} ${dir || 'asc'}`; else delete x.order; });
      kids.push(h('div.sfb-inline',
        B.row('Order by', B.select(ocol || '', [{ value: '', label: '— as the database gives them —' }, ...allCols().map((x) => ({ value: x, label: x }))], (v) => setOrder(v, odir), { dataset: { fid: 'ins-rd-order' } })),
        ocol ? B.row('Direction', B.select(odir || 'asc', [{ value: 'asc', label: 'A–Z, lowest first' }, { value: 'desc', label: 'Z–A, highest first' }], (v) => setOrder(ocol, v), { dataset: { fid: 'ins-rd-dir' } })) : null,
        c.mode === 'all' ? B.row('At most', B.number(c.limit, (v) => write((x) => { if (v) x.limit = Math.trunc(v); else delete x.limit; }, { structural: false, merge: 'rdlim' }), { min: 1, max: 500, placeholder: '100', dataset: { fid: 'ins-rd-limit' } })) : null));
    }

    // Fill fields
    const fill = c.fill && typeof c.fill === 'object' ? c.fill : {};
    const fields = B.allFields().filter((x) => !x.parent && (B.valueType(x.f.type) || x.f.type === 'repeater') && !['calc', 'signature', 'upload', 'consent'].includes(x.f.type));
    const cols = (c.mode || 'one') === 'count' ? ['count'] : (c.columns || []);
    const fl = h('div');
    Object.entries(fill).forEach(([fk, col]) => {
      const fx = B.fieldByKey(fk)?.f;
      if (fx && fx.type === 'repeater') {
        fl.append(h('div.border-start.ps-2.mb-2', h('div.small.fw-semibold', `${fx.label || fk}: a row for each row read`),
          ...(fx.fields || []).filter((ch) => B.valueType(ch.type)).map((ch) => B.row(ch.label || ch.key, B.select((col || {})[ch.key] || '', [{ value: '', label: '— leave empty —' }, ...cols.map((x) => ({ value: x, label: x }))],
            (v) => write((x) => { x.fill[fk] = { ...(x.fill[fk] || {}) }; if (v) x.fill[fk][ch.key] = v; else delete x.fill[fk][ch.key]; }), { dataset: { fid: `ins-rd-f-${fk}-${ch.key}` } }))),
          B.button('Remove', () => write((x) => { delete x.fill[fk]; if (!Object.keys(x.fill).length) delete x.fill; }), 'btn-outline-secondary btn-sm')));
        return;
      }
      fl.append(h('div.sfb-inline.align-items-end',
        h('span.small', fx ? (fx.label || fk) : `${fk} (not found)`), B.select(String(col), cols.map((x) => ({ value: x, label: x })), (v) => write((x) => { x.fill[fk] = v; }), { 'aria-label': `Column for ${fx?.label || fk}`, dataset: { fid: `ins-rd-f-${fk}` } }),
        B.iconButton('✕', `Don’t fill ${fx?.label || fk}`, () => write((x) => { delete x.fill[fk]; if (!Object.keys(x.fill).length) delete x.fill; }), { dataset: { fid: `ins-rd-f-${fk}-x` } })));
    });
    const free = fields.filter((x) => !(x.f.key in fill) && (x.f.type !== 'repeater' || c.mode === 'all'));
    const addSel = B.select(free[0]?.f.key || '', free.map((x) => ({ value: x.f.key, label: x.f.label || x.f.key })), () => {}, { 'aria-label': 'Field to fill', dataset: { fid: 'ins-rd-fillsel' } });
    kids.push(h('fieldset.mt-2', h('legend.small.fw-semibold', 'Fill fields with what it reads'), fl,
      free.length && cols.length ? h('div.sfb-rep-add', addSel, B.button('Add', () => write((x) => {
        const fx = B.fieldByKey(addSel.value)?.f;
        x.fill ||= {};
        x.fill[addSel.value] = fx?.type === 'repeater' ? {} : cols[0];
      }), 'btn-outline-secondary', { dataset: { fid: 'ins-rd-filladd' } })) : null,
      h('p.form-text', sel.event === 'load' ? 'When the page opens, only empty fields are filled: nothing typed is replaced.' : 'When the page is sent, the fields are set to what it read.'),
      h('p.form-text', `In emails and messages: {action:${a.key}.column}${c.mode === 'all' ? ` (the first row), {action:${a.key}.count}` : ''}${c.mode === 'count' ? `; {action:${a.key}} is the number` : ''}. Finding nothing counts as “failed” for “Run only when…”.`)));
    return kids;
  }

  function runPhp(a, sel, c, cfgNow) {
    const list = B.catalog.handlers || [];
    const kids = [];
    if (!list.length) {
      kids.push(h('div.alert.alert-info.small.py-2', 'No PHP handlers are switched on yet. An admin ticks the starters that come with SquidForm in Options → Developers → Starter handlers; a developer can add your own as files in ', h('code', B.catalog.handlerFolder || 'administrator/squidform-handlers'),
        '. Check this server lists them.'));
    }
    const probs = Object.entries(B.catalog.handlerProblems || {});
    if (probs.length) {
      kids.push(h('div.alert.alert-warning.small.py-2', { dataset: { fid: 'ins-php-problems' } },
        h('strong', `${probs.length === 1 ? 'A file' : `${probs.length} files`} in the handler folder didn’t load:`),
        h('ul.mb-0.mt-1', ...probs.map(([f, why]) => h('li', h('code', f), `: ${why}`)))));
    }
    const choices = list.map((x) => ({ value: x.name, label: x.label }));
    if (c.handler && !list.some((x) => x.name === c.handler)) choices.unshift({ value: c.handler, label: `${c.handler} (not on this site)` });
    kids.push(B.row('Handler', B.select(c.handler || '', choices.length ? choices : [{ value: '', label: '— none —' }], (v) => B.update(() => { const x = actionAt(sel); x.config = { handler: v }; }), { dataset: { fid: 'ins-php-h' } })));
    const hd = list.find((x) => x.name === c.handler);
    if (hd?.description) kids.push(h('p.form-text', hd.description));
    const st = c.settings || {};
    const names = Object.keys(hd?.settings || {});
    Object.keys(st).forEach((k) => { if (!names.includes(k)) names.push(k); });
    names.forEach((k) => kids.push(B.row(hd?.settings?.[k] || k, B.input(st[k] ?? '', (v) => B.update(() => {
      const x = actionAt(sel);
      x.config.settings ||= {};
      if (v === '') delete x.config.settings[k]; else x.config.settings[k] = v;
      if (!Object.keys(x.config.settings).length) delete x.config.settings;
    }, { structural: false, merge: `php.${k}` }), { dataset: { fid: `ins-php-s-${k}` } }))));
    kids.push(h('p.form-text', 'Handlers are server code a developer puts on the site. This action runs one of them; to run more (say one when the page loads and another when it’s sent), add another Run PHP handler action. On a page that’s sent it runs inside the save, so if it fails, nothing is saved.'));
    return kids;
  }

  /** A text box with an "Insert" list of placeholders beside it. */
  function withInsert(label, value, on, help, fid, multi = false) {
    const el = multi ? B.textarea(value, on, { rows: 5, dataset: { fid: 'ins-a-' + fid } }) : B.input(value, on, { dataset: { fid: 'ins-a-' + fid } });
    const opts = [{ value: '', label: 'Insert…' }];
    const fields = B.allFields().filter((x) => B.valueType(x.f.type) && !x.parent);
    if (fields.length) opts.push({ group: 'Answers', items: fields.map((x) => ({ value: `{data:${x.f.key}}`, label: x.f.label || x.f.key })).concat([{ value: '{data:_all}', label: 'All answers (table)' }]) });
    const chosen = fields.filter((x) => B.CHOICE.includes(x.f.type) || x.f.type === 'user');
    if (chosen.length) opts.push({ group: 'Answers, as shown', items: chosen.map((x) => ({ value: `{data.label:${x.f.key}}`, label: x.f.label || x.f.key })) });
    opts.push({ group: 'This submission', items: [{ value: '{submission:ref}', label: 'Reference (ca9a4d1b)' }] });
    opts.push({ group: 'The person', items: [['name', 'Name'], ['email', 'Email'], ['username', 'Username']].map(([k, l]) => ({ value: `{user:${k}}`, label: l })) });
    const settings = Object.keys(S.model.settings || {}).filter((k) => !(B.KNOWN_SETTINGS || []).includes(k));
    if (settings.length) opts.push({ group: 'Form settings', items: settings.map((k) => ({ value: `{setting:${k}}`, label: k })) });
    const reads = [];
    (S.model.pages || []).forEach((p) => ['load', 'submit'].forEach((ev) => (p.events?.[ev] || []).forEach((x) => {
      if (x && x.type === 'read_data') {
        if (x.config?.mode === 'count') reads.push({ value: `{action:${x.key}}`, label: `${x.key}: how many` });
        else (x.config?.columns || []).forEach((col) => reads.push({ value: `{action:${x.key}.${col}}`, label: `${x.key}: ${col}` }));
        if (x.config?.mode === 'all') reads.push({ value: `{action:${x.key}.count}`, label: `${x.key}: how many rows` });
      }
    })));
    if (reads.length) opts.push({ group: 'Read data', items: reads });
    opts.push({ group: 'Other', items: [{ value: '{form:title}', label: 'Form title' }, { value: '{site:name}', label: 'Site name' }, { value: '{date:Y-m-d}', label: 'Today’s date' }, { value: '{url:id}', label: 'From the web address (?id=…)' }] });
    const ins = B.select('', opts, (v) => {
      if (!v) return;
      const s = el.selectionStart ?? el.value.length;
      el.value = el.value.slice(0, s) + v + el.value.slice(el.selectionEnd ?? s);
      el.dispatchEvent(new Event('input'));
      ins.value = '';
      el.focus();
      el.setSelectionRange(s + v.length, s + v.length);
    }, { 'aria-label': `Insert into ${label}`, class: 'sfb-insert', dataset: { fid: 'ins-a-' + fid + '-ins' } });
    const r = B.row(label, el, help);
    r.querySelector('label').after(ins);
    if (value && /\{/.test(value)) r.append(h('div.form-text.sfb-readable', 'Reads as: ', B.readable(value).slice(0, 200)));
    return r;
  }

  return { render, refresh };
}
