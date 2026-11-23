// Looks (0.14.1+): Simple, Elegant, Chaotic, Civic and Friendly, per form or
// the site's default, each in the template's light and dark mode: the
// classes, the theme CSS only when needed (and only from this site), and no
// axe problems (contrast included) on a form with most kinds of field,
// including its error summary.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const fields = [
  { key: 'f_name', type: 'text', label: 'Name', help: 'As on your badge', rules: { required: true } },
  { key: 'f_email', type: 'email', label: 'Email' },
  { key: 'f_pick', type: 'radios', label: 'Pick one', options: { static: ['One', 'Two'] } },
  { key: 'f_ticks', type: 'checkboxes', label: 'Tick some', counter: true, rules: { max_selected: 2 }, options: { static: ['A', 'B', 'C'] } },
  { key: 'f_sel', type: 'select', label: 'Choose', options: { static: ['X', 'Y'] } },
  { key: 'f_rate', type: 'rating', label: 'Rate it', max: 5 },
  { key: 'f_note', type: 'textarea', label: 'Note', counter: true, rules: { max_length: 200 } },
  { key: 's_box', type: 'section', label: 'A boxed section', style: 'box', fields: [{ key: 'f_in', type: 'text', label: 'Inside' }] },
];
// Cassiopeia has no dark mode of its own, so dark mode here is what a dark-mode template does:
// [data-bs-theme="dark"] on <html> and Bootstrap's dark page colours.
const darken = (g) => g.evaluate(() => { document.documentElement.setAttribute('data-bs-theme', 'dark'); const st = document.createElement('style'); st.textContent = 'body,.site-grid,.container-component,main{background:#212529!important;color:#dee2e6!important}'; document.head.appendChild(st); });
const looks = ['simple', 'elegant', 'chaotic', 'civic', 'friendly'];

for (const look of looks) {
  await step(`${look}`, async () => {
    L.makeForm('t-look', { schema: 1, settings: { success_message: 'ok', look }, pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit: [] } }] });
    for (const mode of ['light', 'dark']) {
      const g = await L.site(null, L.PASS, { colorScheme: mode });
      const css = [];
      g.on('request', (r) => { if (/\.css(\?|$)/.test(r.url())) css.push(r.url()); });
      await g.goto(L.formUrl('t-look'));
      if (mode === 'dark') await darken(g);
      const cls = await g.getAttribute('#sf-t-look', 'class');
      if (mode === 'light') {
        ok(look === 'simple' ? !/sf-theme-/.test(cls) : cls.includes(`sf-theme-${look}`), `the form's box gets its look (${cls})`);
        ok(look === 'simple' ? !css.some((u) => u.includes('squidform-themes')) : css.some((u) => u.includes('squidform-themes')), 'the theme CSS loads only for a look that needs it');
        ok(css.every((u) => u.startsWith(L.BASE)), 'every stylesheet comes from this site');
      }
      await L.axe(g, `${look}, ${mode}`, '#sf-t-look');
      await L.rawNext(g);
      if (mode === 'dark') await darken(g);
      await L.axe(g, `${look}, ${mode}, with the error summary`, '#sf-t-look');
      const focus = await g.evaluate(() => { const i = document.querySelector('[name="sf[f_email]"]'); i.focus(); const s = getComputedStyle(i); return s.outlineStyle !== 'none' || s.boxShadow !== 'none'; });
      ok(focus, `${mode}: the field with focus shows it`);
      await g.context().close();
    }
  });
}

await step('the site\'s default look', async () => {
  L.makeForm('t-look', { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit: [] } }] });
  L.params({ default_look: 'civic' });
  const g = await L.site();
  await g.goto(L.formUrl('t-look'));
  ok((await g.getAttribute('#sf-t-look', 'class')).includes('sf-theme-civic'), 'a form with no look of its own uses the site\'s default');
  L.saveForm('t-look', { schema: 1, settings: { success_message: 'ok', look: 'plain' }, pages: [{ key: 'p1', title: 'One', fields, events: { load: [], submit: [] } }] });
  await g.goto(L.formUrl('t-look'));
  ok(!/sf-theme-/.test(await g.getAttribute('#sf-t-look', 'class')), '"plain" (0.14.1\'s name) still means Simple');
  L.params({ default_look: 'simple' });
});

L.deleteForms('t-look');
await L.close();
L.finish();
