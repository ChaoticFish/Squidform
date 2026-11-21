// Joomla user data, read only (0.14.0): show and hide by user group and by a
// Joomla user field (worked out on the server; the browser never gets the
// field's value), a list from a user field's own choices, the person picker
// (only the named groups, never blocked accounts, never email addresses),
// and SquidForm never changing anyone's account.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.ensureGroup('SF Staff');
const staff = L.ensureUser('sf_ud_staff', ['Registered', 'SF Staff'], { name: 'Stella Staff', email: 'stella@example.test' });
const plain = L.ensureUser('sf_ud_plain', ['Registered'], { name: 'Paul Plain', email: 'paul@example.test' });
const blocked = L.ensureUser('sf_ud_blocked', ['Registered', 'SF Staff'], { name: 'Bob Blocked' });
L.sql(`UPDATE jos_users SET block=1 WHERE id=${blocked}`);
// A Joomla user field "department" (a list), HR for Stella.
L.sql("DELETE v FROM jos_fields_values v JOIN jos_fields f ON f.id=v.field_id WHERE f.name='sf-department'");
L.sql("DELETE FROM jos_fields WHERE name='sf-department'");
L.sql(`INSERT INTO jos_fields (asset_id, context, group_id, title, name, label, default_value, type, note, description, state, required, only_use_in_subform, ordering, params, fieldparams, language, created_time, created_user_id, modified_time, modified_by, access)
       VALUES (0, 'com_users.user', 0, 'Department', 'sf-department', 'Department', '', 'list', '', '', 1, 0, 0, 0, '{}', '${L.esc(JSON.stringify({ options: { options0: { name: 'People', value: 'HR' }, options1: { name: 'Computers', value: 'IT' } }, multiple: '0' }))}', '*', NOW(), 0, NOW(), 0, 1)`);
const fieldId = L.sql("SELECT id FROM jos_fields WHERE name='sf-department'");
L.sql(`INSERT INTO jos_fields_values (field_id, item_id, value) VALUES (${fieldId}, '${staff}', 'HR')`);
// Signing in changes lastvisitDate (Joomla does that), so compare what SquidForm could change.
const accounts = () => L.sql("SELECT MD5(GROUP_CONCAT(id, name, username, email, password, block, params ORDER BY id)) FROM jos_users") + L.sql('SELECT MD5(GROUP_CONCAT(user_id, group_id ORDER BY user_id, group_id)) FROM jos_user_usergroup_map') + L.sql('SELECT MD5(GROUP_CONCAT(field_id, item_id, value ORDER BY field_id, item_id)) FROM jos_fields_values');
const usersBefore = accounts();

L.makeForm('t-ud', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name' },
    { key: 'f_staff', type: 'text', label: 'Staff only', show_if: { user_group: 'SF Staff' } },
    { key: 'f_hr', type: 'text', label: 'Personnel only', show_if: { user_field: 'sf-department', op: 'eq', value: 'HR' } },
    { key: 'f_dept', type: 'select', label: 'Which department', options: { user_field: 'sf-department' } },
    { key: 'f_who', type: 'user', label: 'Who to tell', groups: ['SF Staff'], exclude_self: true },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});

await step('show and hide by group and user field', async () => {
  const s = await L.site('sf_ud_staff');
  await s.goto(L.formUrl('t-ud'));
  ok(await s.locator('[name="sf[f_staff]"]').isVisible(), 'a member of SF Staff sees the staff-only field');
  ok(await s.locator('[name="sf[f_hr]"]').isVisible(), 'someone whose Department is HR sees the HR field');
  const html = (await s.content()).replace(/<select[^]*?<\/select>/g, ''); // the lists themselves offer departments and staff names
  const leak = ['sf-department', 'user_field', 'user_group', 'SF Staff', '"HR"', "'HR'"].filter((x) => html.includes(x));
  ok(leak.length === 0 && !/[>:"]\s*HR\b/.test(html), `the page never carries the group's name or the field's value, only the outcome${leak.length ? ` (${leak.join(', ')})` : ''}`);
  const p = await L.site('sf_ud_plain');
  await p.goto(L.formUrl('t-ud'));
  ok(!(await p.locator('[name="sf[f_staff]"]').isVisible()) && !(await p.locator('[name="sf[f_hr]"]').isVisible()), 'someone else sees neither');
  // Paul fills them in anyway.
  await p.evaluate(() => { for (const k of ['f_staff', 'f_hr']) { const i = document.querySelector(`[name="sf[${k}]"]`); i.closest('[hidden]')?.removeAttribute('hidden'); i.disabled = false; i.value = 'sneaky'; } });
  await L.fill(p, { f_name: 'Paul' });
  ok(await L.submit(p), 'sent');
  const d = L.latest('t-ud').data;
  ok(!d.f_staff && !d.f_hr, 'their answers to hidden fields are dropped on the server');
  const g = await L.site();
  await g.goto(L.formUrl('t-ud'));
  ok(!(await g.locator('[name="sf[f_staff]"]').isVisible()), 'a guest sees neither');
});

await step('a list from a user field\'s choices', async () => {
  const s = await L.site('sf_ud_staff');
  await s.goto(L.formUrl('t-ud'));
  const opts = await s.locator('[name="sf[f_dept]"] option').evaluateAll((o) => o.filter((x) => x.value).map((x) => `${x.value}=${x.textContent}`));
  ok(opts.join() === 'HR=People,IT=Computers', `the choices come from the Joomla field (${opts.join(', ')})`);
});

await step('the person picker', async () => {
  const s = await L.site('sf_ud_staff');
  await s.goto(L.formUrl('t-ud'));
  const p = await L.site('sf_ud_plain');
  await p.goto(L.formUrl('t-ud'));
  const opts = await p.locator('[name="sf[f_who]"] option').evaluateAll((o) => o.map((x) => x.textContent));
  ok(opts.some((o) => o.includes('Stella Staff')), 'it lists people in the named group');
  ok(!opts.some((o) => /Bob Blocked/.test(o)), 'not blocked accounts');
  ok(!opts.some((o) => /Paul Plain/.test(o)), 'not people outside the group');
  ok(!opts.some((o) => /@/.test(o)) && !(await p.content()).includes('stella@example.test'), 'and never their email addresses');
  const mine = await s.locator('[name="sf[f_who]"] option').evaluateAll((o) => o.map((x) => x.textContent));
  ok(!mine.some((o) => o.includes('Stella Staff')), '"exclude_self": you can\'t pick yourself');
  await p.evaluate((id) => { const sel = document.querySelector('[name="sf[f_who]"]'); sel.add(new Option('x', String(id))); sel.value = String(id); }, plain);
  await L.rawNext(p);
  ok(/Who to tell/.test(await L.text(p, '.sf-error-summary').catch(() => '')), 'someone outside the group, sent anyway, is refused');
});

await step('read only', async () => {
  ok(accounts() === usersBefore, 'no account, group or user field was changed');
});

if (!process.env.KEEP) L.sql(`DELETE FROM jos_fields_values WHERE field_id=${fieldId}`);
if (!process.env.KEEP) L.sql(`DELETE FROM jos_fields WHERE id=${fieldId}`);
if (!process.env.KEEP) L.deleteForms('t-ud');
await L.close();
L.finish();
