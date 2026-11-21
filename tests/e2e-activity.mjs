// Activity (0.13.0): the audit trail across forms. Viewing, PDF, archive and
// restore show up with who did it; filters by form, kind, person and date;
// a person only sees the forms they may view submissions of; never an IP
// address; and nothing in it can be changed from the screen.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const def = { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }] };
const A = L.makeForm('t-act-a', def);
L.makeForm('t-act-b', def);
const g = await L.site();
await L.send(g, 't-act-a', { f_name: 'Alpha' });
await L.send(g, 't-act-b', { f_name: 'Bravo' });
const sa = L.latest('t-act-a');
const sb = L.latest('t-act-b');
const a = await L.admin();
await a.goto(L.adminUrl(`view=submission&id=${sa.id}`));
await a.request.get(L.adminUrl(`task=submission.pdf&id=${sa.id}`));
await a.goto(L.adminUrl('view=submissions'));
await L.adminTask(a, 'submissions.archive', { 'cid[]': String(sa.id) });
await L.adminTask(a, 'submissions.restore', { 'cid[]': String(sa.id) });
await a.goto(L.adminUrl(`view=submission&id=${sb.id}`));
const rows = async (p) => p.locator('#sf-activity tbody tr').evaluateAll((r) => r.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
const filter = async (p, vals) => {
  await p.goto(L.adminUrl('view=activity'));
  if (vals.form) await p.selectOption('#sf-act-form', String(vals.form));
  if (vals.group) await p.selectOption('#sf-act-group', vals.group);
  if (vals.user) await p.fill('#sf-act-user', vals.user);
  if (vals.from) await p.fill('#sf-act-from', vals.from);
  if (vals.to) await p.fill('#sf-act-to', vals.to);
  await Promise.all([p.waitForNavigation(), p.click('#sf-act-go')]);
  return rows(p);
};

await step('what\'s recorded', async () => {
  const r = await filter(a, { form: A });
  ok(r.some((x) => /Super User|admin/i.test(x) && /view|open|look/i.test(x)), 'opening a submission is recorded, with who');
  ok(r.some((x) => /PDF/i.test(x)), 'a PDF download is recorded');
  ok(r.some((x) => /archiv/i.test(x)) && r.some((x) => /restor/i.test(x)), 'archive and restore are recorded');
  ok(r.every((x) => !x.includes('Bravo') && !/t-act-b/.test(x)), 'filtering by form shows only that form');
  ok(r.some((x) => x.includes(sa.uid.slice(0, 8))), 'rows link to the submission by its short reference');
  ok(!(await L.text(a, '#content, main')).match(/\b127\.0\.0\.1\b|::1\b/), 'no IP address is shown');
});

await step('filters', async () => {
  const pdf = await filter(a, { form: A, group: 'downloads' });
  ok(pdf.length >= 1 && pdf.every((x) => /PDF|download/i.test(x)), `by kind (${pdf.length} downloads)`);
  ok((await filter(a, { user: 'nobody-like-this' })).length === 0, 'by person');
  ok((await filter(a, { form: A, from: '2001-01-01', to: '2001-12-31' })).length === 0, 'by date');
  await L.axe(a, 'the Activity screen', '#content, main');
});

await step('only the forms you may see', async () => {
  const MGR = L.groupId('Manager');
  const was = L.componentRules();
  L.componentRules({ ...was, 'core.manage': { ...(was['core.manage'] || {}), [MGR]: 1 } });
  L.ensureUser('sf_act_mgr', ['Manager']);
  L.formRules('t-act-b', { 'squidform.view': { [MGR]: 1 } });
  const m = await L.admin('sf_act_mgr');
  const r = await filter(m, {});
  ok(r.length > 0 && r.every((x) => !/Test t-act-a/.test(x)), 'a Manager who may view only form B sees only form B\'s rows');
  L.componentRules(was);
});

await step('read only', async () => {
  await a.goto(L.adminUrl('view=activity'));
  ok(await a.locator('#sf-activity input[type=checkbox], #toolbar-delete, button[value*="delete"]').count() === 0, 'no tick boxes or delete buttons');
  const n = Number(L.sql('SELECT COUNT(*) FROM jos_squidform_audit'));
  await a.goto(L.adminUrl('view=activity'));
  for (const t of ['activity.delete', 'activity.purge', 'audit.delete']) await L.adminTask(a, t, { 'cid[]': '1' }).catch(() => {});
  ok(Number(L.sql('SELECT COUNT(*) FROM jos_squidform_audit')) >= n, 'posting delete tasks at it removes nothing');
});

L.deleteForms('t-act-a', 't-act-b');
await L.close();
L.finish();
