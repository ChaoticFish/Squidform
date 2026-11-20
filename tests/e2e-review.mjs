// Check your answers (0.10.0): a review page after the last page, Change
// buttons that go back to a page, private answers left out, and Send.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.makeForm('t-rev', {
  schema: 1,
  settings: { review: true, review_title: 'Look it over', submit_label: 'Send now', success_message: 'Sent.' },
  pages: [
    { key: 'p1', title: 'You', fields: [
      { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
      { key: 'f_pin', type: 'text', label: 'PIN', hide_input: true },
    ], events: { load: [], submit: [] } },
    { key: 'p2', title: 'Pet', fields: [
      { key: 'f_pet', type: 'select', label: 'Pet', options: { static: [{ value: 'cat', label: 'A cat' }, { value: 'dog', label: 'A dog' }] } },
      { key: 'f_ok', type: 'checkbox', label: 'All good' },
    ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } },
  ],
});

const g = await L.site();
await step('to the review page', async () => {
  await g.goto(L.formUrl('t-rev'));
  await L.fill(g, { f_name: 'Hawke', f_pin: '1234' });
  await L.next(g);
  await L.fill(g, { f_pet: 'cat', f_ok: true });
  await L.next(g);
  ok(await g.locator('.sf-review-title').count() === 1 && (await L.text(g, '.sf-review-title')) === 'Look it over', 'after the last page comes the review page, with its own title');
  ok(await g.evaluate(() => document.activeElement?.classList.contains('sf-review-title')), 'focus moves to its heading');
  const t = await L.text(g, '.sf-review');
  ok(t.includes('Hawke') && t.includes('A cat'), 'answers are listed, choices by their labels');
  ok(!t.includes('1234'), 'a "hide what\'s typed" answer isn\'t shown');
  ok(await g.locator('.sf-review-page h3').count() === 2, 'one section per page');
  ok(await g.locator('.sf-review-change .visually-hidden').count() === 2, 'each Change button says which page (for screen readers)');
  ok(L.latest('t-rev') === null, 'nothing is saved yet');
});

await step('change', async () => {
  await Promise.all([g.waitForNavigation(), g.locator('.sf-review-change').first().click()]);
  ok(await g.inputValue('[name="sf[f_name]"]') === 'Hawke', 'Change goes back to that page with the answers');
  await L.fill(g, { f_name: 'Hawke the Hawk' });
  await L.next(g);
  ok(await g.locator('.sf-review-title').count() === 1, 'after the changed page, it\'s straight back to the review');
  ok((await L.text(g, '.sf-review')).includes('Hawke the Hawk'), 'the review shows the change');
});

await step('send', async () => {
  ok(await g.locator('button[value="send"]').textContent() === 'Send now', 'Send uses the form\'s own label');
  await L.next(g, 'send');
  ok((await L.text(g, '#sf-t-rev')).includes('Sent.'), 'sent');
  const s = L.latest('t-rev');
  ok(s && s.data.f_name === 'Hawke the Hawk' && s.data.f_pet === 'cat', 'the changed answers are saved');
});

await step('accessible', async () => {
  await g.goto(L.formUrl('t-rev'));
  await L.fill(g, { f_name: 'A' }); await L.next(g); await L.next(g);
  await L.axe(g, 'the review page', '#sf-t-rev');
});

L.deleteForms('t-rev');
await L.close();
L.finish();
