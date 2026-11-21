// Behaviour (0.10.x): skip pages (and their answers dropped), open and close
// times, a limit in total and per person, Read data from an allow-listed table
// (values bound, never written into SQL; refused when the table isn't on the
// list), a redirect only to this site, and a field filled from the address.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const save = { key: 'a_save', type: 'save_data', config: { target: 'submissions' } };
const one = (key, settings = {}, extraFields = [], submit = [save]) => ({
  schema: 1, settings: { success_message: 'Thanks.', ...settings },
  pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_name', type: 'text', label: 'Name' }, ...extraFields], events: { load: [], submit } }],
});
const shows = async (p, alias) => (await p.locator(`form[data-sf-alias="${alias}"]`).count()) === 1;

await step('skip pages', async () => {
  L.makeForm('t-skip', {
    schema: 1, settings: { success_message: 'Thanks.' },
    pages: [
      { key: 'p1', title: 'Start', fields: [{ key: 'f_pet', type: 'radios', label: 'Pet', options: { static: ['Cat', 'Fish'] }, rules: { required: true } }], events: { load: [], submit: [] } },
      { key: 'p2', title: 'About the cat', show_if: { field: 'f_pet', op: 'eq', value: 'Cat' }, fields: [{ key: 'f_cat', type: 'text', label: 'Cat name' }], events: { load: [], submit: [] } },
      { key: 'p3', title: 'Finish', fields: [{ key: 'f_end', type: 'text', label: 'Anything else' }], events: { load: [], submit: [save] } },
    ],
  });
  const g = await L.site();
  await g.goto(L.formUrl('t-skip'));
  await L.fill(g, { f_pet: 'Cat' }); await L.next(g);
  ok(/About the cat/.test(await L.text(g, '#sf-t-skip')), 'a cat owner gets the cat page');
  await L.fill(g, { f_cat: 'Tom' }); await L.next(g);
  ok(/Step 3 of 3/.test(await L.text(g, '#sf-t-skip')), '…as step 3 of 3');
  await L.next(g, 'back'); await L.next(g, 'back');
  await L.fill(g, { f_pet: 'Fish' }); await L.next(g);
  ok(/Finish/.test(await L.text(g, '#sf-t-skip')) && /Step 2 of 2/.test(await L.text(g, '#sf-t-skip')), 'changing to Fish skips it (step 2 of 2)');
  await L.next(g, 'back');
  ok(/Start/.test(await L.text(g, '#sf-t-skip')), 'Back steps over the skipped page too');
  await L.next(g);
  ok(await L.submit(g), 'sent');
  const s = L.latest('t-skip').data;
  ok(s.f_pet === 'Fish' && !s.f_cat, `the skipped page's answer (typed earlier) is dropped (${s.f_cat})`);
  L.deleteForms('t-skip');
});

await step('open and close times', async () => {
  const g = await L.site();
  L.makeForm('t-when', one('t-when', { opens: '2099-01-01T09:00', before_message: '<p>Not yet open.</p>' }));
  await g.goto(L.formUrl('t-when'));
  ok(!(await shows(g, 't-when')) && /Not yet open/.test(await L.text(g)), 'before it opens: the "before" message, no form');
  L.makeForm('t-when', one('t-when', { closes: '2001-01-01T09:00', closed_message: '<p>All done for this year.</p>' }));
  await g.goto(L.formUrl('t-when'));
  ok(!(await shows(g, 't-when')) && /All done/.test(await L.text(g)), 'after it closes: the "closed" message');
  L.makeForm('t-when', one('t-when', { opens: '2001-01-01T09:00', closes: '2099-01-01T09:00' }));
  await g.goto(L.formUrl('t-when'));
  ok(await shows(g, 't-when'), 'in between: the form');
  // Opened in time, sent after it closed.
  await L.fill(g, { f_name: 'Late' });
  L.saveForm('t-when', one('t-when', { closes: '2001-01-01T09:00' }));
  const n = Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${L.formId('t-when')}`));
  await L.next(g);
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submissions WHERE form_id=${L.formId('t-when')}`)) === n, 'a form opened before closing time but sent after isn\'t saved');
  L.deleteForms('t-when');
});

await step('limits', async () => {
  L.makeForm('t-limit', one('t-limit', { max_submissions: 2, full_message: '<p>Sorry, we are full.</p>', max_per_user: 1, user_message: '<p>You already sent one.</p>' }));
  L.ensureUser('sf_lim_a'); L.ensureUser('sf_lim_b');
  const a = await L.site('sf_lim_a');
  ok(await L.send(a, 't-limit', { f_name: 'A1' }), 'the first send');
  await a.goto(L.formUrl('t-limit'));
  ok(!(await shows(a, 't-limit')) && /already sent/.test(await L.text(a)), 'one per person: the same person gets their message');
  const b = await L.site('sf_lim_b');
  ok(await L.send(b, 't-limit', { f_name: 'B1' }), 'someone else can still send');
  const c = await L.site();
  await c.goto(L.formUrl('t-limit'));
  ok(!(await shows(c, 't-limit')) && /we are full/.test(await L.text(c)), 'two in total: the third person finds it full');
  L.sql(`UPDATE jos_squidform_submissions SET archived=1 WHERE form_id=${L.formId('t-limit')}`);
  await c.goto(L.formUrl('t-limit'));
  ok(!(await shows(c, 't-limit')), 'archived submissions still count');
  L.deleteForms('t-limit');
});

await step('Read data', async () => {
  L.sql('CREATE TABLE IF NOT EXISTS jos_sf_test_rooms (id INT PRIMARY KEY, code VARCHAR(20), title VARCHAR(100), secret VARCHAR(100))');
  L.sql('DELETE FROM jos_sf_test_rooms');
  L.sql("INSERT INTO jos_sf_test_rooms VALUES (1,'A1','Atrium','s1'),(2,'B2','Boardroom','s2'),(3,'C3','Canteen','s3')");
  const read = { key: 'a_read', type: 'read_data', on: 'submit', config: { table: '#__sf_test_rooms', columns: ['title'], where: { all: [{ col: 'code', op: 'eq', value: '{data:f_name}' }] }, mode: 'one' } };
  const msg = { key: 'a_msg', type: 'message', when: { action: 'a_read', is: 'success' }, config: { text: 'Room: {action:a_read.title}' } };
  const miss = { key: 'a_miss', type: 'message', when: { action: 'a_read', is: 'fail' }, config: { text: 'No such room.' } };
  L.makeForm('t-read', one('t-read', {}, [], [read, msg, miss]));
  L.params({ read_tables: '' });
  const g = await L.site();
  await L.send(g, 't-read', { f_name: 'B2' });
  ok(!/Boardroom/.test(await L.text(g, '#sf-t-read')), 'a table that isn\'t on the read allow-list can\'t be read');
  L.params({ read_tables: '#__sf_test_rooms' });
  const g2 = await L.site();
  await L.send(g2, 't-read', { f_name: 'B2' });
  ok(/Room: Boardroom/.test(await L.text(g2, '#sf-t-read')), `on the list, it reads the row ({action:a_read.title}) (${(await L.text(g2, '#sf-t-read')).slice(0, 80)})`);
  const g3 = await L.site();
  await L.send(g3, 't-read', { f_name: "x' OR '1'='1" });
  ok(/No such room/.test(await L.text(g3, '#sf-t-read')), 'an answer made to look like SQL matches nothing (values are bound)');
  ok(Number(L.sql('SELECT COUNT(*) FROM jos_sf_test_rooms')) === 3, 'and changes nothing');
  L.params({ read_tables: '' });
  L.sql('DROP TABLE jos_sf_test_rooms');
  L.deleteForms('t-read');
});

await step('redirect', async () => {
  const red = (url) => [save, { key: 'a_go', type: 'redirect', config: { url } }];
  L.makeForm('t-red', one('t-red', {}, [], red('index.php?option=com_users&view=login&from={data:f_name}')));
  const g = await L.site();
  await L.send(g, 't-red', { f_name: 'a&Itemid=9' });
  const u = new URL(g.url());
  ok(u.searchParams.get('view') === 'login' && u.searchParams.get('from') === 'a&Itemid=9' && !u.searchParams.has('Itemid'), `a redirect goes to the page, with answers URL-encoded (${u.search})`);
  L.makeForm('t-red', one('t-red', {}, [], red('https://evil.example/phish')));
  const g2 = await L.site();
  await L.send(g2, 't-red', { f_name: 'x' });
  ok(!g2.url().includes('evil.example'), 'a redirect to another site is refused (unless Options allow it)');
  L.deleteForms('t-red');
});

await step('filled from the web address', async () => {
  L.makeForm('t-url', one('t-url', {}, [
    { key: 'f_dept', type: 'select', label: 'Department', url_param: 'dept', options: { static: ['HR', 'IT'] } },
    { key: 'f_ref', type: 'hidden', label: 'Ref', prefill: '{url:ref}' },
  ]));
  const g = await L.site();
  await g.goto(L.formUrl('t-url', '&dept=IT&ref=<b>x</b>'));
  ok(await g.inputValue('[name="sf[f_dept]"]') === 'IT', 'a field that can be filled from the address is');
  await g.goto(L.formUrl('t-url', '&dept=Payroll'));
  ok(await g.inputValue('[name="sf[f_dept]"]') !== 'Payroll', 'a value that isn\'t one of its choices is ignored');
  await g.goto(L.formUrl('t-url', '&f_name=Mallory'));
  ok(await g.inputValue('[name="sf[f_name]"]') === '', 'a field that wasn\'t set up for it isn\'t filled from the address');
  L.deleteForms('t-url');
});

await L.close();
L.finish();
