// The optional scanner add-on (plg_squidform_scannertools): Check this server
// sees it; "Test with the EICAR file" reaches the daemon and the program and
// is reported found; "ClamAV program" mode scans uploads (clean kept,
// infected refused), runs only a program named clamdscan or clamscan, with
// its fixed arguments, and the file last; and with the add-on switched off,
// the EICAR button is off and program mode says the add-on is needed.
// Stand-ins for ClamAV are used (tools/fakeclamd.mjs, tools/fake-clamav/):
// they know the EICAR file only by its SHA-256.
import * as L from './lib.mjs';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { startFakeClamd } from './tools/fakeclamd.mjs';
const { ok, step } = L;

await L.launch();
L.testDefaults({ upload_types: 'pdf,png', scan_mode: 'none', scan_fail: 'reject' });
const FAKE = path.join(L.HERE, 'tools', 'fake-clamav');
const enable = (on) => L.sql(`UPDATE jos_extensions SET enabled=${on ? 1 : 0} WHERE element='scannertools' AND folder='squidform'`);
ok(Number(L.sql("SELECT COUNT(*) FROM jos_extensions WHERE element='scannertools' AND folder='squidform'")) === 1, 'the add-on is installed on this test site');
enable(true);
L.makeForm('t-scan', { schema: 1, settings: { success_message: 'ok' }, pages: [{ key: 'p1', title: 'One', fields: [{ key: 'f_file', type: 'upload', label: 'Attach', accept: ['pdf'] }], events: { load: [], submit: [{ key: 'a_save', type: 'save_data', config: { target: 'submissions' } }] } }] });
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sf-scan-'));
const pdf = (extra = '') => `%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n${extra}\n%%EOF\n`;
const clean = path.join(dir, 'clean.pdf'); fs.writeFileSync(clean, pdf());
const bad = path.join(dir, 'marked.pdf'); fs.writeFileSync(bad, pdf('% SQUIDFORM-TEST-INFECTED'));
const upload = async (file) => {
  const g = await L.site();
  await g.goto(L.formUrl('t-scan'));
  await g.setInputFiles('[name="sf[f_file][new][]"]', file);
  await L.next(g);
  const e = (await g.locator('.sf-error-summary').count()) ? await L.text(g, '.sf-error-summary') : '';
  await g.context().close();
  return e;
};
const a = await L.admin();
const eicar = async () => {
  await a.goto(L.adminUrl('view=servercheck'));
  await Promise.all([a.waitForNavigation(), a.click('#sf-sc-eicar')]);
  return L.text(a, '#system-message-container');
};

await step('Check this server sees the add-on', async () => {
  await a.goto(L.adminUrl('view=servercheck'));
  ok(/Scanner add-on\s*Installed/.test(await L.text(a, '#content, main')), 'it says the add-on is installed');
});

await step('daemon mode: the EICAR test', async () => {
  const d = await startFakeClamd(33101);
  L.params({ scan_mode: 'clamd', scan_address: 'tcp://127.0.0.1:33101' });
  await a.goto(L.adminUrl('view=servercheck'));
  ok(!(await a.locator('#sf-sc-eicar').isDisabled()), 'the EICAR button is on');
  ok(/Eicar-Signature/.test(await eicar()), 'the daemon finds the test file');
  ok(!fs.readdirSync(L.SITE, { recursive: true }).some((f) => /eicar/i.test(String(f))), 'nothing named like it was written to the site');
  await new Promise((r) => d.close(r));
});

await step('program mode', async () => {
  L.params({ scan_mode: 'command', scan_command: path.join(FAKE, 'clamdscan') });
  ok(/Eicar-Signature/.test(await eicar()), 'the EICAR test reaches the program');
  ok(await upload(clean) === '', 'a clean upload is kept');
  ok(L.sql('SELECT scan FROM jos_squidform_submission_files ORDER BY id DESC LIMIT 1') === 'clean', '…and marked clean');
  const args = JSON.parse(fs.readFileSync(path.join(FAKE, 'last-args.json'), 'utf8'));
  ok(args.slice(0, 4).join(' ') === '--no-summary --stdout --fdpass --' && !args.at(-1).includes('clean.pdf'), `fixed arguments, then SquidForm's own copy of the file (${args.slice(0, 4).join(' ')} …)`);
  ok(/virus|infect|harm/i.test(await upload(bad)), 'an upload the program flags is refused');
  L.params({ scan_command: path.join(FAKE, 'notclam') });
  ok(/scan|check/i.test(await upload(clean)), 'a program that isn\'t clamdscan or clamscan is never run (the upload is refused: "if scanning fails" is Reject)');
  L.params({ scan_command: '/bin/sh' });
  ok(/scan|check/i.test(await upload(clean)), '…nor a shell');
});

await step('switched off', async () => {
  enable(false);
  L.params({ scan_mode: 'command', scan_command: path.join(FAKE, 'clamdscan') });
  await a.goto(L.adminUrl('view=servercheck'));
  ok(await a.locator('#sf-sc-eicar').isDisabled(), 'the EICAR button is off');
  ok(/Not installed/.test(await L.text(a, '#content, main')), 'Check this server says it\'s optional and not there');
  ok(/scan|check/i.test(await upload(clean)), 'program mode refuses uploads (it needs the add-on)');
  enable(true);
});

L.params({ scan_mode: 'none', scan_command: '' });
L.deleteForms('t-scan');
fs.rmSync(dir, { recursive: true, force: true });
await L.close();
L.finish();
