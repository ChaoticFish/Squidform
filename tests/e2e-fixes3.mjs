// Fixes and additions from 1.0.3 on, kept from coming back:
// - {AUTO_FIELDS} (any case) works as {data:_all} in emails and messages, and
//   the ChronoForms importer turns it into {data:_all} (1.0.4; Fishie's report).
// - Text that looks like a placeholder but isn't is pointed out: in the
//   builder ("Check before it goes live") and on Save, without blocking (1.0.4).
// - The reader for other extensions (Api\Submissions) and choices from
//   another form, opt-in field by field (1.0.3).
// (The viewer's filters with SEF off, the builder's stray "null", page titles
// and Preview's Escape are covered in e2e-viewer, e2e-builder, e2e-layout and
// e2e-dark.)
import * as L from './lib.mjs';
import fs from 'fs';
import os from 'os';
import path from 'path';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const save = { key: 'a_save', type: 'save_data', config: { target: 'submissions' } };
const after = { action: 'a_save', is: 'success' };

await step('{AUTO_FIELDS} in emails', async () => {
  L.makeForm('t-auto', { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Your name' }, { key: 'f_email', type: 'email', label: 'Email' },
  ], events: { load: [], submit: [save,
    { key: 'a_staff', type: 'email', when: after, config: { to: 'staff@example.test', subject: 'New', body: '<p>Staff copy</p>{AUTO_FIELDS}' } },
    { key: 'a_conf', type: 'confirm_email', when: after, config: { to_field: 'f_email', subject: 'Your copy', body: '<p>You sent:</p>{auto_fields}' } },
    { key: 'a_msg', type: 'message', when: after, config: { text: 'Got: { AUTO_FIELDS }' } }] } }] });
  L.clearMail();
  const g = await L.site();
  await L.send(g, 't-auto', { f_name: 'Inky', f_email: 'inky@example.test' });
  const mails = await L.waitMail(2);
  for (const who of ['staff@example.test', 'inky@example.test']) {
    const m = mails.find((x) => x.to.includes(who));
    const b = m ? (m.html || m.text) : '';
    ok(!!m && !/AUTO_FIELDS/i.test(b) && b.includes('Your name') && b.includes('Inky'), `${who === 'staff@example.test' ? 'the staff email' : 'the sender\'s own email (Fishie\'s report)'}: {AUTO_FIELDS} lists the answers`);
  }
  ok(/Your name/.test(await L.text(g, '#sf-t-auto')) && !/AUTO_FIELDS/i.test(await L.text(g, '#sf-t-auto')), 'in a message too, spaces and case don\'t matter');
  L.deleteForms('t-auto');
});

await step('the ChronoForms importer turns it into {data:_all}', async () => {
  const demo = JSON.parse(fs.readFileSync(path.join(L.HERE, 'fixtures/chrono/demos.cf8bak'), 'utf8')).find((f) => f.title === 'Contact Form');
  demo.title = 'Auto Fields Import';
  demo.alias = 'auto-fields-import';
  demo.elements['7'].body = '<p>Sent:</p>{AUTO_FIELDS}';
  const file = path.join(os.tmpdir(), `sf-auto-${process.pid}.cf8bak`);
  fs.writeFileSync(file, JSON.stringify([demo]));
  const since = Number(L.sql('SELECT COALESCE(MAX(id),0) FROM jos_squidform_forms'));
  const a = await L.admin();
  await a.goto(L.adminUrl('view=chronoforms'));
  await a.setInputFiles('#sf-cf-file', file);
  await Promise.all([a.waitForNavigation(), a.click('#sf-cf-file-go')]);
  const row = L.sql(`SELECT CONCAT(f.alias,'|',v.schema_json) FROM jos_squidform_forms f JOIN jos_squidform_form_versions v ON v.id=f.current_version_id WHERE f.id>${since} LIMIT 1`);
  const [alias, json] = [row.split('|')[0], row.slice(row.indexOf('|') + 1)];
  ok(!!alias && json.includes('{data:_all}') && !/AUTO_FIELDS/i.test(json), 'the imported email says {data:_all}');
  L.deleteForms(alias);
  fs.unlinkSync(file);
});

await step('placeholders that aren\'t are pointed out', async () => {
  const def = { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }], events: { load: [], submit: [save,
    { key: 'a_mail', type: 'email', when: after, config: { to: 'staff@example.test', subject: 'From {data:f_name} {FORM_TITLE}', body: '{data:f_nope} {wat:thing} {data:_all} {AUTO_FIELDS} {user:name}' } }] } }] };
  const id = L.makeForm('t-notes', def);
  const a = await L.admin();
  await a.goto(L.adminUrl(`task=form.edit&id=${id}`));
  await a.waitForSelector('#sf-builder-root:not([hidden])');
  await a.getByRole('button', { name: 'Fields', exact: true }).click();
  await a.locator('#jform_title').fill('Notes test');
  await a.waitForFunction(() => /Check before it goes live/.test(document.querySelector('#sf-builder-root')?.textContent || ''), null, { timeout: 15000 }).catch(() => {});
  const t = await L.text(a, '#sf-builder-root');
  ok(/Check before it goes live: 3 things/.test(t), `the builder lists them without blocking (${(t.match(/Check before[^:]*: \d+ things?/) || [''])[0]})`);
  ok(t.includes('{FORM_TITLE}') && t.includes('{data:f_nope}') && t.includes('{wat:thing}'), 'naming each one: no namespace, a field that isn\'t there, an unknown namespace');
  ok(!t.includes('{AUTO_FIELDS} isn') && !/\{user:name\} isn/.test(t), 'real placeholders (and {AUTO_FIELDS}) aren\'t listed');
  await Promise.all([a.waitForNavigation(), a.locator('#toolbar-apply button, button.button-apply').first().click()]);
  const msg = await L.text(a, '#system-message-container');
  ok(/FORM_TITLE/.test(msg) && /saved|published|version/i.test(msg), `Save still works, and warns (${msg.slice(0, 200)})`);
  await a.locator('#toolbar-cancel button, button.button-cancel').first().click();
  L.deleteForms('t-notes');
});

await step('the reader for other extensions', async () => {
  L.makeForm('t-api', { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name' }, { key: 'f_size', type: 'select', label: 'Size', options: { static: ['S', 'M', 'L'] } },
  ], events: { load: [], submit: [save] } }] });
  for (const [n, sz] of [['Ann', 'S'], ['Bob', 'L'], ['Cy', 'L']]) await L.send(await L.site(), 't-api', { f_name: n, f_size: sz });
  const rows = JSON.parse(L.sf('eval-api', 't-api', JSON.stringify({ where: [['f_size', '=', 'L']], order: [['f_name', 'desc']] })));
  ok(rows.length === 2 && rows[0].data.f_name === 'Cy' && rows[1].data.f_name === 'Bob', `find with where and order (${rows.map((r) => r.data.f_name).join(', ')})`);
  let err = '';
  try { L.sf('eval-api', 't-api', JSON.stringify({ where: [['f_size; DROP TABLE jos_users', '=', 'L']] })); } catch (e) { err = `${e.stderr || ''}${e.message}`; }
  ok(err !== '' && Number(L.sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='jos_users'")) === 1, 'a key that isn\'t a field is refused (never put into SQL)');
  const inj = JSON.parse(L.sf('eval-api', 't-api', JSON.stringify({ where: [['f_name', '=', "x' OR '1'='1"]] })));
  ok(inj.length === 0, 'values are bound');
  L.deleteForms('t-api');
});

await step('choices from another form, opt-in', async () => {
  L.makeForm('t-src', { schema: 1, settings: { success_message: 'ok', shared_fields: ['f_title'] }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_title', type: 'text', label: 'Title' }, { key: 'f_secret', type: 'text', label: 'Secret' },
  ], events: { load: [], submit: [save] } }] });
  await L.send(await L.site(), 't-src', { f_title: 'Night tour', f_secret: 'hush' });
  await L.send(await L.site(), 't-src', { f_title: 'Archived one', f_secret: 'x' });
  L.sql(`UPDATE jos_squidform_submissions SET archived=1 WHERE id=${L.latest('t-src').id}`);
  L.makeForm('t-pick', { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_event', type: 'select', label: 'Event', options: { form: { alias: 't-src', value: 'f_title', label: 'f_title' } } },
  ], events: { load: [], submit: [save] } }] });
  const g = await L.site();
  await g.goto(L.formUrl('t-pick'));
  const opts = await g.locator('[name="sf[f_event]"] option').allTextContents();
  ok(opts.includes('Night tour') && !opts.includes('Archived one'), `another form's shared answers are offered, not archived ones (${opts.join(', ')})`);
  let err = '';
  try { L.makeForm('t-pick2', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_x', type: 'select', label: 'X', options: { form: { alias: 't-src', value: 'f_secret', label: 'f_secret' } } }], events: { load: [], submit: [] } }] }); } catch (e) { err = `${e.stderr || ''}${e.message}`; }
  const g2 = await L.site();
  if (!err) await g2.goto(L.formUrl('t-pick2'));
  ok(err !== '' || !(await g2.content()).includes('hush'), 'a field the other form didn\'t share can\'t be used');
  L.deleteForms('t-src', 't-pick', 't-pick2');
});

await L.close();
L.finish();
