// Core: a form renders, checks answers on the server, pages forward and back,
// shows/hides by answer (and drops hidden answers), repeats rows, works out a
// calculation, saves, emails after the save, and shows up in the admin.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.makeForm('t-core', 'core.json');
L.clearMail();

const g = await L.site();
await step('render', async () => {
  const r = await g.goto(L.formUrl('t-core', '&from=newsletter'));
  ok(r.status() === 200, 'the form page loads');
  ok(await g.locator('form[data-sf-alias="t-core"]').count() === 1, 'one SquidForm form on the page');
  ok((await g.locator('label[for="sf-t-core-f_name"]').textContent()).includes('Your name'), 'labels are tied to their inputs');
  ok(await g.locator('[name="sf[f_name]"]').getAttribute('aria-required') === 'true', 'required fields say so (aria-required)');
  ok(await g.inputValue('[name="sf[f_from]"]') === 'newsletter', 'a hidden field is filled from the web address ({url:from})');
  ok(await g.locator('[name="sf_hp_website"]').count() === 1 && await g.locator('[name="sf_t"]').count() === 1, 'the honeypot and the signed form token are there');
});

await step('server checks', async () => {
  await g.evaluate(() => document.querySelector('form[data-sf-alias="t-core"]').setAttribute('novalidate', ''));
  await L.fill(g, { f_email: 'not-an-email', f_age: '500' });
  await g.evaluate(() => { document.querySelector('[name="sf[f_name]"]').value = 'x'.repeat(50); }); // past the maxlength, as a script could
  await L.next(g);
  const errs = await L.text(g, '.sf-error-summary');
  ok(/Your name/.test(errs), `too long a name is refused (${errs.slice(0, 160)})`);
  ok(/email/i.test(errs), 'a bad email is refused');
  ok(/120|Age/i.test(errs), 'a number over the maximum is refused');
  ok(/Kind/.test(errs), 'a required choice left empty is refused');
  ok(await g.evaluate(() => document.activeElement?.classList.contains('sf-error-summary')), 'focus moves to the error summary');
  ok(await g.inputValue('[name="sf[f_email]"]') === 'not-an-email', 'answers are kept after an error');
  ok(await g.locator('[name="sf[f_email]"][aria-invalid="true"]').count() === 1, 'the field with the problem is marked aria-invalid');
});

await step('show and hide', async () => {
  await L.fill(g, { f_name: 'Kokido', f_email: 'kokido@example.test', f_age: '7', f_kind: 'dog' });
  // A shown-only-for-cats field that's required doesn't block a dog, and its answer is dropped.
  await g.evaluate(() => { const i = document.querySelector('[name="sf[f_meow]"]'); i.closest('[hidden]')?.removeAttribute('hidden'); i.disabled = false; i.value = 'LOUD'; });
  await L.next(g);
  ok(!(await g.locator('.sf-error-summary').count()), 'a hidden required field doesn\'t block the page');
  ok(await g.locator('button[name="sf_nav"][value="back"]').count() === 1, 'page two has Back');
});

await step('back keeps answers', async () => {
  await L.next(g, 'back');
  ok(await g.inputValue('[name="sf[f_name]"]') === 'Kokido', 'Back keeps what was typed');
  await L.next(g);
});

await step('repeater, calculation, send', async () => {
  const names = await g.$$eval('[name^="sf[f_rows]"]', (n) => n.map((x) => x.name));
  ok(names.some((n) => /x_item/.test(n)), `the repeater has rows (${names.slice(0, 2).join(', ')})`);
  const add = g.locator('button:has-text("Add a thing")');
  if (await add.count()) { await add.click(); await add.click(); }
  const items = g.locator('[name^="sf[f_rows]"][name$="[x_item]"]:visible');
  const qtys = g.locator('[name^="sf[f_rows]"][name$="[x_qty]"]:visible');
  ok(await items.count() >= 2, `Add a thing adds rows (${await items.count()})`);
  await items.nth(0).fill('Fish'); await qtys.nth(0).fill('2');
  await items.nth(1).fill('Yarn'); await qtys.nth(1).fill('3');
  await L.fill(g, { f_tags: ['a', 'c'], f_note: 'Hello <b>there</b>' });
  ok(await L.submit(g), 'the form sends');
  const msg = await L.text(g, '#sf-t-core');
  ok(msg.includes('Thanks, Kokido!'), `a Message action shows its text with placeholders filled (${msg.slice(0, 100)})`);
  ok(!(await g.locator('#sf-t-core script').count()) && !msg.includes('alert(1)'), 'a script in the message text is filtered out');
});

await step('saved', async () => {
  const s = L.latest('t-core');
  ok(s && s.status === 'submitted', 'saved as sent');
  ok(s.data.f_name === 'Kokido' && s.data.f_kind === 'dog', 'answers saved');
  ok(!s.data.f_meow, 'the hidden field\'s answer was dropped on the server');
  ok(s.data.f_from === 'newsletter', 'the hidden field from the web address was saved');
  ok(Array.isArray(s.data.f_tags) && s.data.f_tags.join() === 'a,c', 'tick boxes saved as a list');
  ok(String(s.data.c_total) === '5', `the calculation was worked out again on the server (${s.data.c_total})`);
  ok(Array.isArray(s.data.f_rows) && s.data.f_rows.length >= 2 && s.data.f_rows[1].x_item === 'Yarn', 'repeater rows saved in order');
  ok(s.data.f_note === 'Hello <b>there</b>', 'text saved as typed (escaped when shown, not when stored)');
});

await step('email after the save', async () => {
  const m = (await L.waitMail(1)).find((x) => x.to.includes('staff@example.test'));
  ok(!!m, 'the staff email went out');
  ok(m && m.subject === 'Core test from Kokido', `its subject has the placeholder filled (${m?.subject})`);
  ok(m && m.html.includes('Yarn') && m.html.includes('Your name'), '{data:_all} lists the answers with their labels');
  ok(m && m.html.includes('Hello &lt;b&gt;there&lt;/b&gt;'), 'answers are escaped in the email');
});

await step('admin', async () => {
  const a = await L.admin();
  await a.goto(L.adminUrl(`view=submissions&filter[form_id]=${L.formId('t-core')}`));
  ok((await L.text(a, '#content, main')).includes('Kokido'), 'the submission is listed in the admin');
  const sid = L.latest('t-core').id;
  await a.goto(L.adminUrl(`view=submission&id=${sid}`));
  const t = await L.text(a, '#content, main');
  ok(t.includes('Kokido') && t.includes('Yarn') && t.includes('Alpha'), 'the detail view shows the answers, rows and choice labels');
  ok(!(await a.locator('#content b:text("there")').count()), 'HTML typed into an answer is shown as text');
});

await step('placement', async () => {
  // {squidform alias} in an article.
  const cat = L.sql("SELECT id FROM jos_categories WHERE extension='com_content' AND path='uncategorised'");
  L.sql("DELETE FROM jos_content WHERE alias='sf-core-test'");
  L.sql(`INSERT INTO jos_content (title, alias, introtext, \`fulltext\`, state, catid, created, created_by, modified, publish_up, access, language, attribs, metadesc, metakey, metadata, images, urls, note)
         VALUES ('SF core', 'sf-core-test', '<p>Before</p>{squidform t-core}<p>After</p>', '', 1, ${cat}, NOW(), 0, NOW(), NOW(), 1, '*', '{}', '', '', '{}', '{}', '{}', '')`);
  const art = L.sql("SELECT id FROM jos_content WHERE alias='sf-core-test'");
  await g.goto(`${L.BASE}/index.php?option=com_content&view=article&id=${art}`);
  ok(await g.locator('form[data-sf-alias="t-core"]').count() === 1, '{squidform alias} in an article shows the form');
  ok(!(await L.text(g)).includes('{squidform'), 'the tag itself is gone');
  L.sql("DELETE FROM jos_content WHERE alias='sf-core-test'");
});

await step('unpublished', async () => {
  L.sql(`UPDATE jos_squidform_forms SET state=0 WHERE id=${L.formId('t-core')}`);
  const r = await g.goto(L.formUrl('t-core'));
  ok(r.status() === 404 || !(await g.locator('form[data-sf-alias="t-core"]').count()), 'an unpublished form isn\'t shown');
});

L.deleteForms('t-core');
await L.close();
L.finish();
