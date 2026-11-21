// Date limits (0.13.0): today-relative min and max, weekdays only, office
// hours for a date and time, "after another date by N days", a date range's
// shortest and longest, all checked on the server (the browser's own checks
// are skipped here), and the worked-out min and max on the date boxes.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.makeForm('t-dates', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_soon', type: 'date', label: 'Visit day', rules: { min: 'today+2', max: 'today+60', weekdays: [1, 2, 3, 4, 5] } },
    { key: 'f_meet', type: 'datetime', label: 'Meeting', rules: { hours: { from: '09:00', to: '17:00' } } },
    { key: 'f_start', type: 'date', label: 'Start' },
    { key: 'f_end', type: 'date', label: 'End', rules: { after: { field: 'f_start', days: 1 } } },
    { key: 'f_stay', type: 'daterange', label: 'Stay', min_days: 2, max_days: 7 },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
const iso = (d) => d.toISOString().slice(0, 10);
const day = (n) => { const d = new Date(); d.setUTCHours(12, 0, 0, 0); d.setUTCDate(d.getUTCDate() + n); return d; };
const weekday = (from, want) => { for (let n = from; n < from + 14; n++) { const w = day(n).getUTCDay() || 7; if (want(w)) return iso(day(n)); } };
let g;
const tryIt = async (vals) => {
  g = await L.site();
  await g.goto(L.formUrl('t-dates'));
  await g.evaluate(() => { const f = document.querySelector('form[data-sf-alias="t-dates"]'); f.setAttribute('novalidate', ''); f.querySelectorAll('input[type=date], input[type=datetime-local]').forEach((i) => { i.type = 'text'; i.removeAttribute('min'); i.removeAttribute('max'); }); });
  for (const [k, v] of Object.entries(vals)) await g.fill(`[name="sf[${k}]"]`, v);
  await L.rawNext(g);
  return (await g.locator('.sf-error-summary').count()) ? L.text(g, '.sf-error-summary') : '';
};

await step('the date boxes carry the worked-out limits', async () => {
  g = await L.site();
  await g.goto(L.formUrl('t-dates'));
  const min = await g.getAttribute('[name="sf[f_soon]"]', 'min');
  const max = await g.getAttribute('[name="sf[f_soon]"]', 'max');
  ok(min === iso(day(2)) && max === iso(day(60)), `today+2 and today+60 become real dates (${min} … ${max})`);
});

await step('relative min and max, weekdays', async () => {
  ok(/Visit day/.test(await tryIt({ f_soon: iso(day(1)) })), 'tomorrow is too soon (today+2)');
  ok(/Visit day/.test(await tryIt({ f_soon: iso(day(61)) })), 'day 61 is too late (today+60)');
  ok(/Visit day/.test(await tryIt({ f_soon: weekday(2, (w) => w >= 6) })), 'a weekend day is refused (weekdays only)');
  ok(await tryIt({ f_soon: weekday(2, (w) => w <= 5) }) === '', 'a weekday in range is fine');
});

await step('office hours', async () => {
  ok(/Meeting/.test(await tryIt({ f_meet: `${iso(day(3))}T08:30` })), '08:30 is before 09:00');
  ok(/Meeting/.test(await tryIt({ f_meet: `${iso(day(3))}T17:30` })), '17:30 is after 17:00');
  ok(await tryIt({ f_meet: `${iso(day(3))}T10:15` }) === '', '10:15 is fine');
});

await step('after another date', async () => {
  ok(/End/.test(await tryIt({ f_start: '2027-03-10', f_end: '2027-03-10' })), 'the same day isn\'t a day after');
  ok(await tryIt({ f_start: '2027-03-10', f_end: '2027-03-11' }) === '', 'the next day is');
});

await step('date range: shortest and longest', async () => {
  const range = (a, b) => tryIt({ 'f_stay][from': a, 'f_stay][to': b });
  ok(/Stay/.test(await range('2027-04-01', '2027-04-01')), 'one day is shorter than 2 (both ends count)');
  ok(await range('2027-04-01', '2027-04-02') === '', 'two days is long enough');
  ok(/Stay/.test(await range('2027-04-01', '2027-04-08')), 'eight days is longer than 7');
  ok(/Stay/.test(await range('2027-04-05', '2027-04-01')), 'an end before the start is refused');
  ok(await range('2027-04-01', '2027-04-05') === '', 'four days is fine');
});

await step('nonsense dates', async () => {
  ok(/Start/.test(await tryIt({ f_start: '2027-13-01' })), 'month 13 is refused');
  ok(/Start/.test(await tryIt({ f_start: '01/02/2027' })), 'another date format is refused (the box always sends YYYY-MM-DD)');
  ok(/Meeting/.test(await tryIt({ f_meet: `${iso(day(3))}T25:00` })), 'hour 25 is refused');
});

L.deleteForms('t-dates');
await L.close();
L.finish();
