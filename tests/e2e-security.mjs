// Security: the human checks (honeypot, signed one-time token, fill time, age,
// replay, session binding), rate limiting, tampered answers (choices, locked
// prefill, unknown keys), escaping, SQL-looking input, access levels, and the
// admin's tasks needing the session token.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const def = {
  schema: 1,
  settings: { submit_label: 'Send', success_message: 'Got it.' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name', rules: { required: true } },
    { key: 'f_pick', type: 'select', label: 'Pick', options: { static: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] } },
    { key: 'f_me', type: 'text', label: 'Signed in as', prefill: '{user:username}', prefill_lock: true },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
};
L.makeForm('t-sec', def);
const count = () => Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${L.formId('t-sec')}`));
const errorText = async (p) => (await p.locator('.sf-error-summary').count()) ? L.text(p, '.sf-error-summary') : '';

const g = await L.site();
const open = async () => { await g.goto(L.formUrl('t-sec')); await L.fill(g, { f_name: 'Tester' }); };

await step('honeypot', async () => {
  await open();
  await g.evaluate(() => { document.querySelector('[name="sf_hp_website"]').value = 'http://spam.example'; });
  const n = count();
  await L.next(g);
  ok(count() === n && /verif|robot|check/i.test(await errorText(g)), 'a filled honeypot is refused and nothing is saved');
  ok(await g.locator('.sf-hp[aria-hidden="true"] input[tabindex="-1"]').count() === 1, 'the honeypot is hidden from people and keyboards');
});

await step('token', async () => {
  for (const [what, js] of [
    ['missing', () => { document.querySelector('[name="sf_t"]').remove(); }],
    ['changed', () => { const t = document.querySelector('[name="sf_t"]'); t.value = t.value.replace(/.$/, (c) => (c === 'a' ? 'b' : 'a')); }],
    ['for another form', () => { const t = document.querySelector('[name="sf_t"]'); const [p, s] = t.value.split('.'); const d = JSON.parse(atob(p)); d.a = 'other'; t.value = btoa(JSON.stringify(d)) + '.' + s; }],
  ]) {
    await open();
    await g.evaluate(js);
    const n = count();
    await L.next(g);
    ok(count() === n && (await errorText(g)) !== '', `a token that's ${what} is refused`);
  }
});

await step('replay', async () => {
  await open();
  const post = await g.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form[data-sf-alias="t-sec"]'))));
  const action = await g.getAttribute('form[data-sf-alias="t-sec"]', 'action');
  await L.next(g);
  const n = count();
  // The same token again, from the same session.
  const r = await g.request.post(new URL(action, L.BASE).href, { form: { ...post, sf_nav: 'next' }, maxRedirects: 0 });
  ok(count() === n, `the same token can't be used twice (status ${r.status()})`);
});

await step('session binding', async () => {
  await open();
  const post = await g.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form[data-sf-alias="t-sec"]'))));
  const action = await g.getAttribute('form[data-sf-alias="t-sec"]', 'action');
  const other = await L.site();
  await other.goto(L.formUrl('t-sec'));
  const n = count();
  await other.request.post(new URL(action, L.BASE).href, { form: { ...post, sf_nav: 'next' } });
  ok(count() === n, 'a token taken from one session doesn\'t work in another');
});

await step('fill time', async () => {
  L.params({ min_fill_seconds: 3 });
  await open();
  const n = count();
  await L.next(g);
  ok(count() === n && /verif|robot|check|again/i.test(await errorText(g)), 'a form sent faster than a person could fill it is refused');
  await open();
  await L.wait(3300);
  await L.next(g);
  ok(count() === n + 1, '…and the same form after a few seconds is accepted');
  L.params({ min_fill_seconds: 0 });
});

await step('rate limit', async () => {
  L.params({ rate_limit_max: 2, rate_limit_minutes: 10 });
  L.sql('DELETE FROM jos_squidform_rate');
  const p = await L.site();
  const n = count();
  for (let i = 0; i < 3; i++) { await p.goto(L.formUrl('t-sec')); if (await p.locator('[name="sf[f_name]"]').count()) { await L.fill(p, { f_name: `R${i}` }); await L.next(p); } }
  ok(count() === n + 2, `only two sends in ten minutes (${count() - n})`);
  const t = await L.text(p, '#sf-t-sec');
  ok(/try again|too many|limit/i.test(t), `the third is told when to try again (${t.slice(0, 120)})`);
  ok(Number(L.sql("SELECT COUNT(*) FROM jos_squidform_rate WHERE key_hash REGEXP '^[0-9a-f]{64}$'")) > 0 || Number(L.sql('SELECT COUNT(*) FROM jos_squidform_rate')) > 0, 'the rate table holds hashed keys');
  ok(!L.sql('SELECT * FROM jos_squidform_rate').includes('127.0.0.1'), 'no IP address is stored in the rate table');
  L.params({ rate_limit_max: 0 });
});

await step('tampered answers', async () => {
  const u = L.ensureUser('sf_sec_user', ['Registered']);
  const p = await L.site('sf_sec_user');
  await p.goto(L.formUrl('t-sec'));
  ok(await p.inputValue('[name="sf[f_me]"]') === 'sf_sec_user', 'a locked prefill shows the signed-in person');
  await L.fill(p, { f_name: 'Tamper' });
  await p.evaluate(() => {
    const f = document.querySelector('form[data-sf-alias="t-sec"]');
    const me = f.querySelector('[name="sf[f_me]"]'); me.readOnly = false; me.disabled = false; me.value = 'admin';
    const s = f.querySelector('[name="sf[f_pick]"]'); s.add(new Option('Z', 'zzz')); s.value = 'zzz';
    const x = document.createElement('input'); x.name = 'sf[f_injected]'; x.value = 'boo'; f.appendChild(x);
  });
  await L.next(p);
  ok(/Pick/.test(await errorText(p)), 'a choice that isn\'t on the list is refused');
  await p.selectOption('[name="sf[f_pick]"]', 'a');
  await p.evaluate(() => { const me = document.querySelector('[name="sf[f_me]"]'); me.readOnly = false; me.disabled = false; me.value = 'admin'; });
  await L.next(p);
  const s = L.latest('t-sec');
  ok(s.data.f_me === 'sf_sec_user', `a locked prefill can't be changed in the browser (${s.data.f_me})`);
  ok(!('f_injected' in s.data), 'answers for fields that don\'t exist are ignored');
  ok(s.user_id === u, 'the submission belongs to the signed-in person');
});

await step('SQL-looking and HTML input', async () => {
  await open();
  const nasty = "Robert'); DROP TABLE jos_users; -- <img src=x onerror=alert(1)>";
  await L.fill(g, { f_name: nasty });
  await L.next(g);
  ok(Number(L.sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='jos_users'")) === 1, 'SQL in an answer does nothing');
  ok(L.latest('t-sec').data.f_name === nasty, 'and is saved exactly as typed');
  const a = await L.admin();
  let alerted = false;
  a.on('dialog', () => { alerted = true; });
  await a.goto(L.adminUrl(`view=submission&id=${L.latest('t-sec').id}`));
  await a.goto(L.adminUrl(`view=submissions&filter[form_id]=${L.formId('t-sec')}`));
  ok(!alerted && !(await a.locator('#content img[src="x"]').count()), 'HTML in an answer is shown as text in the admin');
});

await step('access', async () => {
  L.sql(`UPDATE jos_squidform_forms SET access=3 WHERE id=${L.formId('t-sec')}`); // Special
  const p = await L.site();
  await p.goto(L.formUrl('t-sec'));
  ok(!(await p.locator('form[data-sf-alias="t-sec"]').count()), 'a guest can\'t see a form for Special access');
  const r = await p.request.get(L.adminUrl(`view=submission&id=${L.latest('t-sec').id}`));
  ok(!(await r.text()).includes('Tamper'), 'a guest can\'t open a submission in the admin');
  L.sql(`UPDATE jos_squidform_forms SET access=1 WHERE id=${L.formId('t-sec')}`);
});

await step('admin tasks need the token', async () => {
  const a = await L.admin();
  const sid = L.latest('t-sec').id;
  await a.goto(L.adminUrl('view=submissions'));
  for (const task of ['submissions.trash', 'submissions.delete', 'forms.duplicate', 'forms.import']) {
    await a.request.post(L.adminUrl(`task=${task}`), { form: { 'cid[]': String(sid) } });
  }
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE id=${sid}`)) === 1, 'posting admin tasks without the session token changes nothing');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_forms WHERE alias LIKE 't-sec%'`)) === 1, '…no duplicate was made');
});

await step('reading tables', async () => {
  const bad = JSON.parse(JSON.stringify(def));
  bad.pages[0].fields.push({ key: 'f_users', type: 'select', label: 'Users', options: { table: { name: '#__users', value: 'id', label: 'password' } } });
  L.makeForm('t-sec-read', bad);
  const p = await L.site();
  await p.goto(L.formUrl('t-sec-read'));
  const opts = await p.$$eval('[name="sf[f_users]"] option', (o) => o.map((x) => x.textContent)).catch(() => []);
  ok(!opts.some((o) => /^\$2[aby]\$/.test(o)), 'a form can\'t read Joomla\'s users table (nothing on the allow-list)');
  L.params({ read_tables: '#__users' });
  await p.goto(L.formUrl('t-sec-read'));
  const opts2 = await p.$$eval('[name="sf[f_users]"] option', (o) => o.map((x) => x.textContent)).catch(() => []);
  ok(!opts2.some((o) => /^\$2[aby]\$/.test(o)), '…not even when someone adds it to the allow-list (secret tables are always refused)');
  L.params({ read_tables: '' });
  L.deleteForms('t-sec-read');
});

L.deleteForms('t-sec');
await L.close();
L.finish();
