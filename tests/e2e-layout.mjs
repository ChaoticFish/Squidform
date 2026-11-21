// Layout: the 12-column grid (fields side by side on a wide screen, stacked
// on a phone, no sideways scrolling), boxed and collapsible sections (a
// collapsed section opens when something in it needs fixing), text blocks
// (allowed HTML only), dividers, choices in columns, and the page steps of a
// multi-page form.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
L.makeForm('t-lay', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [
    { key: 'p1', title: 'First things', fields: [
      { key: 's_box', type: 'section', label: 'Boxed', style: 'box', fields: [
        { key: 'f_a', type: 'text', label: 'Left half', width: 6 },
        { key: 'f_b', type: 'text', label: 'Right half', width: 6 }] },
      { key: 's_more', type: 'section', label: 'More details', collapsible: true, collapsed: true, fields: [
        { key: 'f_c', type: 'text', label: 'Hidden away', rules: { required: true } }] },
      { key: 'h_1', type: 'html', tag: 'p', content: 'Hello <strong>there</strong> <a href="javascript:alert(1)">bad link</a><script>alert(1)</script><b onclick="alert(1)">bold</b>' },
      { key: 'd_1', type: 'divider' },
      { key: 'f_r', type: 'radios', label: 'Pick one', columns: 3, options: { static: ['a', 'b', 'c', 'd', 'e', 'f'] } },
    ], events: { load: [], submit: [] } },
    { key: 'p2', title: 'Second things', fields: [{ key: 'f_d', type: 'text', label: 'Last bit' }],
      events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } },
  ],
});
const box = (p, sel) => p.locator(sel).first().boundingBox();

await step('wide screen', async () => {
  const g = await L.site(null, L.PASS, { viewport: { width: 1280, height: 900 } });
  await g.goto(L.formUrl('t-lay'));
  const [a, b] = [await box(g, '[name="sf[f_a]"]'), await box(g, '[name="sf[f_b]"]')];
  ok(Math.abs(a.y - b.y) < 2 && b.x > a.x + a.width, 'two half-width fields sit side by side');
  ok(await g.locator('fieldset.sf-section-box > legend:has-text("Boxed")').count() === 1, 'a boxed section is a fieldset with its title as the legend');
  // Columns read down, then across (a b | c d | e f).
  const r = await Promise.all(['a', 'b', 'c', 'e'].map((v) => box(g, `[name="sf[f_r]"][value="${v}"]`)));
  ok(r[1].y > r[0].y + 5 && Math.abs(r[0].y - r[2].y) < 2 && Math.abs(r[2].y - r[3].y) < 2 && r[2].x > r[0].x && r[3].x > r[2].x, 'choices in three columns, reading down each column');
  ok(!(await g.locator('[name="sf[f_c]"]').isVisible()), 'a collapsed section starts closed');
  ok(await g.locator('details.sf-section-collapsible > summary').count() === 1, 'it opens with a real <summary> (keyboard and screen readers know it)');
  const h = await g.locator('[data-sf-key="h_1"]').innerHTML();
  ok(h.includes('<strong>there</strong>') && !/script|onclick|javascript:/i.test(h), `a text block keeps allowed HTML only (${h})`);
  ok(await g.locator('hr.sf-divider').count() === 1, 'a divider');
  await L.axe(g, 'the layout page', 'form[data-sf-alias="t-lay"]');
});

await step('a collapsed section with a problem opens', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-lay'));
  await L.next(g);
  ok(/Hidden away/.test(await L.text(g, '.sf-error-summary')), 'a required field in a collapsed section still has to be filled in');
  ok(await g.locator('details.sf-section-collapsible[open]').count() === 1 && await g.locator('[name="sf[f_c]"]').isVisible(), 'and the section opens to show it');
  const link = g.locator('.sf-error-summary a').first();
  if (await link.count()) { await link.click(); ok(await g.evaluate(() => document.activeElement?.name) === 'sf[f_c]', 'the error\'s link takes you to the field'); }
});

await step('phone screen', async () => {
  const g = await L.site(null, L.PASS, { viewport: { width: 375, height: 800 }, isMobile: true });
  await g.goto(L.formUrl('t-lay'));
  const [a, b] = [await box(g, '[name="sf[f_a]"]'), await box(g, '[name="sf[f_b]"]')];
  ok(b.y > a.y + a.height, 'on a phone the half-width fields stack');
  const sideways = await g.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(sideways <= 1, `no sideways scrolling on a phone (${sideways}px)`);
  const r = await Promise.all(['a', 'c'].map((v) => box(g, `[name="sf[f_r]"][value="${v}"]`)));
  ok(r[1].y > r[0].y + 5 && Math.abs(r[1].x - r[0].x) < 2, 'choices fall back to one column on a phone');
  await g.setViewportSize({ width: 320, height: 800 });
  ok(await g.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'nor at 320px (WCAG reflow)');
});

await step('page steps', async () => {
  const g = await L.site();
  await g.goto(L.formUrl('t-lay'));
  ok(/^Step 1 of 2: First things - Test t-lay/.test(await g.title()), `the browser title says the step and the form (${await g.title()})`);
  const t = await L.text(g, '#sf-t-lay');
  ok(/First things/.test(t), 'the page\'s title shows');
  ok(/1\D+2/.test(t) || await g.locator('[aria-current="step"], [aria-current="true"]').count() >= 1, 'it says which page of how many');
  await g.locator('details.sf-section-collapsible > summary').click();
  await L.fill(g, { f_c: 'Found it' });
  await L.next(g);
  ok(/Second things/.test(await L.text(g, '#sf-t-lay')), 'Next shows page two\'s title');
  ok(/^Step 2 of 2: Second things - Test t-lay/.test(await g.title()), `each page has its own browser title (${await g.title()})`);
});

L.deleteForms('t-lay');
await L.close();
L.finish();
