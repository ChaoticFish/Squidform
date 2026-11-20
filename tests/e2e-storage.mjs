// Storage (0.4.0+): each form's answers in its own tables (sfd_<alias>, one per
// repeater, a history table), typed columns, columns never dropped or renamed by
// a form change (retired instead; a new column when the kind of value changes),
// archive renames sfd_ → sfa_ and back, the Data tables screen (typed
// confirmation to drop a retired column), and permanent deletion of an archived
// form.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const page1 = (fields) => ({
  schema: 1, settings: { success_message: 'Saved.' },
  pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
const v1 = page1([
  { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
  { key: 'f_count', type: 'number', label: 'How many' },
  { key: 'f_when', type: 'date', label: 'When' },
  { key: 'f_ok', type: 'checkbox', label: 'OK?' },
  { key: 'f_tags', type: 'checkboxes', label: 'Tags', options: { static: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] } },
  { key: 'f_note', type: 'textarea', label: 'Note' },
  { key: 'f_rows', type: 'repeater', label: 'Rows', min: 1, max: 5, fields: [{ key: 'x_item', type: 'text', label: 'Item' }] },
]);
const fid = L.makeForm('t-store', v1);
const P = 'jos_';
const tables = () => L.sql(`SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${P}sf%\\_t\\_store%' ORDER BY table_name`).split('\n').filter(Boolean);
const colType = (t, c) => L.sql(`SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${t}' AND column_name='${c}'`);
const cols = (t) => L.sql(`SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${t}' ORDER BY ordinal_position`).split('\n').filter(Boolean);

await step('one set of tables per form', async () => {
  const t = tables();
  ok(t.includes(`${P}sfd_t_store`), `the form's main table (${t.join(', ')})`);
  ok(t.some((x) => x === `${P}sfd_t_store__f_rows`), 'a table for the repeater');
  ok(t.some((x) => x === `${P}sfd_t_store__history`), 'a history table');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_data_tables WHERE form_id=${fid}`)) === t.length, 'every table is in the registry');
  ok(colType(`${P}sfd_t_store`, 'f_count') === 'decimal' && colType(`${P}sfd_t_store`, 'f_when') === 'date' && colType(`${P}sfd_t_store`, 'f_ok') === 'tinyint', 'numbers, dates and tick boxes get typed columns');
});

const g = await L.site();
await step('a submission across the tables', async () => {
  await g.goto(L.formUrl('t-store'));
  await L.fill(g, { f_name: 'Storer', f_count: '3.5', f_when: '2026-12-01', f_ok: true, f_tags: ['a', 'b'], f_note: 'Line one\nLine two' });
  await g.fill('[name="sf[f_rows][0][x_item]"]', 'Kelp');
  ok(await L.submit(g), 'sent');
  const s = L.latest('t-store');
  const row = L.sql(`SELECT f_name, f_count, f_when, f_ok, f_tags FROM ${P}sfd_t_store WHERE submission_id=${s.id}`).split('\t');
  ok(row[0] === 'Storer' && Number(row[1]) === 3.5 && row[2] === '2026-12-01' && row[3] === '1', `the main row holds typed values (${row.join(' | ')})`);
  ok(/a/.test(row[4]) && /b/.test(row[4]), 'a tick-box list is stored in its column');
  ok(L.sql(`SELECT x_item FROM ${P}sfd_t_store__f_rows WHERE submission_id=${s.id}`) === 'Kelp', 'repeater rows are in their own table');
  const sub = cols('jos_squidform_submissions');
  ok(!sub.some((c) => /data|answers|json/i.test(c)), `the shared submissions table holds no answers (${sub.length} columns)`);
});

await step('changing the form keeps the old columns', async () => {
  const v2 = page1([
    { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
    { key: 'f_count', type: 'text', label: 'How many (in words)' }, // number → text: a new column
    { key: 'f_colour', type: 'text', label: 'Colour' }, // new
    { key: 'f_rows', type: 'repeater', label: 'Rows', min: 1, max: 5, fields: [{ key: 'x_item', type: 'text', label: 'Item' }] },
  ]); // f_when, f_ok, f_tags, f_note removed
  L.saveForm('t-store', v2, 'storage test v2');
  const c = cols(`${P}sfd_t_store`);
  ok(['f_when', 'f_ok', 'f_tags', 'f_note'].every((x) => c.includes(x)), 'columns of removed fields are kept');
  ok(c.includes('f_colour'), 'a new field gets a column');
  const countCols = L.sql(`SELECT c.column_name, c.col_class, c.retired FROM jos_squidform_data_columns c JOIN jos_squidform_data_tables t ON t.id=c.table_id WHERE t.form_id=${fid} AND c.field_key='f_count' ORDER BY c.id`).split('\n');
  ok(countCols.length === 2 && /number\t1$/.test(countCols[0]) && /text\t0$/.test(countCols[1]), `a field whose kind of value changes gets a new column; the old one is retired (${countCols.join(' / ')})`);
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_data_columns c JOIN jos_squidform_data_tables t ON t.id=c.table_id WHERE t.form_id=${fid} AND c.retired=1`)) >= 5, 'removed fields\' columns are marked retired');
  const old = L.latest('t-store', { where: [['f_name', '=', 'Storer']] });
  ok(L.sql(`SELECT f_when FROM ${P}sfd_t_store WHERE submission_id=${old.id}`) === '2026-12-01', 'the old answers are still there');
  await g.goto(L.formUrl('t-store'));
  await L.fill(g, { f_name: 'Newer', f_count: 'three', f_colour: 'teal' });
  await g.fill('[name="sf[f_rows][0][x_item]"]', 'Coral');
  ok(await L.submit(g), 'the changed form sends');
  const s = L.latest('t-store');
  ok(s.data.f_count === 'three' && s.data.f_colour === 'teal', 'its answers land in the new columns');
});

const a = await L.admin();
await step('Data tables screen', async () => {
  await a.goto(L.adminUrl('view=datatables'));
  const t = await L.text(a, '#content, main');
  ok(t.includes('sfd_t_store') && /retired column/.test(t), 'the Data tables screen lists the form\'s tables and their retired columns');
  const tid = L.sql(`SELECT id FROM jos_squidform_data_tables WHERE form_id=${fid} AND kind='main'`);
  await a.goto(L.adminUrl(`view=datatables&layout=table&id=${tid}`));
  ok(await a.locator('#sf-dt-tidy').count() === 1, 'a retired column can be dropped from there');
  await a.locator('#sf-dt-tidy summary').click();
  const form = a.locator('form.sf-dt-dropcolumn:has(input[name="column"][value="f_note"])');
  await form.locator('input[name="confirm"]').fill('f_not');
  await Promise.all([a.waitForNavigation(), form.locator('button[type="submit"]').click()]);
  ok(cols(`${P}sfd_t_store`).includes('f_note'), 'a mistyped name drops nothing');
  await a.locator('#sf-dt-tidy summary').click();
  await a.locator('form.sf-dt-dropcolumn:has(input[name="column"][value="f_note"]) input[name="confirm"]').fill('f_note');
  await Promise.all([a.waitForNavigation(), a.locator('form.sf-dt-dropcolumn:has(input[name="column"][value="f_note"]) button[type="submit"]').click()]);
  ok(!cols(`${P}sfd_t_store`).includes('f_note'), 'the name typed in full drops the column');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE action='drop_column' AND form_id=${fid}`)) >= 1, 'and it\'s logged');
  await a.reload(); // the "column dropped" message fades in (Joomla's own alert), which axe catches half-faded
  await L.axe(a, 'the Data tables screen', '#content, main');
});

await step('archive and restore', async () => {
  await a.goto(L.adminUrl('view=forms'));
  await L.adminTask(a, 'forms.archive', { 'cid[]': String(fid) });
  ok(L.sql(`SELECT state FROM jos_squidform_forms WHERE id=${fid}`) === '2', 'the form is archived');
  const t = tables();
  ok(t.length && t.every((x) => x.startsWith(`${P}sfa_`)), `its tables are renamed sfa_ (${t.join(', ')})`);
  const r = await g.goto(L.formUrl('t-store'));
  ok(r.status() === 404 || !(await g.locator('form[data-sf-alias="t-store"]').count()), 'an archived form isn\'t shown on the site');
  await a.goto(L.adminUrl('view=forms'));
  await L.adminTask(a, 'forms.publish', { 'cid[]': String(fid) });
  ok(tables().every((x) => x.startsWith(`${P}sfd_`)), 'publishing it again renames them back');
  ok(L.latest('t-store', { where: [['f_name', '=', 'Storer']] })?.data.f_name === 'Storer', 'every answer is still there');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE form_id=${fid} AND action IN ('archive_form','restore_form')`)) >= 2, 'archive and restore are logged');
});

await step('trash means archive', async () => {
  await a.goto(L.adminUrl('view=forms'));
  await L.adminTask(a, 'forms.trash', { 'cid[]': String(fid) });
  ok(L.sql(`SELECT state FROM jos_squidform_forms WHERE id=${fid}`) === '2', `there's no trash: removing a form archives it (state ${L.sql(`SELECT state FROM jos_squidform_forms WHERE id=${fid}`)})`);
});

await step('permanent deletion', async () => {
  await a.goto(L.adminUrl('view=forms&filter[published]=2'));
  await L.adminTask(a, 'purge.start', { 'cid[]': String(fid), kind: 'forms' });
  await a.goto(L.adminUrl('view=purge'));
  ok((await L.text(a, '#content, main')).includes('t-store'), 'the confirmation screen names the form');
  await a.fill('input[name="confirm"]', 't-stor');
  await Promise.all([a.waitForNavigation(), a.locator('button[type="submit"]').filter({ hasText: /delete/i }).first().click()]);
  ok(tables().length > 0, 'a mistyped alias deletes nothing');
  await a.fill('input[name="confirm"]', 't-store');
  await Promise.all([a.waitForNavigation(), a.locator('button[type="submit"]').filter({ hasText: /delete/i }).first().click()]);
  ok(tables().length === 0, 'typing the alias drops every table');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${fid}`)) === 0 && Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_data_tables WHERE form_id=${fid}`)) === 0, 'and the submissions and registry rows');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE form_id=${fid} AND action='purge_form'`)) === 1, 'the deletion is logged');
});

await step('long aliases', async () => {
  const long = 'a-really-long-form-alias-that-goes-on-and-on-past-what-mysql-allows';
  const id = L.makeForm(long, v1);
  const names = L.sql(`SELECT table_name FROM jos_squidform_data_tables WHERE form_id=${id}`).split('\n');
  ok(names.length >= 3 && names.every((n) => (P + n).length <= 64), `table names fit MySQL's 64 characters (${names.map((n) => (P + n).length).join(', ')})`);
  ok(new Set(names).size === names.length, 'and stay different from each other');
  L.deleteForms(long);
});

L.deleteForms('t-store');
await L.close();
L.finish();
