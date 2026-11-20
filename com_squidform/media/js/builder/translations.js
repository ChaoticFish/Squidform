/**
 * SquidForm builder: the Translations tab (0.15.0). For each language the
 * form offers (Settings → Languages), this form's own words side by side:
 * labels, help, choices, page titles, the intro and messages, confirmation
 * emails. Anything left empty stays as written. Stored as
 *   "translations": { "es-ES": { "f.f_name.label": "Nombre", … } }
 * The paths match Engine\Lang on the server.
 */
export function install(B) {
  const { h } = B;
  const S = B.state;
  let tag = null;
  let onlyMissing = false;

  const T = () => B.catalog.langText || { settings: [], field: [], lists: {} };
  const langs = () => {
    const on = new Set(Array.isArray(S.model.settings?.languages) ? S.model.settings.languages : []);
    return (B.catalog.languages || []).filter((l) => on.has(l.tag) && !l.joke && l.tag !== B.catalog.langBase);
  };
  const NAMES = { label: 'Label', help: 'Help text', placeholder: 'Placeholder', content: 'Text', row_label: 'Row name', add_label: '“Add” button', prefix: 'Before the value', suffix: 'After the value', terms: 'Terms to agree to', agree_label: '“I agree” tick box' };
  const LISTS = { opt: 'Choice', row: 'Row', col: 'Column' };
  const SETS = { intro: 'Text above the form', submit_label: 'Button on the last page', success_message: 'Message after sending', review_title: 'Review page title', review_intro: 'Review page text', before_message: 'Before it opens', closed_message: 'When it’s closed', full_message: 'When it’s full', user_message: 'When they’ve sent it enough' };
  const long = (s) => s.length > 70 || /[<\n]/.test(s);

  /** Every place with words: [{ group, path, what, text }] */
  function places() {
    const out = [];
    const st = S.model.settings || {};
    T().settings.forEach((k) => { if (typeof st[k] === 'string' && st[k] !== '') out.push({ group: 'Form settings', path: `s.${k}`, what: SETS[k] || k, text: st[k] }); });

    const list = (group, f, items, part) => (items || []).forEach((o) => {
      if (typeof o === 'string' || typeof o === 'number') out.push({ group, path: `f.${f.key}.${part}.${o}`, what: `${LISTS[part]}: ${o}`, text: String(o) });
      else if (o && o.value !== undefined) {
        if (typeof o.label === 'string' && o.label !== '') out.push({ group, path: `f.${f.key}.${part}.${o.value}`, what: `${LISTS[part]}: ${o.value}`, text: o.label });
        if (typeof o.alt === 'string' && o.alt !== '') out.push({ group, path: `f.${f.key}.${part}.${o.value}.alt`, what: `Picture description: ${o.value}`, text: o.alt });
      }
    });
    const walk = (fields, group) => (fields || []).forEach((f) => {
      if (!f || !f.key) return;
      const g = `${group} › ${f.label || f.key}`;
      T().field.forEach((p) => { if (typeof f[p] === 'string' && f[p] !== '') out.push({ group: g, path: `f.${f.key}.${p}`, what: NAMES[p] || p, text: f[p] }); });
      if (Array.isArray(f.options?.static)) list(g, f, f.options.static, 'opt');
      Object.entries(T().lists).forEach(([prop, part]) => { if (Array.isArray(f[prop])) list(g, f, f[prop], part); });
      if (Array.isArray(f.fields)) walk(f.fields, group);
    });

    (S.model.pages || []).forEach((pg, n) => {
      const group = `Page ${n + 1}${pg.title ? `: ${pg.title}` : ''}`;
      if (pg.title) out.push({ group, path: `p.${n}.title`, what: 'Page title', text: pg.title });
      if (pg.submit_label) out.push({ group, path: `p.${n}.submit_label`, what: 'Button on this page', text: pg.submit_label });
      walk(pg.fields, group);
      Object.values(pg.events || {}).forEach((acts) => (acts || []).forEach((a) => {
        if (!a || !a.key || !['message', 'confirm_email'].includes(a.type)) return;
        const ag = `${group} › ${a.type === 'message' ? 'Message' : 'Confirmation email'} “${a.key}”`;
        [['text', 'Message'], ['subject', 'Subject'], ['body', 'Body']].forEach(([c, what]) => {
          const v = a.config?.[c];
          if (typeof v === 'string' && v !== '') out.push({ group: ag, path: `a.${a.key}.${c}`, what, text: v });
        });
      }));
    });
    return out;
  }

  const get = (t, path) => S.model.translations?.[t]?.[path] ?? '';
  const set = (t, path, v) => B.update((m) => {
    m.translations ||= {};
    m.translations[t] ||= {};
    if (v.trim() === '') delete m.translations[t][path]; else m.translations[t][path] = v;
    if (!Object.keys(m.translations[t]).length) delete m.translations[t];
    if (!Object.keys(m.translations).length) delete m.translations;
  }, { structural: false, merge: `tr.${t}.${path}` });

  function render() {
    const wrap = h('div.sfb-wide.sfb-translations', h('h3.h5', 'Translations'));
    const ls = langs();
    const base = (B.catalog.languages || []).find((l) => l.tag === B.catalog.langBase);
    if (!ls.length) {
      wrap.append(h('p.form-text', 'This form offers one language. Tick more in Settings → Languages, then translate its words here.'),
        B.button('Go to Settings', () => { S.section = 'settings'; B.render(); }, 'btn-outline-primary', { dataset: { fid: 'tr-to-settings' } }));
      return wrap;
    }
    if (!ls.find((l) => l.tag === tag)) tag = ls[0].tag;
    const lang = ls.find((l) => l.tag === tag);
    const all = places();
    const done = all.filter((p) => get(tag, p.path) !== '').length;

    wrap.append(h('p.form-text', `Written in ${base ? base.name : 'the site’s language'}. Type each one in the other language; anything left empty is shown as written. SquidForm’s own words (buttons, error messages) are already translated. Placeholders such as {data:f_name} must stay as they are.`));
    wrap.append(h('div.btn-group.flex-wrap.mb-2', { role: 'group', 'aria-label': 'Language' },
      ...ls.map((l) => h('button.btn.btn-sm', {
        type: 'button', class: l.tag === tag ? 'btn-dark' : 'btn-outline-dark', 'aria-pressed': String(l.tag === tag), dataset: { fid: `tr-lang-${l.tag}` },
        onclick: () => { tag = l.tag; B.render(); },
      }, l.own !== l.name ? `${l.name} (${l.own})` : l.name))));
    wrap.append(h('p.small', { role: 'status', dataset: { fid: 'tr-count' } }, `${done} of ${all.length} translated into ${lang.name}.`));
    wrap.append(B.check('Show only the ones not translated yet', onlyMissing, (v) => { onlyMissing = v; B.render(); }));

    let group = null;
    let table = null;
    all.forEach((p) => {
      const now = get(tag, p.path);
      if (onlyMissing && now !== '') return;
      if (p.group !== group) {
        group = p.group;
        table = h('tbody');
        wrap.append(h('table.table.table-sm.sfb-tr-table.mt-3',
          h('caption.caption-top.fw-semibold.text-body', group),
          h('thead', h('tr', h('th', { scope: 'col' }, 'What'), h('th', { scope: 'col' }, `As written (${base ? base.name : ''})`), h('th', { scope: 'col' }, lang.name))),
          table));
      }
      const id = B.id('tr');
      const attrs = { id, lang: lang.tag.slice(0, 2), dataset: { fid: `tr-${p.path}` }, 'aria-label': `${p.what} in ${lang.name}` };
      const box = long(p.text) ? B.textarea(now, (v) => set(tag, p.path, v), { rows: Math.min(8, 2 + Math.floor(p.text.length / 80)), ...attrs }) : B.input(now, (v) => set(tag, p.path, v), attrs);
      table.append(h('tr', { dataset: { path: p.path } },
        h('th', { scope: 'row' }, p.what),
        h('td', h('div.sfb-tr-orig', { lang: (B.catalog.langBase || 'en').slice(0, 2) }, p.text)),
        h('td', box)));
    });
    if (onlyMissing && done === all.length) wrap.append(h('p.text-success', 'All translated.'));

    // Translations whose place has gone (a field removed, a key changed).
    const known = new Set(all.map((p) => p.path));
    const stale = Object.keys(S.model.translations?.[tag] || {}).filter((k) => !known.has(k));
    if (stale.length) {
      wrap.append(h('div.alert.alert-secondary.small.mt-3', `${stale.length} translation${stale.length === 1 ? ' is' : 's are'} for words no longer on the form. `,
        B.button('Remove them', () => B.update((m) => { stale.forEach((k) => delete m.translations[tag][k]); if (!Object.keys(m.translations[tag]).length) delete m.translations[tag]; if (!Object.keys(m.translations).length) delete m.translations; }), 'btn-outline-secondary', { dataset: { fid: 'tr-stale' } })));
    }
    return wrap;
  }

  return { render, refresh() {} };
}
