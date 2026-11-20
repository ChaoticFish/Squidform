// Export (0.2.0): CSV and JSON, labels or keys, columns picked, repeater rows,
// never the IP address, formula-looking cells defused, audited, and only for
// people allowed to export that form.
import * as L from './lib.mjs';
import fs from 'node:fs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const fid = L.makeForm('t-exp', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name' },
    { key: 'f_pet', type: 'select', label: 'Pet', options: { static: [{ value: 'cat', label: 'A cat' }, { value: 'dog', label: 'A dog' }] } },
    { key: 'f_rows', type: 'repeater', label: 'Toys', fields: [{ key: 'x_toy', type: 'text', label: 'Toy' }] },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
for (const [n, pet] of [['Ziggy', 'cat'], ['=1+2', 'dog'], ['Cocoa, "the brave"', 'cat']]) {
  const p = await L.site();
  await p.goto(L.formUrl('t-exp'));
  await L.fill(p, { f_name: n, f_pet: pet });
  await p.locator('[name="sf[f_rows][0][x_toy]"]').fill(`${n} toy`);
  await L.submit(p);
  await p.context().close();
}

const a = await L.admin();
const download = async (opts) => {
  await a.goto(L.adminUrl(`view=export&export_form=${fid}`));
  if (opts.format) await a.check(`#sfx-fmt-${opts.format}`);
  if (opts.keys) await a.check('#sfx-h-keys');
  if (opts.only) {
    await a.$$eval('input[name="cols[]"]', (cs) => cs.forEach((c) => { c.checked = false; }));
    for (const v of opts.only) await a.check(`input[name="cols[]"][value="${v}"]`);
  }
  const [d] = await Promise.all([a.waitForEvent('download'), a.click('#sfx-download')]);
  return { name: d.suggestedFilename(), body: fs.readFileSync(await d.path(), 'utf8') };
};

await step('CSV', async () => {
  const cols = await a.goto(L.adminUrl(`view=export&export_form=${fid}`)).then(() => a.$$eval('input[name="cols[]"]', (c) => c.map((x) => x.value)));
  ok(cols.includes('f:f_name') && cols.some((c) => c.startsWith('meta:')), `columns to pick: answers and details (${cols.length})`);
  ok(!cols.some((c) => /ip/i.test(c)), 'the IP address isn\'t offered');
  const d = await download({});
  ok(/\.csv$/.test(d.name) && d.name.includes('t-exp'), `a CSV file named after the form (${d.name})`);
  const body = d.body.replace(/^﻿/, '');
  ok(body.split('\n')[0].includes('Name') && body.includes('Pet'), 'headings are the labels');
  ok(body.includes('Ziggy') && /,cat(,|\r?\n|$)/m.test(body), 'choices exported as stored (option values), as the screen says');
  ok(body.includes('"Cocoa, ""the brave"""'), 'commas and quotes are quoted properly');
  ok(body.includes("'=1+2") && !/(^|,)=1\+2/m.test(body), 'a cell that looks like a formula is defused');
  ok(body.includes('Ziggy toy'), 'repeater rows are in the file');
  ok(!body.includes('127.0.0.1'), 'no IP address in the file');
});

await step('JSON with keys', async () => {
  const d = await download({ format: 'json', keys: true });
  const j = JSON.parse(d.body);
  const recs = Array.isArray(j) ? j : (j.submissions || j.rows || j.data || []);
  ok(/\.json$/.test(d.name) && recs.length === 3, `JSON with all three (${recs.length})`);
  const z = recs.find((r) => JSON.stringify(r).includes('Ziggy'));
  ok(z && ('f_name' in z || JSON.stringify(z).includes('"f_name"')), 'field keys as names');
  ok(!JSON.stringify(j).includes('127.0.0.1'), 'no IP address');
});

await step('picked columns', async () => {
  const d = await download({ only: ['f:f_name'] });
  const head = d.body.replace(/^﻿/, '').split('\n')[0];
  ok(head.includes('Name') && !head.includes('Pet'), `only the ticked columns (${head})`);
});

await step('audited', async () => {
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE form_id=${fid} AND action='export'`)) >= 3, 'each export is in the audit trail');
});

await step('permission', async () => {
  L.ensureUser('sf_exp_mgr', ['Manager']);
  const deny = JSON.stringify({ 'squidform.export': { [L.sql("SELECT id FROM jos_usergroups WHERE title='Manager'")]: 0 } });
  const asset = L.sql(`SELECT asset_id FROM jos_squidform_forms WHERE id=${fid}`);
  L.sql(`UPDATE jos_assets SET rules='${L.esc(deny)}' WHERE id=${asset}`);
  const m = await L.admin('sf_exp_mgr');
  await m.goto(L.adminUrl(`view=export&export_form=${fid}`));
  const t = await L.text(m, 'body');
  const can = await m.locator('#sfx-download').count();
  ok(!can || /not allowed|permission/i.test(t), 'someone denied Export on this form can\'t export it');
  L.sql(`UPDATE jos_assets SET rules='{}' WHERE id=${asset}`);
});

L.deleteForms('t-exp');
await L.close();
L.finish();
