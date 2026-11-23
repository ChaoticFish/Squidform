// 0.15.0 features: session data (read only, allow-listed; values never sent
// to the page; locked prefill; trusted for recipients; rules judged on the
// server), the built-in starter handlers (off until ticked in Options), and
// test mode (banner, emails only to the tester with [TEST], test submissions
// marked, kept out of site lists and cleared in one step).
// Uses the test-only plugin fixtures/plg_system_sftestsession, which plays a
// sign-on script for the account sf_sess_user.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const was = L.sql("SELECT params FROM jos_extensions WHERE element='com_squidform'");
const plugin = (on) => L.sql(`UPDATE jos_extensions SET enabled=${on ? 1 : 0} WHERE element='sftestsession' AND folder='system'`);
ok(Number(L.sql("SELECT COUNT(*) FROM jos_extensions WHERE element='sftestsession'")) === 1, 'the test session plugin is installed on this test site');
plugin(true);
L.ensureUser('sf_sess_user', ['Registered'], { name: 'Session Person', email: 'joomla-account@example.test' });
const save = { key: 'a_save', type: 'save_data', config: { target: 'submissions' } };

await step('session data', async () => {
  L.params({ session_keys: '' });
  L.makeForm('t-sess', { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_emp', type: 'text', label: 'Employee number', prefill: '{session:abcdefuser.employeenumber}' },
    { key: 'f_other', type: 'text', label: 'Other', prefill: '{session:otherkey.hidden}' },
    { key: 'f_bravo', type: 'text', label: 'Bravo only', show_if: { session: 'abcdefuser.fineaccesscontrol', op: 'contains', value: 'BRAVO' } },
    { key: 'f_zulu', type: 'text', label: 'Zulu only', show_if: { session: 'abcdefuser.fineaccesscontrol', op: 'contains', value: 'ZULU' } },
  ], events: { load: [], submit: [save, { key: 'a_mail', type: 'email', when: { action: 'a_save', is: 'success' }, config: { to: '{session:abcdefuser.email}', subject: 'Session {session:abcdefuser.primaryorg}', body: 'Hi' } }] } }] });
  const p = await L.site('sf_sess_user');
  await p.goto(L.formUrl('t-sess'));
  ok(await p.inputValue('[name="sf[f_emp]"]') === '', 'nothing is read while the key isn\'t on the allow-list (empty at install)');
  L.params({ session_keys: 'abcdefuser' });
  await p.goto(L.formUrl('t-sess'));
  ok(await p.inputValue('[name="sf[f_emp]"]') === 'E12345', 'on the list, a field is filled from the session');
  ok(await p.locator('[name="sf[f_emp]"][readonly], [name="sf[f_emp]"][disabled]').count() === 1, 'and locked');
  ok(await p.inputValue('[name="sf[f_other]"]') === '', 'a key that isn\'t on the list stays unreadable');
  ok(await p.locator('[name="sf[f_bravo]"]').isVisible() && !(await p.locator('[name="sf[f_zulu]"]').isVisible()), 'show/hide by a session value');
  const html = await p.content();
  ok(!html.includes('S3CR3T') && !html.includes('fineaccesscontrol') && !html.includes('ALPHA') && !html.includes('never readable'), 'the page never carries session values it doesn\'t show, nor the rules\' inputs');
  await p.evaluate(() => { const i = document.querySelector('[name="sf[f_emp]"]'); i.readOnly = false; i.disabled = false; i.value = 'E99999'; });
  L.clearMail();
  ok(await L.submit(p), 'sent');
  ok(L.latest('t-sess').data.f_emp === 'E12345', 'changing the locked value in the browser does nothing');
  const m = (await L.waitMail(1))[0];
  ok(m && m.to.includes('sess@example.test') && m.subject === 'Session ORG-7', 'session values are trusted for recipients');
  const g = await L.site();
  await g.goto(L.formUrl('t-sess'));
  ok(await g.inputValue('[name="sf[f_emp]"]') === '', 'someone without the session value gets nothing');
  L.deleteForms('t-sess');
});

await step('starter handlers', async () => {
  L.params({ starters: [], session_keys: 'abcdefuser' });
  const def = { schema: 1, settings: { success_message: 'Ref {data:f_ref}' }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_ref', type: 'hidden', label: 'Reference' },
    { key: 'f_employeenumber', type: 'text', label: 'Employee number' },
    { key: 'f_primaryorg', type: 'text', label: 'Organisation' },
  ], events: { load: [{ key: 'a_who', type: 'run_php', config: { handler: 'SessionUser', settings: { session: 'abcdefuser', prefix: 'f_' } } }],
    submit: [{ key: 'a_num', type: 'run_php', config: { handler: 'NextNumber', settings: { prefix: 'REQ', digits: '4', field: 'f_ref' } } }, save] } }] };
  let err = '';
  try { L.makeForm('t-start', def); } catch (e) { err = String(e.stderr || e.message); }
  const p = await L.site('sf_sess_user');
  if (!err) { await p.goto(L.formUrl('t-start')); }
  ok(err !== '' || !(await L.submit(p)) || !/REQ/.test(L.latest('t-start')?.data.f_ref || ''), 'a starter that isn\'t ticked in Options can\'t be used');
  L.params({ starters: ['NextNumber', 'SessionUser'] });
  L.makeForm('t-start', def);
  const y = new Date().getFullYear();
  const q = await L.site('sf_sess_user');
  await q.goto(L.formUrl('t-start'));
  ok(await q.inputValue('[name="sf[f_employeenumber]"]') === 'E12345' && await q.inputValue('[name="sf[f_primaryorg]"]') === 'ORG-7', 'Session user fills every field named after a session value');
  ok(await L.submit(q), 'sent');
  const a1 = L.latest('t-start').data.f_ref;
  ok(a1 === `REQ-${y}-0001`, `Next number gives REQ-${y}-0001 (${a1})`);
  await L.send(await L.site('sf_sess_user'), 't-start', {});
  ok(L.latest('t-start').data.f_ref === `REQ-${y}-0002`, 'and then 0002');
  L.deleteForms('t-start');
});

await step('test mode', async () => {
  L.ensureUser('sf_tester', ['Registered'], { email: 'tester@example.test' });
  const fid = L.makeForm('t-testmode', { schema: 1, settings: { success_message: 'ok', test_mode: true }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name' }, { key: 'f_email', type: 'email', label: 'Email' },
  ], events: { load: [], submit: [save,
    { key: 'a_staff', type: 'email', when: { action: 'a_save', is: 'success' }, config: { to: 'boss@example.test', subject: 'New request', body: 'Hi' } },
    { key: 'a_conf', type: 'confirm_email', when: { action: 'a_save', is: 'success' }, config: { to_field: 'f_email' } }] } }] });
  const p = await L.site('sf_tester');
  await p.goto(L.formUrl('t-testmode'));
  ok(/test mode/i.test(await L.text(p, '#sf-t-testmode')), 'a "Test mode" banner');
  L.clearMail();
  await L.fill(p, { f_name: 'Tess', f_email: 'typed@example.test' });
  ok(await L.submit(p), 'sent');
  const mails = await L.waitMail(2);
  const staff = mails.find((m) => m.subject.includes('New request'));
  ok(staff && staff.to.includes('tester@example.test') && !staff.to.includes('boss@'), 'the staff email goes only to the tester');
  ok(staff && /\[TEST\]/.test(staff.subject) && (staff.html || staff.text).includes('boss@example.test'), 'with [TEST], saying who it would have gone to');
  ok(mails.some((m) => m.to.includes('typed@example.test') && /\[TEST\]/.test(m.subject)), 'the confirmation goes to the address typed, with [TEST]');
  const s = L.latest('t-testmode');
  ok(L.sql(`SELECT is_test FROM jos_squidform_submissions WHERE id=${s.id}`) === '1', 'the submission is marked as a test');
  const item = L.menuItem('t-testmode-list', 'Test list', `index.php?option=com_squidform&view=submissions&form_id=${fid}`, {});
  const ad = await L.site('admin');
  await ad.goto(`${L.BASE}/index.php?Itemid=${item}`);
  ok(!(await L.text(ad)).includes('Tess'), 'test submissions are left out of site lists');
  L.deleteMenuItem('t-testmode-list');
  const a = await L.admin();
  await a.goto(L.adminUrl(`view=submissions&filter[form_id]=${fid}`));
  ok(/test/i.test(await L.text(a, '#content, main')), 'the admin list marks it Test');
  await a.goto(L.adminUrl(`task=form.edit&id=${fid}`));
  await L.adminTask(a, 'form.cleartests', { id: String(fid) });
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${fid}`)) === 0, 'Clear test submissions removes them');
  await a.locator('#toolbar-cancel button, button.button-cancel').first().click().catch(() => {});
  L.deleteForms('t-testmode');
});

plugin(false);
L.sql(`UPDATE jos_extensions SET params='${L.esc(was)}' WHERE element='com_squidform'`);
await L.close();
L.finish();
