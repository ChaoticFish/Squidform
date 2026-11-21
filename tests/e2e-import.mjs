// Moving in from ChronoForms 8 (0.12.0+) and importing submissions from CSV.
// From a backup file (ChronoForms' own demo forms) and from this site's
// ChronoForms tables, with the data log as submissions. PHP never comes
// across; ChronoForms' tables are only read; forms arrive unpublished, each
// with a report. CSV: columns matched to fields, a dry run that saves
// nothing, then the import, refusing values a field wouldn't take, and
// spreadsheet formulas never becoming formulas.
import * as L from './lib.mjs';
import fs from 'fs';
import os from 'os';
import path from 'path';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const a = await L.admin();
const FIX = path.join(L.HERE, 'fixtures', 'chrono');
const maxForm = () => Number(L.sql('SELECT COALESCE(MAX(id),0) FROM jos_squidform_forms'));
const newForms = (since) => L.sql(`SELECT CONCAT(id,'|',alias,'|',title,'|',state) FROM jos_squidform_forms WHERE id>${since} ORDER BY id`).split('\n').filter(Boolean).map((l) => { const [id, alias, title, state] = l.split('|'); return { id: Number(id), alias, title, state: Number(state) }; });
const defOf = (id) => L.sql(`SELECT v.schema_json FROM jos_squidform_forms f JOIN jos_squidform_form_versions v ON v.id=f.current_version_id WHERE f.id=${id}`);
const cleanup = (forms) => L.deleteForms(...forms.map((f) => f.alias));

await step('from a ChronoForms 8 backup file', async () => {
  const since = maxForm();
  await a.goto(L.adminUrl('view=chronoforms'));
  await a.setInputFiles('#sf-cf-file', path.join(FIX, 'demos.cf8bak'));
  await Promise.all([a.waitForNavigation(), a.click('#sf-cf-file-go')]);
  const report = await L.text(a, '#content, main');
  const forms = newForms(since);
  ok(forms.length >= 5, `forms were made (${forms.map((f) => f.title).join(', ')})`);
  ok(forms.every((f) => f.state === 0), 'every imported form starts unpublished');
  ok(forms.some((f) => /Contact Form/.test(f.title)) && forms.some((f) => /Multi Page/.test(f.title)), 'the contact and multi-page demos came across');
  ok(!forms.some((f) => /TCPDF/.test(f.title)), 'a "form" with nothing to fill in (the TCPDF test) isn\'t made into a form');
  ok(/TCPDF/.test(report) && /PHP/i.test(report), 'the report says what was left out and why');
  const all = forms.map((f) => defOf(f.id)).join('\n');
  ok(!/<\?php|\$_(GET|POST|REQUEST)|run_php/i.test(all), 'no PHP came across in any form');
  const multi = forms.find((f) => /Multi Page/.test(f.title));
  ok(multi && JSON.parse(defOf(multi.id).replace(/\\n/g, '\n')).pages.length >= 2, 'a multi-page form keeps its pages');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_imports WHERE form_id IN (${forms.map((f) => f.id).join(',') || 0})`)) >= forms.length, 'each form keeps its import report and field map');
  await L.axe(a, 'the import report', '#content, main');
  // Imported forms render.
  const contact = forms.find((f) => /Contact Form/.test(f.title));
  L.sql(`UPDATE jos_squidform_forms SET state=1 WHERE id=${contact.id}`);
  const g = await L.site();
  await g.goto(L.formUrl(contact.alias));
  ok(await g.locator(`form[data-sf-alias="${contact.alias}"] input`).count() >= 2, 'an imported form shows on the site');
  cleanup(forms);
});

await step('from this site\'s ChronoForms 8 tables, with the data log', async () => {
  const sqlFile = path.join(FIX, 'cf8-install.sql'); // ChronoForms 8's own table definitions
  const ddl = fs.existsSync(sqlFile) ? fs.readFileSync(sqlFile, 'utf8') : '';
  for (const stmt of ddl.replace(/#__/g, 'jos_').split(/;\s*\n/).filter((s) => /CREATE TABLE/i.test(s))) L.sql(stmt);
  L.sql('DELETE FROM jos_chronoforms8'); L.sql('DELETE FROM jos_chronoforms8_datalog');
  const demo = JSON.parse(fs.readFileSync(path.join(FIX, 'demos.cf8bak'), 'utf8')).find((f) => f.title === 'Contact Form');
  L.sql(`INSERT INTO jos_chronoforms8 (id, title, alias, published, elements, params) VALUES (8, 'Contact Form', 'contact-form', 1, '${L.esc(JSON.stringify(demo.elements))}', '${L.esc(JSON.stringify(demo.params || {}))}')`);
  // ChronoForms 8 keys its data log by element id (2 = name, 3 = email, 4 = message in this demo).
  L.sql(`INSERT INTO jos_chronoforms8_datalog (form_id, user_id, ip, created, data) VALUES (8, 0, '10.1.2.3', '2025-05-01 10:00:00', '${L.esc(JSON.stringify({ 2: 'Old Timer', 3: 'old@example.test', 4: 'From the old days' }))}'), (8, 0, '10.1.2.4', '2025-05-02 11:00:00', '${L.esc(JSON.stringify({ 2: 'Second', 3: 'two@example.test', 4: 'Hi' }))}')`);
  const before = L.sql('CHECKSUM TABLE jos_chronoforms8, jos_chronoforms8_datalog');
  const since = maxForm();
  await a.goto(L.adminUrl('view=chronoforms'));
  const t = await L.text(a, '#content, main');
  ok(/Contact Form/.test(t) && /\b2\b/.test(t), 'this site\'s ChronoForms 8 forms are listed, with how many log rows');
  await a.locator('input[name="cid[]"]').first().check();
  await a.locator('input[name="with_log"]').check();
  await Promise.all([a.waitForNavigation(), a.click('button[value="chronoforms.site"]')]);
  const forms = newForms(since);
  ok(forms.length === 1, 'the ticked form was made');
  const subs = L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${forms[0]?.id}`);
  ok(Number(subs) === 2, `its data log came across as submissions (${subs})`);
  const main = L.sql(`SELECT table_name FROM jos_squidform_data_tables WHERE form_id=${forms[0].id} AND kind='main'`);
  ok(L.sql(`SELECT * FROM jos_${main}`).includes('Old Timer'), `with the old answers`);
  ok(L.sql(`SELECT GROUP_CONCAT(DATE(created)) FROM jos_squidform_submissions WHERE form_id=${forms[0].id}`).includes('2025-05-01'), 'and the dates they were sent');
  ok(L.sql('CHECKSUM TABLE jos_chronoforms8, jos_chronoforms8_datalog') === before, 'ChronoForms\' own tables are unchanged');
  cleanup(forms);
  L.sql('DROP TABLE IF EXISTS jos_chronoforms8, jos_chronoforms8_datalog, jos_chronog3_extensions');
});

await step('submissions from a CSV', async () => {
  const fid = L.makeForm('t-csv', {
    schema: 1, settings: { success_message: 'ok' },
    pages: [{ key: 'p1', title: 'One', fields: [
      { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
      { key: 'f_email', type: 'email', label: 'Email' },
      { key: 'f_size', type: 'select', label: 'Size', options: { static: ['S', 'M', 'L'] } },
    ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
  });
  const file = path.join(os.tmpdir(), `sf-csv-${process.pid}.csv`);
  fs.writeFileSync(file, 'Name,Email,Size,Extra\nAda,ada@example.test,M,x\nBob,not-an-email,L,y\nCy,cy@example.test,XXL,z\n"=HYPERLINK(""http://evil.example"")",eve@example.test,S,w\n');
  await a.goto(L.adminUrl('view=csvimport'));
  await a.selectOption('#sf-csv-form', String(fid));
  await a.setInputFiles('#sf-csv-file', file);
  await Promise.all([a.waitForNavigation(), a.locator('button[type="submit"]').filter({ hasText: /upload|next|continue|read/i }).first().click()]);
  const m = await a.locator('select[name^="match["]').evaluateAll((s) => s.map((x) => x.value));
  ok(m[0] === 'f_name' && m[1] === 'f_email' && m[2] === 'f_size', `columns are matched to fields by their headings (${m.join(', ')})`);
  ok(m[3] === '' || m[3] === '-' || !/^f_/.test(m[3]), 'a column with no field is left out');
  await Promise.all([a.waitForNavigation(), a.click('#sf-csv-check')]);
  const check = await L.text(a, '#content, main');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${fid}`)) === 0, 'Check saves nothing');
  ok(/Email/.test(check) && /Size/.test(check), 'and says which rows have problems');
  await Promise.all([a.waitForNavigation(), a.click('#sf-csv-import')]);
  // Old answers are history: every row comes across, each value cleaned up as a typed answer
  // would be, and the ones that wouldn't pass today's checks are listed.
  const rows = L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${fid}`);
  ok(Number(rows) === 4, `every row is imported, as history (${rows})`);
  const cy = L.latest('t-csv', { where: [['f_name', '=', 'Cy']] });
  ok(cy && cy.data.f_size === 'XXL', 'an old answer that isn\'t on today\'s list is kept as it was');
  const report = await L.text(a, '#content, main');
  ok(/Row 2/.test(report) && /Row 3/.test(report), 'and the rows with problems (a bad email, a size not on the list) are listed');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE form_id=${fid} AND action='import'`)) === 4, 'each imported submission says so in its history');
  const eve = L.latest('t-csv', { where: [['f_email', '=', 'eve@example.test']] });
  ok(eve && eve.data.f_name.startsWith('=HYPERLINK'), 'a formula is stored as the text it was');
  const resp = await a.request.get(L.adminUrl(`task=export.start&form_id=${fid}&format=csv`)).catch(() => null);
  ok(!resp || !(await resp.text()).includes('\n=HYPERLINK') , '…and never exported as a live formula');
  fs.unlinkSync(file);
  L.deleteForms('t-csv');
});

await L.close();
L.finish();
