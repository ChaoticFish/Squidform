// Save and finish later (0.11.0+): a signed-in person's draft (any page,
// nothing checked yet) comes back when they open the form again; Start over
// throws it away; sending deletes it. A guest is emailed a link (only its
// SHA-256 kept); saving again makes the old link stop working; the link works
// more than once (mail scanners open links). Saved for later in the admin,
// and My submissions on the site.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const def = (guests) => ({
  schema: 1, settings: { success_message: 'Thanks.', save_later: true, save_later_guests: guests },
  pages: [
    { key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name', rules: { required: true } }], events: { load: [], submit: [] } },
    { key: 'p2', title: 'Two', fields: [{ key: 'f_more', type: 'text', label: 'More', rules: { required: true } }], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } },
  ],
});
const fid = L.makeForm('t-later', def(true));
const drafts = (where = '') => Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_resume WHERE form_id=${fid} ${where}`));
L.ensureUser('sf_later', ['Registered'], { email: 'later@example.test' });
const uid = Number(L.sql("SELECT id FROM jos_users WHERE username='sf_later'"));

await step('signed in: save, come back, finish', async () => {
  L.sql(`DELETE FROM jos_squidform_resume WHERE form_id=${fid}`);
  const p = await L.site('sf_later');
  await p.goto(L.formUrl('t-later'));
  await L.fill(p, { f_name: 'Halfway' });
  await L.next(p);
  ok(await p.locator('button[name="sf_nav"][value="save"]').count() === 1, 'a "Save and finish later" button');
  await L.next(p, 'save');
  ok(drafts(`AND user_id=${uid}`) === 1, 'the draft is kept under their account');
  ok(Number(L.sql(`SELECT page FROM jos_squidform_resume WHERE form_id=${fid} AND user_id=${uid}`)) === 1, 'on the page they were on, without checking it');
  const q = await L.site('sf_later');
  await q.goto(L.formUrl('t-later'));
  ok(/Two/.test(await L.text(q, '#sf-t-later')), 'opening the form again (another browser) carries on from page two');
  await L.next(q, 'back');
  ok(await q.inputValue('[name="sf[f_name]"]') === 'Halfway', 'with their answers');
  await L.next(q);
  await L.fill(q, { f_more: 'Done' });
  ok(await L.submit(q), 'sent');
  ok(drafts(`AND user_id=${uid}`) === 0, 'sending deletes the draft');
});

await step('Start over', async () => {
  const p = await L.site('sf_later');
  await p.goto(L.formUrl('t-later'));
  await L.fill(p, { f_name: 'Throw away' });
  await L.next(p, 'save');
  await p.goto(L.formUrl('t-later'));
  await L.next(p, 'discard');
  ok(drafts(`AND user_id=${uid}`) === 0, 'Start over deletes the draft');
  ok(await p.inputValue('[name="sf[f_name]"]') === '', 'and the form is empty again');
});

let link;
await step('guests get a link', async () => {
  L.clearMail();
  const g = await L.site();
  await g.goto(L.formUrl('t-later'));
  await L.fill(g, { f_name: 'Guest draft' });
  await L.next(g, 'save');
  ok(await g.locator('[name="sf_resume_email"]').count() === 1, 'a guest is asked for an email address');
  await g.fill('[name="sf_resume_email"]', 'guest@example.test');
  await L.next(g, 'resumesend');
  const m = (await L.waitMail(1))[0];
  link = ((m.html || m.text).match(/https?:\/\/[^\s"<]+task=form\.resume[^\s"<]+/) || [])[0]?.replace(/&amp;/g, '&');
  ok(!!link, 'they\'re emailed a link');
  const token = new URL(link).searchParams.get('t') || new URL(link).searchParams.get('token') || '';
  ok(token.length >= 32 && !L.sql(`SELECT token_hash FROM jos_squidform_resume WHERE form_id=${fid}`).includes(token), 'only a hash of the link is stored');
  const h = await L.site();
  await h.request.get(link); // a mail scanner opening it first
  await h.goto(link);
  await h.goto(L.formUrl('t-later'));
  ok(await h.inputValue('[name="sf[f_name]"]').catch(() => '') === 'Guest draft' || /Guest draft/.test(await h.content()), 'the link brings the draft back (even after a scanner opened it)');
});

await step('saving again replaces the link', async () => {
  L.clearMail();
  const g = await L.site();
  await g.goto(link);
  await g.goto(L.formUrl('t-later'));
  await L.next(g, 'save');
  if (await g.locator('[name="sf_resume_email"]').count()) { await g.fill('[name="sf_resume_email"]', 'guest@example.test'); await L.next(g, 'resumesend'); }
  await L.waitMail(1);
  const x = await L.site();
  await x.goto(link);
  await x.goto(L.formUrl('t-later'));
  ok(await x.inputValue('[name="sf[f_name]"]') !== 'Guest draft', 'the old link stops working');
});

await step('guests only where the form allows it', async () => {
  L.saveForm('t-later', def(false));
  const g = await L.site();
  await g.goto(L.formUrl('t-later'));
  ok(await g.locator('button[name="sf_nav"][value="save"]').count() === 0, 'no "finish later" for guests when it\'s off for them');
});

await step('Saved for later (admin)', async () => {
  const p = await L.site('sf_later');
  await p.goto(L.formUrl('t-later'));
  await L.fill(p, { f_name: 'Listed' });
  await L.next(p, 'save');
  const a = await L.admin();
  await a.goto(L.adminUrl('view=resumes'));
  const t = await L.text(a, '#content, main');
  ok(t.includes('Test t-later') && /sf_later|later@example\.test/.test(t), 'drafts are listed, with whose they are');
  ok(!t.includes('Listed'), 'the list doesn\'t show what\'s in them');
  await L.axe(a, 'Saved for later', '#content, main');
});

await step('My submissions', async () => {
  const item = L.menuItem('t-later-mine', 'My things', 'index.php?option=com_squidform&view=mine', {});
  const p = await L.site('sf_later');
  await p.goto(`${L.BASE}/index.php?Itemid=${item}`);
  const t = await L.text(p);
  ok(/Test t-later/.test(t), 'My submissions lists the forms they sent');
  ok(/saved|draft|finish/i.test(t), 'and their saved drafts, to carry on');
  const g = await L.site();
  await g.goto(`${L.BASE}/index.php?Itemid=${item}`);
  ok(!/Test t-later/.test(await L.text(g)), 'a guest sees none');
  await L.axe(p, 'My submissions', 'main, body');
  L.deleteMenuItem('t-later-mine');
});

L.deleteForms('t-later');
await L.close();
L.finish();
