// The sample forms (Start from…): every one passes the definition check,
// renders on the site with no PHP warnings, passes axe on its first page,
// and its JSON stays plain (no PHP, no outside addresses).
import * as L from './lib.mjs';
import fs from 'fs';
import path from 'path';
const { ok, step } = L;

await L.launch();
L.testDefaults();
const LOG = process.env.PHPLOG || (L.BASE.includes(':8081') ? 'php-errors-54.log' : 'php-errors.log');
const logFile = path.join(path.dirname(L.MAILDIR), LOG);
const logSize = () => (fs.existsSync(logFile) ? fs.statSync(logFile).size : 0);
const samples = fs.readdirSync(L.EXAMPLES).filter((f) => f.endsWith('.json')).map((f) => f.replace(/\.json$/, ''));

await step('the samples are all here', async () => {
  ok(samples.length >= 13, `${samples.length} samples (${samples.join(', ')})`);
});

for (const name of samples) {
  await step(`sample: ${name}`, async () => {
    const raw = fs.readFileSync(path.join(L.EXAMPLES, `${name}.json`), 'utf8');
    ok(!/<\?php|\bphp\b.*handler|https?:\/\/(?!www\.w3\.org)[a-z0-9]/i.test(raw.replace(/"(help|content|terms|intro|disclosure|statement)":\s*"[^"]*"/g, '')), 'plain JSON: no PHP, no outside addresses');
    const d = JSON.parse(raw);
    if (d.settings) d.settings.captcha = 'none';
    const alias = `t-sample-${name}`;
    const before = logSize();
    L.makeForm(alias, d);
    ok(true, 'passes the definition check');
    const notes = JSON.parse(L.sf('notes', path.join(L.EXAMPLES, `${name}.json`)));
    ok(notes.length === 0, `nothing in its emails or messages would go out as written${notes.length ? ` (${notes.map((n) => n.message).join(' / ').slice(0, 200)})` : ''}`);
    const g = await L.site('admin');
    const r = await g.goto(L.formUrl(alias));
    ok(r.status() === 200 && await g.locator(`form[data-sf-alias="${alias}"]`).count() === 1, 'renders on the site');
    const added = logSize() > before ? fs.readFileSync(logFile, 'utf8').slice(before) : '';
    ok(!/PHP (Warning|Notice|Deprecated|Fatal)/.test(added), `no PHP warnings${added ? ` (${added.slice(0, 200)})` : ''}`);
    await L.axe(g, `${name}, first page`, `#sf-${alias}`);
    L.deleteForms(alias);
  });
}

await L.close();
L.finish();
