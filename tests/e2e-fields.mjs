// Every field type (the "Every field type" sample, human check off): each one
// takes a good answer and stores it in the right shape; and the server turns
// away a bad answer for each kind of rule even when the browser's checks are
// skipped (confirm email, number range, pattern, phone mask, web address,
// choices not on the list, too many picks, date range too long, rating and
// slider out of range, ranking with a repeat, grid choice not on the list).
import * as L from './lib.mjs';
import fs from 'fs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const d = JSON.parse(fs.readFileSync(`${L.EXAMPLES}/showcase.json`, 'utf8'));
d.settings.captcha = 'none';
L.makeForm('t-fields', d);
let g = await L.site();
let g2 = null;
const errs = async () => ((await g.locator('.sf-error-summary').count()) ? L.text(g, '.sf-error-summary') : '');
const setVal = (name, v) => g.locator(`[name="${name}"]`).first().evaluate((e, val) => { e.value = val; }, v);

await step('good answers, every type', async () => {
  await g.goto(L.formUrl('t-fields', '&from=fieldtest'));
  await g.evaluate(() => document.querySelector('form[data-sf-alias]').setAttribute('novalidate', ''));
  await L.fill(g, { f_text: 'Hello', f_textarea: 'Two\nlines', f_email: 'squid@example.test', f_number: '7', f_tel: '(555) 123-4567', f_url: 'https://example.test/x', f_code: 'ABC123', f_pin: '9876' });
  await g.fill('[name="sf_confirm[f_email]"]', 'squid@example.test');
  // Formatted text is Joomla's editor (TinyMCE); set it the way a paste would, then let it save to the box.
  await g.waitForFunction(() => window.tinymce?.get('sf_t_fields_f_editor'));
  await g.evaluate(() => { const ed = window.tinymce.get('sf_t_fields_f_editor'); ed.setContent('<p>Some <strong>bold</strong> text</p>'); ed.save(); });
  await g.evaluate(() => { const t = document.querySelector('[name="sf[f_editor]"]'); t.value += '<script>alert(1)</script>'; window.tinymce.get('sf_t_fields_f_editor').remove(); });
  await L.next(g);
  ok(!(await errs()), `page 1 accepted ${await errs()}`);
  await L.fill(g, { f_date: '2026-12-24', f_time: '09:30', f_datetime: '2026-12-24T09:30' });
  await g.fill('[name="sf[f_range][from]"]', '2026-12-01');
  await g.fill('[name="sf[f_range][to]"]', '2026-12-05');
  await L.next(g);
  ok(!(await errs()), `page 2 accepted ${await errs()}`);
  await L.fill(g, { f_select: 'Octopus', f_radios: 'Maybe', f_checks: ['Arms', 'Ink'], f_multi: ['Reef', 'Deep sea'], f_check: true, f_rating: '4', f_images: ['pond', 'maple'] });
  await setVal('sf[f_search]', 'Eel');
  await g.fill('[name="sf[f_lookup][label]"]', 'Pacific');
  await setVal('sf[f_lookup][value]', 'Pacific');
  await setVal('sf[f_slider]', '35');
  await g.selectOption('[name="sf[f_ranking][Speed]"]', '3');
  await g.selectOption('[name="sf[f_ranking][Camouflage]"]', '1');
  await g.selectOption('[name="sf[f_ranking][Intelligence]"]', '2');
  await g.check('[name="sf[f_grid][Monday][]"][value="Morning"]');
  await g.check('[name="sf[f_grid][Friday][]"][value="Afternoon"]');
  await g.selectOption('[name="sf[f_grid_sel][Effort][Now]"]', 'High');
  await L.next(g);
  ok(!(await errs()), `page 3 accepted ${await errs()}`);
  for (const [k, v] of Object.entries({ 'f_name][first': 'Inky', 'f_name][last': 'Squid', 'f_address][line1': '1 Reef Road', 'f_address][city': 'Brighton', 'f_address][postal': 'BN1 1AA', 'f_phone][number': '2025550123' })) await g.fill(`[name="sf[${k}]"]`, v);
  await L.next(g);
  ok(!(await errs()), `page 4 accepted ${await errs()}`);
  await L.fill(g, { f_first: 'Left', f_second: 'Right', f_show: 'Yes', f_shown: 'Shown!', f_agree: true });
  await g.fill('[name="sf[f_rows][0][x_item]"]', 'Shell');
  await g.fill('[name="sf[f_rows][0][x_qty]"]', '4');
  ok(await L.submit(g), `sent ${await errs()}`);
});

await step('stored in the right shape', async () => {
  const s = L.latest('t-fields').data;
  ok(s.f_text === 'Hello' && s.f_textarea.includes('\n'), 'text and text box (line breaks kept)');
  ok(!/script/i.test(s.f_editor) && /<strong>bold<\/strong>/.test(s.f_editor), `formatted text keeps allowed HTML, drops scripts (${s.f_editor})`);
  ok(s.f_email === 'squid@example.test' && Number(s.f_number) === 7 && s.f_code === 'ABC123', 'email, number, pattern');
  ok(s.f_pin === '9876', 'a hidden-input answer is stored like any other');
  ok(s.f_hidden === 'fieldtest', 'a hidden value from the web address');
  ok(s.f_date === '2026-12-24' && s.f_time.startsWith('09:30') && String(s.f_datetime).startsWith('2026-12-24'), `date, time, date and time (${s.f_date} ${s.f_time} ${s.f_datetime})`);
  ok(s.f_range?.from === '2026-12-01' && s.f_range?.to === '2026-12-05', 'date range as from/to');
  ok(String(s.c_nights) === '4' || String(s.c_nights) === '5', `days() in a calculation (${s.c_nights})`);
  ok(s.f_select === 'Octopus' && s.f_radios === 'Maybe' && s.f_search === 'Eel', 'dropdown, radios, searchable dropdown');
  ok(JSON.stringify(s.f_checks) === '["Arms","Ink"]' && JSON.stringify(s.f_multi) === '["Reef","Deep sea"]', 'tick boxes and multi-select as lists');
  ok(s.f_check === true || s.f_check === 1 || s.f_check === '1', `single tick box (${s.f_check})`);
  ok(JSON.stringify(s.f_lookup).includes('Pacific'), 'look-up');
  ok(Number(s.f_rating) === 4 && Number(s.f_slider) === 35, 'rating and slider');
  ok(JSON.stringify(s.f_ranking) === JSON.stringify(['Camouflage', 'Intelligence', 'Speed']) || (s.f_ranking?.Camouflage == 1), `ranking in order (${JSON.stringify(s.f_ranking)})`);
  ok(JSON.stringify(s.f_images) === '["pond","maple"]', 'image choice');
  ok(JSON.stringify(s.f_grid?.Monday) === '["Morning"]' && JSON.stringify(s.f_grid?.Friday) === '["Afternoon"]', `grid of tick boxes (${JSON.stringify(s.f_grid)})`);
  ok(s.f_grid_sel?.Effort?.Now === 'High', 'grid of dropdowns');
  ok(s.f_name?.first === 'Inky' && s.f_name?.last === 'Squid', 'name block');
  ok(s.f_address?.city === 'Brighton' && s.f_address?.country === 'GB', 'address block (with its default country)');
  ok(s.f_phone?.country === 'US' && /2025550123/.test(s.f_phone?.number || ''), `phone with country code (${JSON.stringify(s.f_phone)})`);
  ok(s.f_first === 'Left' && s.f_shown === 'Shown!' && String(s.c_total) === '4', 'sections, show-when and a repeater total');
  ok(s.f_agree === true || s.f_agree === 1 || s.f_agree === '1' || typeof s.f_agree === 'object', 'consent');
});

// A form remembers which page you're on for the session, so each check starts in a fresh browser.
const page = async (n) => { if (g2) await g2.context().close(); g = g2 = await L.site(); await g.goto(L.formUrl('t-fields')); await g.evaluate(() => document.querySelector('form[data-sf-alias]').setAttribute('novalidate', '')); for (let i = 0; i < n; i++) await L.next(g); await g.evaluate(() => document.querySelector('form[data-sf-alias]').setAttribute('novalidate', '')); };
const refused = async (what, label, setup) => {
  await setup();
  await L.next(g);
  const e = await errs();
  ok(e !== '' && (!label || e.includes(label)), `${what} is refused on the server${e ? '' : ' (no error)'}`);
};
const label = (k) => { for (const p of d.pages) for (const f of p.fields.concat(...p.fields.map((x) => x.fields || []))) if (f.key === k) return f.label; return ''; };

await step('the server checks every rule', async () => {
  await page(0);
  await refused('a confirm email that doesn\'t match', label('f_email'), async () => { await L.fill(g, { f_email: 'a@example.test' }); await g.fill('[name="sf_confirm[f_email]"]', 'b@example.test'); });
  await page(0);
  await refused('a number over the maximum', label('f_number'), () => L.fill(g, { f_number: '11' }));
  await page(0);
  await refused('text that doesn\'t match the pattern', 'Three capital letters', () => L.fill(g, { f_code: 'abc' }));
  await page(0);
  await refused('a phone that doesn\'t fit the mask', label('f_tel'), () => setVal('sf[f_tel]', '12'));
  await page(0);
  await refused('a web address that isn\'t one', label('f_url'), () => setVal('sf[f_url]', 'javascript:alert(1)'));
  await page(0);
  await refused('text past the longest', label('f_text'), () => setVal('sf[f_text]', 'x'.repeat(61)));
  await page(1);
  await refused('a date range longer than allowed', label('f_range'), async () => { await g.fill('[name="sf[f_range][from]"]', '2026-12-01'); await g.fill('[name="sf[f_range][to]"]', '2026-12-31'); });
  await page(1);
  await refused('a date that isn\'t one', label('f_date'), () => g.evaluate(() => { const i = document.querySelector('[name="sf[f_date]"]'); i.type = 'text'; i.value = '2026-02-30'; }));
  await page(2);
  await refused('more tick boxes than allowed', label('f_checks'), () => L.fill(g, { f_checks: ['Arms', 'Ink', 'Beak'] }));
  await page(2);
  await refused('a dropdown choice not on the list', label('f_select'), () => g.evaluate(() => { const s = document.querySelector('[name="sf[f_select]"]'); s.add(new Option('Shark', 'Shark')); s.value = 'Shark'; }));
  // Choice-style answers that aren't on the list are left out on the server (not an error):
  // the page goes on, and going Back shows what was kept.
  const cleaned = async (what, setup, check) => {
    await page(2);
    await setup();
    await L.next(g);
    await L.next(g, 'back');
    ok(await check(), `${what} is left out on the server`);
  };
  await cleaned('a rating above the top', () => g.evaluate(() => { const r = document.querySelector('[name="sf[f_rating]"]'); r.value = '9'; r.checked = true; }),
    async () => !(await g.locator('[name="sf[f_rating]"]:checked').count()));
  await cleaned('a slider value off its steps (snapped to one)', () => setVal('sf[f_slider]', '33'),
    async () => (await g.inputValue('[name="sf[f_slider]"]')) === '35');
  await cleaned('a ranking with a place used twice (the first keeps it)', async () => { await g.selectOption('[name="sf[f_ranking][Speed]"]', '1'); await g.selectOption('[name="sf[f_ranking][Camouflage]"]', '1'); await g.selectOption('[name="sf[f_ranking][Intelligence]"]', '2'); },
    async () => { const v = await Promise.all(['Speed', 'Camouflage', 'Intelligence'].map((k) => g.inputValue(`[name="sf[f_ranking][${k}]"]`))); return new Set(v.filter(Boolean)).size === v.filter(Boolean).length; });
  await cleaned('a grid answer not on its list', () => g.evaluate(() => { const s = document.querySelector('[name="sf[f_grid_sel][Effort][Now]"]'); s.add(new Option('Huge', 'Huge')); s.value = 'Huge'; }),
    async () => (await g.inputValue('[name="sf[f_grid_sel][Effort][Now]"]')) !== 'Huge');
  await cleaned('an image choice not on the list', () => g.evaluate(() => { const c = document.querySelector('[name="sf[f_images][]"][type="checkbox"]'); c.value = 'nope'; c.checked = true; }),
    async () => !(await g.locator('[name="sf[f_images][]"][value="nope"]').count()) && !(await g.locator('[name="sf[f_images][]"]:checked').count()));
});

await step('accessible, page by page', async () => {
  g = await L.site();
  await g.goto(L.formUrl('t-fields'));
  for (let i = 0; i < 5; i++) { await L.axe(g, `"Every field type" page ${i + 1}`, 'form[data-sf-alias="t-fields"]'); await L.next(g); }
});

L.deleteForms('t-fields');
await L.close();
L.finish();
