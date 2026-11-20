/**
 * SquidForm builder: pages and fields.
 *
 * Left: the field list (click to add, or drag onto the form). Middle: the
 * form, one page at a time, with pages as tabs. Right: settings for the
 * selected field or page. Everything a drag does can also be done with the
 * buttons on each card (move up, move down, duplicate, delete).
 */
export function install(B) {
  const { h } = B;
  const S = B.state;
  let canvasEl = null;
  let sortables = [];        // the form's lists (rebuilt on every refresh)
  let paletteSortables = []; // the field list (rebuilt only with the whole section)

  /* ---------------------------------------------------------- locate */

  // A field's place is a path of indexes (0.9.1): [2] is the page's third
  // field, [2, 0] the first field inside it (a section or a repeating group),
  // [2, 0, 1] a field in a repeating group inside a section.
  const pageOf = (i) => S.model.pages[i];
  const pathOf = (sel) => (Array.isArray(sel.path) ? sel.path : [sel.index].concat(sel.child === null || sel.child === undefined ? [] : [sel.child]));
  const at = (page, path) => ({ kind: 'field', page, path: path.slice() });
  B.pathOf = pathOf;
  /** The list a path's last index points into. */
  const listAt = (page, parentPath) => {
    let list = pageOf(page).fields;
    for (const i of parentPath) list = (list[i].fields ||= []);
    return list;
  };
  const listOfSel = (sel) => listAt(sel.page, pathOf(sel).slice(0, -1));
  const posOf = (sel) => pathOf(sel)[pathOf(sel).length - 1];
  B.fieldAt = (sel) => {
    let list = pageOf(sel.page)?.fields;
    let f = null;
    for (const i of pathOf(sel)) {
      f = list?.[i];
      if (!f) return null;
      list = f.fields;
    }
    return f;
  };
  /** The fields around a path, outermost first. */
  const ancestors = (sel) => {
    const out = [];
    let list = pageOf(sel.page).fields;
    pathOf(sel).slice(0, -1).forEach((i) => { out.push(list[i]); list = list[i].fields || []; });
    return out;
  };
  /** The repeating group a field is in, if any (conditions there see its row). */
  const repeaterOf = (sel) => ancestors(sel).find((a) => a.type === 'repeater') || null;
  const sameSel = (a, b) => a && b && a.kind === b.kind && a.page === b.page && pathOf(a).join('.') === pathOf(b).join('.');
  /** What may go into a list: the page takes anything; a section, anything but a section; a repeating group, simple fields. */
  const NOT_IN_REPEATER = ['repeater', 'section', 'signature', 'grid', 'upload'];
  const allowedIn = (parent, type) => (!parent ? true : (parent.type === 'section' ? type !== 'section' : !NOT_IN_REPEATER.includes(type)));

  function ensurePages() {
    if (!Array.isArray(S.model.pages) || !S.model.pages.length) S.model.pages = [{ key: 'p_main', title: 'Page 1', fields: [], events: { load: [], submit: [] } }];
    S.model.pages.forEach((p) => { if (!Array.isArray(p.fields)) p.fields = []; });
    S.page = Math.min(Math.max(0, S.page), S.model.pages.length - 1);
  }

  /* ---------------------------------------------------------- changes */

  /** where: { page, parent: [path of the container] , at } */
  function addField(type, where = null) {
    B.update((m) => {
      ensurePages();
      const f = B.newField(type, B.allKeys());
      let sel;
      if (where) {
        listAt(where.page, where.parent).splice(where.at, 0, f);
        sel = at(where.page, where.parent.concat([where.at]));
      } else {
        // After the selected field, in its list when it may go there; else at the end of the page.
        const s = S.sel && S.sel.kind === 'field' && S.sel.page === S.page && B.fieldAt(S.sel) ? S.sel : null;
        let path = s ? pathOf(s) : null;
        while (path && path.length > 1 && !allowedIn(B.fieldAt(at(S.page, path.slice(0, -1))), type)) path = path.slice(0, -1);
        if (path) {
          listAt(S.page, path.slice(0, -1)).splice(path[path.length - 1] + 1, 0, f);
          sel = at(S.page, path.slice(0, -1).concat([path[path.length - 1] + 1]));
        } else {
          m.pages[S.page].fields.push(f);
          sel = at(S.page, [m.pages[S.page].fields.length - 1]);
        }
      }
      S.fresh.add(f);
      (f.fields || []).forEach((c) => S.fresh.add(c));
      S.sel = sel;
    });
    B.announce(`${B.typeLabel(type)} added`);
    canvasEl?.querySelector('.sfb-card.is-selected .sfb-card-main')?.focus();
  }

  function move(sel, delta) {
    const list = listOfSel(sel);
    const i = posOf(sel);
    const j = i + delta;
    if (j < 0 || j >= list.length) return;
    B.update(() => {
      const [f] = list.splice(i, 1);
      list.splice(j, 0, f);
      S.sel = at(sel.page, pathOf(sel).slice(0, -1).concat([j]));
    });
    B.announce(`Moved to position ${j + 1} of ${list.length}`);
    canvasEl?.querySelector('.sfb-card.is-selected .sfb-card-main')?.focus();
  }

  function remove(sel) {
    const f = B.fieldAt(sel);
    B.update(() => {
      listOfSel(sel).splice(posOf(sel), 1);
      S.sel = null;
    });
    B.announce(`Deleted ${f.label || f.key}${(f.fields || []).length ? ' and the fields in it' : ''}. Undo brings it back.`);
    canvasEl?.querySelector('.sfb-addhint, .sfb-card .sfb-card-main')?.focus();
  }

  function duplicate(sel) {
    B.update(() => {
      const src = B.fieldAt(sel);
      const copy = JSON.parse(JSON.stringify(src));
      const keys = B.allKeys();
      const rekey = (f) => {
        f.key = B.uniqueKey(f.key + '_copy', keys);
        keys.push(f.key);
        (f.fields || []).forEach(rekey);
      };
      rekey(copy);
      if (copy.label) copy.label += ' (copy)';
      const list = listOfSel(sel);
      const atPos = posOf(sel) + 1;
      list.splice(atPos, 0, copy);
      S.sel = at(sel.page, pathOf(sel).slice(0, -1).concat([atPos]));
    });
    B.announce('Duplicated');
  }

  function moveToPage(sel, page) {
    B.update(() => {
      const [f] = listOfSel(sel).splice(posOf(sel), 1);
      pageOf(page).fields.push(f);
      S.page = page;
      S.sel = at(page, [pageOf(page).fields.length - 1]);
    });
    B.announce(`Moved to page ${page + 1}`);
  }

  function addPage() {
    B.update((m) => {
      const n = m.pages.length + 1;
      m.pages.push({ key: B.uniqueKey('p_page' + n, B.allKeys()), title: `Page ${n}`, fields: [], events: { load: [], submit: [] } });
      S.page = m.pages.length - 1;
      S.sel = { kind: 'page', page: S.page };
    });
    B.announce('Page added');
  }

  /* ---------------------------------------------------------- render */

  function render() {
    ensurePages();
    sortables.forEach((s) => s.destroy());
    sortables = [];
    paletteSortables.forEach((s) => s.destroy());
    paletteSortables = [];
    const wrap = h('div.sfb-3col');
    wrap.append(palette());
    canvasEl = h('div.sfb-canvas');
    fillCanvas();
    wrap.append(canvasEl, inspector());
    return wrap;
  }

  function refresh() {
    if (!canvasEl) return B.render();
    sortables.forEach((s) => s.destroy());
    sortables = [];
    const fid = document.activeElement?.closest?.('.sfb-canvas [data-fid]')?.dataset.fid;
    canvasEl.textContent = '';
    fillCanvas();
    if (fid) canvasEl.querySelector(`[data-fid="${CSS.escape(fid)}"]`)?.focus({ preventScroll: true });
  }

  function palette() {
    const groups = {};
    const known = Object.keys(B.TYPES).filter((t) => B.catalog.fieldTypes.includes(t));
    for (const t of known.concat(B.catalog.fieldTypes.filter((t) => !B.TYPES[t]))) {
      const g = B.TYPES[t]?.group || 'Add-ons';
      (groups[g] ||= []).push(t);
    }
    const box = h('div.sfb-palette', { role: 'region', 'aria-label': 'Add a field' }, h('h3.h6', 'Add a field'));
    for (const [g, types] of Object.entries(groups)) {
      const ul = h('ul.list-unstyled.sfb-palette-list', { 'aria-label': g });
      types.forEach((t) => ul.append(h('li', { dataset: { newType: t } },
        h('button.btn.btn-sm.btn-light.sfb-palette-btn', { type: 'button', dataset: { fid: 'pal-' + t }, onclick: () => addField(t), title: `Add ${B.typeLabel(t)}` },
          h('span.sfb-ico', { 'aria-hidden': 'true' }, B.TYPES[t]?.icon || '•'), ' ', B.typeLabel(t)))));
      box.append(h('p.sfb-palette-group', g), ul);
      if (window.Sortable) {
        paletteSortables.push(window.Sortable.create(ul, { group: { name: 'sfb', pull: 'clone', put: false }, sort: false, animation: 120, forceFallback: false }));
      }
    }
    box.append(h('p.form-text', 'Click to add after the selected field, or drag onto the form.'));
    return box;
  }

  function fillCanvas() {
    const m = S.model;
    // Starter forms, while a new form is still the untouched starter.
    if ((B.opts.isNew && !S.starterChosen) || S.showStarters) canvasEl.append(starterPanel());

    const tabs = h('div.sfb-pages', { role: 'group', 'aria-label': 'Pages' });
    m.pages.forEach((p, i) => {
      const bad = B.problemsFor((s) => s.page === i).length;
      tabs.append(h('button.btn.btn-sm.sfb-pagetab', {
        type: 'button', 'aria-pressed': String(i === S.page), dataset: { fid: 'page-' + i },
        onclick: () => { S.page = i; S.sel = { kind: 'page', page: i }; B.render(); },
      }, `${i + 1}. ${p.title || p.key}`, bad ? h('span.sfb-dot', { 'aria-label': 'has problems' }, '!') : null));
    });
    tabs.append(B.button('+ Page', addPage, 'btn-outline-secondary', { dataset: { fid: 'page-add' } }));
    canvasEl.append(tabs);

    const page = pageOf(S.page);
    canvasEl.append(h('div.sfb-pagehead',
      h('button.btn.btn-link.p-0.sfb-pagetitle', { type: 'button', dataset: { fid: 'pagehead' }, onclick: () => B.pick({ kind: 'page', page: S.page }, { focusInspector: true }) },
        page.title || `Page ${S.page + 1}`, h('span.visually-hidden', ' (page settings)')),
      h('span.small.text-body-secondary', ` ${page.fields.length} field${page.fields.length === 1 ? '' : 's'}`)));

    const ul = h('ul.sfb-list', { 'aria-label': `Fields on page ${S.page + 1}`, dataset: { list: '' } });
    page.fields.forEach((f, i) => ul.append(card(f, at(S.page, [i]))));
    canvasEl.append(ul);
    if (!page.fields.length) canvasEl.append(h('p.sfb-addhint.text-body-secondary', { tabindex: '-1' }, 'This page is empty. Add a field from the list on the left.'));
    sortable(ul, S.page, []);
  }

  const listPath = (el) => (el.dataset.list === '' ? [] : el.dataset.list.split('.').map(Number));

  /** parentPath: [] for the page, else the path of the section or repeating group. */
  function sortable(ul, page, parentPath) {
    if (!window.Sortable) return;
    const parent = parentPath.length ? B.fieldAt(at(page, parentPath)) : null;
    sortables.push(window.Sortable.create(ul, {
      group: { name: 'sfb', pull: true, put: (to, from, el) => allowedIn(parent, el.dataset.newType || el.dataset.type) },
      handle: '.sfb-handle', draggable: '>.sfb-card', animation: 120, filter: 'input,select,textarea', preventOnFilter: false,
      onAdd: (evt) => {
        const t = evt.item.dataset.newType;
        if (t) { evt.item.remove(); addField(t, { page, parent: parentPath, at: evt.newIndex }); }
      },
      onEnd: (evt) => {
        if (evt.item.dataset.newType) return;
        const from = listPath(evt.from);
        const to = listPath(evt.to);
        if (from.join('.') === to.join('.') && evt.oldIndex === evt.newIndex) return;
        B.update(() => {
          const [f] = listAt(page, from).splice(evt.oldIndex, 1);
          // Taking it out of a list shifts what comes after it there.
          const dst = to.slice();
          if (dst.length > from.length && from.every((v, k) => dst[k] === v) && dst[from.length] > evt.oldIndex) dst[from.length] -= 1;
          listAt(page, dst).splice(evt.newIndex, 0, f);
          S.sel = at(page, dst.concat([evt.newIndex]));
        });
        B.announce('Moved');
      },
    }));
  }

  function badges(f) {
    const out = [];
    if (f.rules?.required) out.push(h('span.sfb-badge', { title: 'Required' }, 'required'));
    if (f.prefill) out.push(h('span.sfb-badge.sfb-badge-fill', { title: 'Fills from ' + B.readable(f.prefill) }, '👤 ' + prefillName(f.prefill)));
    if (f.prefill_lock) out.push(h('span.sfb-badge.sfb-badge-lock', { title: 'Locked for signed-in people' }, '🔒 locked'));
    if (f.width && f.width < 12) out.push(h('span.sfb-badge.sfb-badge-width', { title: `${f.width} of 12 columns on wider screens` }, `▥ ${widthName(f.width)}`));
    if (f.hide_input) out.push(h('span.sfb-badge', { title: 'Shows dots while typing; left out of {data:_all}' }, '•••'));
    if (f.type === 'calc' && f.named) out.push(h('span.sfb-badge', { title: 'Worked out, not shown on the form' }, 'named value'));
    if (f.search) out.push(h('span.sfb-badge', { title: 'Type to search the list' }, 'search'));
    if (f.type === 'section' && f.collapsible) out.push(h('span.sfb-badge', { title: f.collapsed ? 'Starts closed' : 'Starts open' }, f.collapsed ? '▸ closed' : '▾ open'));
    if (f.show_if) out.push(h('span.sfb-badge.sfb-badge-cond', { title: 'Shown when ' + B.condText(f.show_if) }, '⤷ when ' + B.condText(f.show_if)));
    return out;
  }

  function card(f, sel) {
    const selected = sameSel(S.sel, sel);
    const bad = B.problemsFor((s) => s.kind === 'field' && sameSel(s, sel)).length;
    const name = f.type === 'html' ? (String(f.content || '').replace(/<[^>]+>/g, '').slice(0, 60) || 'Text block') : (f.type === 'divider' ? 'Divider' : (f.label || f.key));
    const fidBase = `card-${f.key}`;
    const li = h('li.sfb-card', { class: (selected ? 'is-selected' : '') + (bad ? ' has-problem' : '') + (f.type === 'section' ? ' sfb-card-section' : ''), dataset: { type: f.type, fid: fidBase } });
    const list = listOfSel(sel);
    const pos = posOf(sel);
    li.append(
      h('span.sfb-handle', { 'aria-hidden': 'true', title: 'Drag to move' }, '⋮⋮'),
      h('button.sfb-card-main', {
        type: 'button', 'aria-pressed': String(!!selected), dataset: { fid: fidBase + '-main' },
        onclick: () => B.pick(sel),
        onkeydown: (e) => {
          if (e.altKey && e.key === 'ArrowUp') { e.preventDefault(); move(sel, -1); }
          if (e.altKey && e.key === 'ArrowDown') { e.preventDefault(); move(sel, +1); }
          if (e.key === 'Delete') { e.preventDefault(); remove(sel); }
        },
        'aria-keyshortcuts': 'Alt+ArrowUp Alt+ArrowDown Delete',
      },
        h('span.sfb-card-label', name, f.rules?.required ? h('span.text-danger', { 'aria-hidden': 'true' }, ' *') : null),
        h('span.sfb-card-type', B.typeLabel(f.type), f.type === 'section' ? ` · ${(f.fields || []).length} field${(f.fields || []).length === 1 ? '' : 's'}` : ''),
        h('span.sfb-card-key', f.key),
        bad ? h('span.sfb-dot', { 'aria-label': `${bad} problem${bad > 1 ? 's' : ''}` }, '!') : null,
        h('span.sfb-badges', ...badges(f))),
      h('span.sfb-card-tools',
        B.iconButton('↑', `Move ${name} up`, () => move(sel, -1), { disabled: pos === 0, dataset: { fid: fidBase + '-up' } }),
        B.iconButton('↓', `Move ${name} down`, () => move(sel, +1), { disabled: pos === list.length - 1, dataset: { fid: fidBase + '-down' } }),
        B.iconButton('⧉', `Duplicate ${name}`, () => duplicate(sel), { dataset: { fid: fidBase + '-dup' } }),
        B.iconButton('🗑', `Delete ${name}`, () => remove(sel), { dataset: { fid: fidBase + '-del' } })),
    );
    if (f.type === 'repeater' || f.type === 'section') {
      const path = pathOf(sel);
      const kids = (f.fields ||= []);
      const inner = h('ul.sfb-list.sfb-sublist', { 'aria-label': `Fields in ${name}`, dataset: { list: path.join('.') } });
      kids.forEach((c, ci) => inner.append(card(c, at(sel.page, path.concat([ci])))));
      const types = B.catalog.fieldTypes.filter((t) => allowedIn(f, t));
      const typeSel = B.select('text', types.map((t) => ({ value: t, label: B.typeLabel(t) })), () => {},
        { 'aria-label': `Type of field to add to ${name}`, dataset: { fid: fidBase + '-addtype' } });
      li.append(h(f.type === 'section' ? 'div.sfb-rep.sfb-sec-fields' : 'div.sfb-rep', inner,
        !kids.length ? h('p.small.text-body-secondary.mb-1.sfb-sec-empty', f.type === 'section' ? 'Empty: drag fields here, or add one below.' : 'Add the fields of one row.') : null,
        h('div.sfb-rep-add', typeSel,
          B.button(`Add to ${name}`, () => addField(typeSel.value, { page: sel.page, parent: path, at: kids.length }), 'btn-outline-secondary', { dataset: { fid: fidBase + '-add' } }))));
      sortable(inner, sel.page, path);
    }
    return li;
  }

  /* ---------------------------------------------------------- starters */

  function starterPanel() {
    const names = { blank: ['Blank', 'One empty page that saves what people send.'],
      contact: ['Contact', 'Problems go to support, the rest to you; the sender gets a copy.'],
      starter: ['Visitor request', 'Two pages, cascading lists, a repeating group of visitors.'], approval: ['Request with approval', 'A supervisor picker, approval steps, locked name and email.'],
      // 0.12.0
      event: ['Event registration', 'Places per session, a closing date, a confirmation with a PDF.'],
      order: ['Order form', 'Items with running totals, delivery, Check your answers.'],
      volunteer: ['Volunteer application', 'Several pages, a page that only shows when needed, a file, save and finish later.'],
      agreement: ['Agreement (e-signature)', 'Signed in, an agreement and an e-signature with a disclosure. Needs “Use e-signatures”.'],
      report: ['Report a problem', 'For error pages: filled in from the web address (?code=404&page=…).'],
      // 0.12.1
      survey: ['Feedback survey', 'Rating, grid, slider, ranking, image choice; a page only for low scores; Check your answers.'],
      timeoff: ['Time-off request', 'Signed in: date range, part-day times, a day count, manager then HR approval.'],
      membership: ['Membership application', 'Name and address blocks, masks, a family repeater, a look-up, save and finish later.'],
      showcase: ['Every field type', 'One page per group, every field SquidForm has. Good for trying things out.'] };
    const first = B.opts.isNew && !S.starterChosen;
    const box = h('section.sfb-starters', { 'aria-labelledby': 'sfb-starters-h' }, h('h3.h6#sfb-starters-h', { tabindex: '-1' }, first ? 'Start from' : 'Start from a template'));
    if (!first) {
      box.append(h('p.small.mb-2', h('strong', 'This replaces the whole form: '), 'its fields, logic, approvals and settings. Undo brings it back, and nothing is published until you press Save & publish.',
        B.state.liveKeys.size ? ' Answers already sent keep their own columns; fields with new keys start new ones.' : ''));
    }
    const row = h('div.sfb-starter-row');
    for (const s of B.catalog.starters) {
      // 1.0.3: add-on starters bring their own title and description.
      const [title, desc] = names[s.id] || [s.title || s.id, s.description || ''];
      const hid = `sfb-st-${s.id}`;
      row.append(h('div.sfb-starter', { role: 'group', 'aria-labelledby': hid },
        h('h4.h6.mb-1', { id: hid }, title),
        h('p.small.mb-2', desc),
        h('button.btn.btn-sm.btn-outline-primary', { type: 'button', dataset: { fid: 'starter-' + s.id }, 'aria-label': `Use this: ${title}`,
          onclick: () => {
            S.starterChosen = true;
            S.showStarters = false;
            B.update((m) => { const next = JSON.parse(s.json); Object.keys(m).forEach((k) => delete m[k]); Object.assign(m, next); S.page = 0; S.sel = null; });
            B.announce(`${title} template loaded. Undo brings back what was there.`);
          } }, 'Use this')));
    }
    box.append(row, B.button(first ? 'Not now' : 'Close', () => { S.starterChosen = true; S.showStarters = false; B.render(); }, 'btn-link px-0 mt-1', { dataset: { fid: 'starter-close' } }));
    return box;
  }

  /** "Start from a template" in the top bar. */
  B.showStarters = () => {
    S.section = 'fields';
    S.showStarters = true;
    B.render();
    document.getElementById('sfb-starters-h')?.focus();
  };

  /* ---------------------------------------------------------- inspector */

  function inspector() {
    const box = h('div.sfb-inspector', { role: 'region', 'aria-label': 'Settings for the selected item' });
    const sel = S.sel;
    if (sel && sel.kind === 'field' && B.fieldAt(sel)) fieldInspector(box, B.fieldAt(sel), sel);
    else if (sel && sel.kind === 'page' && pageOf(sel.page)) pageInspector(box, pageOf(sel.page), sel.page);
    else box.append(h('h3.h6', 'Settings'), h('p.text-body-secondary.small', 'Select a field to change its label, rules, where its value comes from and when it shows. Select the page title for the page’s own settings.'));
    return box;
  }

  const set = (obj, path, v) => {
    const parts = path.split('.');
    let o = obj;
    for (let i = 0; i < parts.length - 1; i++) o = (o[parts[i]] && typeof o[parts[i]] === 'object') ? o[parts[i]] : (o[parts[i]] = {});
    const last = parts[parts.length - 1];
    if (v === undefined || v === '' || v === false) delete o[last]; else o[last] = v;
    // Tidy empty containers (rules: {}).
    if (parts.length > 1 && Object.keys(o).length === 0) set(obj, parts.slice(0, -1).join('.'), undefined);
  };
  /** Change one property while typing: no full re-render. */
  const typing = (sel, path) => (v) => B.update(() => set(B.fieldAt(sel), path, v), { structural: false, merge: `${sel.page}.${pathOf(sel).join('.')}.${path}` });
  const now = (sel, path) => (v) => B.update(() => set(B.fieldAt(sel), path, v));

  function section(title, ...kids) {
    return h('details.sfb-sec', { open: true }, h('summary', title), h('div.sfb-sec-body', ...kids));
  }

  function fieldInspector(box, f, sel) {
    const t = f.type;
    const fid = (s) => ({ dataset: { fid: `ins-${s}` } });
    box.append(h('h3.h6.sfb-ins-title', B.typeLabel(t), h('span.sfb-card-key.ms-2', f.key)));
    const probs = B.problemsFor((s) => s.kind === 'field' && sameSel(s, sel));
    if (probs.length) box.append(h('div.alert.alert-danger.py-2.small', probs.map((p) => h('div', p.message))));

    // ---- basics
    const basics = [];
    if (t !== 'html' && t !== 'divider') {
      basics.push(B.row('Label', B.input(f.label, (v) => {
        const fx = B.fieldAt(sel);
        const autoKey = S.fresh.has(fx);
        B.update(() => {
          set(fx, 'label', v);
          if (autoKey && !B.state.liveKeys.has(fx.key) && v.trim()) {
            const others = B.allKeys().filter((k) => k !== fx.key);
            fx.key = B.uniqueKey((fx.type === 'section' ? 's_' : 'f_') + B.slug(v), others);
          }
        }, { structural: false, merge: `label.${f.key}` });
      }, fid('label'))));
    }
    if (B.valueType(t) && t !== 'hidden' && t !== 'checkbox') basics.push(B.row('Help text', B.input(f.help, typing(sel, 'help'), fid('help')), 'Shown under the box and read out by screen readers.'));
    if (t === 'section') basics.push(B.row('Line of text under the heading', B.input(f.help, typing(sel, 'help'), fid('help')), 'Optional.'));
    if (['text', 'textarea', 'email', 'number', 'tel', 'url'].includes(t)) basics.push(B.row('Example text in the empty box', B.input(f.placeholder, typing(sel, 'placeholder'), fid('ph')), 'Never use it instead of a label.'));
    if (B.valueType(t) && !['hidden', 'slider', 'calc'].includes(t)) basics.push(B.check('Required', f.rules?.required, (v) => now(sel, 'rules.required')(v || undefined)));
    if (t === 'html') {
      basics.push(B.row('Style', B.select(f.tag || 'p', [['p', 'Paragraph'], ['h2', 'Heading 2'], ['h3', 'Heading 3'], ['h4', 'Heading 4'], ['div', 'Block']].map(([value, label]) => ({ value, label })), now(sel, 'tag'), fid('tag'))));
      basics.push(B.row('Text', B.textarea(f.content, typing(sel, 'content'), { rows: 5, ...fid('content') }), 'Simple HTML is allowed; anything else is removed.'), B.htmlInfo());
      // "Show parts of this text by" a field (0.9.1)
      const avail = B.fieldsUpTo(sel.page, repeaterOf(sel)).filter((x) => B.valueType(x.type));
      basics.push(B.row('Show parts of this text by', B.select(f.parts_by || '', [{ value: '', label: '— the whole text always shows —' }, ...avail.map((x) => ({ value: x.key, label: x.label }))],
        (v) => now(sel, 'parts_by')(v || undefined), fid('parts')),
        f.parts_by ? h('span', 'Each part marked ', h('code', '<div data-show-when="Contractor">…</div>'), ' shows only while that field is Contractor. Several answers: ', h('code', 'data-show-when="Visitor|Contractor"'), '. Use the stored value, and the Block style for divs.')
          : 'Pick a field to show different parts of this text for different answers, without JavaScript.'));
    }
    if (t === 'divider') {
      basics.push(B.row('Space above and below', B.select(f.space || 'medium', [['none', 'None'], ['small', 'A little'], ['medium', 'Some'], ['large', 'A lot']].map(([value, label]) => ({ value, label })),
        (v) => now(sel, 'space')(v === 'medium' ? undefined : v), fid('space'))));
    }
    box.append(section('Basics', ...basics));

    // ---- layout: sections and column widths
    const look = layoutSettings(f, sel);
    if (look.length) box.append(section(t === 'section' ? 'How the section looks' : 'Layout', ...look));

    // ---- fill from signed-in user (obvious, on every value field)
    if (B.valueType(t) && !B.MULTI.includes(t) && !['user', 'editor', 'signature', 'grid', 'name', 'address', 'upload', 'ranking', 'daterange', 'phone', 'consent', 'imagechoice', 'calc'].includes(t)) box.append(prefillSection(f, sel, URL_TYPES.includes(t) && !repeaterOf(sel)));
    else if (URL_TYPES.includes(t) && !repeaterOf(sel)) box.append(section('Where the value comes from', ...urlFill(f, sel)));

    // ---- choices
    if (['select', 'multiselect', 'radios', 'checkboxes', 'lookup'].includes(t)) box.append(section(t === 'lookup' ? 'What it searches' : 'Choices', optionsEditor(f, sel)));
    if (t === 'grid') box.append(section('Questions and answers', gridEditor(f, sel)));
    if (t === 'user') box.append(section('Who can be picked', userEditor(f, sel)));

    // ---- type specifics
    const spec = typeSpecific(f, sel);
    if (spec.length) box.append(section('Rules', ...spec));

    // ---- show if
    const available = B.fieldsUpTo(sel.page, repeaterOf(sel)).filter((x) => x.key !== f.key && !(f.type === 'section' && (f.fields || []).some((c) => c.key === x.key)));
    box.append(section('When to show it', B.conditionEditor({
      value: f.show_if, fields: available, noneLabel: 'Always show it', someLabel: 'Show it only when', fid: `ins-cond-${f.key}`,
      onChange: (c, o) => B.update(() => set(B.fieldAt(sel), 'show_if', c), o?.typing ? { structural: false, merge: `cond.${f.key}` } : {}),
    }), t === 'section' ? h('p.form-text', 'Applies to the heading and every field after it, up to the next section.') : null,
    f.show_if ? h('p.form-text', 'When hidden, its value is removed on the server too.') : null));

    // ---- advanced
    const adv = [];
    adv.push(keyEditor(f, sel));
    if (!repeaterOf(sel) && S.model.pages.length > 1) {
      adv.push(B.row('Move to page', B.select(String(sel.page), S.model.pages.map((p, i) => ({ value: i, label: `${i + 1}. ${p.title || p.key}` })), (v) => moveToPage(sel, Number(v)), fid('page'))));
    }
    const CHANGEABLE = ['select', 'multiselect', 'radios', 'checkboxes'];
    const kinds = B.TEXTLIKE.includes(t) ? B.TEXTLIKE : (CHANGEABLE.includes(t) ? CHANGEABLE : [t]);
    if (kinds.length > 1) adv.push(B.row('Change type', B.select(t, kinds.filter((k) => B.catalog.fieldTypes.includes(k)).map((k) => ({ value: k, label: B.typeLabel(k) })), (v) => B.update(() => {
      const fx = B.fieldAt(sel);
      fx.type = v;
      if (B.MULTI.includes(v) && fx.default !== undefined && !Array.isArray(fx.default)) fx.default = [fx.default];
      if (!B.MULTI.includes(v) && Array.isArray(fx.default)) fx.default = fx.default[0];
      if (!B.MULTI.includes(v) && fx.rules) { delete fx.rules.min_selected; delete fx.rules.max_selected; }
      if (v !== 'textarea') delete fx.rows;
      if (!['text', 'tel'].includes(v)) delete fx.mask;
      if (v !== 'text') delete fx.hide_input;
      if (!['text', 'textarea'].includes(v)) delete fx.counter;
    }), fid('type'))));
    if (['text', 'email', 'tel', 'url', 'number', 'date', 'time'].includes(t)) adv.push(B.row('Autocomplete', B.input(f.autocomplete, typing(sel, 'autocomplete'), { list: 'sfb-ac', ...fid('ac') }), 'For example name, email or tel. Helps everyone fill it in, and is required for accessibility on personal details.'));
    if (B.valueType(t) && !['hidden', 'editor', 'rating', 'upload', 'signature', 'consent', 'ranking', 'imagechoice', 'calc'].includes(t)) adv.push(B.check('Read-only in the browser', f.readonly, (v) => now(sel, 'readonly')(v || undefined), 'Not enforced by the server. To really lock it, use “Lock it” above.'));
    if ((B.valueType(t) && t !== 'calc') || t === 'repeater') adv.push(B.check('Staff can change it when editing a submission', f.editable !== false, (v) => now(sel, 'editable')(v ? undefined : false)));
    adv.push(jsonEditor(f, sel));
    box.append(h('details.sfb-sec', h('summary', 'Advanced'), h('div.sfb-sec-body', ...adv)));
    box.append(h('datalist#sfb-ac', ['name', 'given-name', 'family-name', 'email', 'tel', 'organization', 'street-address', 'postal-code', 'country-name', 'bday', 'url', 'username'].map((v) => h('option', { value: v }))));
  }

  /* ---- prefill: "Fill from signed-in user" + "Lock it" */

  const PREFILLS = [
    ['', 'Don’t fill it in'], ['{user:name}', 'Their name'], ['{user:email}', 'Their email'], ['{user:username}', 'Their username'], ['{user:id}', 'Their user id'],
  ];
  function prefillName(p) {
    const k = PREFILLS.find((x) => x[0] === p);
    if (k) return k[1].replace('Their ', '').replace('Don’t fill it in', '');
    const m = String(p).match(/^\{user\.field:([^}]+)\}$/);
    if (m) return (B.catalog.userFields.find((u) => u.name === m[1])?.title || m[1]);
    return B.readable(p);
  }
  /** Fields a web address may fill (0.10.0); the same list as Schema::URL_TYPES. */
  const URL_TYPES = ['text', 'textarea', 'email', 'number', 'tel', 'url', 'date', 'time', 'datetime', 'hidden', 'select', 'radios', 'checkboxes', 'checkbox', 'multiselect', 'rating', 'slider'];

  /** "Can be filled from the web address" (0.10.0): ?dept=HR fills the field. */
  function urlFill(f, sel) {
    const on = typeof f.url_param === 'string';
    const kids = [B.check('Can be filled from the web address', on, (v) => B.update(() => {
      const fx = B.fieldAt(sel);
      if (v) fx.url_param = B.slug(fx.key.replace(/^f_/, '')).replace(/_/g, '-').slice(0, 40) || 'value';
      else delete fx.url_param;
    }), 'A link like …/form?dept=HR fills it in, for example from an email or another page.')];
    if (on) {
      kids.push(B.row('Name in the web address', B.input(f.url_param, typing(sel, 'url_param'), { class: 'font-monospace', dataset: { fid: 'ins-urlp' } }),
        h('span', 'For example ', h('code', `?${f.url_param || 'dept'}=${B.MULTI.includes(f.type) ? 'a,b' : 'HR'}`), B.MULTI.includes(f.type) ? ' (commas between several).' : '.',
          ' Anyone can change a link, so it’s still checked like a typed answer; don’t use it for anything that must be trusted. Only fills the field when it’s empty.')));
    }
    return kids;
  }

  function prefillSection(f, sel, withUrl = false) {
    const cur = f.prefill ?? '';
    const isProfile = /^\{user\.field:[^}]+\}$/.test(cur);
    const isSession = /^\{session:[^}]+\}$/.test(cur);
    const known = PREFILLS.some((x) => x[0] === cur);
    const choice = known ? cur : (isProfile ? 'profile' : (isSession ? 'session' : 'custom'));
    const choices = PREFILLS.map(([value, label]) => ({ value, label }));
    if (B.catalog.userFields.length) choices.push({ value: 'profile', label: 'A profile field…' });
    if (B.catalog.sessionKeys.length || isSession) choices.push({ value: 'session', label: 'Session data…' });
    choices.push({ value: 'custom', label: 'Something else (placeholder)…' });

    const kids = [B.row('Fill from signed-in user', B.select(choice, choices, (v) => B.update(() => {
      const fx = B.fieldAt(sel);
      if (v === '') { delete fx.prefill; delete fx.prefill_lock; } else if (v === 'profile') fx.prefill = `{user.field:${B.catalog.userFields[0].name}}`;
      else if (v === 'session') fx.prefill = `{session:${B.catalog.sessionPaths.find((p) => !p.list)?.path || (B.catalog.sessionKeys[0] || 'abcdefuser') + '.employeenumber'}}`;
      else if (v === 'custom') fx.prefill = fx.prefill && !known ? fx.prefill : '{date:Y-m-d}';
      else fx.prefill = v;
    }), { dataset: { fid: 'ins-prefill' } }), 'Guests see it empty and can type.')];

    if (choice === 'profile') {
      const m = cur.match(/^\{user\.field:([^}]+)\}$/);
      kids.push(B.row('Profile field', B.select(m ? m[1] : '', B.catalog.userFields.map((u) => ({ value: u.name, label: u.title })), (v) => B.update(() => { B.fieldAt(sel).prefill = `{user.field:${v}}`; }), { dataset: { fid: 'ins-prefill-pf' } })));
    }
    if (choice === 'session') {
      // 0.15.0: values another script put in the session (Options → Data Sources → Session data forms may read).
      const m = cur.match(/^\{session:([^}]+)\}$/);
      const paths = B.catalog.sessionPaths.map((p) => ({ value: p.path, label: p.list ? `${p.path} (a list of ${p.list})` : `${p.path}${p.sample ? ' (now: ' + p.sample + ')' : ''}` }));
      kids.push(B.row('Session path', paths.length
        ? B.select(m ? m[1] : '', paths.some((p) => p.value === (m && m[1])) ? paths : [{ value: m ? m[1] : '', label: m ? m[1] : '' }].concat(paths), (v) => B.update(() => { B.fieldAt(sel).prefill = `{session:${v}}`; }), { dataset: { fid: 'ins-prefill-session' } })
        : B.input(m ? m[1] : '', (v) => typing(sel, 'prefill')(`{session:${v.trim()}}`), { class: 'font-monospace', dataset: { fid: 'ins-prefill-session' } }),
        'A dotted path such as abcdefuser.employeenumber; abcdefuser.fineaccesscontrol.0 is a list’s first entry. The list shows what’s in your own session now. Locked unless you untick below.'));
    }
    if (choice === 'custom') {
      kids.push(B.row('Value', B.input(cur, typing(sel, 'prefill'), { class: 'font-monospace', dataset: { fid: 'ins-prefill-custom' } }), 'Any placeholder, for example {date:Y-m-d} or {setting:office}.'));
    }
    if (f.prefill && choice === 'session') {
      kids.push(B.check('Lock it: people can’t change it', f.prefill_lock !== false, (v) => now(sel, 'prefill_lock')(v ? undefined : false),
        'Locked by default for session data: the server puts the value back on submit, so it can’t be changed by editing the page.'));
    } else if (f.prefill) {
      kids.push(B.check('Lock it: signed-in people can’t change it', f.prefill_lock, (v) => now(sel, 'prefill_lock')(v || undefined),
        'The server puts the value back on submit, so it can’t be changed by editing the page.'));
    }
    if (withUrl) kids.push(...urlFill(f, sel));
    if (f.type !== 'checkbox') kids.push(B.row('Starting value (when nothing is filled in)', f.type === 'checkboxes' ? B.input((f.default || []).join(', '), (v) => typing(sel, 'default')(v ? v.split(',').map((s) => s.trim()) : undefined)) : B.input(f.default, typing(sel, 'default'), { dataset: { fid: 'ins-default' } })));
    return section('Where the value comes from', ...kids);
  }

  /* ---- options */

  /** "f_topic:static" → that field's options when it used that source. */
  const sourceMemory = new Map();

  function optionsEditor(f, sel) {
    const o = f.options || {};
    const src = o.table ? 'table' : (o.file ? 'file' : (o.user_field !== undefined ? 'user' : (o.form ? 'form' : (o.addon !== undefined ? 'addon' : 'static'))));
    const shared = B.catalog.sharedForms || [];
    const addons = B.catalog.optionSources || [];
    const listFields = B.catalog.userFields.filter((u) => u.options.length);
    const wrap = h('div');
    const name = B.id('src');
    const radio = (val, label) => {
      const id = B.id('r');
      return h('div.form-check.form-check-inline', h('input.form-check-input', { type: 'radio', name, id, checked: src === val, dataset: { fid: 'ins-src-' + val },
        onchange: () => B.update(() => {
          const fx = B.fieldAt(sel);
          // Keep what this source had, so switching back brings it back (this session).
          if (fx.options) sourceMemory.set(`${fx.key}:${src}`, JSON.parse(JSON.stringify(fx.options)));
          const kept = sourceMemory.get(`${fx.key}:${val}`);
          if (kept) fx.options = JSON.parse(JSON.stringify(kept));
          else if (val === 'static') fx.options = { static: ['Option 1', 'Option 2'] };
          else if (val === 'file') fx.options = { file: { path: B.catalog.optionFiles[0] || '' } };
          else if (val === 'user') fx.options = { user_field: listFields[0]?.name || '' };
          else if (val === 'form') { const sf = shared[0]; fx.options = { form: { alias: sf?.alias || '', value: sf?.fields[0]?.key || '', label: sf?.fields[1]?.key || sf?.fields[0]?.key || '' } }; }
          else if (val === 'addon') fx.options = { addon: addons[0]?.name || '' };
          else if (val === 'table') { const tb = B.catalog.tables[0]; fx.options = { table: { name: tb?.name || '', value: tb?.columns[0] || 'id', label: tb?.columns[1] || tb?.columns[0] || 'title' } }; }
        }) }), h('label.form-check-label', { for: id }, label));
    };
    wrap.append(h('div.mb-2', { role: 'radiogroup', 'aria-label': 'Where the choices come from' }, radio('static', 'A list'), radio('file', 'A file'), B.catalog.tables.length || src === 'table' ? radio('table', 'A database table') : null,
      listFields.length || src === 'user' ? radio('user', 'A Joomla user field') : null,
      shared.length || src === 'form' ? radio('form', 'Another form') : null,
      addons.length || src === 'addon' ? radio('addon', 'An add-on’s list') : null));

    if (src === 'static') wrap.append(staticList(f, sel));
    if (src === 'file') wrap.append(fileSource(f, sel));
    if (src === 'table') wrap.append(tableSource(f, sel));
    if (src === 'user') wrap.append(userFieldSource(f, sel, listFields));
    if (src === 'form') wrap.append(formSource(f, sel, shared));
    if (src === 'addon') wrap.append(addonSource(f, sel, addons));
    if (f.type === 'select') wrap.append(B.row('Text for “nothing chosen”', B.input(f.empty_label, typing(sel, 'empty_label'), { placeholder: '- Select -', dataset: { fid: 'ins-empty' } })));
    if (f.type === 'select') wrap.append(B.check('Type to search the list', f.search, (v) => B.update(() => { const t = B.fieldAt(sel); if (v) t.search = true; else { delete t.search; delete t.striped; } }),
      'For long lists, like countries or staff. People type part of a choice and pick from the matches; screen readers hear how many match. Without JavaScript it stays a plain dropdown.'));
    // 0.14.0: stripes between options, where SquidForm (or a list box) draws the list.
    if (f.type === 'multiselect' || f.type === 'select') {
      const can = f.type === 'multiselect' || !!f.search;
      const box = B.check('Stripe the options', f.striped, (v) => now(sel, 'striped')(v || undefined),
        can ? 'A thin line and light shading between options, easier to follow in a long list.' : 'Turn on Type to search the list to stripe the options.');
      const input = box.querySelector('input');
      input.dataset.fid = 'ins-striped';
      if (!can && !f.striped) input.disabled = true;
      wrap.append(box);
    }
    if (f.type === 'multiselect') wrap.append(h('p.form-text.sfb-hint', { dataset: { fid: 'ins-multi-hint' } }, 'Choosing several from a short list? Checkboxes are easier, and can go in columns.'));
    // 0.13.2: one Layout choice for radios and checkboxes. JSON may set 4 to 12
    // columns; the builder shows and keeps such a value.
    if (['radios', 'checkboxes'].includes(f.type)) {
      const n = Number(f.columns) || 1;
      const cur = f.inline ? 'row' : String(n);
      const opts = [['1', 'One column'], ['2', 'Two columns'], ['3', 'Three columns'], ['row', 'In a row']];
      if (!f.inline && n > 3) opts.push([String(n), `${n} columns (set in JSON)`]);
      wrap.append(B.row('Layout', B.select(cur, opts.map(([value, label]) => ({ value, label })), (v) => B.update(() => {
        const t = B.fieldAt(sel);
        delete t.inline; delete t.columns;
        if (v === 'row') t.inline = true; else if (v !== '1') t.columns = Number(v);
      }), { dataset: { fid: 'ins-layout' } })),
        h('div.form-text', 'Columns fill down, then across (A B C | D E F). Phones show one column (two for more than three); in a row, choices wrap as needed.'));
    }
    return wrap;
  }

  /* 1.0.3: another form's answers (that form ticks them in Settings → Share as choices). */
  function formSource(f, sel, shared) {
    const fo = f.options.form;
    const cur = shared.find((x) => x.alias === fo.alias);
    const upd = (k) => (v) => B.update(() => { const t = B.fieldAt(sel).options.form; if (v === '' || v === undefined) delete t[k]; else t[k] = v; });
    const fieldChoices = (cur ? cur.fields : []).map((x) => ({ value: x.key, label: `${x.label} (${x.key})` }));
    const kids = [
      B.row('Form', B.select(fo.alias, [{ value: '', label: '— choose —' }].concat(shared.map((x) => ({ value: x.alias, label: x.title }))).concat(cur || !fo.alias ? [] : [{ value: fo.alias, label: `${fo.alias} (not shared)` }]),
        (v) => B.update(() => { const nx = shared.find((x) => x.alias === v); B.fieldAt(sel).options.form = { alias: v, value: nx?.fields[0]?.key || '', label: nx?.fields[1]?.key || nx?.fields[0]?.key || '' }; }), { dataset: { fid: 'ins-form-alias' } }),
        'Only forms that share some fields (their Settings → Share as choices) are listed.'),
      B.row('Stored value', B.select(fo.value, fieldChoices, upd('value'), { dataset: { fid: 'ins-form-value' } })),
      B.row('Shown as', B.select(fo.label || fo.value, fieldChoices, upd('label'), { dataset: { fid: 'ins-form-label' } })),
      B.row('Sort by', B.select(fo.order || '', [{ value: '', label: 'When they were sent' }].concat(fieldChoices.map((c) => ({ value: c.value + ' asc', label: c.label }))), upd('order'), { dataset: { fid: 'ins-form-order' } })),
      h('p.form-text', 'Choices come from submissions that were sent or approved, aren’t archived and aren’t tests. To leave some out (a switched-off category, say), add a condition in JSON: ', h('code', '"where": {"all": [{"field": "f_on", "op": "eq", "value": true}]}'), '.'),
    ];
    if (fo.where) kids.push(h('p.form-text', h('strong', 'Only some: '), 'this list has a condition (set in JSON).'));
    return h('div', ...kids);
  }

  /* 1.0.3: a list an add-on offers. */
  function addonSource(f, sel, addons) {
    const cur = f.options.addon;
    const choices = addons.map((a) => ({ value: a.name, label: a.title }));
    if (cur && !addons.some((a) => a.name === cur)) choices.unshift({ value: cur, label: `${cur} (no add-on offers it now)` });
    return B.row('List', B.select(cur, choices, (v) => B.update(() => { B.fieldAt(sel).options = { addon: v }; }), { dataset: { fid: 'ins-addon' } }),
      'Kept up to date by the add-on.');
  }

  function staticList(f, sel) {
    const items = B.staticOptions(f);
    const capped = items.some((x) => x.max !== undefined);
    const separate = Array.isArray(f.options.static) && f.options.static.some((x) => x && typeof x === 'object' && (x.value !== x.label || x.max === undefined));
    const wrap = h('div.sfb-opts');
    const canCap = ['select', 'radios', 'checkboxes', 'multiselect'].includes(f.type) && !repeaterOf(sel);
    // Places (0.10.0) need {value, label, max}; the rest stay as simple as they were.
    const write = (list, sep, o = {}) => B.update(() => {
      const anyMax = list.some((x) => x.max !== undefined);
      B.fieldAt(sel).options = { static: sep || anyMax ? list.map((x) => (x.max !== undefined ? { value: x.value, label: x.label, max: x.max } : { value: x.value, label: x.label })) : list.map((x) => x.label) };
    }, o);
    const ul = h('ol.sfb-optlist');
    items.forEach((it, i) => {
      const parts = [B.input(it.label, (v) => { items[i].label = v; if (!separate) items[i].value = v; write(items, separate, { structural: false, merge: `opt.${f.key}.${i}.l` }); },
        { 'aria-label': `Choice ${i + 1}`, dataset: { fid: `opt-${i}-l` } })];
      if (separate) parts.push(B.input(it.value, (v) => { items[i].value = v; write(items, true, { structural: false, merge: `opt.${f.key}.${i}.v` }); },
        { 'aria-label': `Stored value of choice ${i + 1}`, class: 'font-monospace', placeholder: 'stored value', dataset: { fid: `opt-${i}-v` } }));
      if (capped) parts.push(B.number(it.max, (v) => { items[i].max = v === undefined || v === '' ? 0 : Number(v); write(items, separate, { structural: false, merge: `opt.${f.key}.${i}.m` }); },
        { min: 0, class: 'sfb-places', 'aria-label': `Places for choice ${i + 1}`, title: 'Places', dataset: { fid: `opt-${i}-max` } }));
      parts.push(
        B.iconButton('↑', `Move choice ${i + 1} up`, () => { items.splice(i - 1, 0, items.splice(i, 1)[0]); write(items, separate); }, { disabled: i === 0, dataset: { fid: `opt-${i}-up` } }),
        B.iconButton('↓', `Move choice ${i + 1} down`, () => { items.splice(i + 1, 0, items.splice(i, 1)[0]); write(items, separate); }, { disabled: i === items.length - 1, dataset: { fid: `opt-${i}-dn` } }),
        B.iconButton('✕', `Remove choice ${i + 1}`, () => { items.splice(i, 1); write(items, separate); }, { dataset: { fid: `opt-${i}-x` } }));
      ul.append(h('li.sfb-opt', ...parts));
    });
    const paste = B.textarea(items.map((x) => (separate ? `${x.value}|${x.label}` : x.label)).join('\n'), () => {}, { rows: 5, 'aria-label': 'All choices, one per line', dataset: { fid: 'opt-paste' } });
    // DOM append() would print a null as the text "null" (1.0.4 fix).
    wrap.append(...[ul,
      h('div.d-flex.gap-2.flex-wrap',
        B.button('+ Add a choice', () => { items.push({ value: `Option ${items.length + 1}`, label: `Option ${items.length + 1}` }); write(items, separate); }, 'btn-outline-secondary', { dataset: { fid: 'opt-add' } })),
      B.check('Store a different value than people see', separate, (v) => write(items, v), 'For example show “East office” but store “east”.'),
      canCap ? B.check('Limit the places for each choice', capped, (v) => {
        items.forEach((x) => { if (v) x.max = x.max ?? 20; else delete x.max; });
        B.update(() => {
          const fx = B.fieldAt(sel);
          fx.options = { static: v || separate ? items.map((x) => (v ? { value: x.value, label: x.label, max: x.max } : { value: x.value, label: x.label })) : items.map((x) => x.label) };
          if (!v) delete fx.show_left;
        });
      }, 'Each choice holds a number of people (a workshop session, say). Full ones show “(full)” and can’t be picked.') : null,
      canCap && capped ? B.check('Show how many places are left', f.show_left, (v) => now(sel, 'show_left')(v || undefined), 'For example “Session 1 (3 places left)”.') : null,
      h('details.mt-2', h('summary.small', 'Paste or type the whole list'),
        h('p.form-text', separate ? 'One per line, as value|label.' : 'One choice per line.'), paste,
        B.button('Use this list', () => {
          const list = paste.value.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
            const [a, b] = l.split('|');
            return separate ? { value: a.trim(), label: (b ?? a).trim() } : { value: l, label: l };
          });
          write(list, separate);
        }, 'btn-outline-primary mt-1'))].filter(Boolean));
    return wrap;
  }

  function filterBy(f, sel, kind) {
    const src = f.options[kind];
    const avail = B.fieldsUpTo(sel.page, repeaterOf(sel)).filter((x) => x.key !== f.key);
    const fb = src.filter_by;
    const kids = [B.check('Only show rows that match another field (cascading list)', !!fb, (v) => B.update(() => {
      const s = B.fieldAt(sel).options[kind];
      if (v) s.filter_by = kind === 'file' ? { field: avail[0]?.key || '', key: '' } : { field: avail[0]?.key || '', column: '' };
      else delete s.filter_by;
    }))];
    if (fb) {
      kids.push(B.row('Field', B.select(fb.field, avail.map((x) => ({ value: x.key, label: x.label })), (v) => B.update(() => { B.fieldAt(sel).options[kind].filter_by.field = v; }), { dataset: { fid: 'ins-fb-f' } })));
      if (kind === 'file') kids.push(B.row('Matches this column in the file', B.input(fb.key, (v) => B.update(() => { B.fieldAt(sel).options.file.filter_by.key = v; }, { structural: false, merge: 'fbk' }), { dataset: { fid: 'ins-fb-k' } })));
      else {
        const cols = B.catalog.tables.find((x) => x.name === src.name)?.columns || [];
        kids.push(B.row('Matches this table column', B.select(fb.column, [{ value: '', label: '— choose —' }, ...cols.map((c) => ({ value: c, label: c }))], (v) => B.update(() => { B.fieldAt(sel).options.table.filter_by.column = v; }), { dataset: { fid: 'ins-fb-c' } })));
      }
    }
    return h('div.mt-2', ...kids);
  }

  /** 0.14.0: the choices a Joomla List, Radio or Checkboxes user field offers (its list, not anyone's answer). */
  function userFieldSource(f, sel, listFields) {
    const cur = f.options.user_field;
    const choices = listFields.map((u) => ({ value: u.name, label: `${u.title} (${u.options.length} choices)` }));
    if (!choices.some((c) => c.value === cur)) choices.unshift({ value: cur, label: cur ? `${cur} (not found, or has no choices)` : '— choose —' });
    return h('div',
      B.row('User field', B.select(cur, choices, (v) => B.update(() => { B.fieldAt(sel).options.user_field = v; }), { dataset: { fid: 'ins-ufield' } }),
        h('span', 'Uses the choices set up in Users → Fields. Change them there and every form using them follows. To fill in the person’s own answer, use “Where the value comes from” → A profile field.')));
  }

  function fileSource(f, sel) {
    const file = f.options.file;
    const files = B.catalog.optionFiles;
    const folder = B.catalog.optionsFolder || 'images/squidform/options';
    const choices = files.map((p) => ({ value: p, label: p }));
    if (file.path && !files.includes(file.path)) choices.unshift({ value: file.path, label: `${file.path} (not found)` });
    if (!choices.length) choices.push({ value: '', label: 'No files in the folder yet' });
    return h('div',
      B.row('File', B.select(file.path, choices, (v) => B.update(() => { B.fieldAt(sel).options.file.path = v; }), { dataset: { fid: 'ins-file' } }),
        h('span', 'From the site folder ', h('code', folder + '/'), ' (set in SquidForm → Options → Data Sources → Option Files Folder). '
          + 'JSON (a list), CSV (value and label columns) or TXT (one per line). To add one, put the file in that folder (for example by FTP).')),
      filterBy(f, sel, 'file'));
  }

  function tableSource(f, sel) {
    const tb = f.options.table;
    const info = B.catalog.tables.find((x) => x.name === tb.name);
    const cols = info?.columns || [];
    const colChoices = (cur) => {
      const c = cols.map((x) => ({ value: x, label: x }));
      if (cur && !cols.includes(cur)) c.unshift({ value: cur, label: `${cur} (not found)` });
      return c;
    };
    const tables = B.catalog.tables.map((x) => ({ value: x.name, label: x.name }));
    if (tb.name && !info) tables.unshift({ value: tb.name, label: `${tb.name} (not on the allow-list)` });
    const upd = (k) => (v) => B.update(() => { const t = B.fieldAt(sel).options.table; if (v === '' || v === undefined) delete t[k]; else t[k] = v; });
    const [ocol, odir] = String(tb.order || '').split(/\s+/);

    // where: the same picker, with columns standing in for fields.
    const asField = (c) => (c && typeof c === 'object' ? JSON.parse(JSON.stringify(c).replace(/"col":/g, '"field":')) : c);
    const asCol = (c) => (c && typeof c === 'object' ? JSON.parse(JSON.stringify(c).replace(/"field":/g, '"col":')) : c);
    const colFields = cols.map((c) => ({ key: c, label: c, type: 'text', f: {} }));

    return h('div',
      B.row('Table', B.select(tb.name, tables, upd('name'), { dataset: { fid: 'ins-tb' } }), 'Only tables on the read allow-list (Options → Data Sources).'),
      B.row('Stored value column', B.select(tb.value, colChoices(tb.value), upd('value'), { dataset: { fid: 'ins-tb-v' } })),
      B.row('Label column', B.select(tb.label, colChoices(tb.label), upd('label'), { dataset: { fid: 'ins-tb-l' } })),
      h('div.sfb-inline',
        B.row('Sort by', B.select(ocol || '', [{ value: '', label: 'No order' }, ...colChoices(ocol)], (v) => upd('order')(v ? `${v} ${odir || 'asc'}` : ''), { dataset: { fid: 'ins-tb-o' } })),
        ocol ? B.row('Direction', B.select(odir || 'asc', [{ value: 'asc', label: 'A→Z' }, { value: 'desc', label: 'Z→A' }], (v) => upd('order')(`${ocol} ${v}`), { dataset: { fid: 'ins-tb-d' } })) : null),
      B.row('At most', B.number(tb.limit, upd('limit'), { min: 1, max: 5000, dataset: { fid: 'ins-tb-n' } })),
      h('div.mt-2', h('p.small.fw-semibold.mb-1', 'Which rows'), B.conditionEditor({
        value: asField(tb.where), fields: colFields, noneLabel: 'All rows', someLabel: 'Only rows where', fid: 'ins-tb-where', people: false,
        onChange: (c, o) => B.update(() => { const t = B.fieldAt(sel).options.table; if (c) t.where = asCol(c); else delete t.where; }, o?.typing ? { structural: false, merge: 'tbwhere' } : {}),
      })),
      filterBy(f, sel, 'table'));
  }

  function userEditor(f, sel) {
    const groups = (f.groups || []).map(String);
    const has = (g) => groups.includes(String(g.id)) || groups.includes(g.title);
    const list = h('div.sfb-groups', { role: 'group', 'aria-label': 'User groups' });
    B.catalog.groups.forEach((g) => list.append(h('div', { style: `padding-left:${g.level}rem` }, B.check(g.title, has(g), (v) => B.update(() => {
      const fx = B.fieldAt(sel);
      const cur = (fx.groups || []).filter((x) => String(x) !== String(g.id) && x !== g.title);
      if (v) cur.push(g.title);
      fx.groups = cur;
    })))));
    return h('div', h('p.form-text', 'People in these groups (and their child groups) can be picked. Everyone who can see the form sees the names.'), list,
      B.check('Leave out the person filling it in', f.exclude_self !== false, (v) => now(sel, 'exclude_self')(v ? undefined : false), 'So nobody can name themselves as their own approver.'));
  }

  // Date limits (0.13.0): fixed or relative to today, weekdays, hours, after another date.
  function dateLimits(f, sel, t) {
    const get = (path) => path.split('.').reduce((o, k) => o?.[k], f);
    const n = (label, path, attrs = {}) => B.row(label, B.number(get(path), typing(sel, path), { dataset: { fid: 'ins-' + path }, ...attrs }));
    const ph = t === 'datetime' ? '2027-01-31T09:00 or today+2' : '2027-01-31 or today+2';
    const box = (label, path) => B.row(label, B.input(get(path), typing(sel, path), { placeholder: ph, class: 'font-monospace', dataset: { fid: 'ins-' + path } }));
    const out = [h('div.sfb-inline', box('Earliest', 'rules.min'), box('Latest', 'rules.max')),
      h('p.form-text', h('code', 'today'), ', ', h('code', 'today+2'), ' (two days from now) or ', h('code', 'today-30'), ' move with the calendar, in the site’s time zone.')];

    // Weekdays: all ticked means no limit.
    const days = get('rules.weekdays') || [1, 2, 3, 4, 5, 6, 7];
    const names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const setDays = (list) => now(sel, 'rules.weekdays')(list.length === 7 || list.length === 0 ? undefined : list.sort((a, b) => a - b));
    out.push(h('fieldset.mb-2', h('legend.form-label.small.mb-1', t === 'daterange' ? 'Days it can start and end on' : 'Days that can be chosen'),
      h('div.d-flex.flex-wrap.gap-2', names.map((nm, i) => {
        const id = `sfb-wd-${i + 1}`;
        return h('div.form-check.form-check-inline.m-0', h('input.form-check-input', { type: 'checkbox', id, checked: days.includes(i + 1), dataset: { fid: 'ins-wd-' + (i + 1) },
          onchange: (e) => setDays(e.target.checked ? [...new Set([...days, i + 1])] : days.filter((d) => d !== i + 1)) }), h('label.form-check-label', { for: id }, nm));
      }))));

    if (t === 'datetime') {
      out.push(h('div.sfb-inline',
        B.row('Opens at', B.input(get('rules.hours.from'), typing(sel, 'rules.hours.from'), { type: 'time', dataset: { fid: 'ins-hours-from' } })),
        B.row('Closes at', B.input(get('rules.hours.to'), typing(sel, 'rules.hours.to'), { type: 'time', dataset: { fid: 'ins-hours-to' } }))));
    }

    // After another date field on this page or an earlier one.
    const here = B.allFields().find((x) => x.f === f);
    const others = B.allFields().filter((x) => x.f !== f && !x.parent && ['date', 'datetime', 'daterange'].includes(x.f.type) && (!here || x.page <= here.page));
    if (others.length || get('rules.after.field')) {
      out.push(h('div.sfb-inline',
        B.row('After', B.select(get('rules.after.field') || '', [{ value: '', label: '- No -' }, ...others.map((x) => ({ value: x.f.key, label: x.f.label || x.f.key }))],
          (v) => now(sel, 'rules.after')(v ? { field: v, days: get('rules.after.days') ?? 0 } : undefined), { dataset: { fid: 'ins-after' } })),
        get('rules.after.field') ? n('By at least (days)', 'rules.after.days', { min: 0 }) : null));
      out.push(h('p.form-text', 'For example a return date at least 1 day after the start date. 0 means the same day or later.'));
    }
    return out;
  }

  function typeSpecific(f, sel) {
    const t = f.type;
    const out = [];
    const fid = (s) => ({ dataset: { fid: `ins-${s}` } });
    const n = (label, path, attrs = {}) => B.row(label, B.number(path.split('.').reduce((o, k) => o?.[k], f), typing(sel, path), { dataset: { fid: 'ins-' + path }, ...attrs }));
    const d = (label, path) => B.row(label, B.input(path.split('.').reduce((o, k) => o?.[k], f), typing(sel, path), { type: 'date', dataset: { fid: 'ins-' + path } }));
    const tm = (label, path, type) => B.row(label, B.input(path.split('.').reduce((o, k) => o?.[k], f), typing(sel, path), { type, dataset: { fid: 'ins-' + path } }));
    if (['text', 'textarea', 'email', 'tel', 'url', 'editor'].includes(t)) out.push(h('div.sfb-inline', n('Shortest', 'rules.min_length', { min: 0 }), n('Longest', 'rules.max_length', { min: 1 })));
    if (t === 'editor') out.push(h('p.form-text', 'Counts the words people see, not the formatting.'));
    if (['text', 'textarea'].includes(t)) {
      out.push(B.check('Show a character counter', f.counter, (v) => now(sel, 'counter')(v || undefined),
        f.rules?.max_length ? `“12 of ${f.rules.max_length} characters”, and a spoken warning near the limit.` : 'Set a Longest above to count towards it; without one it shows how many have been typed.'));
    }
    if (['text', 'tel'].includes(t)) {
      out.push(B.row('Input mask', B.input(f.mask, typing(sel, 'mask'), { class: 'font-monospace', placeholder: '(999) 999-9999', dataset: { fid: 'ins-mask' } }),
        h('span', h('code', '9'), ' a digit, ', h('code', 'a'), ' a letter, ', h('code', '*'), ' either; anything else is typed for them. The server checks it too. Use it only when every answer has the same shape.')));
    }
    if (t === 'text') {
      out.push(B.check('Hide what’s typed (show dots)', f.hide_input, (v) => now(sel, 'hide_input')(v || undefined),
        'For PINs and codes shown over a shoulder; a Show button lets people check what they typed. Not included in {data:_all}. Lists show dots; the submission’s own page and exports show it to people allowed to see them. It’s stored like any other answer, so never use it for passwords.'));
    }
    if (['text', 'tel'].includes(t)) {
      out.push(B.row('Pattern', B.input(f.rules?.pattern, typing(sel, 'rules.pattern'), { class: 'font-monospace', placeholder: '[A-Z]{2}[0-9]{4}', dataset: { fid: 'ins-pattern' } }), 'The whole value must match. Written the JavaScript way.'));
      if (f.rules?.pattern) out.push(B.row('Message when it doesn’t match', B.input(f.rules?.pattern_message, typing(sel, 'rules.pattern_message'), { dataset: { fid: 'ins-patmsg' } })));
    }
    if (t === 'textarea') out.push(n('Height (lines)', 'rows', { min: 2, max: 30 }));
    if (t === 'number') out.push(h('div.sfb-inline', n('Lowest', 'rules.min'), n('Highest', 'rules.max'), n('Step', 'rules.step', { min: 0, step: 'any' })));
    if (t === 'date' || t === 'datetime' || t === 'daterange') out.push(...dateLimits(f, sel, t));
    if (t === 'time') out.push(h('div.sfb-inline', tm('Earliest', 'rules.min', 'time'), tm('Latest', 'rules.max', 'time')));
    if (t === 'editor') {
      out.push(n('Height (lines)', 'rows', { min: 4, max: 30 }));
      out.push(allowedHtml(f, sel));
    }
    if (t === 'rating') {
      out.push(B.row('Highest score', B.select(String(f.max || 5), [2, 3, 4, 5, 6, 7, 8, 9, 10].map((v) => ({ value: v, label: `1 to ${v}` })), (v) => now(sel, 'max')(Number(v)), fid('max'))));
      out.push(h('div.sfb-inline',
        B.row('Words for 1', B.input(f.low_label, typing(sel, 'low_label'), { placeholder: 'Poor', dataset: { fid: 'ins-low' } })),
        B.row(`Words for ${f.max || 5}`, B.input(f.high_label, typing(sel, 'high_label'), { placeholder: 'Excellent', dataset: { fid: 'ins-high' } }))));
    }
    // 0.9.2
    if (t === 'email' && !repeaterOf(sel)) {
      out.push(B.check('Ask for it twice', f.confirm, (v) => now(sel, 'confirm')(v || undefined), 'A second box, “Your email again”, that must match. Pasting is allowed.'));
    }
    if (t === 'calc') {
      const ta = B.textarea(f.formula, typing(sel, 'formula'), { rows: 3, class: 'font-monospace', spellcheck: 'false', 'aria-describedby': 'sfb-calc-help', dataset: { fid: 'ins-formula' } });
      const insertAt = (text) => {
        const st = ta.selectionStart ?? ta.value.length;
        ta.value = ta.value.slice(0, st) + text + ta.value.slice(ta.selectionEnd ?? st);
        ta.dispatchEvent(new Event('input'));
        ta.focus();
        ta.setSelectionRange(st + text.length, st + text.length);
      };
      // Fields it may use: same or earlier page; in a repeating group, that row; a group's fields inside sum() etc.
      const own = repeaterOf(sel);
      const usable = B.fieldsUpTo(sel.page, own).filter((x) => x.key !== f.key && B.valueType(x.type) && !['signature', 'upload', 'consent', 'grid', 'name', 'address', 'editor'].includes(x.type));
      const groups = [];
      (S.model.pages || []).forEach((p, pi) => {
        if (pi > sel.page) return;
        const walk = (list) => (list || []).forEach((x) => { if (x.type === 'section') walk(x.fields); else if (x.type === 'repeater' && x !== own) groups.push(x); });
        walk(p.fields);
      });
      const opts = [{ value: '', label: 'Insert a field…' }, { group: 'Fields', items: usable.map((x) => ({ value: `{${x.key}}`, label: x.label })) }];
      groups.forEach((g) => opts.push({ group: `${g.label || g.key} (a repeating group)`, items: [{ value: `count({${g.key}})`, label: 'How many rows' }]
        .concat((g.fields || []).filter((c) => B.valueType(c.type)).map((c) => ({ value: `sum({${g.key}.${c.key}})`, label: `Total of ${c.label || c.key}` }))) }));
      const fsel = B.select('', opts, (v) => { if (v) { insertAt(v); fsel.value = ''; } }, { 'aria-label': 'Insert a field into the formula', class: 'sfb-insert', dataset: { fid: 'ins-formula-field' } });
      const fns = B.select('', [{ value: '', label: 'Insert a function…' }, ...[['round(, 2)', 'round(x, 2): round to 2 places'], ['if(, , )', 'if(test, then, otherwise)'], ['min(, )', 'min(a, b): the smaller'], ['max(, )', 'max(a, b): the larger'],
        ['days(, )', 'days(from, to): days between dates'], ['years(, today())', 'years(born, today()): whole years'], ['age(, today())', 'age(born, today()): shown as 34 years, 2 months and 5 days'], ['abs()', 'abs(x): without the minus'], ['floor()', 'floor(x): round down'], ['ceil()', 'ceil(x): round up']].map(([value, label]) => ({ value, label }))],
      (v) => { if (v) { insertAt(v); fns.value = ''; } }, { 'aria-label': 'Insert a function into the formula', class: 'sfb-insert', dataset: { fid: 'ins-formula-fn' } });
      const r = B.row('Formula', ta);
      r.querySelector('label').after(fsel, fns);
      out.push(r, h('div.form-text#sfb-calc-help', 'For example ', h('code', '{f_qty} * {f_price}'), '. Use + − * / and ( ). ',
        h('code', 'sum({items.price})'), ' adds up a repeating group; ', h('code', 'if({f_size} = "L", 5, 0)'), ' picks a value. Empty answers count as 0. The server works it out again when the form is sent; that’s what is saved.'));
      out.push(h('div.sfb-inline', n('Decimal places', 'decimals', { min: 0, max: 10, placeholder: 'as it comes' }),
        B.row('Before the number', B.input(f.prefix, typing(sel, 'prefix'), { placeholder: '$', dataset: { fid: 'ins-prefix' } })),
        B.row('After the number', B.input(f.suffix, typing(sel, 'suffix'), { placeholder: 'km', dataset: { fid: 'ins-suffix' } }))));
      out.push(B.check('Separate thousands with commas (1,234.50)', f.thousands, (v) => now(sel, 'thousands')(v || undefined)));
      if (!own) out.push(B.check('Named value: work it out, don’t show it', f.named, (v) => now(sel, 'named')(v || undefined), `Not on the form, the review or the sender’s copy. Use it in emails as {data:${f.key}}, in show/hide rules and in the viewer.`));
    }
    if (t === 'ranking') {
      const ta = B.textarea(toLines(f.items), () => {}, { rows: 5, dataset: { fid: 'ins-items' } });
      ta.addEventListener('change', () => B.update(() => { const v = fromLines(ta.value); if (v.length) B.fieldAt(sel).items = v; }));
      out.push(B.row('Items to put in order, one per line', ta, '2 to 20. value|label stores a different value.'));
      out.push(h('p.form-text', 'People number them 1, 2, 3… (or use ↑ ↓). Required means every item gets a number.'));
    }
    if (t === 'slider') {
      out.push(h('div.sfb-inline', n('Lowest', 'min', { step: 'any' }), n('Highest', 'max', { step: 'any' }), n('Step', 'step', { min: 0, step: 'any' }), n('Starts at', 'default', { step: 'any' })));
      out.push(B.row('Unit shown after the number', B.input(f.unit, typing(sel, 'unit'), { placeholder: '%', dataset: { fid: 'ins-unit' } })));
      out.push(h('div.sfb-inline',
        B.row('Words for the lowest', B.input(f.low_label, typing(sel, 'low_label'), { dataset: { fid: 'ins-low' } })),
        B.row('Words for the highest', B.input(f.high_label, typing(sel, 'high_label'), { dataset: { fid: 'ins-high' } }))));
      out.push(h('p.form-text', 'A slider always has a value (where it starts, if nobody moves it).'));
    }
    if (t === 'daterange') {
      out.push(h('div.sfb-inline', n('At least (days)', 'min_days', { min: 1 }), n('At most (days)', 'max_days', { min: 1 })));
    }
    if (t === 'phone') {
      const cs = Object.entries(B.catalog.countries || {}).filter(([c]) => (B.catalog.dial || {})[c]).map(([value, label]) => ({ value, label: `${label} (+${B.catalog.dial[value]})` }));
      out.push(B.row('Country to start on', B.select(f.default_country || 'US', cs, (v) => now(sel, 'default_country')(v), fid('country'))));
      out.push(h('p.form-text', 'Stored as +15551234567 with the country and number kept. Checked by the international length rule.'));
    }
    if (t === 'consent') {
      out.push(B.row('The terms', B.textarea(f.terms, typing(sel, 'terms'), { rows: 8, ...fid('terms') }), 'Shown in a scrolling box. People can tick the box without scrolling to the end.'), B.htmlInfo());
      out.push(B.row('Words beside the tick box', B.input(f.agree_label, typing(sel, 'agree_label'), { placeholder: 'I have read and agree to the terms above', dataset: { fid: 'ins-agree' } })));
      out.push(n('Height of the box (lines)', 'height', { min: 6, max: 40 }));
      out.push(h('p.form-text', 'Each answer keeps when they agreed and a fingerprint of the exact terms, so you can show which text they agreed to.'));
    }
    if (t === 'imagechoice') {
      const cs = Array.isArray(f.choices) ? f.choices : [];
      const capped = cs.some((x) => x && x.max !== undefined);
      const write = (list, o) => B.update(() => { B.fieldAt(sel).choices = list; }, o);
      const rows = h('ol.sfb-optlist');
      cs.forEach((c, i) => {
        const upd = (k) => (v) => { const list = JSON.parse(JSON.stringify(cs)); list[i][k] = v; write(list, { structural: false, merge: `ic.${f.key}.${i}.${k}` }); };
        rows.append(h('li.sfb-opt.flex-wrap',
          B.input(c.label, upd('label'), { 'aria-label': `Choice ${i + 1}: label`, placeholder: 'Label', dataset: { fid: `ic-${i}-l` } }),
          B.input(c.value, upd('value'), { 'aria-label': `Choice ${i + 1}: stored value`, class: 'font-monospace', placeholder: 'value', dataset: { fid: `ic-${i}-v` } }),
          mediaPicker(c.image, upd('image'), `Choice ${i + 1}: image`, `ic-${i}-img`),
          B.input(c.alt, upd('alt'), { 'aria-label': `Choice ${i + 1}: image description (alt)`, placeholder: 'What the picture shows', dataset: { fid: `ic-${i}-alt` } }),
          capped ? B.number(c.max, (v) => { const list = JSON.parse(JSON.stringify(cs)); list[i].max = v === undefined || v === '' ? 0 : Number(v); write(list, { structural: false, merge: `ic.${f.key}.${i}.max` }); },
            { min: 0, class: 'sfb-places', 'aria-label': `Places for choice ${i + 1}`, title: 'Places', dataset: { fid: `ic-${i}-max` } }) : null,
          B.iconButton('✕', `Remove choice ${i + 1}`, () => write(cs.filter((x, k) => k !== i)), { dataset: { fid: `ic-${i}-x` } })));
      });
      out.push(h('div', h('p.small.fw-semibold.mb-1', 'Choices'), rows,
        B.button('+ Add a choice', () => write(cs.concat([{ value: B.uniqueKey('c' + (cs.length + 1), cs.map((x) => x.value)), label: `Choice ${cs.length + 1}`, image: '', alt: '' }])), 'btn-outline-secondary', { dataset: { fid: 'ic-add' } }),
        h('p.form-text', 'Image: choose or upload one with Select (the Media Manager), or type a path on this site (images/…). Description: what the picture shows; leave it empty if the label says it all.')));
      if (!repeaterOf(sel)) {
        out.push(B.check('Limit the places for each choice', capped, (v) => write(cs.map((x) => { const y = { ...x }; if (v) y.max = y.max ?? 20; else delete y.max; return y; })),
          'Each picture holds a number of people. Full ones show “(full)” and can’t be picked.'));
        if (capped) out.push(B.check('Show how many places are left', f.show_left, (v) => now(sel, 'show_left')(v || undefined)));
      }
      out.push(B.check('Let people pick several', f.multiple, (v) => B.update(() => { const fx = B.fieldAt(sel); if (v) fx.multiple = true; else { delete fx.multiple; if (fx.rules) { delete fx.rules.min_selected; delete fx.rules.max_selected; } } })));
      if (f.multiple) out.push(h('div.sfb-inline', n('Pick at least', 'rules.min_selected', { min: 0 }), n('Pick at most', 'rules.max_selected', { min: 1 })));
    }
    if (t === 'lookup') {
      out.push(B.row('Suggest after', B.select(String(f.min_chars || 2), [1, 2, 3, 4, 5].map((v) => ({ value: v, label: `${v} letter${v > 1 ? 's' : ''}` })), (v) => now(sel, 'min_chars')(Number(v) === 2 ? undefined : Number(v)), fid('minchars'))));
      out.push(B.check('Accept an answer that isn’t in the list', f.allow_other, (v) => now(sel, 'allow_other')(v || undefined), 'Stored as typed. Without this, people must pick a suggestion (or type one exactly).'));
    }
    if (t === 'upload') {
      const all = B.catalog.uploadTypes || [];
      const acc = Array.isArray(f.accept) ? f.accept : [];
      out.push(h('div', { role: 'group', 'aria-label': 'File types' }, h('p.small.fw-semibold.mb-1', 'File types'),
        h('p.form-text', all.length ? `None ticked: every type the site allows (${all.join(', ')}). Tick some to allow only those.` : 'The site allows no file types yet: an administrator sets them in Options → Uploads.'),
        h('div.sfb-types', ...all.map((x) => B.check(x.toUpperCase(), acc.includes(x), (v) => B.update(() => {
          const fx = B.fieldAt(sel);
          let cur = Array.isArray(fx.accept) ? fx.accept.slice() : [];
          cur = v ? cur.concat([x]) : cur.filter((y) => y !== x);
          if (cur.length) fx.accept = all.filter((y) => cur.includes(y)); else delete fx.accept;
        }))))));
      out.push(h('div.sfb-inline',
        n('How many files', 'max_files', { min: 1, max: 20 }),
        n(`Largest file, MB (the site allows ${B.catalog.uploadMaxMb || 10})`, 'max_mb', { min: 1, max: B.catalog.uploadMaxMb || 10 })));
      out.push(h('p.form-text', B.catalog.scanMode && B.catalog.scanMode !== 'none' ? 'Files are checked for viruses when they arrive.' : 'Files aren’t checked for viruses on this site (Options → Uploads → Virus scanning).',
        ' Files are never shown straight from the server: people download them from the submission, if they may see it.'));
    }
    if (t === 'signature') {
      const modes = Array.isArray(f.modes) ? f.modes : ['draw', 'type'];
      const setMode = (m, on) => B.update(() => {
        const fx = B.fieldAt(sel);
        let cur = Array.isArray(fx.modes) ? fx.modes.slice() : ['draw', 'type'];
        cur = on ? Array.from(new Set(cur.concat([m]))) : cur.filter((x) => x !== m);
        if (!cur.length) cur = [m === 'draw' ? 'type' : 'draw'];
        if (cur.length === 2) delete fx.modes; else fx.modes = ['draw', 'type'].filter((x) => cur.includes(x));
      });
      out.push(B.check('Let people draw it (mouse, finger or pen)', modes.includes('draw'), (v) => setMode('draw', v)));
      out.push(B.check('Let people type their name instead', modes.includes('type'), (v) => setMode('type', v),
        modes.includes('type') ? 'Keeps it usable from the keyboard, with a screen reader, and without JavaScript.' : 'Drawing only: people who can’t use a mouse, finger or pen can’t sign. Keep typing on unless you must have a drawing.'));
      out.push(B.row('What signing confirms', B.textarea(f.statement, typing(sel, 'statement'), { rows: 2, ...fid('statement') }), 'Shown above the signature.'));
      out.push(esignSection(f, sel));
    }
    if (t === 'name') {
      const parts = Array.isArray(f.parts) ? f.parts : ['first', 'last'];
      const NAMES = { title: 'Title (Dr, Ms…)', first: 'First name', middle: 'Middle name', last: 'Last name', suffix: 'Suffix (Jr, III…)' };
      out.push(h('div', { role: 'group', 'aria-label': 'Parts of the name' }, h('p.small.fw-semibold.mb-1', 'Parts'),
        ...Object.keys(NAMES).map((p) => B.check(NAMES[p], parts.includes(p), (v) => B.update(() => {
          const fx = B.fieldAt(sel);
          let cur = Array.isArray(fx.parts) ? fx.parts.slice() : ['first', 'last'];
          cur = v ? cur.concat([p]) : cur.filter((x) => x !== p);
          fx.parts = Object.keys(NAMES).filter((x) => cur.includes(x));
          if (!fx.parts.length) fx.parts = ['first'];
        })))));
      out.push(h('p.form-text', 'First and last are needed once anything is filled in. Use {data:' + f.key + '.first} for one part in an email.'));
    }
    if (t === 'address') {
      const extra = Object.entries(B.catalog.addressFormats || {}).map(([value, name]) => ({ value, label: `${name} (from an add-on)` }));
      if (f.format && !['us', 'intl'].includes(f.format) && !(B.catalog.addressFormats || {})[f.format]) extra.push({ value: f.format, label: `${f.format} (its add-on isn’t installed)` });
      out.push(B.row('Format', B.select(f.format || 'us', [{ value: 'us', label: 'United States (state list, ZIP code)' }, { value: 'intl', label: 'International (country list, postal code)' }, ...extra], now(sel, 'format'), fid('format'))));
      if (f.format === 'intl') {
        const cs = Object.entries(B.catalog.countries || {}).map(([value, label]) => ({ value, label }));
        out.push(B.row('Country to start on', B.select(f.default_country || '', [{ value: '', label: '— none —' }, ...cs], (v) => now(sel, 'default_country')(v || undefined), fid('country'))));
      }
      out.push(B.check('Second address line (apartment, suite)', f.line2 !== false, (v) => now(sel, 'line2')(v ? undefined : false)));
      out.push(h('p.form-text', 'Use {data:' + f.key + '.city} (or line1, state, zip, postal, country) for one part in an email.'));
    }
    if (B.MULTI.includes(t)) out.push(h('div.sfb-inline', n('Pick at least', 'rules.min_selected', { min: 0 }), n('Pick at most', 'rules.max_selected', { min: 1 })));
    if (t === 'repeater') {
      out.push(B.row('Name of one row', B.input(f.row_label, typing(sel, 'row_label'), { placeholder: 'Visitor', dataset: { fid: 'ins-rowlabel' } })));
      out.push(B.row('Text on the add button', B.input(f.add_label, typing(sel, 'add_label'), { placeholder: 'Add another visitor', dataset: { fid: 'ins-addlabel' } })));
      out.push(h('div.sfb-inline', n('Fewest rows', 'min', { min: 0, max: 500 }), n('Most rows', 'max', { min: 1, max: 500 })));
    }
    return out;
  }

  /** "value|label" lines ⇄ strings or {value, label} objects. */
  const toLines = (list) => (Array.isArray(list) ? list : []).map((x) => (x && typeof x === 'object' ? (x.value === x.label || x.label === undefined ? x.value : `${x.value}|${x.label}`) : String(x))).join('\n');
  const fromLines = (text) => text.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
    const i = l.indexOf('|');
    return i > 0 ? { value: l.slice(0, i).trim(), label: l.slice(i + 1).trim() } : l;
  });

  function gridEditor(f, sel) {
    const fid = (s) => ({ dataset: { fid: `ins-${s}` } });
    const lines = (label, path, help) => {
      const ta = B.textarea(toLines(f[path]), () => {}, { rows: 4, ...fid('grid-' + path) });
      ta.addEventListener('change', () => B.update(() => { const v = fromLines(ta.value); if (v.length) B.fieldAt(sel)[path] = v; else delete B.fieldAt(sel)[path]; }));
      return B.row(label, ta, help);
    };
    const kids = [
      B.row('Each cell is', B.select(f.cell || 'radio', [
        { value: 'radio', label: 'One choice per row (radio buttons)' }, { value: 'checkbox', label: 'Any number per row (tick boxes)' },
        { value: 'text', label: 'A short answer (text box)' }, { value: 'select', label: 'A dropdown' }], (v) => B.update(() => {
        const fx = B.fieldAt(sel);
        fx.cell = v;
        if (v === 'select' && !fx.choices) fx.choices = ['Yes', 'No'];
        if (v !== 'select') delete fx.choices;
      }), fid('cell'))),
      lines('Questions (rows), one per line', 'rows', 'value|label stores a different value, for example food|Food quality.'),
      lines('Answers (columns), one per line', 'columns', 'The headings across the top.'),
    ];
    if ((f.cell || 'radio') === 'select') kids.push(lines('Choices in every dropdown, one per line', 'choices', 'A column can have its own: edit the field as JSON and give the column "choices".'));
    kids.push(h('p.form-text', 'Required means every row needs an answer. Wide grids scroll sideways on small screens.'));
    return h('div', ...kids);
  }

  const WIDTHS = { 12: 'full width', 9: 'three quarters', 8: 'two thirds', 6: 'half', 4: 'a third', 3: 'a quarter' };
  function widthName(w) { return WIDTHS[w] || `${w}/12`; }

  /** Column width for fields; box/rule and collapsing for sections. */
  function layoutSettings(f, sel) {
    const t = f.type;
    const fid = (s) => ({ dataset: { fid: `ins-${s}` } });
    if (t === 'section') {
      const out = [B.row('Style', B.select(f.style || 'rule', [{ value: 'rule', label: 'A heading with a line' }, { value: 'box', label: 'A box around it' }], now(sel, 'style'), fid('style')),
        'Change either look with CSS: .sf-section-rule and .sf-section-box.')];
      out.push(B.check('Let people open and close it', f.collapsible, (v) => B.update(() => { const fx = B.fieldAt(sel); if (v) fx.collapsible = true; else { delete fx.collapsible; delete fx.collapsed; } }),
        'Opens by itself when a field in it has a mistake.'));
      if (f.collapsible) out.push(B.check('Start closed', f.collapsed, (v) => now(sel, 'collapsed')(v || undefined)));
      out.push(cssClassRow(f, sel));
      return out;
    }
    if (t === 'hidden') return [];
    const choices = [];
    for (let w = 12; w >= 1; w--) choices.push({ value: w, label: `${w} of 12 columns${WIDTHS[w] ? ` (${WIDTHS[w]})` : ''}` });
    return [B.row('Width', B.select(String(f.width || 12), choices, (v) => now(sel, 'width')(Number(v) === 12 ? undefined : Number(v)), fid('width')),
      'Fields that fit share a row, left to right. On phones every field is full width.'),
    // 0.13.2: room for the small part labels a neighbour in parts has.
    ...(['text', 'email', 'number', 'tel', 'url', 'date', 'time', 'datetime', 'select', 'multiselect', 'lookup', 'textarea', 'calc', 'user'].includes(f.type)
      ? [B.check('Line up with a field in parts beside it', f.align_parts, (v) => now(sel, 'align_parts')(v || undefined), 'Adds room above the box for the small labels (Country, Number) of a phone, name, address or date range beside it.')] : []),
    cssClassRow(f, sel)];
  }

  /** 0.13.2: which tags and attributes a Formatted text field keeps (only ever fewer than SquidForm's list). */
  function allowedHtml(f, sel) {
    const allTags = B.catalog.htmlTags || [];
    const allAttrs = (B.catalog.htmlAttributes || []).filter((a) => a !== 'data-show-when');
    const grid = (title, list, key, fidp) => {
      const chosen = Array.isArray(f[key]) ? f[key] : list;
      return h('fieldset.mb-2', h('legend.form-label.small.mb-1', title),
        h('div.d-flex.flex-wrap.gap-2', ...list.map((x) => {
          const id = `ins-${fidp}-${x}`;
          return h('div.form-check.form-check-inline.m-0', h('input.form-check-input', { type: 'checkbox', id, checked: chosen.includes(x), dataset: { fid: id },
            onchange: (e) => B.update(() => {
              const t = B.fieldAt(sel);
              const now2 = new Set(Array.isArray(t[key]) ? t[key] : list);
              if (e.target.checked) now2.add(x); else now2.delete(x);
              const next = list.filter((y) => now2.has(y));
              if (next.length === list.length) delete t[key]; else t[key] = next;
            }) }), h('label.form-check-label.font-monospace.small', { for: id }, x));
        })));
    };
    const tags = Array.isArray(f.allowed_tags) ? allTags.filter((x) => f.allowed_tags.includes(x)) : allTags;
    const attrs = Array.isArray(f.allowed_attributes) ? allAttrs.filter((x) => f.allowed_attributes.includes(x)) : allAttrs;
    const auto = tags.length ? `HTML you can use here. Tags: ${tags.join(', ')}. Attributes: ${attrs.join(', ') || 'none'}. Anything else (such as style or onclick) is removed when you send.` : 'HTML isn’t kept here: tags are removed when you send, and the text stays.';
    return h('details.sfb-sub', h('summary', 'Allowed HTML'),
      h('p.form-text', 'Untick what people may not use. The toolbar only offers what’s left, and the server removes anything else.'),
      grid('Tags', allTags, 'allowed_tags', 'tag'), grid('Attributes', allAttrs, 'allowed_attributes', 'attr'),
      B.row('Note under the HTML box', B.textarea(f.html_note, typing(sel, 'html_note'), { placeholder: auto, dataset: { fid: 'ins-htmlnote' } }),
        'Shown while someone uses Toggle Editor to see the HTML. Leave it empty for the list above.'));
  }

  /** The field's own CSS classes (0.9.1). */
  function cssClassRow(f, sel) {
    const ok = (v) => !v || /^[A-Za-z_][A-Za-z0-9_-]*( [A-Za-z_][A-Za-z0-9_-]*){0,9}$/.test(v);
    const msg = h('div.form-text', f.type === 'section' ? 'Added to the section, so CSS can style it and everything in it.' : 'Added to the box around the field, so your CSS can style it.');
    const inp = B.input(f.css_class, (v) => {
      const val = v.trim().replace(/\s+/g, ' ');
      msg.classList.toggle('text-danger', !ok(val));
      msg.textContent = ok(val) ? 'Saved.' : 'Class names: letters, digits, - and _, separated by spaces (start with a letter).';
      if (ok(val)) B.update(() => set(B.fieldAt(sel), 'css_class', val || undefined), { structural: false, merge: `css.${f.key}` });
    }, { class: 'font-monospace', placeholder: 'wide highlight', dataset: { fid: 'ins-css' } });
    return h('div', B.row('CSS class', inp), msg);
  }

  function keyEditor(f, sel) {
    const msg = h('div.form-text');
    const live = B.state.liveKeys.has(f.key);
    const inp = B.input(f.key, () => {}, { class: 'font-monospace', dataset: { fid: 'ins-key' } });
    const apply = () => {
      const v = inp.value.trim();
      if (v === f.key) return;
      if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(v)) { msg.textContent = 'A letter first, then letters, digits or _ (64 at most).'; msg.classList.add('text-danger'); return; }
      if (B.allKeys().includes(v)) { msg.textContent = `“${v}” is already used in this form.`; msg.classList.add('text-danger'); return; }
      const old = f.key;
      S.fresh.delete(B.fieldAt(sel));
      B.update(() => { B.fieldAt(sel).key = v; renameRefs(old, v); });
      B.announce(`Key changed to ${v}`);
    };
    inp.addEventListener('change', apply);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
    const bait = B.BLOCKER_WORDS.test(f.key) ? ` Ad blockers such as Brave Shields hide things named like “${f.key}”: try ${B.blockerSafe(f.key.toLowerCase())}.` : '';
    if (bait) msg.classList.add('text-warning-emphasis');
    msg.textContent = (live ? 'Submissions already use this key. A new key starts a new column; earlier answers stay under the old one.' : 'Used in placeholders such as {data:' + f.key + '}. Conditions and actions that use it are updated.') + bait;
    return h('div', B.row('Key', inp), msg);
  }

  /** A renamed field: update conditions, placeholders, viewer and approvals that use it. */
  function renameRefs(from, to) {
    const m = S.model;
    const json = JSON.stringify(m);
    const re = new RegExp(`(\\{data(?:\\.label|\\.raw)?:)${from}\\}`, 'g');
    let out = json.replace(re, `$1${to}}`)
      .replace(new RegExp(`"field":"${from}"`, 'g'), `"field":"${to}"`)
      .replace(new RegExp(`"field:${from}"`, 'g'), `"field:${to}"`)
      .replace(new RegExp(`"submitter_email":"${from}"`, 'g'), `"submitter_email":"${to}"`);
    if (m.viewer) out = out.replace(new RegExp(`"${from}"(?=[,\\]])`, 'g'), (s, off) => (inViewer(out, off) ? `"${to}"` : s)).replace(new RegExp(`"name":"${from}"`, 'g'), `"name":"${to}"`);
    const next = JSON.parse(out);
    Object.keys(m).forEach((k) => delete m[k]);
    Object.assign(m, next);
  }
  function inViewer(s, off) { const v = s.indexOf('"viewer":'); return v >= 0 && off > v; }

  function jsonEditor(f, sel) {
    const box = B.textarea(JSON.stringify(f, null, 2), () => {}, { rows: 8, class: 'font-monospace', 'aria-label': 'This field as JSON', dataset: { fid: 'ins-json' } });
    const msg = h('div.form-text');
    return h('details.mt-2', h('summary.small', 'Edit this field as JSON'), box, msg, B.button('Apply', () => {
      try {
        const v = JSON.parse(box.value);
        if (!v || typeof v !== 'object' || !v.key || !v.type) throw new Error('it needs a "key" and a "type"');
        B.update(() => { listOfSel(sel)[posOf(sel)] = v; });
      } catch (e) { msg.textContent = 'Not applied: ' + e.message; msg.classList.add('text-danger'); }
    }, 'btn-outline-primary mt-1'));
  }

  /**
   * An image path with Joomla's Media Manager (0.10.0): Select opens it, where
   * an image can be chosen or uploaded. Typing a path still works. The
   * Media Manager adds "#joomlaImage://…" to what it picks; only the path is kept.
   */
  function mediaPicker(value, on, label, fid) {
    const clean = (v) => String(v || '').split('#')[0].replace(/^\/+/, '');
    const proto = document.getElementById('sfb-media-proto');
    const thumb = h('img.sfb-thumb', { alt: '', hidden: !value, src: value ? (B.catalog.siteRoot || '/') + clean(value) : '' });
    if (!proto || !proto.content.querySelector('joomla-field-media')) {
      return h('div.sfb-media', thumb, B.input(value, (v) => on(clean(v)), { 'aria-label': label, class: 'font-monospace', placeholder: 'images/forms/a.jpg', dataset: { fid } }));
    }
    const box = proto.content.firstElementChild.cloneNode(true);
    const inp = box.querySelector('input.field-media-input, input[type=text]');
    const id = B.id('sfb-media');
    inp.id = id;
    inp.removeAttribute('name');
    inp.removeAttribute('readonly');
    inp.value = value || '';
    inp.classList.add('font-monospace', 'form-control-sm');
    inp.setAttribute('aria-label', label);
    inp.dataset.fid = fid;
    inp.placeholder = 'images/forms/a.jpg';
    box.querySelector('.button-select')?.setAttribute('aria-label', `Select: ${label}`);
    box.querySelector('.button-select')?.setAttribute('data-fid', `${fid}-pick`);
    box.querySelector('.button-clear')?.setAttribute('aria-label', `Clear: ${label}`);
    const sync = () => {
      const v = clean(inp.value);
      thumb.hidden = !v;
      if (v) thumb.src = (B.catalog.siteRoot || '/') + v;
      on(v);
    };
    inp.addEventListener('change', sync);
    inp.addEventListener('input', sync);
    return h('div.sfb-media', thumb, box);
  }

  function pageInspector(box, p, i) {
    const u = (path, merge) => (v) => B.update(() => set(pageOf(i), path, v), merge ? { structural: false, merge: `page.${i}.${path}` } : {});
    box.append(h('h3.h6', `Page ${i + 1}`),
      B.row('Title', B.input(p.title, u('title', true), { dataset: { fid: 'ins-ptitle' } }), 'Shown as “Step 2 of 3 — Title” on forms with several pages.'),
      B.row('Button text on this page', B.input(p.submit_label, u('submit_label', true), { placeholder: i === S.model.pages.length - 1 ? (S.model.settings?.submit_label || 'Submit') : 'Next', dataset: { fid: 'ins-pbutton' } })),
      B.row('Key', B.input(p.key, u('key', true), { class: 'font-monospace', dataset: { fid: 'ins-pkey' } })),
      // Skip pages (0.10.0): not the first page, nor the last (where the form is sent).
      i > 0 && i < S.model.pages.length - 1 ? h('details.sfb-sec.mt-2', { open: !!p.show_if }, h('summary', 'When to show this page'), h('div.sfb-sec-body',
        B.conditionEditor({
          value: p.show_if, fields: B.fieldsUpTo(i - 1), noneLabel: 'Always show it', someLabel: 'Show it only when', fid: `ins-pcond-${p.key}`,
          onChange: (c, o) => B.update(() => { const pg = pageOf(i); if (c) pg.show_if = c; else delete pg.show_if; }, o?.typing ? { structural: false, merge: `pcond.${i}` } : {}),
        }),
        h('p.form-text', 'Uses answers from earlier pages. When it’s hidden, Next and Back step over it, and its answers aren’t saved. The first and last pages always show.'))) : null,
      h('div.d-flex.flex-wrap.gap-2.mt-3',
        B.button('← Move earlier', () => B.update(() => { const ps = S.model.pages; ps.splice(i - 1, 0, ps.splice(i, 1)[0]); S.page = i - 1; S.sel = { kind: 'page', page: i - 1 }; }), 'btn-outline-secondary', { disabled: i === 0 }),
        B.button('Move later →', () => B.update(() => { const ps = S.model.pages; ps.splice(i + 1, 0, ps.splice(i, 1)[0]); S.page = i + 1; S.sel = { kind: 'page', page: i + 1 }; }), 'btn-outline-secondary', { disabled: i === S.model.pages.length - 1 }),
        B.button('Delete this page', () => {
          B.update(() => { S.model.pages.splice(i, 1); S.page = Math.max(0, i - 1); S.sel = null; });
          B.announce('Page deleted. Undo brings it back.');
        }, 'btn-outline-danger', { disabled: S.model.pages.length === 1 })),
      h('p.form-text.mt-3', 'What happens when this page opens or is sent (save, email, message) is on the Logic tab.'));
  }

  return { render, refresh };

  /** E-signature (0.11.0): only for editors given "Use e-signatures"; the disclaimer comes first. */
  function esignSection(f, sel) {
    const es = f.esign && typeof f.esign === 'object' ? f.esign : null;
    const allowed = !!B.catalog.esign?.allowed;
    const write = (fn, o) => B.update(() => { const fx = B.fieldAt(sel); fn(fx); }, o);
    const fid = (x) => ({ dataset: { fid: `ins-${x}` } });
    const set = (k, typingMode) => (v) => write((fx) => { fx.esign ||= {}; if (v === '' || v === undefined) delete fx.esign[k]; else fx.esign[k] = v; }, typingMode ? { structural: false, merge: `${f.key}.esign.${k}` } : undefined);
    const body = h('div.sfb-sec-body');
    body.append(h('div.alert.alert-warning.small.py-2.sfb-esign-disclaimer', { role: 'note' }, h('strong', 'Before you use it: '), B.catalog.esign?.disclaimer || ''));
    if (!allowed) {
      body.append(h('p.small.text-body-secondary', es ? 'This field is an e-signature. Only people allowed to “Use e-signatures” (Options → Permissions) can change or remove it; publishing other changes keeps it as it is.'
        : 'Only people allowed to “Use e-signatures” (Options → Permissions, or this form’s Permissions) can turn this on.'));
    }
    body.append(B.check('Make this an e-signature', !!es, (v) => write((fx) => { if (v) fx.esign = {}; else delete fx.esign; }),
      'Signed-in people only. Records who signed, how they signed in, when, what they agreed to, and a fingerprint of the answers; emails them a signed PDF copy.'));
    if (es) {
      body.append(B.row('What they agree to', B.textarea(es.statement, set('statement', true), { rows: 2, placeholder: B.catalog.esign?.statement || '', ...fid('esign-statement') }),
        'Shown as a tick box under the signature. Stored exactly as shown.'));
      body.append(B.row('Consumer disclosure (optional)', B.textarea(es.disclosure, set('disclosure', true), { rows: 4, ...fid('esign-disclosure') }),
        'For consumers: their right to a paper copy, how to withdraw consent, and what they need to open and keep the records. Shown in a box with its own tick box. Simple HTML is allowed.'));
      body.append(B.row('If someone edits the answers later', B.select(es.after_edit || 'lock', [
        { value: 'lock', label: 'Nobody can edit a signed submission' },
        { value: 'void', label: 'Editing voids the signature; the signer can sign again' }],
      (v) => set('after_edit')(v === 'lock' ? undefined : v), fid('esign-after')),
      S.model.approval ? 'This form has approvals: “Return for changes” needs editing, so choose “Editing voids the signature”.' : null));
      body.append(B.row('Keep signed submissions for at least (years)', B.number(es.retain_years ?? 7, (v) => set('retain_years', true)(v === undefined || v === 7 ? undefined : Math.max(0, Math.min(100, Math.trunc(v)))), { min: 0, max: 100, ...fid('esign-retain') }),
        'They can’t be permanently deleted before then (archiving still works). 0: no hold. Check your records schedule.'));
      body.append(B.check('Email the signer a signed PDF copy', es.send_copy !== false, (v) => set('send_copy')(v ? undefined : false)));
    }
    const sec = h('details.sfb-sec', { open: !!es }, h('summary', 'E-signature'), body);
    if (!allowed) sec.querySelectorAll('input, textarea, select').forEach((el) => { el.disabled = true; });
    return sec;
  }
}
