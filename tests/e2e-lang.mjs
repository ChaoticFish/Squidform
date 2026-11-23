// Visitor languages (0.15.0): language tabs (a real <nav> with aria-current
// and hreflang), switching keeps what's typed, the form's own translations
// (labels, help, choices, messages; missing ones fall back to as written),
// SquidForm's own words in each pack, lang="" on the form, the server's error
// messages in the visitor's language, stored values never translated, the
// confirmation email in the sender's language, and the fun packs.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const was = L.sql("SELECT params FROM jos_extensions WHERE element='com_squidform'");
L.params({ languages: ['en-GB', 'es-ES', 'fr-FR', 'de-DE', 'eo-XX', 'en-XC', 'en-XF', 'en-XD'] });
L.makeForm('t-lang', {
  schema: 1, settings: { success_message: 'Thanks!', languages: ['en-GB', 'es-ES', 'fr-FR', 'en-XF'] },
  translations: { 'es-ES': { 'f.f_name.label': 'Su nombre', 'f.f_name.help': 'Como en su tarjeta', 'f.f_pet.label': 'Mascota', 'f.f_pet.opt.cat': 'Gato', 'f.f_pet.opt.dog': 'Perro', 's.success_message': '¡Gracias!' } },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Your name', help: 'As on your badge', rules: { required: true } },
    { key: 'f_email', type: 'email', label: 'Email' },
    { key: 'f_pet', type: 'radios', label: 'Pet', options: { static: [{ value: 'cat', label: 'Cat' }, { value: 'dog', label: 'Dog' }] }, rules: { required: true } },
  ], events: { load: [], submit: [
    { key: 'a_save', type: 'save_data', config: { target: 'submissions' } },
    { key: 'a_conf', type: 'confirm_email', when: { action: 'a_save', is: 'success' }, config: { to_field: 'f_email' } },
  ] } }],
});

await step('language tabs', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-lang'));
  const nav = g.locator('#sf-t-lang nav.sf-langs');
  ok(await nav.count() === 1 && !!(await nav.getAttribute('aria-label')), 'the tabs are a labelled <nav>');
  const tabs = await nav.locator('a').evaluateAll((a) => a.map((x) => `${x.textContent}|${x.getAttribute('lang')}|${x.getAttribute('hreflang')}|${x.getAttribute('aria-current')}`));
  ok(tabs.length === 4 && tabs[0].startsWith('English|en|en|true'), `each tab names its language in that language (${tabs.join(' ; ')})`);
  ok(tabs.some((t) => t.startsWith('Español|es|es|')), 'Español');
  ok(tabs.some((t) => /\|null\|/.test(t)), 'a fun pack has no hreflang (it isn\'t a real language)');
  await L.axe(g, 'the language tabs', '#sf-t-lang');
});

await step('switching keeps answers and translates', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-lang'));
  await L.fill(g, { f_name: 'Inky', f_email: 'inky@example.test' });
  await Promise.all([g.waitForNavigation(), g.locator('[data-sf-lang="es-ES"]').click()]);
  ok(await g.inputValue('[name="sf[f_name]"]') === 'Inky' && await g.inputValue('[name="sf[f_email]"]') === 'inky@example.test', 'what was typed is still there');
  const t = await L.text(g, '#sf-t-lang');
  ok(t.includes('Su nombre') && t.includes('Como en su tarjeta') && t.includes('Gato') && t.includes('Perro'), 'the form\'s own translations: label, help and choices');
  ok(t.includes('Email'), 'a missing translation falls back to as written');
  ok(await g.locator('#sf-t-lang[lang="es"], #sf-t-lang > [lang="es"], .squidform[lang="es"]').count() >= 1, 'lang="es" on the form');
  ok(/Enviar|Siguiente/.test(await g.locator('button[name="sf_nav"]').first().textContent()), 'SquidForm\'s own words are in Spanish');
  await L.rawNext(g);
  ok(/Mascota/.test(await L.text(g, '.sf-error-summary')) && !/required|is needed/i.test(await L.text(g, '.sf-error-summary')), `the server's error messages are in Spanish (${(await L.text(g, '.sf-error-summary')).slice(0, 80)})`);
  L.clearMail();
  await L.fill(g, { f_pet: 'cat' });
  const sent = await L.submit(g);
  ok(sent, `sent ${sent ? '' : await L.text(g, '.sf-error-summary').catch(() => '')}`);
  ok((await L.text(g, '#sf-t-lang')).includes('¡Gracias!'), 'the thank-you message is translated');
  const s = L.latest('t-lang');
  ok(s.data.f_pet === 'cat', 'the stored answer is the value, never the translated label');
  ok(L.sql(`SELECT language FROM jos_squidform_submissions WHERE id=${s.id}`) === 'es-ES', 'the submission records the language it was sent in');
  const m = (await L.waitMail(1))[0];
  ok(m && /Gracias|gracias|Su nombre|Gato/.test(`${m.subject} ${m.html || m.text}`), `the confirmation email is in Spanish (${m?.subject})`);
});

await step('the choice is remembered, and the fun packs', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-lang', '&sf_lang=fr-FR'));
  await g.goto(L.formUrl('t-lang'));
  ok(await g.locator('[data-sf-lang="fr-FR"][aria-current="true"]').count() === 1, 'the language chosen stays for the session');
  await g.goto(L.formUrl('t-lang', '&sf_lang=en-XF'));
  ok(/meow|mrow|purr|prr/i.test(await g.locator('button[name="sf_nav"]').first().textContent()), 'the Feline pack speaks cat');
  await g.goto(L.formUrl('t-lang', '&sf_lang=ja-JP'));
  ok(await g.locator('[data-sf-lang="en-XF"][aria-current="true"]').count() === 1, 'a language the form doesn\'t offer is ignored');
});

await step('a language the site turned off', async () => {
  L.params({ languages: ['en-GB', 'es-ES'] });
  const g = await L.site();
  await g.goto(L.formUrl('t-lang'));
  const tabs = await g.locator('#sf-t-lang nav.sf-langs a').evaluateAll((a) => a.map((x) => x.dataset.sfLang));
  ok(tabs.join() === 'en-GB,es-ES', `only languages the site offers are shown (${tabs.join(', ')})`);
});

L.sql(`UPDATE jos_extensions SET params='${L.esc(was)}' WHERE element='com_squidform'`);
if (!process.env.KEEP) L.deleteForms('t-lang');
await L.close();
L.finish();
