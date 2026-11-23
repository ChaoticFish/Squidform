// Every SquidForm admin screen and state, for a11y-100.mjs (light) and
// a11y-dark.mjs (dark): lists, the builder's every section (with a field of
// each kind open in turn), the JSON view, Preview, a submission with an
// approval, Activity, Export, Data tables, the importers, Saved for later,
// Check this server, the permanent-delete confirmation, and Options.
import * as L from './lib.mjs';
import path from 'path';
import fs from 'fs';
import os from 'os';
const { ok, step } = L;

export async function screens(dark) {
  await L.launch();
  L.testDefaults();
  const mode = dark ? 'dark' : 'light';
  const show = JSON.parse(fs.readFileSync(path.join(L.EXAMPLES, 'showcase.json'), 'utf8'));
  show.settings.captcha = 'none';
  const fid = L.makeForm('t-a11y', show);
  const a = await L.admin('admin', L.PASS, { dark });
  const check = async (what, include = '#content, main', exclude = []) => L.axe(a, `${what} (${mode})`, include, exclude);

  await step('the colour scheme is right', async () => {
    await a.goto(L.adminUrl('view=forms'));
    const t = await a.evaluate(() => document.documentElement.getAttribute('data-bs-theme') || document.documentElement.getAttribute('data-color-scheme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    ok(t === mode, `Atum is in ${mode} mode (${t})`);
  });

  for (const [view, what] of [['forms', 'Forms'], ['submissions', 'Submissions'], ['activity', 'Activity'], ['export', 'Export'], ['datatables', 'Data tables'], ['chronoforms', 'Import from ChronoForms'], ['csvimport', 'Import submissions from CSV'], ['resumes', 'Saved for later'], ['servercheck', 'Check this server'], ['delegates', 'Delegates']]) {
    await step(what, async () => {
      const r = await a.goto(L.adminUrl(`view=${view}`));
      ok(r.status() === 200, `${what} opens`);
      await check(what);
    });
  }

  await step('Data tables: one table', async () => {
    const tid = L.sql(`SELECT id FROM jos_squidform_data_tables WHERE form_id=${fid} AND kind='main'`);
    await a.goto(L.adminUrl(`view=datatables&layout=table&id=${tid}`));
    await check('a data table');
  });

  await step('the builder, section by section', async () => {
    await a.goto(L.adminUrl(`task=form.edit&id=${fid}`));
    await a.waitForSelector('#sf-builder-root:not([hidden])');
    for (const s of ['Fields', 'Logic', 'Approvals', 'Settings', 'Translations', 'Viewer', 'Placeholders']) {
      await a.getByRole('button', { name: s, exact: true }).click();
      await a.waitForTimeout(150);
      await check(`builder: ${s}`, '#sf-builder-root');
    }
    await a.getByRole('button', { name: 'Fields', exact: true }).click();
    // Each field's settings panel in turn (the first of each type).
    const seen = new Set();
    const items = a.locator('#sf-builder-root [aria-label^="Fields on page"] > li > button, #sf-builder-root [aria-label^="Fields on page"] > li button:first-of-type');
    const n = await items.count();
    let opened = 0;
    for (let i = 0; i < n && opened < 40; i++) {
      const b = items.nth(i);
      const t = ((await b.textContent()) || '').trim().split(/\s+/).slice(-1)[0];
      if (seen.has(t)) continue;
      seen.add(t);
      await b.click().catch(() => {});
      opened++;
    }
    await check('builder: field settings', '#sf-builder-root');
    await a.getByRole('button', { name: 'JSON', exact: true }).click();
    await check('builder: JSON', '#sf-builder-root, #content');
    await a.getByRole('button', { name: 'Builder', exact: true }).click();
    await a.getByRole('button', { name: 'Preview', exact: true }).click();
    await a.waitForSelector('#sf-preview-dialog[open]');
    await a.frameLocator('#sf-preview-dialog iframe').locator('form[data-sf-alias]').waitFor({ timeout: 15000 });
    await check('Preview', '#sf-preview-dialog');
    await a.keyboard.press('Escape');
    await a.locator('#toolbar-cancel button, button.button-cancel').first().click();
    await a.waitForLoadState();
  });

  await step('a new form: the starters', async () => {
    await a.goto(L.adminUrl('task=form.add'));
    await a.waitForSelector('#sf-builder-root:not([hidden])');
    await check('builder: Start from', '#sf-builder-root');
    await a.locator('#toolbar-cancel button, button.button-cancel').first().click();
    await a.waitForLoadState();
  });

  await step('a submission, with its approval panel', async () => {
    L.ensureGroup('SF Approvers');
    L.makeForm('t-a11y-sub', { schema: 1, settings: { success_message: 'ok' },
      approval: { mode: 'chain', steps: [{ key: 's_one', title: 'Check', approvers: ['group:SF Approvers'] }] },
      pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }, { key: 'f_note', type: 'textarea', label: 'Note' }],
        events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }] });
    await L.send(await L.site('admin'), 't-a11y-sub', { f_name: 'Ada', f_note: 'Two\nlines' });
    await a.goto(L.adminUrl(`view=submissions&filter[form_id]=${L.formId('t-a11y-sub')}`));
    await check('a form\'s submissions');
    await a.goto(L.adminUrl(`view=submission&id=${L.latest('t-a11y-sub').id}`));
    await check('a submission');
    L.deleteForms('t-a11y-sub');
  });

  await step('CSV import: matching columns', async () => {
    const file = path.join(os.tmpdir(), `sf-a11y-${process.pid}.csv`);
    fs.writeFileSync(file, 'Name,Email\nAda,ada@example.test\n');
    L.makeForm('t-a11y-csv', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }, { key: 'f_email', type: 'email', label: 'Email' }], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }] });
    await a.goto(L.adminUrl('view=csvimport'));
    await a.selectOption('#sf-csv-form', String(L.formId('t-a11y-csv')));
    await a.setInputFiles('#sf-csv-file', file);
    await Promise.all([a.waitForNavigation(), a.locator('button[type="submit"]').filter({ hasText: /upload|next|continue|read/i }).first().click()]);
    await check('CSV import: match columns');
    await Promise.all([a.waitForNavigation(), a.click('#sf-csv-check')]);
    await check('CSV import: check');
    L.deleteForms('t-a11y-csv');
    fs.unlinkSync(file);
  });

  await step('permanent delete', async () => {
    L.makeForm('t-a11y-del', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_x', type: 'text', label: 'X' }], events: { load: [], submit: [] } }] });
    const id = L.formId('t-a11y-del');
    await a.goto(L.adminUrl('view=forms'));
    await L.adminTask(a, 'forms.archive', { 'cid[]': String(id) });
    await L.adminTask(a, 'purge.start', { 'cid[]': String(id), kind: 'forms' });
    await a.goto(L.adminUrl('view=purge'));
    await check('Delete permanently: confirm');
    L.deleteForms('t-a11y-del');
  });

  await step('Options', async () => {
    await a.goto(`${L.BASE}/administrator/index.php?option=com_config&view=component&component=com_squidform`);
    const tabs = a.locator('joomla-tab button[role="tab"]').filter({ visible: true });
    const n = await tabs.count();
    for (let i = 0; i < n; i++) {
      const name = ((await tabs.nth(i).textContent()) || '').trim();
      if (/permission/i.test(name)) continue; // Joomla's own Permissions tab
      await tabs.nth(i).click();
      await a.waitForTimeout(100);
      await check(`Options: ${name}`, '#content, main', ['joomla-field-permissions']);
    }
    ok(n >= 5, `every Options tab (${n})`);
  });

  L.deleteForms('t-a11y');
  await L.close();
  L.finish();
}
