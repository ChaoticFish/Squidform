// Approvals (0.5.0+): a chain of steps (a named person, then a group only when
// a condition holds), who may act (not the submitter, not anyone else, not on a
// stale page), approve / return / reject with comments, the emails, withdraw
// and resubmit, the decisions log, and the on_approve hook.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.ensureGroup('SF Security');
const sup = L.ensureUser('sf_ap_sup', ['Registered'], { name: 'Sue Pervisor', email: 'sue@example.test' });
L.ensureUser('sf_ap_sec', ['Registered', 'SF Security'], { name: 'Sec Urity', email: 'sec@example.test' });
L.ensureUser('sf_ap_sub', ['Registered'], { name: 'Sam Mitter', email: 'sam@example.test' });
L.ensureUser('sf_ap_other', ['Registered'], { name: 'Otto Other' });

const fid = L.makeForm('t-appr', {
  schema: 1, settings: { success_message: 'Request received.', admin_email: 'office@example.test' },
  approval: {
    mode: 'chain',
    steps: [
      { key: 's_sup', title: 'Supervisor', approvers: ['user:sf_ap_sup'] },
      { key: 's_sec', title: 'Security', approvers: ['group:SF Security'], when: { field: 'f_outside', op: 'eq', value: 'yes' } },
    ],
  },
  on_approve: [{ key: 'h_ok', type: 'email', config: { to: '{setting:admin_email}', subject: 'Approved: {data:f_what}', body: '<p>Approved by {approval:by}.</p>' } }],
  pages: [{ key: 'p1', title: 'Request', fields: [
    { key: 'f_what', type: 'text', label: 'What for', rules: { required: true } },
    { key: 'f_outside', type: 'radios', label: 'From outside?', options: { static: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }] }, rules: { required: true } },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
// Approvers need Approve on the form (form → Permissions); here every registered person has it.
L.formRules('t-appr', { 'squidform.approve': { [L.groupId('Registered')]: 1 } });
const item = L.menuItem('t-appr-list', 'Approval test viewer', `index.php?option=com_squidform&view=submissions&form_id=${fid}`, {});

const sub = await L.site('sf_ap_sub');
const supP = await L.site('sf_ap_sup');
const secP = await L.site('sf_ap_sec');
const other = await L.site('sf_ap_other');
// lib's page() accepts "Are you sure?" dialogs (Reject, Withdraw).
const approvalUrl = (uid) => `${L.BASE}/index.php?option=com_squidform&view=approval&uid=${uid}`;
const mineUrl = (uid) => `${L.BASE}/index.php?option=com_squidform&view=submission&uid=${uid}&Itemid=${item}`;
const decide = async (p, uid, task, comment = '') => {
  await p.goto(approvalUrl(uid));
  if (comment) await p.fill('textarea[name="sf_comment"]', comment);
  await Promise.all([p.waitForNavigation(), p.click(`form.sf-approve-form button[value="approval.${task}"]`)]);
};
const status = (id) => L.sql(`SELECT CONCAT(status, ' ', current_step) FROM jos_squidform_submissions WHERE id=${id}`);

let one;
await step('a request starts at the first step', async () => {
  L.clearMail();
  ok(await L.send(sub, 't-appr', { f_what: 'Inside job', f_outside: 'no' }), 'sent');
  one = L.latest('t-appr');
  ok(status(one.id) === 'pending s_sup', `pending at Supervisor (${status(one.id)})`);
  const m = (await L.waitMail(1)).find((x) => x.to.includes('sue@example.test'));
  ok(!!m, 'the supervisor is emailed');
  ok(m && (m.html || m.text).includes(`view=approval&uid=${one.uid}`), 'the email links to the request by its uid');
});

await step('who may act', async () => {
  await other.goto(approvalUrl(one.uid));
  ok(!(await other.locator('form.sf-approve-form').count()), 'someone who isn\'t an approver gets no buttons');
  await sub.goto(approvalUrl(one.uid));
  ok(!(await sub.locator('form.sf-approve-form').count()), 'the submitter can\'t approve their own request');
  await supP.goto(approvalUrl(one.uid));
  const post = await supP.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form.sf-approve-form'))));
  const action = await supP.getAttribute('form.sf-approve-form', 'action');
  // Otto posts the supervisor's form (with his own session token).
  await other.goto(`${L.BASE}/index.php?option=com_squidform&view=approvals`);
  const tok = await other.evaluate(() => [...document.querySelectorAll('input[type=hidden][value="1"]')].map((i) => i.name).find((n) => /^[0-9a-f]{32}$/.test(n)));
  const form = Object.fromEntries(Object.entries(post).filter(([k]) => !/^[0-9a-f]{32}$/.test(k)));
  if (tok) form[tok] = '1';
  await other.request.post(new URL(action, L.BASE).href, { form: { ...form, task: 'approval.approve' } });
  ok(status(one.id) === 'pending s_sup', 'posting an approval as someone else does nothing');
  ok(!(await supP.locator('.sf-approval-detail').innerHTML()).includes('127.0.0.1'), 'the approver never sees the IP address');
});

await step('approving moves on (and skips a step whose condition is false)', async () => {
  L.clearMail();
  await decide(supP, one.uid, 'approve', 'Fine by me');
  ok(status(one.id) === 'approved ', `approved; Security skipped for an inside request (${status(one.id)})`);
  const d = L.sql(`SELECT CONCAT(step_key,'|',decision,'|',IFNULL(comment,''),'|',user_id) FROM jos_squidform_approvals WHERE submission_id=${one.id} ORDER BY id`).replace(/\n/g, ' / ');
  ok(d.includes(`s_sup|approve|Fine by me|${sup}`), `the decision is logged with its comment (${d})`);
  const mail = await L.waitMail(2);
  ok(mail.some((x) => x.to.includes('sam@example.test') && /approved/i.test(x.subject + x.html + x.text)), 'the submitter is told it\'s approved');
  ok(mail.some((x) => x.to.includes('office@example.test') && x.subject === 'Approved: Inside job' && x.html.includes('Sue Pervisor')), 'the on_approve email goes out with who approved it');
});

await step('a stale page can\'t decide twice', async () => {
  await supP.goto(approvalUrl(one.uid));
  ok(!(await supP.locator('form.sf-approve-form').count()), 'no buttons once it\'s decided');
});

let two;
await step('a second step for outside requests; reject', async () => {
  await L.send(sub, 't-appr', { f_what: 'Outside job', f_outside: 'yes' });
  two = L.latest('t-appr');
  await supP.goto(approvalUrl(two.uid));
  const stale = await supP.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form.sf-approve-form'))));
  const action = await supP.getAttribute('form.sf-approve-form', 'action');
  await decide(supP, two.uid, 'approve');
  ok(status(two.id) === 'pending s_sec', `an outside request goes on to Security (${status(two.id)})`);
  await supP.request.post(new URL(action, L.BASE).href, { form: { ...stale, task: 'approval.approve' } });
  ok(status(two.id) === 'pending s_sec' && Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_approvals WHERE submission_id=${two.id} AND step_key='s_sec'`)) === 0, 'the same approval posted again (an old page) doesn\'t approve the next step');
  L.clearMail();
  await decide(secP, two.uid, 'reject', 'Not this time');
  ok(status(two.id).startsWith('rejected'), `a group member rejects it (${status(two.id)})`);
  const m = (await L.waitMail(1)).find((x) => x.to.includes('sam@example.test'));
  ok(m && (m.html || m.text).includes('Not this time'), 'the submitter is told, with the comment');
  ok(!(await L.mails()).some((x) => x.subject.startsWith('Approved:')), 'no on_approve email for a rejection');
});

await step('return for changes, then resubmit', async () => {
  await L.send(sub, 't-appr', { f_what: 'Needs work', f_outside: 'no' });
  const three = L.latest('t-appr');
  await decide(supP, three.uid, 'return', 'Say more');
  ok(status(three.id).startsWith('returned'), `returned to the submitter (${status(three.id)})`);
  await sub.goto(mineUrl(three.uid));
  const t = await L.text(sub);
  ok(t.includes('Say more'), 'the submitter sees the approver\'s comment');
  ok(await sub.locator('button[value="approval.resubmit"]').count() === 1, 'and can resubmit it');
  await Promise.all([sub.waitForNavigation(), sub.click('button[value="approval.resubmit"]')]);
  ok(status(three.id) === 'pending s_sup', `resubmitting sends it back to the step that returned it (${status(three.id)})`);
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_approvals WHERE submission_id=${three.id} AND decision='resubmit'`)) === 1, 'the resubmission is in the decisions log');
});

await step('withdraw', async () => {
  await L.send(sub, 't-appr', { f_what: 'Never mind', f_outside: 'no' });
  const four = L.latest('t-appr');
  await sub.goto(mineUrl(four.uid));
  await Promise.all([sub.waitForNavigation(), sub.click('button[value="approval.withdraw"]')]);
  ok(status(four.id).startsWith('withdrawn'), `the submitter can withdraw it while it's undecided (${status(four.id)})`);
  await supP.goto(approvalUrl(four.uid));
  ok(!(await supP.locator('form.sf-approve-form').count()), 'and the approver can\'t act on it any more');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_approval_queue WHERE submission_id=${four.id}`)) === 0, 'it leaves the approval queue');
});

await step('Awaiting my approval', async () => {
  await L.send(sub, 't-appr', { f_what: 'Queue me', f_outside: 'no' });
  await supP.goto(`${L.BASE}/index.php?option=com_squidform&view=approvals`);
  const t = await L.text(supP);
  ok(t.includes('Queue me') || t.includes(L.latest('t-appr').uid.slice(0, 8)), 'the supervisor\'s list shows the waiting request');
  ok(!t.includes('Never mind'), '…and not a withdrawn one');
  await other.goto(`${L.BASE}/index.php?option=com_squidform&view=approvals`);
  ok(!(await L.text(other)).includes('Queue me'), 'someone else\'s list doesn\'t');
  await L.axe(supP, 'Awaiting my approval', 'main, .container-component, body');
  await supP.goto(approvalUrl(L.latest('t-appr').uid));
  await L.axe(supP, 'an approval page', '.sf-approval-detail');
});

L.deleteMenuItem('t-appr-list');
L.deleteForms('t-appr');
await L.close();
L.finish();
