// Fields made of parts (0.8.1+): name, US and international address, and
// phone with country code. Which parts are needed (when required, or once any
// part is filled in), the US state list and ZIP rule, the default country
// alone not counting as an answer, postcodes in capitals, phone numbers tidied
// to E.164 (trunk 0 and a typed country code taken off), and how each one
// reads in an email.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.makeForm('t-parts', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'name', label: 'Full name', parts: ['title', 'first', 'middle', 'last'], rules: { required: true } },
    { key: 'f_us', type: 'address', label: 'US address', format: 'us' },
    { key: 'f_intl', type: 'address', label: 'Postal address', format: 'intl', default_country: 'GB', line2: false },
    { key: 'f_phone', type: 'phone', label: 'Phone', default_country: 'GB' },
  ], events: { load: [], submit: [
    { key: 'a_save', type: 'save_data', config: { target: 'submissions' } },
    { key: 'a_mail', type: 'email', when: { action: 'a_save', is: 'success' }, config: { to: 'parts@example.test', subject: 'Parts', body: '{data:_all}' } },
  ] } }],
});
let g;
const fresh = async () => { g = await L.site(); await g.goto(L.formUrl('t-parts')); await g.evaluate(() => document.querySelector('form[data-sf-alias]').setAttribute('novalidate', '')); };
const put = async (vals) => { for (const [k, v] of Object.entries(vals)) { const el = g.locator(`[name="sf[${k}]"]`); if (await el.evaluate((e) => e.tagName) === 'SELECT') await el.selectOption(v); else await el.fill(v); } };
const errs = async () => ((await g.locator('.sf-error-summary').count()) ? L.text(g, '.sf-error-summary') : '');
const tryIt = async (vals) => { await fresh(); await put({ 'f_name][first': 'Ada', 'f_name][last': 'Lovelace', ...vals }); await L.next(g); return errs(); };

await step('labels and autocomplete', async () => {
  await fresh();
  ok(await g.locator('fieldset:has(legend:has-text("Full name"))').count() === 1, 'each block is a fieldset with its label as the legend');
  ok(await g.getAttribute('[name="sf[f_name][first]"]', 'autocomplete') === 'given-name' && await g.getAttribute('[name="sf[f_us][zip]"]', 'autocomplete') === 'postal-code', 'parts carry autocomplete names');
  ok(await g.locator('[name="sf[f_intl][line2]"]').count() === 0, '"line2": false drops the second line');
  ok(await g.inputValue('[name="sf[f_intl][country]"]') === 'GB' && await g.inputValue('[name="sf[f_phone][country]"]') === 'GB', 'the default country is chosen');
  ok(/\+44/.test(await g.locator('[name="sf[f_phone][country]"] option[value="GB"]').textContent()), 'the phone country list shows dialling codes');
  await L.axe(g, 'name, address and phone blocks', 'form[data-sf-alias="t-parts"]');
});

await step('name', async () => {
  await fresh();
  await L.next(g);
  ok(/Full name/.test(await errs()), 'a required name needs filling in');
  ok(/Full name/.test(await tryIt({ 'f_name][last': '' })), 'first and last are both needed');
  ok(await tryIt({ 'f_name][middle': '' }) === '', 'the middle name isn\'t');
});

await step('US address', async () => {
  ok(await tryIt({}) === '', 'an optional address left empty is fine');
  ok(/US address|ZIP|city|state/i.test(await tryIt({ 'f_us][line1': '1 Main St' })), 'once any part is filled in, the needed parts are needed');
  const good = { 'f_us][line1': '1 Main St', 'f_us][city': 'Martinsburg', 'f_us][state': 'WV' };
  ok(/ZIP/i.test(await tryIt({ ...good, 'f_us][zip': '2540' })), 'a four-digit ZIP is refused');
  ok(await tryIt({ ...good, 'f_us][zip': '25401-1234' }) === '', 'ZIP+4 is fine');
  await fresh();
  await put({ 'f_name][first': 'Ada', 'f_name][last': 'Lovelace', ...good, 'f_us][zip': '25401' });
  await g.evaluate(() => { const s = document.querySelector('[name="sf[f_us][state]"]'); s.add(new Option('Narnia', 'NN')); s.value = 'NN'; });
  await L.next(g);
  ok((await errs()) !== '', 'a state that isn\'t on the list is refused');
  ok((await g.locator('[name="sf[f_us][state]"] option').count()) >= 51 && await g.locator('[name="sf[f_us][state]"] option[value="AE"]').count() === 1, 'the state list has every state, DC, territories and military (AA/AE/AP)');
});

await step('international address and phone, sent', async () => {
  await fresh();
  await put({ 'f_name][title': 'Countess', 'f_name][first': 'Ada', 'f_name][last': 'Lovelace',
    'f_intl][line1': '12 St James Square', 'f_intl][city': 'London', 'f_intl][postal': 'sw1y 4jh',
    'f_phone][number': '+44 (0)20 7946 0000' });
  L.clearMail();
  ok(await L.submit(g), `sent ${await errs()}`);
  const s = L.latest('t-parts').data;
  ok(s.f_intl.postal === 'SW1Y 4JH', `postcodes are stored in capitals (${s.f_intl.postal})`);
  ok(s.f_intl.country === 'GB', 'with the country');
  ok(!s.f_us || Object.keys(s.f_us).length === 0, 'an empty address stores nothing');
  ok(s.f_phone.e164 === '+442079460000' && s.f_phone.number === '2079460000', `a typed +44 and the (0) are taken off (${JSON.stringify(s.f_phone)})`);
  ok(s.f_name.title === 'Countess' && !('suffix' in s.f_name), 'only the chosen name parts are kept');
  const m = (await L.waitMail(1))[0];
  const body = m.html || m.text;
  ok(body.includes('Countess Ada Lovelace'), 'the name reads as one line in the email');
  ok(body.includes('12 St James Square') && body.includes('London') && body.includes('United Kingdom'), 'the address reads with the country\'s name');
  ok(body.includes('+44'), 'the phone shows with its country code');
});

await step('the default country alone isn\'t an answer', async () => {
  ok(await tryIt({}) === '', 'an untouched international address (only its default country) isn\'t treated as half-filled');
});

await step('phone rules', async () => {
  ok(/Phone/.test(await tryIt({ 'f_phone][number': '12' })), 'too short a number is refused');
  ok(/Phone/.test(await tryIt({ 'f_phone][number': '1234567890123456' })), 'more than 15 digits with the country code is refused');
  ok(/Phone/.test(await tryIt({ 'f_phone][number': 'call me' })), 'words are refused');
  ok(await tryIt({ 'f_phone][number': '020-7946.0000' }) === '', 'dashes, dots and spaces are fine');
  await fresh();
  await put({ 'f_name][first': 'Ada', 'f_name][last': 'Lovelace', 'f_phone][number': '2025550123' });
  await g.evaluate(() => { const s = document.querySelector('[name="sf[f_phone][country]"]'); s.add(new Option('Atlantis', 'XX')); s.value = 'XX'; });
  await L.submit(g);
  ok(L.latest('t-parts').data.f_phone?.country === 'GB', 'a country that isn\'t on the list falls back to the default');
});

L.deleteForms('t-parts');
await L.close();
L.finish();
