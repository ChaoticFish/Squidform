/**
 * SquidForm builder: Approvals. Off by default: a form without approvals
 * just saves what people send. On: one approver (or group) for the whole
 * form, or steps in order, each with its own approvers and condition.
 */
export function install(B) {
  const { h } = B;
  const S = B.state;

  const userFields = () => B.allFields().filter((x) => !x.parent && x.f.type === 'user');
  const emailFields = () => B.allFields().filter((x) => !x.parent && x.f.type === 'email');

  function approverText(e) {
    const [kind, ref] = String(e).split(/:(.*)/s);
    if (kind === 'group') {
      const g = B.catalog.groups.find((x) => String(x.id) === ref || x.title === ref);
      return `Anyone in ${g ? g.title : ref}${g ? '' : ' (no such group)'}`;
    }
    if (kind === 'user') return `The account “${ref}”`;
    if (kind === 'field') return `The person picked in “${B.fieldName(ref)}”`;
    return e;
  }

  /** The list of approvers, with ways to add each kind. */
  function approversEditor(list, write, fid) {
    const wrap = h('div.sfb-approvers');
    const ul = h('ul.list-unstyled.mb-2');
    list.forEach((e, i) => ul.append(h('li.sfb-approver', approverText(e),
      B.iconButton('✕', `Remove ${approverText(e)}`, () => { list.splice(i, 1); write(list); }, { dataset: { fid: `${fid}-x${i}` } }))));
    if (!list.length) ul.append(h('li.text-danger.small', 'Nobody yet: add at least one.'));
    wrap.append(ul);

    const kind = B.select('group', [
      { value: 'group', label: 'A user group' }, { value: 'field', label: 'The person picked in a field' }, { value: 'user', label: 'One account' },
    ], () => paint(), { 'aria-label': 'Kind of approver', dataset: { fid: `${fid}-kind` } });
    const slot = h('span.sfb-slot');
    const addBtn = B.button('Add', () => {
      const v = slot.querySelector('select,input')?.value?.trim();
      if (!v) return;
      const entry = `${kind.value}:${v}`;
      if (!list.includes(entry)) { list.push(entry); write(list); }
    }, 'btn-outline-secondary', { dataset: { fid: `${fid}-add` } });
    function paint() {
      slot.textContent = '';
      if (kind.value === 'group') slot.append(B.select('', B.catalog.groups.map((g) => ({ value: g.title, label: ' '.repeat(g.level * 2) + g.title })), () => {}, { 'aria-label': 'Group' }));
      if (kind.value === 'field') {
        const uf = userFields();
        slot.append(uf.length ? B.select('', uf.map((x) => ({ value: x.f.key, label: x.f.label || x.f.key })), () => {}, { 'aria-label': 'Field' })
          : h('span.small.text-body-secondary', 'Add a Person picker field first.'));
      }
      if (kind.value === 'user') slot.append(B.input('', () => {}, { 'aria-label': 'Username or user id', placeholder: 'username' }));
    }
    paint();
    wrap.append(h('div.sfb-rep-add', kind, slot, addBtn));
    return wrap;
  }

  function ruleSelect(rule, on, fid) {
    return B.row('How many must approve', B.select(rule || 'any', [
      { value: 'any', label: 'Any one of them' }, { value: 'all', label: 'Each of them (one person from each group)' },
    ], (v) => on(v === 'any' ? undefined : v), { dataset: { fid } }));
  }

  function render() {
    const m = S.model;
    const a = m.approval;
    const wrap = h('div.sfb-wide');
    wrap.append(h('h3.h5', 'Approvals'));
    const probs = B.state.problems.filter((p) => /^approval/.test(p.path || ''));
    if (probs.length) wrap.append(h('div.alert.alert-danger.py-2.small', probs.map((p) => h('div', p.message))));

    wrap.append(B.check('Requests from this form need approval', !!a, (v) => B.update((mm) => {
      if (v) mm.approval = { mode: 'single', approvers: [] }; else delete mm.approval;
    }), 'Off: what people send is saved as Submitted. On: it waits for approvers, who work on the site under “Awaiting my approval”.'));
    if (!a) return wrap;

    const name = B.id('mode');
    const radio = (val, label, help) => {
      const id = B.id('r');
      return h('div.form-check', h('input.form-check-input', { type: 'radio', name, id, checked: (a.mode || 'single') === val, dataset: { fid: 'ap-mode-' + val },
        onchange: () => B.update((mm) => {
          const cur = mm.approval;
          if (val === 'chain') {
            mm.approval = { ...cur, mode: 'chain', steps: cur.steps || [{ key: 's_approve', title: cur.title || 'Approval', approvers: cur.approvers || [], ...(cur.rule ? { rule: cur.rule } : {}) }] };
            delete mm.approval.approvers; delete mm.approval.rule; delete mm.approval.title;
          } else {
            const first = (cur.steps || [])[0] || {};
            mm.approval = { ...cur, mode: 'single', approvers: first.approvers || [] };
            if (first.rule) mm.approval.rule = first.rule;
            delete mm.approval.steps;
          }
        }) }), h('label.form-check-label', { for: id }, label), h('div.form-text', help));
    };
    wrap.append(h('fieldset.mb-3', h('legend.h6', 'Who approves'),
      radio('single', 'One approval for the whole form', 'For example: anyone in Supervisors.'),
      radio('chain', 'Steps, in order', 'For example: the chosen supervisor, then Security for non-US visitors.')));

    if ((a.mode || 'single') === 'single') {
      wrap.append(h('div.sfb-card.sfb-step-card',
        approversEditor([...(a.approvers || [])], (list) => B.update((mm) => { mm.approval.approvers = list; }), 'ap-single'),
        ruleSelect(a.rule, (v) => B.update((mm) => { if (v) mm.approval.rule = v; else delete mm.approval.rule; }), 'ap-single-rule')));
    } else {
      const steps = a.steps || [];
      const ol = h('ol.sfb-steps');
      steps.forEach((s, i) => ol.append(stepCard(s, i, steps.length)));
      wrap.append(ol, B.button('+ Add a step', () => B.update((mm) => {
        const keys = (mm.approval.steps || []).map((x) => x.key);
        (mm.approval.steps ||= []).push({ key: B.uniqueKey('s_step' + (keys.length + 1), keys.concat(B.allKeys())), title: `Step ${keys.length + 1}`, approvers: [] });
      }), 'btn-outline-secondary', { dataset: { fid: 'ap-addstep' } }));
    }

    // Options
    const opt = (k, label, def, help) => B.check(label, a[k] === undefined ? def : !!a[k], (v) => B.update((mm) => { if (v === def) delete mm.approval[k]; else mm.approval[k] = v; }), help);
    const notify = a.notify || {};
    const nopt = (k, label, def) => B.check(label, notify[k] === undefined ? def : !!notify[k], (v) => B.update((mm) => {
      mm.approval.notify ||= {};
      if (v === def) delete mm.approval.notify[k]; else mm.approval.notify[k] = v;
      if (!Object.keys(mm.approval.notify).length) delete mm.approval.notify;
    }));
    const ef = emailFields();
    wrap.append(h('fieldset.mt-4', h('legend.h6', 'Options'),
      opt('allow_return', 'Approvers may return a request for changes', true),
      opt('allow_withdraw', 'The submitter may withdraw a request', true),
      opt('edit_while_pending', 'The submitter may edit while it’s waiting', true, 'Saving an edit starts the approvals again from the first step.'),
      opt('allow_self_approval', 'Someone may approve their own request', false),
      nopt('approvers', 'Email approvers when a request reaches them', true),
      nopt('submitter', 'Email the submitter when it’s approved, rejected or returned', true),
      B.row('Send the submitter’s emails to', B.select(notify.submitter_email || '', [{ value: '', label: 'Their account’s email' }, ...ef.map((x) => ({ value: x.f.key, label: `The “${x.f.label || x.f.key}” field (for guests)` }))],
        (v) => B.update((mm) => { mm.approval.notify ||= {}; if (v) mm.approval.notify.submitter_email = v; else delete mm.approval.notify.submitter_email; if (!Object.keys(mm.approval.notify).length) delete mm.approval.notify; }),
        { dataset: { fid: 'ap-subemail' } }))));
    wrap.append(h('p.form-text.mt-3', 'Emails after the decision (for example to a badge office) are on the Logic tab, under “After a decision or edit”.'));
    return wrap;
  }

  function stepCard(s, i, count) {
    const upd = (fn, o) => B.update((mm) => fn(mm.approval.steps[i]), o);
    const fid = `ap-step-${i}`;
    return h('li.sfb-card.sfb-step-card',
      h('div.sfb-step-head',
        h('span.sfb-step', { 'aria-hidden': 'true' }, String(i + 1)),
        B.row('Step name', B.input(s.title, (v) => upd((x) => { if (v) x.title = v; else delete x.title; }, { structural: false, merge: `step.${i}.title` }), { dataset: { fid: fid + '-title' } })),
        h('span.sfb-card-tools',
          B.iconButton('↑', `Move step ${i + 1} earlier`, () => B.update((mm) => { const st = mm.approval.steps; st.splice(i - 1, 0, st.splice(i, 1)[0]); }), { disabled: i === 0, dataset: { fid: fid + '-up' } }),
          B.iconButton('↓', `Move step ${i + 1} later`, () => B.update((mm) => { const st = mm.approval.steps; st.splice(i + 1, 0, st.splice(i, 1)[0]); }), { disabled: i === count - 1, dataset: { fid: fid + '-dn' } }),
          B.iconButton('🗑', `Delete step ${i + 1}`, () => { B.update((mm) => { mm.approval.steps.splice(i, 1); }); B.announce('Step deleted. Undo brings it back.'); }, { disabled: count === 1, dataset: { fid: fid + '-del' } }))),
      approversEditor([...(s.approvers || [])], (list) => upd((x) => { x.approvers = list; }), fid),
      ruleSelect(s.rule, (v) => upd((x) => { if (v) x.rule = v; else delete x.rule; }), fid + '-rule'),
      h('div.mt-2', h('p.small.fw-semibold.mb-1', 'When this step is needed'), B.conditionEditor({
        value: s.when, fields: B.allValueFields(), noneLabel: 'Always', someLabel: 'Only when', fid: fid + '-when',
        onChange: (c, o) => upd((x) => { if (c) x.when = c; else delete x.when; }, o?.typing ? { structural: false, merge: `step.${i}.when` } : {}),
      }), s.when ? h('p.form-text', 'Otherwise it’s skipped, and the trail says so.') : null),
      h('details', h('summary.small', 'Key'), B.input(s.key, (v) => upd((x) => { x.key = v; }, { structural: false, merge: `step.${i}.key` }), { class: 'font-monospace', 'aria-label': 'Step key', dataset: { fid: fid + '-key' } })));
  }

  // Typing changes the model only; the boxes already show what was typed.
  return { render, refresh() {} };
}
