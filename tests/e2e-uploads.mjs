// File uploads (0.9.0+): a good file is checked, stored under a random name
// in a folder with deny-all files, kept pending until the form is saved, then
// attached. Refused: a type the field doesn't allow, a type that's never
// allowed (php, html, svg… even when someone adds it to Options), content
// that isn't what its name says, too big, too many. Downloads only for people
// who may see the submission, always as attachments. The virus scanner (a
// stand-in ClamAV daemon here, using a harmless marker, never a test virus):
// infected files refused and logged; a scanner that's down refuses files.
import * as L from './lib.mjs';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { startFakeClamd } from './tools/fakeclamd.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults({ upload_types: 'pdf,png,txt,php,html,svg', upload_max_mb: 10, upload_random_names: 1, scan_mode: 'none' });
L.makeForm('t-up', {
  schema: 1, settings: { success_message: 'ok' },
  pages: [{ key: 'p1', title: 'One', fields: [
    { key: 'f_name', type: 'text', label: 'Name' },
    { key: 'f_file', type: 'upload', label: 'Attach', accept: ['pdf', 'png'], max_files: 2, max_mb: 1 },
  ], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }],
});
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sf-up-'));
const file = (name, content) => { const p = path.join(dir, name); fs.writeFileSync(p, content); return p; };
const pdf = (extra = '') => `%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n${extra}\ntrailer<</Root 1 0 R>>\n%%EOF\n`;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const good = file('report.pdf', pdf());
const pic = file('photo.png', png);
let g = await L.site();
const errs = async () => ((await g.locator('.sf-error-summary').count()) ? L.text(g, '.sf-error-summary') : '');
const pending = () => Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_submission_files WHERE form_id=${L.formId('t-up')} AND submission_id=0`));
// Each try starts in a fresh browser: a form remembers files already uploaded for the session.
const sendWith = async (...files) => {
  await g.context().close();
  g = await L.site();
  await g.goto(L.formUrl('t-up'));
  await L.fill(g, { f_name: 'Uploader' });
  await g.setInputFiles('[name="sf[f_file][new][]"]', files);
  await L.next(g);
  return errs();
};

await step('a good file', async () => {
  const before = Number(L.sql('SELECT COUNT(*) FROM jos_squidform_submission_files'));
  ok(await sendWith(good, pic) === '', 'a PDF and a PNG are accepted');
  const s = L.latest('t-up');
  const rows = L.sql(`SELECT CONCAT(original_name,'|',stored_name,'|',path,'|',scan,'|',sha256) FROM jos_squidform_submission_files WHERE submission_id=${s.id} ORDER BY id`).split('\n');
  ok(rows.length === 2 && Number(L.sql('SELECT COUNT(*) FROM jos_squidform_submission_files')) === before + 2, 'both are recorded and attached to the submission');
  const [orig, stored, p] = rows[0].split('|');
  ok(orig === 'report.pdf' && stored !== 'report.pdf' && !stored.includes('report'), `stored under a random name (${stored})`);
  ok(Array.isArray(s.data.f_file) && s.data.f_file.length === 2, 'the answer lists the files');
  const folder = path.join(L.SITE, 'squidform-files');
  ok(fs.existsSync(path.join(folder, '.htaccess')) && /deny|Require all denied/i.test(fs.readFileSync(path.join(folder, '.htaccess'), 'utf8')), 'the folder has a deny-all .htaccess');
  ok(fs.existsSync(path.join(folder, 'web.config')), '…and a web.config for IIS');
  ok(fs.readdirSync(folder, { recursive: true }).some((f) => String(f).endsWith(stored)) || p.includes(stored), 'the file is in the folder');
  ok(pending() === 0, 'nothing is left pending once the form is saved');
});

await step('refused files', async () => {
  ok(/type|allowed/i.test(await sendWith(file('notes.txt', 'hello'))), 'a type Options allows but the field doesn\'t is refused');
  // A form can't even ask for them, and a field with no list of its own still never takes them.
  let refusedDef = '';
  try { L.makeForm('t-up-bad', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_f', type: 'upload', label: 'F', accept: ['php'] }], events: { load: [], submit: [] } }] }); } catch (e) { refusedDef = String(e.stderr || e.message); }
  ok(/never accepts/i.test(refusedDef), 'a form asking for .php files is refused when it\'s saved');
  L.makeForm('t-up-any', { schema: 1, settings: {}, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_file', type: 'upload', label: 'Any', max_files: 5 }], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }] });
  for (const [name, body] of [['shell.php', '<?php echo 1;'], ['page.html', '<script>alert(1)</script>'], ['pic.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>']]) {
    await g.goto(L.formUrl('t-up-any'));
    await g.setInputFiles('[name="sf[f_file][new][]"]', file(name, body));
    await L.next(g);
    ok((await errs()) !== '', `${name.split('.').pop()} is never accepted, even when listed in Options`);
  }
  L.deleteForms('t-up-any');
  ok(/PDF|content|isn/i.test(await sendWith(file('sneaky.pdf', '<?php system($_GET["c"]); ?>'))), 'a "PDF" that\'s really PHP is refused');
  ok(/PNG|content|isn/i.test(await sendWith(file('fake.png', pdf()))), 'a "PNG" that\'s really a PDF is refused');
  ok(/size|big|1 MB|MB/i.test(await sendWith(file('big.pdf', pdf('%' + 'x'.repeat(1.2 * 1048576))))), 'a file over the field\'s 1 MB is refused');
  ok(/2|many/i.test(await sendWith(good, pic, file('third.pdf', pdf()))), 'a third file when two are allowed is refused');
  ok(/\.\.|\//.test(L.sql("SELECT GROUP_CONCAT(original_name) FROM jos_squidform_submission_files")) === false, 'no stored name keeps a path');
});

await step('downloads', async () => {
  const s = L.latest('t-up', { where: [['f_name', '=', 'Uploader']] });
  const uid = L.sql(`SELECT uid FROM jos_squidform_submission_files WHERE submission_id=${s.id} ORDER BY id LIMIT 1`);
  const url = `${L.BASE}/index.php?option=com_squidform&task=file.download&uid=${uid}`;
  const guest = await L.site();
  const r = await guest.request.get(url);
  ok(r.status() === 404 || r.status() === 403, `a guest can't download it (${r.status()})`);
  const ad = await L.site('admin');
  const r2 = await ad.request.get(url);
  ok(r2.status() === 200 && (await r2.body()).toString().startsWith('%PDF'), 'someone who may see the submission can');
  ok(/^attachment;/.test(r2.headers()['content-disposition'] || '') && r2.headers()['x-content-type-options'] === 'nosniff', 'always as an attachment, with nosniff');
  ok(Number(L.sql(`SELECT COUNT(*) FROM jos_squidform_audit WHERE submission_id=${s.id} AND action='file_download'`)) >= 1, 'the download is logged');
  const r3 = await ad.request.get(`${L.BASE}/index.php?option=com_squidform&task=file.download&uid=${'0'.repeat(32)}`);
  ok(r3.status() === 404, 'an unknown file is "not found"');
});

await step('virus scanner', async () => {
  const clamd = await startFakeClamd(33100);
  L.params({ scan_mode: 'clamd', scan_address: 'tcp://127.0.0.1:33100', scan_fail: 'reject' });
  const e0 = await sendWith(good);
  ok(e0 === '', `a clean file passes the scanner ${e0}`);
  ok(L.sql(`SELECT scan FROM jos_squidform_submission_files ORDER BY id DESC LIMIT 1`) === 'clean', 'and is marked clean');
  const n = Number(L.sql('SELECT COUNT(*) FROM jos_squidform_submission_files'));
  ok(/virus|infect|harm/i.test(await sendWith(file('bad.pdf', pdf('% SQUIDFORM-TEST-INFECTED')))), 'a file the scanner flags is refused');
  ok(Number(L.sql('SELECT COUNT(*) FROM jos_squidform_submission_files')) === n, 'and isn\'t kept');
  ok(Number(L.sql("SELECT COUNT(*) FROM jos_squidform_audit WHERE action='upload_infected'")) >= 1, 'and it\'s logged');
  await new Promise((r) => clamd.close(r));
  ok(/scan|check/i.test(await sendWith(good)), 'with the scanner down, files are refused (the default)');
  L.params({ scan_mode: 'none' });
});

await step('accessible', async () => {
  await g.goto(L.formUrl('t-up'));
  await L.axe(g, 'an upload field', 'form[data-sf-alias="t-up"]');
  ok(/PDF|pdf/.test(await L.text(g, 'form[data-sf-alias="t-up"]')) && /1 MB/.test(await L.text(g, 'form[data-sf-alias="t-up"]')), 'the field says which types and how big');
});

L.params({ upload_types: 'pdf,jpg,jpeg,png,gif,webp,txt,csv,doc,docx,xls,xlsx,ppt,pptx,odt,ods', scan_mode: 'none' });
L.deleteForms('t-up');
fs.rmSync(dir, { recursive: true, force: true });
await L.close();
L.finish();
