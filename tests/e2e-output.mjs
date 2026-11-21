// Output (0.11.0+): emails by answer (routes, first or all), recipients from
// answers refused unless Options allow them, the confirmation email to the
// address typed (always just that one, the site as sender), a CSV and a PDF
// attached (the sender's copy leaves out hidden fields and hidden-typing
// answers), the sender's "Download a copy" PDF (only in their session), and
// the staff PDF download.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults({ allow_input_recipients: 0 });
const fields = [
  { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
  { key: 'f_email', type: 'email', label: 'Email', rules: { required: true } },
  { key: 'f_dept', type: 'select', label: 'Department', options: { static: [{ value: 'hr', label: 'People' }, { value: 'it', label: 'Computers' }] } },
  { key: 'f_pin', type: 'text', label: 'PIN', hide_input: true },
  { key: 'f_secret', type: 'hidden', label: 'Internal code', default: 'INTERNAL-42' },
];
const make = (submit, settings = {}) => L.makeForm('t-out', { schema: 1, settings: { success_message: 'Thanks.', ...settings }, pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }, ...submit] } }] });
const after = { action: 'a_save', is: 'success' };
const answers = { f_name: 'Inky', f_email: 'inky@example.test', f_dept: 'it', f_pin: '4321' };
const go = async (vals = {}) => { L.clearMail(); const g = await L.site(); ok(await L.send(g, 't-out', { ...answers, ...vals }), 'sent'); return g; };
const body = (m) => m.html || m.text;

await step('emails by answer', async () => {
  make([{ key: 'a_mail', type: 'email', when: after, config: {
    to: 'office@example.test', subject: 'New: {data:f_name}', body: '{data:_all}',
    routes: [{ when: { field: 'f_dept', op: 'eq', value: 'hr' }, to: 'people@example.test' }, { when: { field: 'f_dept', op: 'eq', value: 'it' }, to: 'computers@example.test', cc: 'boss@example.test' }],
  } }]);
  await go();
  let m = await L.waitMail(1);
  ok(m.length === 1 && m[0].to.includes('computers@example.test') && m[0].cc.includes('boss@example.test') && !m[0].to.includes('office@'), `the matching route's address (and cc) is used (${m[0]?.to})`);
  ok(body(m[0]).includes('Computers'), '{data:_all} shows choice labels');
  await go({ f_dept: 'hr' });
  m = await L.waitMail(1);
  ok(m[0].to.includes('people@example.test'), 'another answer, another address');
});

await step('recipients from answers', async () => {
  make([{ key: 'a_mail', type: 'email', when: after, config: { to: '{data:f_email}', subject: 'Hi', body: 'Hello' } }]);
  await go();
  await L.wait(1500);
  ok((await L.mails()).length === 0, 'an email "to" an answer isn\'t sent while Options don\'t allow recipients from form input');
  make([{ key: 'a_mail', type: 'email', when: after, config: { to: 'office@example.test', reply_to: '{data:f_email}', subject: 'Hi {data:f_name}\nBcc: evil@example.test', body: 'Hello' } }]);
  await go();
  const m = (await L.waitMail(1))[0];
  ok(m.replyTo.includes('inky@example.test'), 'reply-to may come from an answer');
  ok(!m.raw.includes('evil@example.test') || !/^Bcc:/im.test(m.raw.split(/\r?\n\r?\n/)[0]), 'a line break in a subject can\'t add a header');
});

await step('confirmation email', async () => {
  make([{ key: 'a_conf', type: 'confirm_email', when: after, config: { to_field: 'f_email', attach_csv: true, attach_pdf: true } },
    { key: 'a_staff', type: 'email', when: after, config: { to: 'office@example.test', subject: 'Staff', body: '{data:_all}', attach_csv: true } }]);
  await go({ f_email: 'inky@example.test' });
  const all = await L.waitMail(2);
  const conf = all.find((x) => x.to.includes('inky@example.test'));
  const staff = all.find((x) => x.to.includes('office@example.test'));
  ok(!!conf && conf.to.split(',').length === 1, 'the confirmation goes to the typed address only');
  ok(conf && !conf.from.includes('inky@'), `from the site, never from an answer (${conf?.from})`);
  const csv = conf?.attachments.find((a) => /\.csv$/i.test(a.name));
  const pdf = conf?.attachments.find((a) => /\.pdf$/i.test(a.name));
  ok(!!csv && !!pdf, 'with a CSV and a PDF attached');
  const csvText = csv ? csv.data.toString('utf8') : '';
  ok(csvText.includes('Inky') && !csvText.includes('INTERNAL-42') && !csvText.includes('4321'), 'the sender\'s CSV leaves out hidden fields and hidden-typing answers');
  ok(!/127\.0\.0\.1/.test(csvText), 'and has no IP address');
  ok(pdf && pdf.data.subarray(0, 5).toString() === '%PDF-', 'the PDF is a PDF');
  ok(pdf && !pdf.data.toString('latin1').includes('INTERNAL-42'), 'the sender\'s PDF leaves out hidden fields');
  const staffCsv = staff?.attachments.find((a) => /\.csv$/i.test(a.name))?.data.toString('utf8') || '';
  ok(staffCsv.includes('INTERNAL-42'), 'the staff CSV has everything');
  ok(/^﻿?"?[^\n]*Name/.test(staffCsv) || staffCsv.split('\n')[0].includes('Name'), 'a row of headings first');
});

await step('confirmation emails can be switched off', async () => {
  L.params({ allow_confirmation: 0 });
  await go();
  await L.wait(1500);
  ok(!(await L.mails()).some((x) => x.to.includes('inky@example.test')), 'with Options → Email → confirmation emails off, none is sent');
  L.params({ allow_confirmation: 1 });
});

await step('the sender\'s PDF copy', async () => {
  make([], { copy_pdf: true });
  const g = await go();
  const link = g.locator('.sf-copy a');
  ok(await link.count() === 1, 'after sending, a "download a copy" link');
  const href = await link.getAttribute('href');
  const r = await g.request.get(new URL(href, L.BASE).href);
  const b = await r.body();
  ok(r.status() === 200 && b.subarray(0, 5).toString() === '%PDF-' && /attachment/.test(r.headers()['content-disposition'] || ''), 'it downloads a PDF');
  ok(!b.toString('latin1').includes('INTERNAL-42'), 'without hidden fields');
  const other = await L.site();
  const r2 = await other.request.get(new URL(href, L.BASE).href);
  ok(r2.status() !== 200 || (await r2.body()).subarray(0, 5).toString() !== '%PDF-', 'the same link in someone else\'s browser gets nothing');
});

await step('staff PDF', async () => {
  const a = await L.admin();
  const id = L.latest('t-out').id;
  await a.goto(L.adminUrl(`view=submission&id=${id}`));
  const r = await a.request.get(L.adminUrl(`task=submission.pdf&id=${id}`));
  const b = await r.body();
  ok(r.status() === 200 && b.subarray(0, 5).toString() === '%PDF-', 'staff download a PDF of a submission');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE submission_id=${id} AND action='pdf'`)) >= 1, 'and it\'s logged');
  const g = await L.site();
  const r2 = await g.request.get(`${L.BASE}/index.php?option=com_squidform&task=submission.pdf&uid=${L.latest('t-out').uid}`);
  ok(r2.status() === 404 || (await r2.body()).subarray(0, 5).toString() !== '%PDF-', 'a guest can\'t');
});

L.deleteForms('t-out');
await L.close();
L.finish();
