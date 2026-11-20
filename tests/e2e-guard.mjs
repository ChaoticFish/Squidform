// Permissions: an administrator (Manager) gets only what each form's
// permissions give them. View Submissions per form (list, detail, Activity),
// Export, Archive, Permanently Delete, and the screens kept for Admin on
// SquidForm (Data tables, Check this server, Options). Every refusal leaves
// the data as it was.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const def = (who) => ({
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name', rules: { required: true } }],
    events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
const A = L.makeForm('t-guard-a', def('A'));
const B = L.makeForm('t-guard-b', def('B'));
L.ensureUser('sf_guard_mgr', ['Manager'], { name: 'Mia Manager' });
const MGR = L.groupId('Manager');
// Like a real site: Managers may use SquidForm (Options → Permissions → Access Administration Interface).
const rulesBefore = L.componentRules();
L.componentRules({ ...rulesBefore, 'core.manage': { ...(rulesBefore['core.manage'] || {}), [MGR]: 1 } });
const g = await L.site();
await L.send(g, 't-guard-a', { f_name: 'Answer in A' });
await L.send(g, 't-guard-b', { f_name: 'Answer in B' });
const sa = L.latest('t-guard-a');
const sb = L.latest('t-guard-b');
const listText = async (p, fid = 0) => { await p.goto(L.adminUrl(`view=submissions${fid ? `&filter[form_id]=${fid}` : ''}`)); return L.text(p, '#content, main'); };
const archived = (id) => L.sql(`SELECT archived FROM jos_squidform_submissions WHERE id=${id}`) === '1';

const m = await L.admin('sf_guard_mgr');

await step('nothing until a form gives it', async () => {
  L.formRules('t-guard-a', {}); L.formRules('t-guard-b', {});
  const t = await listText(m);
  ok(!t.includes('Answer in A') && !t.includes('Answer in B'), 'a Manager with no View Submissions sees no answers');
  await m.goto(L.adminUrl(`view=submission&id=${sa.id}`));
  ok(!(await L.text(m)).includes('Answer in A'), '…and can\'t open one by its number');
});

await step('View Submissions on one form', async () => {
  L.formRules('t-guard-a', { 'squidform.view': { [MGR]: 1 } });
  const t = await listText(m);
  ok(t.includes('Answer in A') && !t.includes('Answer in B'), `sees form A's answers, not form B's`);
  ok(!(await listText(m, B)).includes('Answer in B'), 'filtering the list to form B shows nothing');
  await m.goto(L.adminUrl(`view=submission&id=${sb.id}`));
  ok(!(await L.text(m)).includes('Answer in B'), 'can\'t open a form B answer by its number');
  await m.goto(L.adminUrl(`view=submission&id=${sa.id}`));
  ok((await L.text(m)).includes('Answer in A'), 'can open a form A answer');
  await m.goto(L.adminUrl('view=activity'));
  const act = await L.text(m, '#content, main');
  ok(!act.includes('t-guard-b') && !act.includes('Test t-guard-b'), 'Activity leaves out form B');
});

await step('Export', async () => {
  const r = await m.goto(L.adminUrl('view=export'));
  ok(r.status() === 403 || /permission|not allowed|not permitted/i.test(await L.text(m)), `no Export permission: the export screen is refused (${r.status()})`);
  L.formRules('t-guard-a', { 'squidform.view': { [MGR]: 1 }, 'squidform.export': { [MGR]: 1 } });
  const r2 = await m.goto(L.adminUrl('view=export'));
  ok(r2.status() === 200, 'with Export on form A, the export screen opens');
  const opts = await m.$$eval('select option', (o) => o.map((x) => x.textContent)).catch(() => []);
  ok(!opts.some((o) => o.includes('t-guard-b')), 'and it doesn\'t offer form B');
  await m.goto(L.adminUrl('view=submissions'));
  const resp = await L.adminTask(m, 'export.start', { form_id: String(B), format: 'csv' });
  const body = await resp.text();
  ok(!body.includes('Answer in B'), 'asking to export form B anyway gets nothing');
});

await step('Archive', async () => {
  // Archive Submissions falls back to Joomla's Delete, which Managers have site-wide; deny both here.
  L.formRules('t-guard-a', { 'squidform.view': { [MGR]: 1 }, 'core.delete': { [MGR]: 0 } });
  L.formRules('t-guard-b', { 'core.delete': { [MGR]: 0 } });
  await m.goto(L.adminUrl('view=submissions'));
  await L.adminTask(m, 'submissions.archive', { 'cid[]': String(sa.id) });
  ok(!archived(sa.id), 'without Archive Submissions (or Delete), archiving does nothing');
  L.formRules('t-guard-a', { 'squidform.view': { [MGR]: 1 }, 'squidform.delete': { [MGR]: 1 } });
  await m.goto(L.adminUrl('view=submissions'));
  await L.adminTask(m, 'submissions.archive', { 'cid[]': String(sb.id) });
  ok(!archived(sb.id), 'Archive on form A doesn\'t reach a form B answer');
  await L.adminTask(m, 'submissions.archive', { 'cid[]': String(sa.id) });
  ok(archived(sa.id), 'with Archive on form A, its answer is archived');
  await m.goto(L.adminUrl('view=submissions'));
  await L.adminTask(m, 'submissions.restore', { 'cid[]': String(sa.id) });
  ok(!archived(sa.id), 'and restored');
});

await step('Permanently delete', async () => {
  const r = await m.goto(L.adminUrl('view=purge'));
  ok(r.status() === 403, `without Permanently Delete, the confirmation screen is refused (${r.status()})`);
  L.sql(`UPDATE jos_squidform_submissions SET archived=1 WHERE id=${sa.id}`);
  await m.goto(L.adminUrl('view=submissions'));
  await L.adminTask(m, 'purge.start', { 'cid[]': String(sa.id) });
  await L.adminTask(m, 'purge.run', { confirm: '1' }).catch(() => {});
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE id=${sa.id}`)) === 1, 'and posting it anyway deletes nothing');
  L.sql(`UPDATE jos_squidform_submissions SET archived=0 WHERE id=${sa.id}`);
});

await step('Admin-only screens', async () => {
  for (const [v, what] of [['datatables', 'Data tables'], ['servercheck', 'Check this server']]) {
    const r = await m.goto(L.adminUrl(`view=${v}`));
    ok(r.status() === 403, `${what} is only for Admin on SquidForm (${r.status()})`);
  }
  await m.goto(`${L.BASE}/administrator/index.php?option=com_config&view=component&component=com_squidform`);
  ok(!(await m.locator('#jform_read_tables, [name="jform[read_tables]"]').count()), 'the Options (with the read and write allow-lists) aren\'t open to a Manager');
  await m.goto(L.adminUrl(`task=form.edit&id=${A}`));
  ok(!(await m.locator('#jform_rules, joomla-field-permissions').count()), 'a form\'s Permissions tab isn\'t shown to someone who can\'t change them');
});

await step('site: View Own can be taken away per form', async () => {
  L.ensureUser('sf_guard_reg', ['Registered']);
  const p = await L.site('sf_guard_reg');
  await L.send(p, 't-guard-b', { f_name: 'Mine in B' });
  const item = L.menuItem('t-guard-list', 'Guard viewer', `index.php?option=com_squidform&view=submissions&form_id=${B}`, {});
  const url = `${L.BASE}/index.php?option=com_squidform&view=submissions&form_id=${B}&Itemid=${item}`;
  await p.goto(url);
  ok((await L.text(p)).includes('Mine in B'), 'with View Own (the default for Registered) a person sees their own');
  L.formRules('t-guard-b', { 'squidform.view.own': { [L.groupId('Registered')]: 0 } });
  await p.goto(url);
  ok(!(await L.text(p)).includes('Mine in B'), 'with View Own denied on the form, they don\'t');
  L.deleteMenuItem('t-guard-list');
});

L.componentRules(rulesBefore);
L.deleteForms('t-guard-a', 't-guard-b');
await L.close();
L.finish();
