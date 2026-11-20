// Modules: the SquidForm form module (a form in a module position, sent from
// any page, alongside a {squidform} tag on the same page) and the SquidForm
// Viewer module (a viewer menu item's list in a position, with its own columns
// and paging, and nothing at all for people who may not see it).
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const def = (label) => ({
  schema: 1, settings: { success_message: `${label} sent.` },
  viewer: { columns: ['uid', 'f_name', 'created'], sort: 'created desc', per_page: 20 },
  pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: `${label} name`, rules: { required: true } }],
    events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
L.makeForm('t-modform', def('Module'));
L.makeForm('t-modtag', def('Tag'));
L.deleteModules('mod_squidform');
L.deleteModules('mod_squidform_viewer');
L.addModule('mod_squidform', 'Module form', { form: 't-modform' }, { position: 'sidebar-right' });

const cat = L.sql("SELECT id FROM jos_categories WHERE extension='com_content' AND path='uncategorised'");
L.sql("DELETE FROM jos_content WHERE alias='sf-mod-test'");
L.sql(`INSERT INTO jos_content (title, alias, introtext, \`fulltext\`, state, catid, created, created_by, modified, publish_up, access, language, attribs, metadesc, metakey, metadata, images, urls, note)
       VALUES ('SF module test', 'sf-mod-test', '<p>Article</p>{squidform t-modtag}', '', 1, ${cat}, NOW(), 0, NOW(), NOW(), 1, '*', '{}', '', '', '{}', '{}', '{}', '')`);
const art = L.sql("SELECT id FROM jos_content WHERE alias='sf-mod-test'");
const artUrl = `${L.BASE}/index.php?option=com_content&view=article&id=${art}`;

const g = await L.site();
await step('form module', async () => {
  await g.goto(L.BASE + '/index.php');
  ok(await g.locator('.sidebar-right form[data-sf-alias="t-modform"], form[data-sf-alias="t-modform"]').count() === 1, 'the form module shows its form on the home page');
  await L.fill(g, { f_name: 'From the module' });
  ok(await L.submit(g), 'it sends');
  ok((await L.text(g, '#sf-t-modform')).includes('Module sent.'), 'its message shows in the module');
  ok(L.latest('t-modform')?.data.f_name === 'From the module', 'the answer is saved');
});

await step('module and tag on one page', async () => {
  await g.goto(artUrl);
  ok(await g.locator('form[data-sf-alias="t-modform"]').count() === 1 && await g.locator('form[data-sf-alias="t-modtag"]').count() === 1, 'both forms are on the page');
  const ids = await g.$$eval('[id]', (n) => n.map((x) => x.id));
  const dup = ids.filter((x, i) => ids.indexOf(x) !== i);
  ok(dup.length === 0, `no repeated ids on the page${dup.length ? ` (${dup.slice(0, 3).join(', ')})` : ''}`);
  const tag = g.locator('form[data-sf-alias="t-modtag"]');
  await tag.locator('[name="sf[f_name]"]').fill('From the tag');
  await Promise.all([g.waitForNavigation(), tag.locator('button[name="sf_nav"][value="next"], button[type="submit"]').last().click()]);
  ok((await L.text(g, '#sf-t-modtag')).includes('Tag sent.'), 'sending one shows its own message');
  ok(await g.locator('form[data-sf-alias="t-modform"]').count() === 1, '…and leaves the other form as it was');
  ok(L.latest('t-modtag')?.data.f_name === 'From the tag' && L.latest('t-modform')?.data.f_name === 'From the module', 'each answer went to its own form');
  await g.goto(artUrl);
  await L.axe(g, 'a page with two forms', 'main, .container-component, body');
});

await step('form module for a form people can\'t see', async () => {
  L.sql(`UPDATE jos_squidform_forms SET access=3 WHERE alias='t-modform'`);
  await g.goto(L.BASE + '/index.php');
  ok(!(await g.locator('form[data-sf-alias="t-modform"]').count()), 'a guest doesn\'t see a Special form in the module');
  L.sql(`UPDATE jos_squidform_forms SET access=1 WHERE alias='t-modform'`);
});

await step('viewer module', async () => {
  const fid = L.formId('t-modtag');
  const item = L.menuItem('t-modview', 'Module viewer', `index.php?option=com_squidform&view=submissions&form_id=${fid}`, {});
  L.addModule('mod_squidform_viewer', 'Latest tags', { viewer_item: item, columns: '', per_page: 5 }, { position: 'sidebar-right' });
  for (let i = 0; i < 6; i++) await L.send(g, 't-modtag', { f_name: `Row ${i}` });
  const ad = await L.site('admin');
  await ad.goto(L.BASE + '/index.php');
  const box = ad.locator('.mod-squidform-viewer');
  ok(await box.count() === 1, 'a Super User sees the viewer module');
  ok((await L.text(ad, '.mod-squidform-viewer')).includes('Row 5'), 'it lists the newest answers');
  ok(await box.locator('tbody tr').count() === 5, `its own page size (${await box.locator('tbody tr').count()} rows)`);
  const link = await box.locator('tbody th[scope="row"] a').first().getAttribute('href');
  ok(new RegExp(`Itemid=${item}`).test(link) && /uid=/.test(link), 'its links open on the viewer menu item, by uid');
  await L.axe(ad, 'the viewer module', '.mod-squidform-viewer');
  await g.goto(L.BASE + '/index.php');
  ok(!(await g.locator('.mod-squidform-viewer').count()) && !(await L.text(g)).includes('Row 5'), 'a guest sees nothing (no sign-in prompt in a module)');
  L.deleteMenuItem('t-modview');
  await ad.goto(L.BASE + '/index.php');
  ok(!(await ad.locator('.mod-squidform-viewer').count()), 'with its menu item gone, the module shows nothing (and doesn\'t break the page)');
});

L.deleteModules('mod_squidform');
L.deleteModules('mod_squidform_viewer');
L.sql("DELETE FROM jos_content WHERE alias='sf-mod-test'");
L.deleteForms('t-modform', 't-modtag');
await L.close();
L.finish();
