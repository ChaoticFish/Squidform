/**
 * SquidForm builder: conditions ("Show Badge # when Visit type is Unescorted").
 *
 * The picker handles one rule, or several joined by "all" or "any", which is
 * what almost every form needs. A condition with deeper nesting or "not" is
 * shown in words and edited as JSON, so nothing the builder can't draw is lost.
 */
export function install(B) {
  const { h } = B;

  const OPS = [
    ['eq', 'is'], ['neq', 'is not'], ['in', 'is one of'], ['nin', 'is not one of'],
    ['empty', 'is empty'], ['filled', 'is not empty'],
    ['gt', 'is more than'], ['gte', 'is at least'], ['lt', 'is less than'], ['lte', 'is at most'],
    ['contains', 'contains'], ['ncontains', 'does not contain'], ['matches', 'matches the pattern'],
  ];
  const NOVALUE = ['filled', 'empty'];
  /** Answers made of parts (0.8.1): only "is not empty" / "is empty" make sense. */
  const WHOLE = ['signature', 'grid', 'name', 'address', 'upload', 'ranking', 'daterange', 'phone', 'consent'];
  const RESULTS = [['success', 'succeeded'], ['fail', 'failed'], ['skipped', 'was skipped']];
  const opLabel = (op) => (OPS.find((o) => o[0] === op) || [op, op])[1];

  // 0.14.0: rules about the person filling it in (judged on the server).
  const isUser = (c) => c && (('user_group' in c && (typeof c.user_group === 'string' || typeof c.user_group === 'number')) || ('user_field' in c && 'op' in c) || ('session' in c && 'op' in c));
  const isLeaf = (c) => c && typeof c === 'object' && !Array.isArray(c) && (('field' in c && 'op' in c) || ('action' in c && 'is' in c) || isUser(c)) && !('all' in c) && !('any' in c) && !('not' in c);
  const groupTitle = (ref) => (B.catalog.groups.find((g) => String(g.id) === String(ref) || g.title === ref) || { title: String(ref) }).title;
  const userField = (n) => B.catalog.userFields.find((u) => u.name === n);

  /** {join, rules} when the picker can show it, else null. */
  function flat(c) {
    if (!c) return { join: 'all', rules: [] };
    if (isLeaf(c)) return { join: 'all', rules: [c] };
    for (const join of ['all', 'any']) {
      if (Array.isArray(c[join]) && Object.keys(c).length === 1 && c[join].every(isLeaf)) return { join, rules: c[join] };
    }
    return null;
  }
  function unflat(join, rules) {
    if (!rules.length) return undefined;
    if (rules.length === 1) return rules[0];
    return { [join]: rules };
  }

  const valueText = (v) => (Array.isArray(v) ? v.join(', ') : (v === undefined || v === null ? '' : String(v)));

  /** Plain English. */
  B.condText = function condText(c) {
    if (!c) return '';
    if (c.action) {
      const res = (RESULTS.find((r) => r[0] === c.is) || [c.is, c.is])[1];
      return `“${B.actionName ? B.actionName(c.action) : c.action}” ${res}`;
    }
    if ('user_group' in c) return `they’re in ${[].concat(c.user_group).map((g) => '“' + groupTitle(g) + '”').join(' or ')}`;
    if ('session' in c) {
      const name = `their session’s ${c.session}`;
      if (NOVALUE.includes(c.op)) return `${name} ${opLabel(c.op)}`;
      return `${name} ${opLabel(c.op)} ${valueText(c.value) ? '“' + valueText(c.value) + '”' : '…'}`;
    }
    if ('user_field' in c) {
      const name = `their ${userField(c.user_field)?.title || c.user_field}`;
      if (NOVALUE.includes(c.op)) return `${name} ${opLabel(c.op)}`;
      return `${name} ${opLabel(c.op)} ${valueText(c.value) ? '“' + valueText(c.value) + '”' : '…'}`;
    }
    if ('field' in c) {
      const name = B.fieldName(c.field);
      if (NOVALUE.includes(c.op)) return `${name} ${opLabel(c.op)}`;
      return `${name} ${opLabel(c.op)} ${valueText(c.value) ? '“' + valueText(c.value) + '”' : '…'}`;
    }
    if (Array.isArray(c.all)) return c.all.map(condText).join(' and ');
    if (Array.isArray(c.any)) return c.any.map(condText).join(' or ');
    if (c.not) return `not (${condText(c.not)})`;
    return 'a custom condition';
  };

  /**
   * The editor.
   *  value      the condition (or undefined)
   *  onChange   (newCondition|undefined) => void
   *  fields     [{key, label, type, f}] that may be used
   *  actions    [{key, label}] earlier actions (for "when")
   *  noneLabel  text for "no condition" ("Always show")
   *  someLabel  text for "with a condition" ("Show only when")
   *  fid        stable id prefix for focus
   */
  // 0.15.0: the session paths found in the admin's own session, offered while typing.
  if ((B.catalog.sessionPaths || []).length && !document.getElementById('sfb-session-paths')) {
    const dl = document.createElement('datalist');
    dl.id = 'sfb-session-paths';
    B.catalog.sessionPaths.forEach((p) => { const o = document.createElement('option'); o.value = p.path; dl.append(o); });
    document.body.append(dl);
  }

  B.conditionEditor = function conditionEditor({ value, onChange, fields, actions = [], noneLabel = 'Always', someLabel = 'Only when', fid = 'cond', people = true }) {
    const wrap = h('div.sfb-cond');
    const shape = flat(value);

    if (!shape) {
      // Too detailed for the picker: show it in words, edit the JSON.
      const box = B.textarea(JSON.stringify(value, null, 2), () => {}, { rows: 6, class: 'font-monospace', dataset: { fid: fid + '-json' } });
      const msg = h('div.form-text');
      wrap.append(
        h('p.small.mb-1', 'This condition is too detailed for the picker: ', h('em', B.condText(value))),
        B.row('Condition (JSON)', box),
        msg,
        h('div.d-flex.gap-2',
          B.button('Apply', () => {
            try { onChange(box.value.trim() ? JSON.parse(box.value) : undefined); } catch (e) { msg.textContent = 'That isn’t valid JSON: ' + e.message; msg.classList.add('text-danger'); }
          }, 'btn-outline-primary'),
          B.button('Remove the condition', () => onChange(undefined), 'btn-outline-danger')),
      );
      return wrap;
    }

    const on = shape.rules.length > 0;
    const name = B.id('cm');
    const radio = (val, label) => {
      const id = B.id('r');
      return h('div.form-check.form-check-inline',
        h('input.form-check-input', { type: 'radio', name, id, checked: (val === 'some') === on, dataset: { fid: `${fid}-${val}` },
          onchange: () => {
            if (val === 'none') onChange(undefined);
            else {
              const first = fields[0];
              onChange(first ? { field: first.key, op: 'eq', value: B.staticOptions(first.f)[0]?.value ?? '' } : (actions[0] ? { action: actions[0].key, is: 'success' } : (people && B.catalog.groups.length ? { user_group: B.catalog.groups.find((g) => g.level > 0)?.title || B.catalog.groups[0].title } : undefined)));
            }
          } }),
        h('label.form-check-label', { for: id }, label));
    };
    wrap.append(h('div.mb-2', { role: 'radiogroup' }, radio('none', noneLabel), radio('some', someLabel)));
    if (!on) return wrap;

    const rules = shape.rules.map((r) => ({ ...r }));
    const emit = () => onChange(unflat(shape.join, rules));

    if (rules.length > 1) {
      wrap.append(h('div.sfb-row.sfb-inline',
        B.select(shape.join, [{ value: 'all', label: 'all of these are true' }, { value: 'any', label: 'any of these is true' }],
          (v) => { shape.join = v; emit(); }, { 'aria-label': 'Join rules', dataset: { fid: fid + '-join' } })));
    }

    rules.forEach((r, i) => wrap.append(ruleRow(r, i)));
    wrap.append(B.button('+ Add a rule', () => {
      const first = fields[0];
      rules.push(first ? { field: first.key, op: 'eq', value: '' } : { action: actions[0]?.key || '', is: 'success' });
      emit();
    }, 'btn-link px-0', { dataset: { fid: fid + '-add' } }));
    return wrap;

    function ruleRow(r, i) {
      const rowFid = `${fid}-${i}`;
      const subjects = [];
      if (fields.length) subjects.push({ group: 'Fields', items: fields.map((x) => ({ value: 'f:' + x.key, label: x.label })) });
      if (actions.length) subjects.push({ group: 'Earlier actions', items: actions.map((x) => ({ value: 'a:' + x.key, label: `Result of: ${x.label}` })) });
      if (people && B.catalog.groups.length) {
        subjects.push({ group: 'The person filling it in', items: [{ value: 'g:', label: 'Their user group' }]
          .concat(B.catalog.userFields.map((u) => ({ value: 'u:' + u.name, label: `Their ${u.title} (user field)` })))
          .concat(B.catalog.sessionKeys.length ? [{ value: 'x:', label: 'Their session data' }] : []) });
      }
      const current = r.action !== undefined ? 'a:' + r.action : ('user_group' in r ? 'g:' : ('user_field' in r ? 'u:' + r.user_field : ('session' in r ? 'x:' : 'f:' + r.field)));
      // A subject that isn't offered (wrong page, deleted field) stays visible.
      if (!subjects.some((g) => g.items.some((x) => x.value === current))) subjects.unshift({ value: current, label: `${current.slice(2)} (not available here)` });

      const subj = B.select(current, subjects, (v) => {
        if (v.startsWith('a:')) rules[i] = { action: v.slice(2), is: 'success' };
        else if (v === 'g:') rules[i] = { user_group: B.catalog.groups.find((g) => g.level > 0)?.title || B.catalog.groups[0]?.title || 'Registered' };
        else if (v.startsWith('u:')) rules[i] = { user_field: v.slice(2), op: 'eq', value: userField(v.slice(2))?.options[0]?.value ?? '' };
        else if (v === 'x:') rules[i] = { session: B.catalog.sessionPaths.find((p) => p.list)?.path || B.catalog.sessionPaths[0]?.path || (B.catalog.sessionKeys[0] || 'abcdefuser'), op: 'contains', value: '' };
        else {
          const sf = fields.find((x) => x.key === v.slice(2));
          rules[i] = sf && WHOLE.includes(sf.type) ? { field: v.slice(2), op: 'filled' } : { field: v.slice(2), op: 'eq', value: '' };
        }
        emit();
      }, { 'aria-label': `Rule ${i + 1}: field`, dataset: { fid: rowFid + '-s' } });

      const parts = [subj];
      if ('session' in r) {
        // 0.15.0: a dotted path in the session; "contains" for a list.
        parts.push(B.input(r.session, (v) => { rules[i].session = v.trim(); emit(); }, { class: 'font-monospace', 'aria-label': `Rule ${i + 1}: session path`, list: 'sfb-session-paths', dataset: { fid: rowFid + '-sp' } }));
      }
      if ('user_group' in r) {
        const choices = B.catalog.groups.map((g) => ({ value: g.title, label: ' '.repeat(g.level * 2) + g.title }));
        const cur = groupTitle(r.user_group);
        if (!choices.some((c) => c.value === cur)) choices.unshift({ value: cur, label: `${cur} (not found)` });
        parts.push(h('span.align-self-center', 'is'), B.select(cur, choices, (v) => { rules[i].user_group = v; emit(); },
          { 'aria-label': `Rule ${i + 1}: user group (child groups count)`, dataset: { fid: rowFid + '-v' } }));
      } else if (r.action !== undefined) {
        parts.push(B.select(r.is, RESULTS.map(([value, label]) => ({ value, label })), (v) => { rules[i].is = v; emit(); },
          { 'aria-label': `Rule ${i + 1}: result`, dataset: { fid: rowFid + '-o' } }));
      } else {
        const uf = 'user_field' in r ? userField(r.user_field) : null;
        const fx = uf ? { type: uf.type, f: { options: { static: uf.options } } } : fields.find((x) => x.key === r.field);
        const ops = fx && WHOLE.includes(fx.type) ? OPS.filter((o) => NOVALUE.includes(o[0])) : OPS;
        parts.push(B.select(r.op, ops.map(([value, label]) => ({ value, label })), (v) => {
          rules[i].op = v;
          if (NOVALUE.includes(v)) delete rules[i].value;
          else if (['in', 'nin'].includes(v) && !Array.isArray(rules[i].value)) rules[i].value = valueText(rules[i].value) ? [valueText(rules[i].value)] : [];
          else if (!['in', 'nin'].includes(v) && Array.isArray(rules[i].value)) rules[i].value = rules[i].value[0] ?? '';
          emit();
        }, { 'aria-label': `Rule ${i + 1}: test`, dataset: { fid: rowFid + '-o' } }));

        if (!NOVALUE.includes(r.op)) {
          const opts = fx ? B.staticOptions(fx.f) : [];
          const multi = ['in', 'nin'].includes(r.op);
          if (fx && fx.type === 'checkbox') {
            parts.push(B.select(String(r.value ?? '1'), [{ value: '1', label: 'ticked' }, { value: '', label: 'not ticked' }],
              (v) => { rules[i].value = v; emit(); }, { 'aria-label': `Rule ${i + 1}: value`, dataset: { fid: rowFid + '-v' } }));
          } else if (opts.length && !multi && ['eq', 'neq'].includes(r.op)) {
            const choices = opts.map((o) => ({ value: o.value, label: o.label }));
            if (!choices.some((c) => c.value === String(r.value ?? ''))) choices.unshift({ value: String(r.value ?? ''), label: r.value ? String(r.value) : '— choose —' });
            parts.push(B.select(String(r.value ?? ''), choices, (v) => { rules[i].value = v; emit(); },
              { 'aria-label': `Rule ${i + 1}: value`, dataset: { fid: rowFid + '-v' } }));
          } else {
            const inp = B.input(valueText(r.value), (v) => {
              if (multi) rules[i].value = v.split(',').map((s) => s.trim()).filter(Boolean);
              else if (['gt', 'gte', 'lt', 'lte'].includes(r.op) && v.trim() !== '' && !Number.isNaN(Number(v)) && fx?.type === 'number') rules[i].value = Number(v);
              else rules[i].value = v;
              onChange(unflat(shape.join, rules), { typing: true });
            }, { 'aria-label': `Rule ${i + 1}: value${multi ? ' (separate with commas)' : ''}`, placeholder: multi ? 'a, b, c' : 'value', dataset: { fid: rowFid + '-v' } });
            parts.push(inp);
          }
        }
      }
      parts.push(B.iconButton('✕', `Remove rule ${i + 1}`, () => { rules.splice(i, 1); emit(); }, { dataset: { fid: rowFid + '-x' } }));
      return h('div.sfb-rule', ...parts);
    }
  };

  /** Fields a condition at this place may use (same or earlier page; same repeater row). */
  B.fieldsUpTo = (page, repeater = null) => {
    const out = [];
    (B.state.model.pages || []).forEach((p, pi) => {
      if (pi > page) return;
      const walk = (list) => (list || []).forEach((f) => {
        if (f.type === 'section') { walk(f.fields); return; }
        if (f.type === 'html' || f.type === 'divider') return;
        if (f.type !== 'repeater') out.push({ key: f.key, label: f.label || f.key, type: f.type, f });
      });
      walk(p.fields);
    });
    if (repeater) (repeater.fields || []).forEach((c) => { if (c.type !== 'html') out.push({ key: c.key, label: `${c.label || c.key} (this row)`, type: c.type, f: c }); });
    return out;
  };
  /** Every value field, repeater children included (approval steps: "any row"). */
  B.allValueFields = () => B.allFields().filter((x) => B.valueType(x.f.type)).map((x) => ({
    key: x.f.key, label: x.parent ? `${x.f.label || x.f.key} (any row of ${x.parent.label || x.parent.key})` : (x.f.label || x.f.key), type: x.f.type, f: x.f,
  }));
}
