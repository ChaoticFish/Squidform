// The site viewer (0.3.0+): who sees which submissions, columns and filters,
// search by reference, sorting, the detail page by uid, editing your own,
// export from the site, archive, and never the IP address.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const fid = L.makeForm('t-view', {
  schema: 1, settings: { success_message: 'ok' },
  viewer: { columns: ['uid', 'f_name', 'f_pet', 'status', 'created'], filters: ['f_pet'], sort: 'created desc', per_page: 20 },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
    { key: 'f_pet', type: 'select', label: 'Pet', options: { static: [{ value: 'cat', label: 'A cat' }, { value: 'dog', label: 'A dog' }] } },
    { key: 'f_secret', type: 'text', label: 'Secret' },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
const item = L.menuItem('t-view-list', 'Viewer test', `index.php?option=com_squidform&view=submissions&form_id=${fid}`, {});
const clear = async () => { const c = a.locator('.sf-viewer-filters button[name][value="1"]'); if (await c.count()) await Promise.all([a.waitForNavigation(), c.click()]); };
const listUrl = `${L.BASE}/index.php?option=com_squidform&view=submissions&form_id=${fid}&Itemid=${item}`;

L.ensureUser('sf_view_a', ['Registered'], { name: 'Viewer A' });
L.ensureUser('sf_view_b', ['Registered'], { name: 'Viewer B' });
const a = await L.site('sf_view_a');
const b = await L.site('sf_view_b');
await L.send(a, 't-view', { f_name: 'Alpha one', f_pet: 'cat', f_secret: 's1' });
await L.send(a, 't-view', { f_name: 'Alpha two', f_pet: 'dog' });
await L.send(b, 't-view', { f_name: 'Bravo', f_pet: 'cat' });
const guest = await L.site();
await L.send(guest, 't-view', { f_name: 'Guesty', f_pet: 'dog' });

await step('who sees what', async () => {
  await guest.goto(listUrl);
  ok(!(await L.text(guest)).includes('Bravo') && !(await guest.locator('.sf-viewer-table').count()), 'a guest sees no list');
  await a.goto(listUrl);
  const t = await L.text(a, '.sf-viewer-table');
  ok(t.includes('Alpha one') && t.includes('Alpha two'), 'a person sees their own submissions (View Own)');
  ok(!t.includes('Bravo') && !t.includes('Guesty'), '…and nobody else\'s');
  const ad = await L.site('admin');
  await ad.goto(listUrl);
  const all = await L.text(ad, '.sf-viewer-table');
  ok(['Alpha one', 'Bravo', 'Guesty'].every((x) => all.includes(x)), 'a Super User sees everyone\'s');
  ok(!all.includes('127.0.0.1'), 'the IP address never shows on the site');
});

await step('columns and headings', async () => {
  const heads = await a.$$eval('.sf-viewer-table thead th', (n) => n.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
  ok(heads.some((h) => /Name/.test(h)) && heads.some((h) => /Pet/.test(h)) && !heads.some((h) => /Secret/.test(h)), `the viewer block's columns (${heads.join(' | ')})`);
  ok((await L.text(a, '.sf-viewer-table')).includes('A cat'), 'choices show by their labels');
  ok(await a.locator('.sf-viewer-table th[aria-sort]').count() >= 1, 'the sorted column says so (aria-sort)');
  ok(await a.locator('.sf-viewer-table tbody th[scope="row"] a .visually-hidden').count() >= 2, 'row links say which submission they open');
});

await step('filters and search', async () => {
  await a.selectOption('.sf-viewer-filters select[name$="[f][f_pet]"]', 'dog').catch(async () => { await a.selectOption('.sf-viewer-filters select >> nth=0', 'dog'); });
  await Promise.all([a.waitForNavigation(), a.click('.sf-viewer-filters button[type="submit"]:not([name])')]);
  const t = await L.text(a, '.sf-viewer-table');
  ok(t.includes('Alpha two') && !t.includes('Alpha one'), 'a field filter narrows the list');
  // Filters stay for the session (as designed); Clear empties them.
  await clear();
  ok((await L.text(a, '.sf-viewer-table')).includes('Alpha one'), 'Clear empties the filters');
  const ref = L.latest('t-view', { where: [['f_name', '=', 'Alpha one']] }).uid.slice(0, 8);
  await a.fill('.sf-viewer-filters input[type="search"]', ref);
  await Promise.all([a.waitForNavigation(), a.click('.sf-viewer-filters button[type="submit"]:not([name])')]);
  const t2 = await L.text(a, '.sf-viewer-table');
  ok(t2.includes('Alpha one') && !t2.includes('Alpha two'), `searching the short reference finds it (${ref})`);
});

await step('detail by uid', async () => {
  await a.goto(listUrl);
  await clear();
  await Promise.all([a.waitForNavigation(), a.locator('.sf-viewer-table tbody th[scope="row"] a').first().click()]);
  ok(/uid=[0-9a-f-]{36}/.test(a.url()) && !/[?&]id=/.test(a.url()), `the detail link uses the uid, not the number (${a.url().split('?')[1]})`);
  ok((await L.text(a)).includes('Alpha'), 'the detail page shows the answers');
  const bravo = L.latest('t-view', { where: [['f_name', '=', 'Bravo']] });
  await a.goto(`${L.BASE}/index.php?option=com_squidform&view=submission&uid=${bravo.uid}&Itemid=${item}`);
  ok(!(await L.text(a)).includes('Bravo'), 'someone else\'s submission isn\'t shown');
  const notFound = await L.text(a);
  await a.goto(`${L.BASE}/index.php?option=com_squidform&view=submission&uid=00000000-0000-4000-8000-000000000000&Itemid=${item}`);
  ok((await L.text(a)).slice(0, 200) === notFound.slice(0, 200) || /not found/i.test(await L.text(a)), 'not yours and not there look the same');
  await a.goto(`${L.BASE}/index.php?option=com_squidform&view=submission&id=${bravo.id}&Itemid=${item}`);
  ok(!(await L.text(a)).includes('Bravo'), 'a number in the address doesn\'t open anything');
});

await step('edit your own', async () => {
  const mine = L.latest('t-view', { where: [['f_name', '=', 'Alpha one']] });
  await a.goto(`${L.BASE}/index.php?option=com_squidform&view=submission&uid=${mine.uid}&Itemid=${item}`);
  const edit = a.locator('a:has-text("Edit"), button:has-text("Edit")').first();
  ok(await edit.count() === 1, 'an Edit button on your own submission (Edit Own)');
  await Promise.all([a.waitForNavigation(), edit.click()]);
  await a.fill('[name="sf[f_name]"]', 'Alpha one, edited');
  await Promise.all([a.waitForNavigation(), a.locator('button[type="submit"]:has-text("Save"), button[name="sf_nav"][value="next"]').first().click()]);
  const after = L.latest('t-view', { where: [['f_name', '=', 'Alpha one, edited']] });
  ok(!!after && after.id === mine.id, 'the change is saved on the same submission');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_sfd_t_view__history WHERE submission_id=${mine.id} AND action='edit' AND diff_json LIKE '%Alpha one, edited%'`)) >= 1, 'the edit is in the history, with what changed');
});

await step('site export', async () => {
  const ad = await L.site('admin');
  await ad.goto(listUrl);
  const btn = ad.locator('button[value="export.start"]');
  ok(await btn.count() === 1, 'people allowed to export get Export on the site');
  await a.goto(listUrl);
  ok(await a.locator('button[value="export.start"]').count() === 0, 'people who aren\'t, don\'t');
});

await step('accessible', async () => {
  await a.goto(listUrl);
  await clear();
  await L.axe(a, 'the viewer list', '.sf-viewer-table');
});

L.deleteMenuItem('t-view-list');
if (process.env.KEEP) process.exit(0);
L.deleteForms('t-view');
await L.close();
L.finish();
