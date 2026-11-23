/**
 * SquidForm visual builder (0.7.0): the core.
 *
 * The builder edits the same JSON definition the JSON box holds. Every change
 * is written back to the box (which is what Save publishes), kept for undo,
 * and saved as the form's draft a moment later. Sections (Fields, Logic,
 * Approvals, Settings, Viewer) are separate modules; each gets this core as B.
 *
 * Plain ES modules, no build step, nothing loaded from outside the site.
 * Drag and drop uses SortableJS (bundled); every drag has a keyboard twin.
 */
const VERSION = new URL(import.meta.url).search;
const load = (name) => import(`./${name}.js${VERSION}`);

const B = {};
window.SquidFormBuilder = B;

/* ------------------------------------------------------------------ DOM */

let uid = 0;
B.id = (p = 'sfb') => `${p}-${++uid}`;

/** h('div.cls#id', {attrs}, ...children): small element helper. */
B.h = (sel, attrs, ...kids) => {
  const m = sel.match(/^([a-z0-9-]+)((?:[.#][^.#]+)*)$/i);
  const el = document.createElement(m ? m[1] : 'div');
  if (m && m[2]) m[2].replace(/([.#])([^.#]+)/g, (_, k, v) => { if (k === '.') el.classList.add(v); else el.id = v; });
  if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class') el.className += (el.className ? ' ' : '') + v;
    else if (k === 'text') el.textContent = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'value') el.value = v;
    else if (k === 'checked' || k === 'selected' || k === 'disabled' || k === 'hidden') el[k] = !!v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  const add = (c) => {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) c.forEach(add);
    else el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  };
  kids.forEach(add);
  return el;
};

/** Labelled control row. control gets the id. */
B.row = (label, control, help) => {
  const id = control.id || (control.id = B.id('in'));
  const hid = help ? B.id('help') : null;
  if (hid) control.setAttribute('aria-describedby', hid);
  return B.h('div.sfb-row', B.h('label.form-label', { for: id }, label), control, help ? B.h('div.form-text', { id: hid }, help) : null);
};

B.input = (value, on, attrs = {}) => B.h('input.form-control.form-control-sm', { type: 'text', value: value ?? '', oninput: (e) => on(e.target.value), ...attrs });
B.textarea = (value, on, attrs = {}) => B.h('textarea.form-control.form-control-sm', { rows: 3, oninput: (e) => on(e.target.value), ...attrs }, value ?? '');
B.number = (value, on, attrs = {}) => B.h('input.form-control.form-control-sm', {
  type: 'number', value: value ?? '', oninput: (e) => on(e.target.value === '' ? undefined : Number(e.target.value)), ...attrs,
});
B.select = (value, choices, on, attrs = {}) => {
  const el = B.h('select.form-select.form-select-sm', { onchange: (e) => on(e.target.value), ...attrs });
  for (const c of choices) {
    if (c.group) {
      const g = B.h('optgroup', { label: c.group });
      c.items.forEach((i) => g.append(B.h('option', { value: i.value, selected: String(i.value) === String(value ?? '') }, i.label)));
      el.append(g);
    } else el.append(B.h('option', { value: c.value, selected: String(c.value) === String(value ?? '') }, c.label));
  }
  return el;
};
B.check = (label, checked, on, help) => {
  const id = B.id('chk');
  const hid = help ? B.id('help') : null;
  return B.h('div.form-check.sfb-check',
    B.h('input.form-check-input', { type: 'checkbox', id, checked: !!checked, 'aria-describedby': hid, onchange: (e) => on(e.target.checked) }),
    B.h('label.form-check-label', { for: id }, label),
    help ? B.h('div.form-text', { id: hid }, help) : null);
};
B.button = (label, on, cls = 'btn-outline-secondary', attrs = {}) => B.h('button.btn.btn-sm', { type: 'button', onclick: on, ...attrs, class: cls + (attrs.class ? ' ' + attrs.class : '') }, label);
/** Small icon-like button with an accessible name. */
B.iconButton = (glyph, name, on, attrs = {}) => B.h('button.btn.btn-sm.btn-link.sfb-icon', { type: 'button', 'aria-label': name, title: name, onclick: (e) => { e.stopPropagation(); on(e); }, ...attrs }, glyph);

let liveEl = null;
B.announce = (msg) => {
  if (!liveEl) return;
  liveEl.textContent = '';
  setTimeout(() => { liveEl.textContent = msg; }, 30);
};

/* ------------------------------------------------------------------ meta */

B.TYPES = {
  text: { label: 'Text (one line)', group: 'Text', icon: 'Aa' },
  textarea: { label: 'Text box (several lines)', group: 'Text', icon: '¶' },
  editor: { label: 'Formatted text', group: 'Text', icon: 'B' },
  email: { label: 'Email', group: 'Text', icon: '@' },
  number: { label: 'Number', group: 'Text', icon: '#' },
  tel: { label: 'Phone', group: 'Text', icon: '☎' },
  url: { label: 'Web address', group: 'Text', icon: '🔗' },
  date: { label: 'Date', group: 'Dates and times', icon: '📅' },
  time: { label: 'Time', group: 'Dates and times', icon: '🕒' },
  datetime: { label: 'Date and time', group: 'Dates and times', icon: '🗓' },
  daterange: { label: 'Date range', group: 'Dates and times', icon: '↔' },
  select: { label: 'Dropdown', group: 'Choices', icon: '▾' },
  multiselect: { label: 'Dropdown (several choices)', group: 'Choices', icon: '☰' },
  radios: { label: 'Radio buttons', group: 'Choices', icon: '◉' },
  checkboxes: { label: 'Checkboxes', group: 'Choices', icon: '☑' },
  checkbox: { label: 'Single tick box', group: 'Choices', icon: '✓' },
  rating: { label: 'Rating', group: 'Choices', icon: '★' },
  slider: { label: 'Slider', group: 'Choices', icon: '⎯●' },
  imagechoice: { label: 'Image choice', group: 'Choices', icon: '🖼' },
  ranking: { label: 'Ranking (put in order)', group: 'Choices', icon: '⇅' },
  grid: { label: 'Grid (questions × answers)', group: 'Choices', icon: '▦' },
  lookup: { label: 'Look-up (type to search)', group: 'Choices', icon: '🔍' },
  user: { label: 'Person picker', group: 'People', icon: '👤' },
  name: { label: 'Name', group: 'People', icon: '🪪' },
  address: { label: 'Address', group: 'People', icon: '🏠' },
  phone: { label: 'Phone with country code', group: 'People', icon: '🌐' },
  consent: { label: 'Consent (terms + I agree)', group: 'People', icon: '☑' },
  signature: { label: 'Signature', group: 'People', icon: '✍' },
  upload: { label: 'File upload', group: 'Other', icon: '📎' },
  hidden: { label: 'Hidden value', group: 'Other', icon: '◌' },
  calc: { label: 'Calculation', group: 'Other', icon: '∑' },
  section: { label: 'Section', group: 'Layout', icon: '▭' },
  html: { label: 'Text block', group: 'Layout', icon: 'T' },
  divider: { label: 'Divider', group: 'Layout', icon: '―' },
  repeater: { label: 'Repeating group', group: 'Layout', icon: '⧉' },
};
B.typeLabel = (t) => (B.TYPES[t] ? B.TYPES[t].label : t);
B.TEXTLIKE = ['text', 'textarea', 'email', 'number', 'tel', 'url', 'date', 'time', 'datetime', 'hidden'];
B.CHOICE = ['select', 'multiselect', 'radios', 'checkboxes', 'checkbox'];
/** Answers that are a list (several choices). */
B.MULTI = ['checkboxes', 'multiselect'];
B.valueType = (t) => !['html', 'repeater', 'section', 'divider'].includes(t);

B.newField = (type, keys) => {
  const label = B.typeLabel(type).replace(/\s*\(.*\)$/, '');
  const f = { key: B.uniqueKey((type === 'section' ? 's_' : 'f_') + B.slug(label), keys), type, label };
  if (type === 'textarea') f.rows = 4;
  if (['select', 'multiselect', 'radios', 'checkboxes'].includes(type)) f.options = { static: ['Option 1', 'Option 2'] };
  if (type === 'checkbox') f.label = 'I agree';
  if (type === 'rating') f.max = 5;
  if (type === 'lookup') f.options = { static: ['Option 1', 'Option 2'] };
  if (B.TYPES[type]?.defaults) Object.assign(f, JSON.parse(JSON.stringify(B.TYPES[type].defaults)));
  if (type === 'grid') { f.cell = 'radio'; f.rows = ['Question 1', 'Question 2']; f.columns = ['Poor', 'Fair', 'Good', 'Excellent']; }
  if (type === 'name') f.parts = ['first', 'last'];
  if (type === 'address') f.format = 'us';
  if (type === 'upload') f.max_files = 1;
  if (type === 'ranking') f.items = ['First item', 'Second item', 'Third item'];
  if (type === 'slider') { f.min = 0; f.max = 10; f.step = 1; }
  if (type === 'phone') f.default_country = 'US';
  if (type === 'consent') { f.label = 'Terms'; f.terms = '<p>Write the terms people agree to here.</p>'; f.rules = { required: true }; }
  if (type === 'calc') { f.label = 'Total'; f.formula = '0'; f.decimals = 2; }
  if (type === 'imagechoice') f.choices = [{ value: 'a', label: 'Choice A', image: '', alt: '' }, { value: 'b', label: 'Choice B', image: '', alt: '' }];
  if (type === 'signature') { f.statement = 'I confirm that the information above is correct.'; }
  if (type === 'section') { f.style = 'rule'; f.fields = []; }
  if (type === 'divider') delete f.label;
  if (type === 'user') { f.label = 'Person'; f.groups = []; }
  if (type === 'html') { f.tag = 'p'; f.content = 'Some text.'; delete f.label; }
  if (type === 'repeater') {
    f.label = 'Rows'; f.row_label = 'Row'; f.add_label = 'Add another'; f.min = 0; f.max = 10;
    f.fields = [{ key: B.uniqueKey('f_name', keys.concat([f.key])), type: 'text', label: 'Name' }];
  }
  return f;
};

/**
 * "Which HTML is allowed" (ⓘ): a disclosure next to any box that takes HTML.
 * The list comes from the server (Engine\Html), so it can't drift.
 */
B.htmlInfo = () => {
  const tags = (B.catalog.htmlTags || []).map((t) => `<${t}>`).join(' ');
  const attrs = (B.catalog.htmlAttributes || []).join(', ');
  return B.h('details.sfb-htmlinfo',
    B.h('summary.small', B.h('span.sfb-info-ico', { 'aria-hidden': 'true' }, 'ⓘ'), ' Which HTML is allowed'),
    B.h('div.small',
      B.h('p.mb-1', 'Tags: ', B.h('code', tags)),
      B.h('p.mb-1', 'Attributes: ', B.h('code', attrs), '. Links that open a new tab get rel="noopener noreferrer".'),
      B.h('p.mb-0', 'Anything else is removed, with its content for scripts, styles and frames. Images can use the site’s own files, for example images/logo.png; style with class="…" and your template’s or the form’s CSS.')));
};

// 0.13.1: ad blockers' cookie-banner lists (Brave Shields) hide elements whose
// id holds these words, and field keys end up in ids; so keys made from labels avoid them.
B.BLOCKER_WORDS = /consent|cookie|banner|gdpr|popup|advert|sponsor|newsletter|subscri/i;
B.blockerSafe = (s) => s.replace(/consent/g, 'agree').replace(/cookies?/g, 'crumbs').replace(/banner/g, 'bar').replace(/gdpr/g, 'privacy').replace(/popup/g, 'pop').replace(/advert/g, 'notice').replace(/sponsor/g, 'backer').replace(/newsletter/g, 'news').replace(/subscri(be|ption)?/g, 'signup');
B.slug = (s) => B.blockerSafe(String(s || '').normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')).slice(0, 40) || 'field';
B.uniqueKey = (base, taken) => {
  base = base.replace(/[^A-Za-z0-9_]/g, '_');
  if (!/^[A-Za-z]/.test(base)) base = 'f_' + base;
  base = base.slice(0, 60);
  const set = new Set(taken);
  if (!set.has(base)) return base;
  for (let n = 2; ; n++) if (!set.has(`${base}_${n}`)) return `${base}_${n}`;
};

/** Every key in the definition (fields, pages, actions, steps): keys are unique form-wide. */
B.allKeys = () => {
  const out = [];
  const m = B.state.model;
  if (!m) return out;
  const walkActions = (list) => (Array.isArray(list) ? list : []).forEach((a) => a && a.key && out.push(a.key));
  (m.pages || []).forEach((p) => {
    if (p.key) out.push(p.key);
    const walk = (list) => (list || []).forEach((f) => { if (f.key) out.push(f.key); walk(f.fields); });
    walk(p.fields);
    const ev = p.events || {};
    walkActions(ev.load); walkActions(ev.submit);
  });
  ['on_edit', 'on_approve', 'on_reject', 'on_return'].forEach((k) => walkActions(m[k]));
  return out;
};

/** Top-level and repeater fields with where they are. */
/**
 * Every field, sections' and repeating groups' fields too, in form order.
 * parent: the repeating group it's in (null for a field of the page or of a
 * section); section: the section it's in; path: where it is (fields.js).
 */
B.allFields = () => {
  const out = [];
  const walk = (list, pi, path, parent, section) => (list || []).forEach((f, fi) => {
    const here = path.concat([fi]);
    out.push({ f, page: pi, index: fi, path: here, parent, section });
    if (f.type === 'repeater') walk(f.fields, pi, here, f, section);
    if (f.type === 'section') walk(f.fields, pi, here, parent, f);
  });
  (B.state.model?.pages || []).forEach((p, pi) => walk(p.fields, pi, [], null, null));
  return out;
};

/**
 * 0.8.x sections ran to the next section; since 0.9.1 a section holds its
 * fields. Converts a definition in place (the server does the same).
 */
B.nestSections = (model) => {
  let changed = false;
  (model?.pages || []).forEach((p) => {
    if (!Array.isArray(p.fields)) return;
    const out = [];
    let open = null;
    p.fields.forEach((f) => {
      if (f && f.type === 'section') {
        open = null;
        if (!Array.isArray(f.fields)) { f.fields = []; open = f; changed = true; }
        out.push(f);
      } else if (open) open.fields.push(f);
      else out.push(f);
    });
    p.fields = out;
  });
  return changed;
};
B.fieldByKey = (key) => B.allFields().find((x) => x.f.key === key) || null;
B.fieldName = (key) => {
  const x = B.fieldByKey(key);
  return x ? (x.f.label || x.f.key) : key;
};

/** Static options of a choice field as [{value,label}] (empty when the list comes from a file or table). */
B.staticOptions = (f) => {
  const s = f?.options?.static;
  if (!Array.isArray(s)) return [];
  return s.map((o) => (o && typeof o === 'object' ? { value: String(o.value ?? o.label ?? ''), label: String(o.label ?? o.value ?? ''), ...(o.max !== undefined ? { max: o.max } : {}) } : { value: String(o), label: String(o) }));
};

/** Make placeholders readable: {data:f_email} → «Your email». */
B.readable = (text) => String(text ?? '').replace(/\{([a-z_.]+):([^}]*)\}/gi, (all, kind, arg) => {
  if (/^data(\.label|\.raw)?$/.test(kind)) return arg === '_all' ? '«all answers»' : `«${B.fieldName(arg)}»`;
  if (kind === 'setting') return `«${arg} setting»`;
  if (kind === 'submission') return arg === 'ref' ? '«reference»' : `«submission ${arg}»`;
  if (kind === 'user') return `«your ${arg}»`;
  return all;
});

/* ------------------------------------------------------------------ state */

B.state = {
  model: null, section: 'fields', page: 0, sel: null, mode: 'builder', problems: [], notes: [], fresh: new WeakSet(), logicPage: 0, hooks: false,
};
const hist = { undo: [], redo: [] };
let ta; let opts; let root; let mainEl; let statusEl; let problemsEl; let undoBtn; let redoBtn;
const sections = {};

B.json = () => JSON.stringify(B.state.model, null, 2);

/** Change the model. structural: re-render everything (else only the canvas and summaries). */
B.update = (mutate, { structural = true, merge = '' } = {}) => {
  const before = ta.value;
  mutate(B.state.model);
  const after = B.json();
  if (after === before) return;
  // Typing in one box makes one undo step, not one per key.
  const top = hist.undo[hist.undo.length - 1];
  if (!(merge && top && top.merge === merge && Date.now() - top.t < 1500)) hist.undo.push({ json: before, merge, t: Date.now() });
  else top.t = Date.now();
  if (hist.undo.length > 200) hist.undo.shift();
  hist.redo = [];
  ta.value = after;
  scheduleSave();
  if (structural) B.render(); else B.refresh();
};

function restore(json) {
  B.state.model = JSON.parse(json);
  ta.value = json;
  scheduleSave();
  B.render();
}
B.undo = () => {
  const s = hist.undo.pop();
  if (!s) return;
  hist.redo.push({ json: ta.value });
  restore(s.json);
  B.announce('Undone');
};
B.redo = () => {
  const s = hist.redo.pop();
  if (!s) return;
  hist.undo.push({ json: ta.value, t: 0 });
  restore(s.json);
  B.announce('Redone');
};

B.pick = (sel, { focusInspector = false } = {}) => {
  B.state.sel = sel;
  B.render();
  if (focusInspector) {
    const first = root.querySelector('.sfb-inspector input, .sfb-inspector select, .sfb-inspector textarea');
    if (first) first.focus();
  }
};

/* ------------------------------------------------------------------ render */

/** Full render; keeps focus on the element with the same data-fid. */
B.render = () => {
  const fid = document.activeElement?.closest?.('[data-fid]')?.dataset.fid;
  const scroll = mainEl.querySelector('.sfb-inspector')?.scrollTop;
  mainEl.textContent = '';
  renderNav();
  const sec = sections[B.state.section];
  if (sec) mainEl.append(sec.render());
  renderProblems();
  updateUndo();
  if (scroll) { const i = mainEl.querySelector('.sfb-inspector'); if (i) i.scrollTop = scroll; }
  if (fid) {
    const el = root.querySelector(`[data-fid="${CSS.escape(fid)}"]`);
    const target = el && (el.matches('input,select,textarea,button,[tabindex]') ? el : el.querySelector('input,select,textarea,button'));
    if (target) {
      target.focus({ preventScroll: true });
      if (target.matches('input[type=text],input:not([type]),textarea')) { const n = target.value.length; target.setSelectionRange(n, n); }
    }
  }
};
/** Light refresh while typing: the canvas and summaries only, the inspector stays. */
B.refresh = () => {
  const sec = sections[B.state.section];
  if (sec && sec.refresh) sec.refresh(); else B.render();
  renderProblems();
  updateUndo();
};

const NAV = [
  ['fields', 'Fields'], ['logic', 'Logic'], ['approvals', 'Approvals'], ['settings', 'Settings'], ['translations', 'Translations'], ['viewer', 'Viewer'], ['placeholders', 'Placeholders'],
];
let navEl;
function renderNav() {
  navEl.textContent = '';
  for (const [k, label] of NAV) {
    const n = B.state.problems.filter((p) => problemSection(p) === k).length;
    navEl.append(B.h('button.btn.btn-sm.sfb-navbtn', {
      type: 'button', 'aria-pressed': String(B.state.section === k), dataset: { fid: 'nav-' + k },
      onclick: () => { B.state.section = k; B.state.sel = null; B.render(); },
    }, label, n ? B.h('span.badge.bg-danger.ms-1', { 'aria-label': `${n} problem${n > 1 ? 's' : ''}` }, String(n)) : null));
  }
}
function updateUndo() {
  undoBtn.disabled = !hist.undo.length;
  redoBtn.disabled = !hist.redo.length;
}

/* ------------------------------------------------------------------ problems */

/** Where a problem from the server points: { section, page, field path } */
function problemTarget(p) {
  const path = p.path || '';
  let m;
  if ((m = path.match(/^pages\[(\d+)\]\.events\.(load|submit)\[(\d+)\]/))) return { section: 'logic', page: +m[1], sel: { kind: 'action', page: +m[1], event: m[2], index: +m[3] } };
  if ((m = path.match(/^pages\[(\d+)\]((?:\.fields\[\d+\])+)/))) return { section: 'fields', page: +m[1], sel: { kind: 'field', page: +m[1], path: [...m[2].matchAll(/\[(\d+)\]/g)].map((x) => +x[1]) } };
  if ((m = path.match(/^pages\[(\d+)\]/))) return { section: 'fields', page: +m[1], sel: { kind: 'page', page: +m[1] } };
  if ((m = path.match(/^(on_edit|on_approve|on_reject|on_return)(?:\[(\d+)\])?/))) return { section: 'logic', hooks: true, sel: m[2] !== undefined ? { kind: 'hook', hook: m[1], index: +m[2] } : null };
  if (/^approval/.test(path)) return { section: 'approvals' };
  if (/^viewer/.test(path)) return { section: 'viewer' };
  if (/^translations/.test(path) || /^translations/.test(p.message || '')) return { section: 'translations' };
  if (/^(settings|schema)/.test(path)) return { section: 'settings' };
  const key = path.split(/[.[]/)[0];
  const x = key && B.fieldByKey(key);
  if (x) {
    return { section: 'fields', page: x.page, sel: { kind: 'field', page: x.page, path: x.path } };
  }
  return null;
}
function problemSection(p) { return problemTarget(p)?.section || null; }

/** Problems for one item, for markers on cards. */
B.problemsFor = (match) => B.state.problems.filter((p) => { const t = problemTarget(p); return t && t.sel && match(t.sel); });

function renderProblems() {
  problemsEl.textContent = '';
  const problems = B.state.problems;
  const notes = problems.length ? [] : (B.state.notes || []);
  if (!problems.length && !notes.length) { problemsEl.hidden = true; return; }
  problemsEl.hidden = false;
  const list = problems.length ? problems : notes;
  const json = B.state.mode === 'json';
  const ul = B.h('ul.mb-0');
  list.forEach((p) => {
    const t = problemTarget(p);
    let action = null;
    if (json && p.line > 0) action = B.button(`Go to line ${p.line}`, () => B.gotoLine(p.line, p.column || 1), 'btn-link p-0 align-baseline');
    else if (!json && t) {
      action = B.button('Show', () => {
        Object.assign(B.state, { section: t.section, sel: t.sel || null });
        if (t.page !== undefined) { B.state.page = t.page; B.state.logicPage = t.page; }
        if (t.hooks) B.state.hooks = true;
        B.render();
        root.querySelector('.sfb-card.is-selected, .sfb-inspector')?.scrollIntoView({ block: 'nearest' });
      }, 'btn-link p-0 align-baseline');
    }
    const where = json && p.line > 0 && !/line \d+/.test(p.message) ? ` (line ${p.line})` : '';
    ul.append(B.h('li', p.message + where, ' ', action));
  });
  problemsEl.append(B.h('p.fw-semibold.mb-1', problems.length
    ? `Can't publish yet: ${list.length} problem${list.length > 1 ? 's' : ''}`
    : `Check before it goes live: ${list.length} thing${list.length > 1 ? 's' : ''} that would go out as written`), ul);
}

/** Select a line of the JSON box (switching to JSON first). */
B.gotoLine = (line, col = 1) => {
  if (B.state.mode !== 'json') setMode('json');
  const lines = ta.value.split('\n');
  const l = Math.min(Math.max(1, line), lines.length);
  let start = 0;
  for (let k = 0; k < l - 1; k++) start += lines[k].length + 1;
  const end = start + lines[l - 1].length;
  const indent = lines[l - 1].length - lines[l - 1].trimStart().length;
  const lh = parseFloat(getComputedStyle(ta).lineHeight) || 20;
  ta.focus({ preventScroll: true });
  ta.setSelectionRange(start + indent, end);
  ta.scrollTop = Math.max(0, (l - 1) * lh - ta.clientHeight / 3);
  ta.scrollIntoView({ block: 'nearest' });
  ta.dispatchEvent(new Event('select'));
};

/** Where JSON.parse gave up, as {line, column}, from the browser's message. */
function parseErrorAt(text, err) {
  const msg = String(err && err.message || '');
  let m = msg.match(/line (\d+) column (\d+)/i);
  if (m) return { line: +m[1], column: +m[2] };
  m = msg.match(/position (\d+)/i);
  if (m) {
    const before = text.slice(0, +m[1]);
    return { line: before.split('\n').length, column: before.length - before.lastIndexOf('\n') };
  }
  return { line: 0, column: 0 };
}

/* ------------------------------------------------------------------ autosave */

let saveTimer = null;
let saving = false;
let pendingAfter = false;
let lastSent = null;
function scheduleSave() {
  clearTimeout(saveTimer);
  status('Changes not saved yet…');
  saveTimer = setTimeout(saveNow, 1200);
}
B.saveNow = saveNow;
async function saveNow() {
  clearTimeout(saveTimer);
  if (saving) { pendingAfter = true; return; }
  const json = ta.value;
  if (json === lastSent) { status(lastStatus); return; }
  saving = true;
  status('Saving draft…');
  const body = new FormData();
  body.append('id', String(opts.id || 0));
  body.append('json', json);
  body.append(Joomla.getOptions('csrf.token') || '', '1');
  try {
    const res = await fetch(opts.urls.draft, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await res.json();
    if (!res.ok || data.success === false) throw new Error(data.error || res.statusText);
    lastSent = json;
    B.state.problems = data.problems || [];
    B.state.notes = data.notes || []; // 1.0.4: worth a look, but don't stop publishing
    if (opts.id && data.saved) lastStatus = `Draft saved ${data.at}. People still see version ${data.live_version}.`;
    else if (opts.id) lastStatus = 'No unpublished changes.';
    else lastStatus = 'New form: Save it once to keep a draft.';
    if (opts.draft && data.live_version > opts.draft.base_version) lastStatus += ` Version ${data.live_version} was published after this draft was started.`;
    status(lastStatus);
    renderProblems(); renderNav();
    if (B.state.mode === 'builder') B.refresh();
    document.dispatchEvent(new CustomEvent('squidform:checked', { detail: data }));
  } catch (e) {
    if (stickyMistake && localMistake?.line) {
      B.state.problems = [{ message: `JSON syntax error near line ${localMistake.line}, column ${localMistake.column}.`, path: '', line: localMistake.line, column: localMistake.column }];
      renderProblems();
    }
    status(`Draft not saved: ${e.message}`, true);
  } finally {
    saving = false;
    if (pendingAfter) { pendingAfter = false; saveNow(); }
  }
}
let lastStatus = '';
let stickyMistake = false; // keep the "JSON has a mistake" line until the box changes
let localMistake = null;
function status(msg, bad = false) {
  if (stickyMistake && !bad) return;
  statusEl.textContent = msg;
  statusEl.classList.toggle('text-danger', bad);
}
/** Leaving the page: send what's unsaved. */
function flushOnLeave() {
  if (ta.value === lastSent || !opts.id) return;
  // Discarding: the draft must not come back.
  if (document.querySelector('#form-form [name="task"]')?.value === 'form.discardDraft') return;
  const body = new FormData();
  body.append('id', String(opts.id));
  body.append('json', ta.value);
  body.append(Joomla.getOptions('csrf.token') || '', '1');
  navigator.sendBeacon?.(opts.urls.draft, body);
}

/* ------------------------------------------------------------------ preview */

B.preview = (page = 0) => {
  const f = document.getElementById('sf-preview-post');
  f.querySelector('[name=json]').value = ta.value;
  f.querySelector('[name=page]').value = String(page);
  f.querySelector('[name=title]').value = document.getElementById('jform_title')?.value || '';
  f.querySelector('[name=alias]').value = document.getElementById('jform_alias')?.value || opts.alias || 'preview';
  const dlg = document.getElementById('sf-preview-dialog');
  if (dlg && dlg.showModal) dlg.showModal();
  f.submit();
};

/* ------------------------------------------------------------------ modes */

function setMode(mode) {
  if (mode === 'builder') {
    let parsed;
    try { parsed = JSON.parse(ta.value); } catch (e) {
      // The server's check says exactly what is wrong and where; the
      // browser's own position is the fallback if the server can't answer.
      localMistake = parseErrorAt(ta.value, e);
      status('The JSON has a mistake, so the builder can’t show it yet. It’s listed above the box.', true);
      stickyMistake = true;
      B.state.problems = [{ message: 'Finding the mistake…', path: '', line: 0, column: 0 }];
      renderProblems();
      lastSent = null;
      saveNow().then(() => {
        const p = B.state.problems.find((x) => x.line > 0);
        if (p) B.gotoLine(p.line, p.column || 1);
      });
      return false;
    }
    if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.pages)) {
      status('The builder needs a definition with a "pages" list.', true);
      return false;
    }
    B.nestSections(parsed);
    if (JSON.stringify(parsed, null, 2) !== B.json()) {
      if (B.state.model) hist.undo.push({ json: B.json(), t: 0 });
      B.state.model = parsed;
    }
  }
  B.state.mode = mode;
  if (mode === 'builder') stickyMistake = false;
  root.querySelector('#sfb-builder').hidden = mode !== 'builder';
  document.getElementById('sf-json-pane').hidden = mode !== 'json';
  builderBtn.setAttribute('aria-pressed', String(mode === 'builder'));
  jsonBtn.setAttribute('aria-pressed', String(mode === 'json'));
  undoBtn.hidden = redoBtn.hidden = startBtn.hidden = mode !== 'builder';
  try { localStorage.setItem('squidform.builder.mode', mode); } catch (e) { /* not kept */ }
  if (mode === 'builder') B.render();
  renderProblems();
  return true;
}
let builderBtn; let jsonBtn; let startBtn;

/* ------------------------------------------------------------------ boot */

async function boot() {
  ta = document.getElementById('jform_schema_json');
  root = document.getElementById('sf-builder-root');
  opts = Joomla.getOptions('com_squidform.builder');
  if (!ta || !root || !opts) return;
  B.opts = opts;
  B.catalog = opts.catalog;
  // 0.13.1: add-on field types say how they show (BuilderInfoInterface).
  for (const [t, i] of Object.entries(B.catalog.addonFields || {})) if (!B.TYPES[t]) B.TYPES[t] = { label: i.label, group: i.group || 'Add-ons', icon: i.icon || '•', defaults: i.defaults || {} };
  B.state.liveKeys = new Set(opts.liveKeys || []);

  const [fields, conditions, logic, approvals, settings, placeholders, translations] = await Promise.all(['fields', 'conditions', 'logic', 'approvals', 'settings', 'placeholders', 'translations'].map(load));
  conditions.install(B);
  Object.assign(sections, {
    fields: fields.install(B), logic: logic.install(B), approvals: approvals.install(B), settings: settings.install(B, 'settings'), viewer: settings.install(B, 'viewer'), placeholders: placeholders.install(B), translations: translations.install(B),
  });

  liveEl = B.h('div.visually-hidden', { 'aria-live': 'polite' });
  builderBtn = B.h('button.btn.btn-sm', { type: 'button', 'aria-pressed': 'true', onclick: () => setMode('builder') }, 'Builder');
  jsonBtn = B.h('button.btn.btn-sm', { type: 'button', 'aria-pressed': 'false', onclick: () => setMode('json') }, 'JSON');
  undoBtn = B.button('Undo', B.undo, 'btn-outline-secondary', { 'aria-keyshortcuts': 'Control+Z' });
  redoBtn = B.button('Redo', B.redo, 'btn-outline-secondary', { 'aria-keyshortcuts': 'Control+Y' });
  statusEl = B.h('span.small.text-body-secondary.sfb-status', { role: 'status' });
  problemsEl = B.h('div.alert.alert-warning.sfb-problems', { hidden: true });
  navEl = B.h('div.sfb-nav', { role: 'group', 'aria-label': 'Builder sections' });
  mainEl = B.h('div.sfb-main');

  const bar = B.h('div.sfb-bar',
    B.h('div.btn-group.sfb-mode', { role: 'group', 'aria-label': 'Edit with' }, builderBtn, jsonBtn),
    B.h('div.sfb-bar-actions', undoBtn, redoBtn, B.button('Preview', () => B.preview(B.state.section === 'logic' ? B.state.logicPage : B.state.page), 'btn-outline-primary'),
      startBtn = B.button('Start from a template', () => B.showStarters(), 'btn-outline-secondary', { dataset: { fid: 'bar-starters' } })),
    statusEl);
  root.append(liveEl, bar, problemsEl, B.h('div#sfb-builder', navEl, mainEl));
  root.hidden = false;
  document.getElementById('sf-json-pane').classList.add('sfb-json');

  // Keyboard: undo/redo outside text boxes (text boxes keep their own undo).
  root.addEventListener('keydown', (e) => {
    if (!(e.ctrlKey || e.metaKey) || e.target.matches('input,textarea,[contenteditable]')) return;
    if (e.key === 'z' && !e.shiftKey) { e.preventDefault(); B.undo(); }
    if (e.key === 'y' || (e.key === 'z' && e.shiftKey)) { e.preventDefault(); B.redo(); }
  });

  // The JSON box is autosaved too.
  ta.addEventListener('input', () => { stickyMistake = false; if (B.state.mode === 'json') scheduleSave(); });
  window.addEventListener('pagehide', flushOnLeave);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushOnLeave(); });

  lastSent = opts.draftSaved ? ta.value : (opts.id ? ta.value : null);
  lastStatus = opts.draft
    ? `Unpublished changes, saved ${opts.draft.at} by ${opts.draft.by}. People still see version ${opts.liveVersion}.`
    : (opts.id ? `Version ${opts.liveVersion} is live.` : 'New form: Save it once to keep a draft.');
  status(lastStatus);

  let mode = 'builder';
  try { mode = localStorage.getItem('squidform.builder.mode') || 'builder'; } catch (e) { /* default */ }
  if (opts.errorAt) mode = 'json'; // a refused save points at a line in the JSON box
  if (!setMode(mode)) setMode('json');
  if (mode === 'builder' && !B.state.model) setMode('json');

  // Ask the server what it thinks of the current definition (problems list).
  if (!opts.errorAt) { lastSent = null; saveNow(); }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
