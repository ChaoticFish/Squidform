// Add-on hooks (0.13.1–1.0.3), with the test-only plugin in
// fixtures/plg_squidform_hooktest (switched on only for this suite). Each
// hook's good entry is used; its broken entry is left out without breaking
// anything: action and field types, PHP handlers, placeholders, choice lists,
// visitor languages, starter forms, address formats, the editor, and links
// on the Submissions screen (only inside SquidForm's admin).
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const plugin = (on) => L.sql(`UPDATE jos_extensions SET enabled=${on ? 1 : 0} WHERE element='hooktest' AND folder='squidform'`);
ok(Number(L.sql("SELECT COUNT(*) FROM jos_extensions WHERE element='hooktest' AND folder='squidform'")) === 1, 'the hook test plugin is installed on this test site');
plugin(true);
const was = L.sql("SELECT params FROM jos_extensions WHERE element='com_squidform'");
L.params({ languages: ['en-GB', 'it-IT'] });
const save = { key: 'a_save', type: 'save_data', config: { target: 'submissions' } };

const fid = L.makeForm('t-hooks', {
  schema: 1, settings: { success_message: 'Saved.', languages: ['en-GB', 'it-IT'] },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name' },
    { key: 'f_loud', type: 'hook_upper', label: 'Loud word' },
    { key: 'f_colour', type: 'radios', label: 'Colour', options: { addon: 'hooktest.colours' } },
    { key: 'f_addr', type: 'address', label: 'Dirección', format: 'mx' },
    { key: 'f_rich', type: 'editor', label: 'Rich text' },
  ], events: { load: [], submit: [
    { key: 'a_shout', type: 'run_php', config: { handler: 'HookShout', settings: { suffix: '!!' } } },
    save,
    { key: 'a_echo', type: 'hook_echo', when: { action: 'a_save', is: 'success' }, config: {} },
    { key: 'a_msg', type: 'message', when: { action: 'a_echo', is: 'success' }, config: { text: 'Said {action:a_echo.said}. {hooky:hello}' } },
  ] } }],
});

await step('a field type, a choice list, an address format and an editor from an add-on', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-hooks'));
  ok(await g.locator('[data-hooktest-field]').count() === 1, 'the add-on\'s field type renders');
  const colours = await g.locator('[name="sf[f_colour]"]').evaluateAll((n) => n.map((x) => x.value));
  ok(colours.join() === 'teal,coral', `the add-on's choice list (${colours.join(', ')})`);
  ok(await g.getByLabel('Código postal').count() === 1 && await g.getByLabel('Calle y número').count() === 1, 'the add-on\'s address format (México)');
  ok(await g.locator('[data-hooktest-editor]').count() === 1, 'the add-on\'s editor replaces the built-in one');
  await L.fill(g, { f_name: 'quiet', f_loud: 'bad', f_colour: 'coral' });
  await g.fill('[name="sf[f_addr][street]"]', 'Av. Reforma 1');
  await g.fill('[name="sf[f_addr][city]"]', 'CDMX');
  await g.fill('[name="sf[f_addr][zip]"]', '123');
  await L.rawNext(g);
  const e = await L.text(g, '.sf-error-summary').catch(() => '');
  ok(/not that word/.test(e), 'the field type\'s own check runs on the server');
  ok(/Código postal|Dirección/.test(e), 'the address format\'s pattern is checked on the server');
});

await step('an action type, a handler and a placeholder from an add-on', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-hooks'));
  await L.fill(g, { f_name: 'quiet', f_loud: 'calm', f_colour: 'teal' });
  await g.fill('[name="sf[f_addr][street]"]', 'Av. Reforma 1');
  await g.fill('[name="sf[f_addr][city]"]', 'CDMX');
  await g.fill('[name="sf[f_addr][zip]"]', '06600');
  ok(await L.submit(g), 'sent');
  const s = L.latest('t-hooks').data;
  ok(s.f_name === 'QUIET!!', `the handler changed the answer before it was saved (${s.f_name})`);
  ok(s.f_loud === 'CALM', 'the field type\'s normalize ran');
  ok(s.f_addr?.zip === '06600', 'the México address was saved');
  const msg = await L.text(g, '#sf-t-hooks');
  ok(/Said echo:QUIET!!/.test(msg), `the add-on action's output reached the message (${msg.slice(0, 80)})`);
  ok(msg.includes('Hello from a hook <b>bold</b>') && !(await g.locator('#sf-t-hooks b').count()), 'the add-on placeholder\'s value is escaped like any other');
});

await step('broken entries are left out', async () => {
  let err = '';
  try { L.makeForm('t-hooks-bad', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_x', type: 'radios', label: 'X', options: { addon: 'hooktest.broken' } }], events: { load: [], submit: [{ key: 'a_b', type: 'hook_broken', config: {} }] } }] }); } catch (e) { err = String(e.stderr || e.message); }
  ok(err !== '', 'a form can\'t use the broken action type');
  L.deleteForms('t-hooks-bad');
  const g = await L.site();
  L.makeForm('t-hooks-ph', { schema: 1, settings: { success_message: 'x' }, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }], events: { load: [], submit: [{ key: 'a_msg', type: 'message', config: { text: 'Name {data:f_name}' } }] } }] });
  await L.send(g, 't-hooks-ph', { f_name: 'Real' });
  ok(/Name Real/.test(await L.text(g, '#sf-t-hooks-ph')) && !/stolen/.test(await L.text(g, '#sf-t-hooks-ph')), 'an add-on can\'t take over a built-in placeholder (data:)');
  L.deleteForms('t-hooks-ph');
});

await step('visitor language from an add-on', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-hooks', '&sf_lang=it-IT'));
  const nav = await g.locator('button[name="sf_nav"]').allTextContents();
  ok(nav.some((t) => /Invia|Avanti/.test(t)), `the add-on's Italian words are used (${nav.join(', ')})`);
  ok(await g.locator('form[data-sf-alias="t-hooks"][lang="it"], #sf-t-hooks [lang="it"], #sf-t-hooks[lang="it"]').count() >= 1, 'with lang="it" on the form');
});

await step('builder: starters, field and action types, address formats', async () => {
  const a = await L.admin();
  await a.goto(L.adminUrl('task=form.add'));
  await a.waitForSelector('#sf-builder-root:not([hidden])');
  const starters = await L.text(a, '#sf-builder-root');
  ok(/Hook test starter/.test(starters), 'the add-on\'s starter form is offered');
  ok(!/Broken starter/.test(starters), 'the broken one isn\'t');
  await a.getByRole('button', { name: 'Use this: Hook test starter' }).click();
  ok(/Hooked field/.test(await L.text(a, '#sf-builder-root')), 'and it starts the form');
  ok(/hook_upper|Loud|hook/i.test(await L.text(a, '#sf-builder-root')), 'the add-on\'s field type is in the palette');
  await a.locator('#toolbar-cancel button, button.button-cancel').first().click();
  await a.waitForLoadState();
});

await step('links on the Submissions screen', async () => {
  const a = await L.admin();
  await a.goto(L.adminUrl(`view=submissions&filter[form_id]=${fid}`));
  ok(await a.locator('#toolbar a:has-text("Hook results"), joomla-toolbar-button a:has-text("Hook results"), a:has-text("Hook results")').count() >= 1, 'an add-on adds a button for the form being looked at');
  ok(await a.locator('a:has-text("Evil link"), a[href*="evil.example"]').count() === 0, 'a link outside SquidForm\'s admin is left out');
});

await step('switched off, nothing of it is left', async () => {
  plugin(false);
  const g = await L.site();
  await g.goto(L.formUrl('t-hooks'));
  ok(await g.locator('[data-hooktest-field], [data-hooktest-editor]').count() === 0, 'with the plugin off, its field and editor are gone');
  ok(/not available/i.test(await L.text(g)), '…and a form that needs it says it isn\'t available, instead of breaking');
  L.makeForm('t-hooks-plain', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }], events: { load: [], submit: [] } }] });
  await g.goto(L.formUrl('t-hooks-plain'));
  ok(await g.locator('form[data-sf-alias="t-hooks-plain"]').count() === 1, 'forms that don\'t need it are untouched');
  L.deleteForms('t-hooks-plain');
});

plugin(false);
L.sql(`UPDATE jos_extensions SET params='${L.esc(was)}' WHERE element='com_squidform'`);
L.deleteForms('t-hooks');
await L.close();
L.finish();
