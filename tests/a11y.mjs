// Accessibility of every site page SquidForm makes (axe: serious, critical and
// contrast), plus the keyboard: every page of a long form, its error summary,
// Check your answers, the thank-you message, the "finish later" email box, the
// viewer list and detail, My submissions, an approval page, and a form used
// with the keyboard alone from the first field to Send.
import * as L from './lib.mjs';
import fs from 'fs';
import path from 'path';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const show = JSON.parse(fs.readFileSync(path.join(L.EXAMPLES, 'showcase.json'), 'utf8'));
show.settings.captcha = 'none';
L.makeForm('t-a11y-site', show);
const core = L.makeForm('t-a11y-core', 'core.json');
const core2 = JSON.parse(fs.readFileSync(path.join(L.FIXTURES, 'core.json'), 'utf8'));
core2.settings = { ...core2.settings, review: true, save_later: true, save_later_guests: true };
L.saveForm('t-a11y-core', core2);

await step('every page of the "Every field type" form, and its errors', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-a11y-site'));
  for (let i = 1; i <= 5; i++) {
    await L.axe(g, `page ${i}`, '#sf-t-a11y-site');
    await L.next(g);
  }
  const p = await L.site();
  await p.goto(L.formUrl('t-a11y-core'));
  await L.rawNext(p);
  await L.axe(p, 'the error summary and marked fields', '#sf-t-a11y-core');
  ok(await p.locator('.sf-error-summary a[href^="#"]').count() >= 1, 'each error links to its field');
});

await step('Check your answers, finish later, thank you', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-a11y-core'));
  await L.fill(g, { f_name: 'Kokido', f_email: 'k@example.test', f_age: '7', f_kind: 'dog' });
  await L.next(g);
  await L.axe(g, 'page two (repeater)', '#sf-t-a11y-core');
  await L.next(g);
  if (/Check your answers|Review/i.test(await L.text(g, '#sf-t-a11y-core'))) await L.axe(g, 'Check your answers', '#sf-t-a11y-core');
  const h = await L.site();
  await h.goto(L.formUrl('t-a11y-core'));
  await L.fill(h, { f_name: 'Later' });
  await L.next(h, 'save');
  await L.axe(h, 'the "finish later" email box', '#sf-t-a11y-core');
  ok(await h.locator('label[for]:has-text("mail")').count() >= 1 || await h.getByLabel(/email/i).count() >= 1, 'the email box has a label');
  await g.goto(L.formUrl('t-a11y-core'));
  await L.next(g, 'send').catch(() => {});
  await L.submit(g);
  await L.axe(g, 'the thank-you message', '#sf-t-a11y-core');
  ok(await g.locator('#sf-t-a11y-core [role="status"], #sf-t-a11y-core [tabindex="-1"]').count() >= 1, 'the message is announced or takes focus');
});

await step('keyboard only', async () => {
  L.makeForm('t-a11y-kb', { schema: 1, settings: { success_message: 'Sent by keyboard.' }, pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
    { key: 'f_pick', type: 'radios', label: 'Pick', options: { static: ['One', 'Two'] }, rules: { required: true } },
    { key: 'f_ok', type: 'checkbox', label: 'I agree' },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }] });
  const g = await L.site();
  await g.goto(L.formUrl('t-a11y-kb'));
  await g.locator('[name="sf[f_name]"]').focus();
  await g.keyboard.type('Keys');
  await g.keyboard.press('Tab');
  await g.keyboard.press('Space'); // the first radio
  await g.keyboard.press('ArrowDown'); // the second
  await g.keyboard.press('Tab');
  await g.keyboard.press('Space'); // the tick box
  let reached = false;
  for (let i = 0; i < 6 && !reached; i++) {
    await g.keyboard.press('Tab');
    reached = await g.evaluate(() => document.activeElement?.getAttribute('name') === 'sf_nav');
  }
  ok(reached, 'Tab reaches the Send button');
  const focusRing = await g.evaluate(() => { const s = getComputedStyle(document.activeElement); return s.outlineStyle !== 'none' || s.boxShadow !== 'none'; });
  ok(focusRing, 'and it shows focus');
  await Promise.all([g.waitForNavigation(), g.keyboard.press('Enter')]);
  ok(/Sent by keyboard/.test(await L.text(g, '#sf-t-a11y-kb')), 'Enter sends it');
  const d = L.latest('t-a11y-kb').data;
  ok(d.f_name === 'Keys' && d.f_pick === 'Two' && (d.f_ok === true || d.f_ok === 1 || d.f_ok === '1'), `everything was answered by keyboard (${JSON.stringify(d)})`);
  L.deleteForms('t-a11y-kb');
});

await step('viewer, My submissions, approvals', async () => {
  await L.send(await L.site('admin'), 't-a11y-core', { f_name: 'Viewer row', f_email: 'v@example.test', f_age: '3', f_kind: 'cat' }).catch(() => {});
  const list = L.menuItem('t-a11y-list', 'A11y viewer', `index.php?option=com_squidform&view=submissions&form_id=${core}`, {});
  const mine = L.menuItem('t-a11y-mine', 'A11y mine', 'index.php?option=com_squidform&view=mine', {});
  const a = await L.site('admin');
  await a.goto(`${L.BASE}/index.php?Itemid=${list}`);
  await L.axe(a, 'the viewer list', 'main, body');
  const link = a.locator('tbody th[scope="row"] a').first();
  if (await link.count()) { await link.click(); await a.waitForLoadState(); await L.axe(a, 'the viewer detail', 'main, body'); }
  await a.goto(`${L.BASE}/index.php?Itemid=${mine}`);
  await L.axe(a, 'My submissions', 'main, body');
  await a.goto(`${L.BASE}/index.php?option=com_squidform&view=approvals`);
  await L.axe(a, 'Awaiting my approval (empty)', 'main, body');
  L.deleteMenuItem('t-a11y-list');
  L.deleteMenuItem('t-a11y-mine');
});

L.deleteForms('t-a11y-site', 't-a11y-core');
await L.close();
L.finish();
