// 1.0.2's hardening, kept from coming back:
// - Next number never repeats: two people sending at the same moment, and
//   after a submission is deleted; test mode has its own counter.
// - Blocked list can't be dodged with look-alike or invisible characters.
// - Tidy text leaves phone numbers, states, ZIPs and countries alone.
// - Working days takes strict dates only.
// - Write a file writes only to the folder set in Options.
// - Look up a person answers signed-in visitors only (unless a form allows
//   guests) and can't choose who gets an email.
import * as L from './lib.mjs';
import fs from 'fs';
import os from 'os';
import path from 'path';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const was = L.sql("SELECT params FROM jos_extensions WHERE element='com_squidform'");
L.params({ starters: ['NextNumber', 'BlockedList', 'TidyText', 'WorkingDays', 'WriteFile', 'LookUpPerson'] });
const save = { key: 'a_save', type: 'save_data', config: { target: 'submissions' } };
const php = (handler, settings, on = 'submit') => ({ key: `a_${handler.toLowerCase()}`, type: 'run_php', config: { handler, settings } });
const form = (fields, submit, settings = {}) => ({ schema: 1, settings: { success_message: 'ok', ...settings }, pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit } }] });
const y = new Date().getFullYear();

await step('Next number never repeats', async () => {
  L.makeForm('t-num', form([{ key: 'f_ref', type: 'hidden', label: 'Ref' }, { key: 'f_name', type: 'text', label: 'Name' }], [php('NextNumber', { prefix: 'NUM', field: 'f_ref' }), save]));
  // Five people press Send at the same moment.
  const pages = await Promise.all([...Array(5)].map(() => L.site()));
  await Promise.all(pages.map((p) => p.goto(L.formUrl('t-num'))));
  await Promise.all(pages.map((p, i) => L.fill(p, { f_name: `P${i}` })));
  await Promise.all(pages.map((p) => L.submit(p)));
  const refs = L.sql(`SELECT GROUP_CONCAT(f_ref ORDER BY f_ref) FROM jos_sfd_t_num`).split(',');
  ok(refs.length === 5 && new Set(refs).size === 5, `five at once get five different numbers (${refs.join(' ')})`);
  ok(refs.at(-1) === `NUM-${y}-0005`, 'numbered 1 to 5 with no gaps');
  L.sql(`DELETE s, d FROM jos_squidform_submissions s JOIN jos_sfd_t_num d ON d.submission_id=s.id WHERE d.f_ref='NUM-${y}-0005'`);
  await L.send(await L.site(), 't-num', { f_name: 'After' });
  ok(L.latest('t-num').data.f_ref === `NUM-${y}-0006`, 'deleting the last one doesn\'t hand its number out again');
  L.saveForm('t-num', form([{ key: 'f_ref', type: 'hidden', label: 'Ref' }, { key: 'f_name', type: 'text', label: 'Name' }], [php('NextNumber', { prefix: 'NUM', field: 'f_ref' }), save], { test_mode: true }));
  await L.send(await L.site(), 't-num', { f_name: 'Test' });
  ok(L.latest('t-num').data.f_ref === `TEST-NUM-${y}-0001`, `test mode has its own counter (${L.latest('t-num').data.f_ref})`);
  L.deleteForms('t-num');
});

await step('Blocked list', async () => {
  L.makeForm('t-block', form([{ key: 'f_email', type: 'text', label: 'Email' }], [php('BlockedList', { field: 'f_email', list: '@spam.example, badword', message: 'Not that one.' }), save]));
  const tryIt = async (v) => { const g = await L.site(); await g.goto(L.formUrl('t-block')); await L.fill(g, { f_email: v }); await L.next(g); return L.text(g, '#sf-t-block'); };
  ok(/Not that one/.test(await tryIt('x@spam.example')), 'a listed domain is refused');
  ok(/Not that one/.test(await tryIt('x@ｓｐａｍ.example')), 'full-width look-alike letters are caught');
  ok(/Not that one/.test(await tryIt('bad​word')), 'an invisible character in the middle is caught');
  ok(/Not that one/.test(await tryIt('BADWORD')), 'capitals are caught');
  ok(!/Not that one/.test(await tryIt('fine@example.test')), 'anything else is fine');
  L.deleteForms('t-block');
});

await step('Tidy text leaves numbers and codes alone', async () => {
  L.makeForm('t-tidy', form([
    { key: 'f_name', type: 'text', label: 'Name' },
    { key: 'f_phone', type: 'phone', label: 'Phone', default_country: 'US' },
    { key: 'f_addr', type: 'address', label: 'Address', format: 'us' },
  ], [php('TidyText', { trim: 'all', names: 'f_name' }), save]));
  const g = await L.site();
  await g.goto(L.formUrl('t-tidy'));
  await L.fill(g, { f_name: '  mARY o\'neil  ' });
  await g.fill('[name="sf[f_phone][number]"]', '(304) 555-0123');
  for (const [k, v] of Object.entries({ line1: '1 main st', city: 'martinsburg', zip: '25401' })) await g.fill(`[name="sf[f_addr][${k}]"]`, v);
  await g.selectOption('[name="sf[f_addr][state]"]', 'WV');
  ok(await L.submit(g), 'sent');
  const d = L.latest('t-tidy').data;
  ok(d.f_name === 'Mary O\'Neil', `names in Proper Case (${d.f_name})`);
  ok(d.f_phone?.country === 'US' && /3045550123/.test(d.f_phone?.number || ''), 'the phone number is left alone');
  ok(d.f_addr?.state === 'WV' && d.f_addr?.zip === '25401', 'state and ZIP are left alone');
  L.deleteForms('t-tidy');
});

await step('Working days takes strict dates', async () => {
  L.makeForm('t-wd', form([{ key: 'f_start', type: 'date', label: 'Start' }, { key: 'f_due', type: 'date', label: 'Due' }],
    [php('WorkingDays', { days: '3', from: 'f_start', holidays: '2027-03-10', field: 'f_due' }), save]));
  await L.send(await L.site(), 't-wd', { f_start: '2027-03-05' }); // a Friday
  ok(L.latest('t-wd').data.f_due === '2027-03-11', `3 working days from Fri 5 March, skipping the weekend and the 10th: Thu 11 March (${L.latest('t-wd').data.f_due})`);
  L.saveForm('t-wd', form([{ key: 'f_start', type: 'date', label: 'Start' }, { key: 'f_due', type: 'date', label: 'Due' }],
    [php('WorkingDays', { days: '3', from: 'f_start', holidays: 'next tuesday, 2027-02-30', field: 'f_due' }), save]));
  const g = await L.site();
  await g.goto(L.formUrl('t-wd'));
  await L.fill(g, { f_start: '2027-03-05' });
  await L.next(g);
  ok(L.latest('t-wd').data.f_due === '2027-03-11' || /holiday|date/i.test(await L.text(g, '#sf-t-wd')), 'holidays written as words or impossible dates are refused, not guessed at');
  L.deleteForms('t-wd');
});

await step('Write a file stays in its folder', async () => {
  const outside = fs.mkdtempSync(path.join(os.tmpdir(), 'sf-write-'));
  L.makeForm('t-write', form([{ key: 'f_name', type: 'text', label: 'Name' }], [save, php('WriteFile', { format: 'csv' })]));
  L.params({ writefile_folder: '' });
  await L.send(await L.site(), 't-write', { f_name: 'Nowhere' });
  ok(fs.readdirSync(outside).length === 0, 'with no folder set in Options, nothing is written');
  L.params({ writefile_folder: path.join(L.SITE, 'images') });
  await L.send(await L.site(), 't-write', { f_name: 'Inside site' });
  ok(!fs.readdirSync(path.join(L.SITE, 'images')).some((f) => f.startsWith('t-write')), 'a folder inside the website is refused');
  L.params({ writefile_folder: outside });
  await L.send(await L.site(), 't-write', { f_name: '=HYPERLINK("http://x")' });
  const files = fs.readdirSync(outside);
  const content = files.length ? fs.readFileSync(path.join(outside, files[0]), 'utf8') : '';
  ok(files.length === 1 && files[0].startsWith('t-write-') && files[0].endsWith('.csv'), `the folder set in Options gets one file, named from the alias and month (${files.join(', ')})`);
  ok(content.includes("'=HYPERLINK") , 'a formula is written as text');
  ok((fs.statSync(path.join(outside, files[0])).mode & 0o007) === 0, 'the file isn\'t readable by everyone');
  L.params({ writefile_folder: '' });
  fs.rmSync(outside, { recursive: true, force: true });
  L.deleteForms('t-write');
});

await step('Look up a person', async () => {
  L.ensureUser('sf_findme', ['Registered'], { name: 'Findable Person', email: 'findme@example.test' });
  L.ensureUser('sf_looker', ['Registered']);
  const def = (guests) => form([{ key: 'f_who', type: 'text', label: 'Username' }, { key: 'f_found', type: 'text', label: 'Found' }],
    [php('LookUpPerson', { from: 'f_who', by: 'username', name_into: 'f_found', guests }), save,
      { key: 'a_mail', type: 'email', when: { action: 'a_save', is: 'success' }, config: { to: '{var:person_email}', subject: 'Hi', body: 'x' } }]);
  L.makeForm('t-look', def('no'));
  await L.send(await L.site(), 't-look', { f_who: 'sf_findme' });
  ok(!L.latest('t-look').data.f_found, 'a guest finds nobody (signed-in only by default)');
  L.clearMail();
  await L.send(await L.site('sf_looker'), 't-look', { f_who: 'sf_findme' });
  ok(L.latest('t-look').data.f_found === 'Findable Person', 'someone signed in finds them');
  await L.wait(1500);
  ok(!(await L.mails()).some((m) => m.to.includes('findme@example.test')), 'who it finds can\'t be emailed by the form ({var:} isn\'t trusted for recipients)');
  L.makeForm('t-look', def('yes'));
  await L.send(await L.site(), 't-look', { f_who: 'sf_findme' });
  ok(L.latest('t-look').data.f_found === 'Findable Person', 'with "Guests too", a guest finds them');
  L.deleteForms('t-look');
});

L.sql(`UPDATE jos_extensions SET params='${L.esc(was)}' WHERE element='com_squidform'`);
await L.close();
L.finish();
