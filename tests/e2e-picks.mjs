// Choices (0.9–0.13): lists from a file (JSON/CSV/TXT in the option folder
// only), a list that depends on another answer (worked out again on the
// server), places per choice ("(full)", "3 places left", and a race for the
// last place), at least / at most picks with the counter, and the
// type-to-search dropdown as a keyboard-usable combobox.
import * as L from './lib.mjs';
import fs from 'fs';
import path from 'path';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const OPT = path.join(L.SITE, 'images/squidform/options');
fs.writeFileSync(path.join(OPT, 'sf-test-colours.txt'), '# a comment\nred|Red\ngreen|Green\nblue\n');
const save = { key: 'a_save', type: 'save_data', config: { target: 'submissions' } };
const form = (fields, settings = {}) => ({ schema: 1, settings: { success_message: 'ok', ...settings }, pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit: [save] } }] });

await step('lists from files', async () => {
  L.makeForm('t-picks', form([
    { key: 'f_site', type: 'select', label: 'Site', options: { file: { path: 'sites.json' } } },
    { key: 'f_building', type: 'select', label: 'Building', options: { file: { path: 'buildings.csv', filter_by: { field: 'f_site', key: 'site' } } } },
    { key: 'f_colour', type: 'radios', label: 'Colour', options: { file: { path: 'sf-test-colours.txt' } } },
  ]));
  const g = await L.site();
  await g.goto(L.formUrl('t-picks'));
  ok(await g.locator('[name="sf[f_site]"] option[value="north"]').textContent() === 'North Campus', 'a JSON file');
  ok(await g.locator('[name="sf[f_colour]"][value="red"]').count() === 1 && await g.locator('[name="sf[f_colour]"][value="blue"]').count() === 1 && !(await g.content()).includes('a comment'), 'a TXT file (value|label, comments skipped)');
  await g.selectOption('[name="sf[f_site]"]', 'hq');
  await g.waitForTimeout(300);
  const b = await g.locator('[name="sf[f_building]"] option:not([value=""])').evaluateAll((o) => o.filter((x) => !x.hidden && !x.disabled).map((x) => x.value));
  ok(b.length >= 1 && b.every((v) => v.startsWith('hq-')), `a CSV list that depends on another answer (${b.join(', ')})`);
  // A building from another site, sent anyway.
  await g.evaluate(() => { const s = document.querySelector('[name="sf[f_building]"]'); const o = new Option('North X', [...document.querySelectorAll('[name="sf[f_building]"] option')].map((x) => x.value).find((v) => v.startsWith('north')) || 'north-a'); s.add(o); s.value = o.value; });
  await L.rawNext(g);
  ok(/Building/.test(await L.text(g, '.sf-error-summary').catch(() => '')), 'a building that doesn\'t belong to the chosen site is refused on the server');
});

await step('files outside the option folder are refused', async () => {
  let err = '';
  try { L.makeForm('t-picks-bad', form([{ key: 'f_x', type: 'select', label: 'X', options: { file: { path: '../../configuration.php' } } }])); } catch (e) { err = String(e.stderr || e.message); }
  const g = await L.site();
  if (!err) { await g.goto(L.formUrl('t-picks-bad')); }
  ok(err !== '' || !(await g.content()).includes('$password'), 'a path out of the folder is refused (configuration.php can\'t be read)');
  L.deleteForms('t-picks-bad');
});

await step('places per choice', async () => {
  L.makeForm('t-places', form([{ key: 'f_slot', type: 'radios', label: 'Session', show_left: true, rules: { required: true },
    options: { static: [{ value: 'am', label: 'Morning', max: 2 }, { value: 'pm', label: 'Afternoon', max: 1 }] } }]));
  const g = await L.site();
  await g.goto(L.formUrl('t-places'));
  ok(/Afternoon.*1 place/i.test(await L.text(g, 'form[data-sf-alias="t-places"]')), 'it says how many places are left');
  // Two people open the form; the first takes the last afternoon place.
  const p1 = await L.site(); const p2 = await L.site();
  await p1.goto(L.formUrl('t-places')); await p2.goto(L.formUrl('t-places'));
  await L.fill(p1, { f_slot: 'pm' }); await L.fill(p2, { f_slot: 'pm' });
  ok(await L.submit(p1), 'the first person gets the last place');
  await L.next(p2);
  ok(/full|Session/i.test(await L.text(p2, '.sf-error-summary').catch(() => '')), 'the second, who opened the form before it filled, is told it\'s full');
  await p2.goto(L.formUrl('t-places'));
  ok(await p2.locator('[name="sf[f_slot]"][value="pm"]').isDisabled() && /full/i.test(await L.text(p2, 'form[data-sf-alias="t-places"]')), 'now it shows "(full)" and can\'t be picked');
  L.deleteForms('t-places');
});

await step('at least and at most', async () => {
  L.makeForm('t-count', form([{ key: 'f_tags', type: 'checkboxes', label: 'Pick two or three', counter: true, rules: { min_selected: 2, max_selected: 3 }, options: { static: ['a', 'b', 'c', 'd', 'e'] } }]));
  const g = await L.site();
  await g.goto(L.formUrl('t-count'));
  const counter = () => L.text(g, '[data-sf-key="f_tags"]');
  ok(/at least 2 more/.test(await counter()), 'the counter says how many are still needed');
  await L.fill(g, { f_tags: ['a'] });
  ok(/at least 1 more/.test(await counter()), 'and counts down');
  await L.fill(g, { f_tags: ['a', 'b'] });
  ok(/1 more/.test(await counter()) && !/at least/.test(await counter()), `then how many more are allowed (${await counter()})`);
  await g.uncheck('[name="sf[f_tags][]"][value="b"]');
  await L.rawNext(g);
  ok(/Pick two or three/.test(await L.text(g, '.sf-error-summary')), 'one is too few (server)');
  await L.fill(g, { f_tags: ['a', 'b', 'c', 'd'] });
  await L.rawNext(g);
  ok(/Pick two or three/.test(await L.text(g, '.sf-error-summary')), 'four is too many (server)');
  ok(await g.locator('.sf-choice-live, [data-sf-key="f_tags"] [aria-live]').count() >= 1, 'the counter is announced to screen readers');
  L.deleteForms('t-count');
});

await step('type to search', async () => {
  L.makeForm('t-search', form([{ key: 'f_fish', type: 'select', label: 'Fish', search: true, options: { static: ['Anemone', 'Angelfish', 'Barracuda', 'Clownfish', 'Cuttlefish', 'Eel'] } }]));
  const g = await L.site();
  await g.goto(L.formUrl('t-search'));
  const box = g.getByRole('combobox', { name: 'Fish' });
  ok(await box.count() === 1, 'the dropdown becomes a combobox with its label');
  await box.fill('fish');
  const opts = await g.getByRole('option').allTextContents();
  ok(opts.length === 3 && opts.every((o) => /fish/i.test(o)), `typing narrows the list (${opts.join(', ')})`);
  ok(/3/.test(await L.text(g, '[data-sf-key="f_fish"] [aria-live], [data-sf-key="f_fish"] [role="status"]').catch(() => '')), 'and says how many match');
  await box.press('ArrowDown'); await box.press('ArrowDown'); await box.press('Enter');
  ok(await g.inputValue('[name="sf[f_fish]"]') === 'Clownfish', 'arrow keys and Enter pick one');
  await L.axe(g, 'the searchable dropdown', 'form[data-sf-alias="t-search"]');
  ok(await L.submit(g) && L.latest('t-search').data.f_fish === 'Clownfish', 'and it\'s sent');
  L.deleteForms('t-search');
});

L.deleteForms('t-picks');
fs.unlinkSync(path.join(OPT, 'sf-test-colours.txt'));
await L.close();
L.finish();
