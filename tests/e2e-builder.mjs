// The visual builder: a new form from a starter, adding fields from the
// palette, their settings (label, required, choices, show only when), moving,
// duplicating, deleting, Undo/Redo, the JSON view in step with the builder,
// saving (a new version each time), Preview, and every field type's settings
// panel reading cleanly (no stray "null" or "undefined", no axe problems).
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.deleteForms('t-built');
const a = await L.admin();
const B = '#sf-builder-root';
const btn = (name) => a.getByRole('button', { name, exact: true });
const panel = () => a.getByRole('region', { name: 'Settings for the selected item' });
const fieldsList = () => a.getByRole('list', { name: /Fields on page 1/ });
const keys = async () => a.locator(`${B} [aria-label^="Fields on page"] > li`).evaluateAll((n) => n.map((x) => (x.textContent.match(/\bf_[a-z0-9_]+/) || [''])[0]));
const save = async () => { await Promise.all([a.waitForNavigation(), a.locator('#toolbar-apply button, button.button-apply').first().click()]); };
const def = () => JSON.parse(L.sql("SELECT v.schema_json FROM jos_squidform_forms f JOIN jos_squidform_form_versions v ON v.id=f.current_version_id WHERE f.alias='t-built'").replace(/\\n/g, '\n').replace(/\\t/g, '\t') || 'null');

await step('a new form from a starter', async () => {
  await a.goto(L.adminUrl('task=form.add'));
  await a.waitForSelector(`${B}:not([hidden])`);
  ok(await a.getByRole('region', { name: 'Start from' }).count() === 1, 'a new form offers starters');
  await btn('Use this: Blank').click();
  ok(await a.getByRole('region', { name: 'Start from' }).count() === 0, 'choosing one closes the starters');
  ok(await a.getByRole('group', { name: 'Pages' }).getByRole('button', { name: /^1\./ }).count() === 1, 'Blank gives one page');
});

await step('adding fields and their settings', async () => {
  await btn('Text (one line)').click();
  await panel().getByRole('textbox', { name: 'Label', exact: true }).fill('Your name');
  await panel().getByRole('checkbox', { name: 'Required', exact: true }).check();
  await btn('Dropdown').click();
  await panel().getByRole('textbox', { name: 'Label', exact: true }).fill('Favourite fish');
  await panel().getByRole('textbox', { name: 'Choice 1' }).fill('Squid');
  await panel().getByRole('textbox', { name: 'Choice 2' }).fill('Eel');
  await panel().getByRole('button', { name: '+ Add a choice' }).click();
  await panel().getByRole('textbox', { name: 'Choice 3' }).fill('Other');
  await btn('Text box (several lines)').click();
  await panel().getByRole('textbox', { name: 'Label', exact: true }).fill('Tell us more');
  await panel().getByRole('radio', { name: 'Show it only when' }).check();
  await panel().getByRole('combobox', { name: 'Rule 1: field' }).selectOption({ label: 'Favourite fish' }).catch(async () => {
    const o = await panel().getByRole('combobox', { name: 'Rule 1: field' }).locator('option').evaluateAll((n) => n.map((x) => x.value));
    await panel().getByRole('combobox', { name: 'Rule 1: field' }).selectOption(o.find((v) => /dropdown|f_/.test(v)));
  });
  await panel().getByRole('combobox', { name: 'Rule 1: value' }).selectOption({ label: 'Other' });
  const k = await keys();
  ok(k.length === 3, `three fields on the page (${k.join(', ')})`);
  ok(!(await panel().textContent()).match(/\bnull\b|\bundefined\b/), 'the settings panel has no stray "null" or "undefined"');
});

await step('move, duplicate, delete, undo and redo', async () => {
  const before = await keys();
  await a.getByRole('button', { name: 'Move Tell us more up' }).click();
  const moved = await keys();
  ok(moved[1] === before[2] && moved[2] === before[1], `↑ moves a field up (${moved.join(', ')})`);
  await btn('Undo').click();
  ok((await keys()).join() === before.join(), 'Undo puts it back');
  await btn('Redo').click();
  ok((await keys()).join() === moved.join(), 'Redo does it again');
  await btn('Undo').click();
  await a.getByRole('button', { name: 'Duplicate Your name' }).click();
  const dup = await keys();
  ok(dup.length === 4 && new Set(dup).size === 4, `Duplicate adds a copy with its own key (${dup.join(', ')})`);
  await a.getByRole('button', { name: /^Delete Your name/ }).nth(1).click();
  ok((await keys()).length === 3, 'Delete removes it');
});

await step('the JSON view keeps step', async () => {
  await btn('JSON').click();
  const json = await a.locator('#jform_schema_json').inputValue();
  const d = JSON.parse(json);
  const f = d.pages[0].fields;
  ok(f[0].label === 'Your name' && f[0].rules?.required === true, 'JSON shows the label and Required');
  ok(JSON.stringify(f[1].options).includes('Squid') && JSON.stringify(f[1].options).includes('Other'), 'and the choices');
  ok(f[2].show_if && JSON.stringify(f[2].show_if).includes('Other'), 'and the show-only-when rule');
  d.pages[0].fields[0].label = 'Your full name';
  await a.locator('#jform_schema_json').fill(JSON.stringify(d, null, 2));
  await btn('Builder').click();
  ok(await fieldsList().getByRole('button', { name: /^Your full name/ }).count() >= 1, 'a change in JSON shows in the builder');
});

await step('save', async () => {
  await a.fill('#jform_title', 'Built in the builder');
  await a.fill('#jform_alias', 't-built');
  await save();
  const d = def();
  ok(d && d.pages[0].fields.length === 3, 'Save stores the definition');
  ok(Number(L.sql("SELECT COUNT(*) FROM jos_squidform_form_versions v JOIN jos_squidform_forms f ON f.id=v.form_id WHERE f.alias='t-built'")) === 1, 'as version 1');
  await a.waitForSelector(`${B}:not([hidden])`);
  await panel().count();
  await fieldsList().getByRole('button', { name: /^Your full name/ }).first().click();
  await panel().getByRole('textbox', { name: 'Help text' }).fill('As on your badge');
  await save();
  ok(Number(L.sql("SELECT COUNT(*) FROM jos_squidform_form_versions v JOIN jos_squidform_forms f ON f.id=v.form_id WHERE f.alias='t-built'")) === 2, 'a change saves as version 2');
  ok(L.sql("SHOW TABLES LIKE 'jos_sfd_t_built'") === 'jos_sfd_t_built', 'saving made the form\'s data table');
});

await step('the built form works on the site', async () => {
  L.sql("UPDATE jos_squidform_forms SET state=1 WHERE alias='t-built'");
  const g = await L.site();
  await g.goto(L.formUrl('t-built'));
  ok(await g.locator('label:has-text("Your full name")').count() === 1, 'the site shows the built form');
  const fishKey = def().pages[0].fields[1].key;
  const moreKey = def().pages[0].fields[2].key;
  ok(await g.locator(`[name="sf[${moreKey}]"]`).isHidden(), '"Tell us more" starts hidden');
  await g.selectOption(`[name="sf[${fishKey}]"]`, { label: 'Other' });
  ok(await g.locator(`[name="sf[${moreKey}]"]`).isVisible(), 'and shows when Other is picked');
});

await step('Preview', async () => {
  await a.goto(L.adminUrl(`task=form.edit&id=${L.formId('t-built')}`));
  await a.waitForSelector(`${B}:not([hidden])`);
  await btn('Preview').click();
  await a.waitForSelector('#sf-preview-dialog[open]');
  const fr = a.frameLocator('#sf-preview-dialog iframe');
  await fr.locator('form[data-sf-alias]').waitFor({ timeout: 15000 });
  ok(await fr.locator('label:has-text("Your full name")').count() === 1, 'Preview shows the form as it is in the builder');
  await a.keyboard.press('Escape');
  ok(!(await a.locator('#sf-preview-dialog[open]').count()), 'Escape closes Preview');
  await Promise.all([a.waitForNavigation(), a.locator('#toolbar-cancel button, button.button-cancel').first().click()]);
});

await step('every field type\'s settings read cleanly', async () => {
  await a.goto(L.adminUrl('task=form.add'));
  await a.waitForSelector(`${B}:not([hidden])`);
  await btn('Use this: Blank').click();
  const palette = a.getByRole('region', { name: 'Add a field' });
  const buttons = palette.getByRole('button');
  const names = [];
  const bad = [];
  for (let i = 0; i < await buttons.count(); i++) {
    const b = buttons.nth(i);
    const n = (await b.getAttribute('aria-label')) || (await b.innerText()).replace(/^\S+\s+/, '');
    names.push(n);
    await b.click();
    const t = (await panel().textContent()) || '';
    if (/\bnull\b|\bundefined\b|NaN|\[object Object\]/.test(t)) bad.push(n);
  }
  ok(names.length >= 30, `the palette has every field type (${names.length})`);
  ok(bad.length === 0, `no stray "null", "undefined" or "[object Object]" in any settings panel${bad.length ? ` (${bad.join(', ')})` : ''}`);
  ok((await keys()).length === names.length, 'each one was added');
  await L.axe(a, 'the builder with every field type', B);
  await a.locator('#toolbar-cancel button, button.button-cancel').first().click();
});

L.deleteForms('t-built');
await L.close();
L.finish();
