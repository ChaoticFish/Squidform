// Moving in from ChronoForms 7 (0.12.1+), from backup files: ChronoForms 7's
// own demo forms. Forms arrive unpublished with a report; a listing becomes
// another form's viewer settings or is reported; connected dropdowns and
// dynamic options keep their choices or say what needs doing; no PHP ever
// comes across; a file that isn't a backup is refused politely.
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
const importFile = async (file) => {
  const since = maxForm();
  await a.goto(L.adminUrl('view=chronoforms'));
  await a.setInputFiles('#sf-cf-file', file);
  await Promise.all([a.waitForNavigation(), a.click('#sf-cf-file-go')]);
  return { forms: newForms(since), report: await L.text(a, '#content, main') };
};
const made = [];

for (const [file, title] of [['multi_field.cf7bak', 'Muti Field Test'], ['connected_dropdowns.cf7bak', 'Connected DropDowns Demo'], ['dynamic_options.cf7bak', 'Dynamic Options DropDown']]) {
  await step(`ChronoForms 7: ${title}`, async () => {
    const { forms, report } = await importFile(path.join(FIX, file));
    made.push(...forms);
    ok(forms.length === 1 && forms[0].title.includes(title.split(' ')[0]), `the form was made (${forms.map((f) => f.title).join(', ')})`);
    ok(forms.every((f) => f.state === 0), 'unpublished');
    const d = defOf(forms[0]?.id || 0);
    ok(!/<\?php|\$_(GET|POST|REQUEST)|run_php/i.test(d), 'no PHP came across');
    ok(report.length > 100 && report.includes(title.split(' ')[0]), 'with a report');
    L.sql(`UPDATE jos_squidform_forms SET state=1 WHERE id=${forms[0].id}`);
    const g = await L.site();
    await g.goto(L.formUrl(forms[0].alias));
    ok(await g.locator(`form[data-sf-alias="${forms[0].alias}"] input, form[data-sf-alias="${forms[0].alias}"] select`).count() >= 1, 'it shows on the site');
  });
}

await step('a listing on its own', async () => {
  const { forms, report } = await importFile(path.join(FIX, 'basic_listing.cf7bak'));
  made.push(...forms);
  ok(forms.length === 0, 'a listing of articles (nothing to fill in) isn\'t made into a form');
  ok(/Basic Articles List/.test(report), 'the report says why');
});

await step('a file that isn\'t a backup', async () => {
  const bad = path.join(os.tmpdir(), `sf-bad-${process.pid}.cf7bak`);
  fs.writeFileSync(bad, '<?php echo "hello"; ?>');
  const since = maxForm();
  await a.goto(L.adminUrl('view=chronoforms'));
  await a.setInputFiles('#sf-cf-file', bad);
  await Promise.all([a.waitForNavigation(), a.click('#sf-cf-file-go')]);
  ok(newForms(since).length === 0 && /ChronoForms|backup|read/i.test(await L.text(a, '#system-message-container, joomla-alert, .alert')), 'is refused with a message');
  fs.unlinkSync(bad);
});

L.deleteForms(...made.map((f) => f.alias));
await L.close();
L.finish();
