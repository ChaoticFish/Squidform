// Calculations and named values (0.10.0, 0.13.0): the formula language
// (arithmetic, if, round, min/max/avg/count over a repeater, days, age),
// worked out again on the server whatever the browser sends; a named value
// isn't shown, isn't in the sender's copy, and only reaches the browser when a
// show/hide rule there needs it; formulas that could run away (too long, too
// deep, unknown functions) are refused when the form is saved.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.makeForm('t-calc', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_a', type: 'number', label: 'A' },
    { key: 'f_b', type: 'number', label: 'B' },
    { key: 'f_born', type: 'date', label: 'Born' },
    { key: 'f_rows', type: 'repeater', label: 'Items', max: 5, fields: [{ key: 'x_price', type: 'number', label: 'Price' }, { key: 'x_qty', type: 'number', label: 'Qty' }] },
    { key: 'c_mix', type: 'calc', label: 'Mix', formula: 'round(({f_a} + {f_b}) * 1.5 / 4, 2)', decimals: 2 },
    { key: 'c_big', type: 'calc', label: 'Bigger', formula: 'if({f_a} > {f_b}, 1, 2)' },
    { key: 'c_total', type: 'calc', label: 'Total', formula: 'sum({f_rows.x_price} * {f_rows.x_qty})', decimals: 2 },
    { key: 'c_stats', type: 'calc', label: 'Stats', formula: 'max({f_rows.x_price}) - min({f_rows.x_price}) + count({f_rows.x_price}) * 100' },
    { key: 'c_age', type: 'calc', label: 'Age', formula: 'floor(years({f_born}, "2026-06-15"))' },
    { key: 'c_secret', type: 'calc', label: 'Score', named: true, formula: '{f_a} * 7' },
    { key: 'c_seen', type: 'calc', label: 'Seen', named: true, formula: '{f_b} * 2' },
    { key: 'f_bonus', type: 'text', label: 'Bonus question', show_if: { field: 'c_seen', op: 'gt', value: 10 } },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});

let g;
await step('worked out in the browser', async () => {
  g = await L.site();
  await g.goto(L.formUrl('t-calc'));
  await L.fill(g, { f_a: '3', f_b: '5', f_born: '2000-06-16' });
  await g.fill('[name="sf[f_rows][0][x_price]"]', '2.5');
  await g.fill('[name="sf[f_rows][0][x_qty]"]', '4');
  await g.locator('[name="sf[f_a]"]').press('Tab');
  ok(await g.inputValue('#sf-t-calc-c_mix') === '3.00', `(3 + 5) × 1.5 ÷ 4 = 3.00 (${await g.inputValue('#sf-t-calc-c_mix')})`);
  ok(await g.inputValue('#sf-t-calc-c_big') === '2', 'if() picks the second (A isn\'t bigger)');
  ok(await g.inputValue('#sf-t-calc-c_total') === '10.00', 'a repeater total');
  ok(await g.locator('[data-sf-key="c_secret"]').count() === 0 && !(await g.content()).includes('* 7'), 'a named value nobody in the browser needs isn\'t sent to it at all');
  ok(await g.locator('[data-sf-key="c_seen"] input[type=hidden]').count() >= 1, 'one a show/hide rule needs is (hidden)');
  ok(!(await g.locator('[name="sf[f_bonus]"]').isVisible()), 'and the rule works: B = 5 gives 10, not more than 10, so hidden');
  await L.fill(g, { f_b: '6' });
  await g.locator('[name="sf[f_b]"]').press('Tab');
  ok(await g.locator('[name="sf[f_bonus]"]').isVisible(), 'B = 6 gives 12: shown');
  await L.fill(g, { f_b: '5' });
  await g.locator('[name="sf[f_b]"]').press('Tab');
});

await step('worked out again on the server', async () => {
  // Whatever the browser says, the server works them out from the answers.
  await g.evaluate(() => { for (const n of ['c_mix', 'c_total', 'c_big']) { const i = document.querySelector(`[name="sf[${n}]"]`); if (i) i.value = '999'; } });
  ok(await L.submit(g), 'sent');
  const s = L.latest('t-calc').data;
  ok(String(s.c_mix) === '3' || String(s.c_mix) === '3.00', `c_mix = 3 (${s.c_mix})`);
  ok(Number(s.c_big) === 2 && Number(s.c_total) === 10, `if() and the total (${s.c_big}, ${s.c_total})`);
  ok(Number(s.c_stats) === 100, `max − min + count × 100 (${s.c_stats})`);
  ok(Number(s.c_age) === 25, `floor(years()) counts whole years (born 2000-06-16, on 2026-06-15: ${s.c_age})`);
  ok(Number(s.c_secret) === 21, 'the named value is saved (3 × 7)');
});

await step('named values stay private', async () => {
  L.makeForm('t-calc2', {
    schema: 1, settings: { success_message: 'Thanks', copy_pdf: true },
    pages: [{ key: 'p1', title: 'One', fields: [
      { key: 'f_email', type: 'email', label: 'Email' },
      { key: 'f_a', type: 'number', label: 'A' },
      { key: 'c_secret', type: 'calc', label: 'Internal score', named: true, formula: '{f_a} * 7' },
    ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } },
      { key: 'a_conf', type: 'confirm_email', when: { action: 'a_save', is: 'success' }, config: { to_field: 'f_email' } },
      { key: 'a_staff', type: 'email', when: { action: 'a_save', is: 'success' }, config: { to: 'office@example.test', subject: 'Staff', body: '{data:_all} Score {data:c_secret}' } }] } }],
  });
  L.clearMail();
  const p = await L.site();
  await L.send(p, 't-calc2', { f_email: 'me@example.test', f_a: '6' });
  const m = await L.waitMail(2);
  const mine = m.find((x) => x.to.includes('me@example.test'));
  const staff = m.find((x) => x.to.includes('office@'));
  ok(mine && !(mine.html || mine.text).includes('Internal score') && !(mine.html || mine.text).includes('42'), 'the sender\'s copy leaves the named value out');
  ok(staff && (staff.html || staff.text).includes('Score 42'), 'staff can use it ({data:c_secret})');
  L.deleteForms('t-calc2');
});

await step('formulas that could run away are refused', async () => {
  const bad = (formula) => {
    try { L.makeForm('t-calc-bad', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_a', type: 'number', label: 'A' }, { key: 'c_x', type: 'calc', label: 'X', formula }], events: { load: [], submit: [] } }] }); L.deleteForms('t-calc-bad'); return ''; } catch (e) { return String(e.stderr || e.message); }
  };
  ok(/unknown function/i.test(bad('system("ls")')), 'an unknown function (system) is refused');
  ok(bad('{f_a}' + ' + 1'.repeat(1200)) !== '', 'too long a formula is refused');
  ok(bad('('.repeat(60) + '1' + ')'.repeat(60)) !== '', 'too deep a formula is refused');
  ok(bad('{f_nope} + 1') !== '', 'a formula about a field that doesn\'t exist is refused');
  ok(bad('round({f_a} / 0)') === '', 'dividing by zero is allowed (it gives nothing, not an error)');
});

L.deleteForms('t-calc');
await L.close();
L.finish();
