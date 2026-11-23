// Dark mode in Preview (0.15.0+): the Light and Dark buttons switch the form
// without changing the admin's own theme (and say which is on), every look
// reads in both (axe, contrast included), and the choice is remembered in
// this browser. Admin screens in dark are in a11y-dark.mjs; the site's looks
// in a dark template are in e2e-looks.mjs.
import * as L from './lib.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const id = L.makeForm('t-dark', 'core.json');
const a = await L.admin();
const frame = () => a.frameLocator('#sf-preview-dialog iframe');
const openPreview = async () => {
  await a.goto(L.adminUrl(`task=form.edit&id=${id}`));
  await a.waitForSelector('#sf-builder-root:not([hidden])');
  await a.getByRole('button', { name: 'Preview', exact: true }).click();
  await a.waitForSelector('#sf-preview-dialog[open]');
  await frame().locator('form[data-sf-alias]').waitFor({ timeout: 15000 });
};
const theme = () => frame().locator('html').getAttribute('data-bs-theme');
const close = async () => { if (await a.locator('#sf-preview-dialog[open]').count()) await a.keyboard.press('Escape'); await a.locator('#toolbar-cancel button, button.button-cancel').first().click(); await a.waitForLoadState(); };

await step('Light and Dark', async () => {
  await openPreview();
  const adminTheme = await a.evaluate(() => document.documentElement.getAttribute('data-bs-theme') || 'light');
  await frame().locator('[data-sf-mode="dark"]').click();
  ok(await theme() === 'dark', 'Dark switches the form to dark');
  await frame().locator('[data-sf-mode="dark"]').focus();
  await a.keyboard.press('Escape');
  ok(!(await a.locator('#sf-preview-dialog[open]').count()), 'Escape closes Preview, even with focus inside it');
  await a.getByRole('button', { name: 'Preview', exact: true }).click();
  await frame().locator('form[data-sf-alias]').waitFor({ timeout: 15000 });
  ok(await frame().locator('[data-sf-mode="dark"]').getAttribute('aria-pressed') === 'true' && await frame().locator('[data-sf-mode="light"]').getAttribute('aria-pressed') === 'false', 'the buttons say which is on');
  ok(await a.evaluate(() => document.documentElement.getAttribute('data-bs-theme') || 'light') === adminTheme, 'the admin\'s own theme doesn\'t change');
  await close();
  await openPreview();
  ok(await theme() === 'dark', 'the choice is remembered next time');
  await frame().locator('[data-sf-mode="light"]').click();
  ok(await theme() === 'light', 'Light switches back');
  await close();
});

await step('every look, light and dark', async () => {
  await openPreview();
  for (const look of ['simple', 'elegant', 'chaotic', 'civic', 'friendly']) {
    const link = frame().locator(`[data-sf-look="${look}"]`);
    await link.click();
    await frame().locator(`[data-sf-look="${look}"][aria-current="true"]`).waitFor({ timeout: 15000 });
    for (const mode of ['light', 'dark']) {
      await frame().locator(`[data-sf-mode="${mode}"]`).click();
      await a.waitForTimeout(600); // colours fade
      await L.axe(a, `Preview: ${look}, ${mode}`, '#sf-preview-dialog');
    }
  }
  await frame().locator('[data-sf-mode="light"]').click();
  await close();
});

L.deleteForms('t-dark');
await L.close();
L.finish();
