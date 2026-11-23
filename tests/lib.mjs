// Shared helpers for SquidForm's tests (Playwright + axe, a MariaDB test site).
//
// Environment:
//   BASE        site address (default http://127.0.0.1:8080)
//   SITE        the site's folder (default /home/claude/joomla/j6, or j54 for :8081)
//   DB          its database (default j6, or j54 for :8081); table prefix jos_
//   ADMIN_PASS  the password of the "admin" Super User
//   MAILDIR     where the test sendmail writes messages
//   CHROMIUM    optional browser path
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const is54 = BASE.includes(':8081');
export const SITE = process.env.SITE || (is54 ? '/home/claude/joomla/j54' : '/home/claude/joomla/j6');
export const DB = process.env.DB || (is54 ? 'j54' : 'j6');
export const PASS = process.env.ADMIN_PASS || 'SquidTest!2026x';
export const MAILDIR = process.env.MAILDIR || '/tmp/claude-0/-home-claude/3e9e5fa8-6400-57eb-a8e3-9230a399975a/scratchpad/mail';
export const HERE = path.dirname(new URL(import.meta.url).pathname);
export const FIXTURES = path.join(HERE, 'fixtures');
export const EXAMPLES = path.join(HERE, '../com_squidform/admin/examples');
export const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

// ------------------------------------------------------------------ results
let passed = 0;
let failed = 0;
const failures = [];
export function ok(cond, msg) {
  if (cond) { passed++; console.log(`PASS  ${msg}`); } else { failed++; failures.push(msg); console.log(`FAIL  ${msg}`); }
  return !!cond;
}
export async function step(what, fn) {
  try { await fn(); } catch (e) { ok(false, `${what}: ran (${String(e.message).split('\n')[0]})`); }
}
export function finish() {
  console.log(`${passed} pass, ${failed} fail`);
  console.log(failed ? `${failed} FAILED` : 'ALL PASSED');
  process.exitCode = failed ? 1 : 0;
}
export const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// ------------------------------------------------------------------ database and SquidForm's own code
export function sql(q) {
  return execFileSync('mysql', ['-N', '--raw', '-B', DB], { input: q }).toString().replace(/\n$/, '');
}
export const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "''");
export function sf(...args) {
  return execFileSync('php', [path.join(HERE, 'tools/sf.php'), SITE, ...args.map(String)], { stdio: ['ignore', 'pipe', 'pipe'] }).toString().trim();
}

/** Makes a form from a definition (object, JSON string or examples/<name>.json). Replaces any form with that alias. */
export function makeForm(alias, def, { title, published = 1, access = 1 } = {}) {
  deleteForms(alias);
  let json = def;
  if (typeof def === 'string' && /^[a-z0-9_-]+$/.test(def)) json = fs.readFileSync(path.join(EXAMPLES, `${def}.json`), 'utf8');
  else if (typeof def === 'string' && def.endsWith('.json')) json = fs.readFileSync(path.isAbsolute(def) ? def : path.join(HERE, 'fixtures', def), 'utf8');
  else if (typeof def !== 'string') json = JSON.stringify(def);
  const file = path.join(os.tmpdir(), `sf-${alias}-${process.pid}.json`);
  fs.writeFileSync(file, json);
  const out = JSON.parse(sf('create', alias, title || `Test ${alias}`, file, published, access));
  fs.unlinkSync(file);
  return out.id;
}
/** Posts an admin task from the page that's open, with that page's session token (as the toolbar does). */
export async function adminTask(p, task, fields = {}) {
  const token = await p.evaluate(() => [...document.querySelectorAll('input[type=hidden][value="1"]')].map((i) => i.name).find((n) => /^[0-9a-f]{32}$/.test(n)));
  if (!token) throw new Error('no session token on the page');
  const r = await p.request.post(adminUrl(''), { form: { option: 'com_squidform', task, [token]: '1', ...fields } });
  return r;
}
/** Sets a form's own permissions, e.g. { 'squidform.approve': { [groupId]: 1 } } (replaces them). */
export function formRules(alias, rules) {
  sql(`UPDATE jos_assets a JOIN jos_squidform_forms f ON f.asset_id=a.id SET a.rules='${esc(JSON.stringify(rules))}' WHERE f.alias='${esc(alias)}'`);
}
/** Reads or replaces SquidForm's own permissions (Options → Permissions). */
export function componentRules(rules) {
  const was = JSON.parse(sql("SELECT rules FROM jos_assets WHERE name='com_squidform'") || '{}');
  if (rules !== undefined) sql(`UPDATE jos_assets SET rules='${esc(JSON.stringify(rules))}' WHERE name='com_squidform'`);
  return was;
}
export const groupId = (title) => Number(sql(`SELECT id FROM jos_usergroups WHERE title='${esc(title)}'`));
/** Saves a new definition for an existing form (as Save in the admin: checks, a new version, new columns). */
export function saveForm(alias, def, note = '') {
  const file = path.join(os.tmpdir(), `sf-save-${alias}-${process.pid}.json`);
  fs.writeFileSync(file, typeof def === 'string' ? def : JSON.stringify(def));
  try { return JSON.parse(sf('save', alias, file, note)).id; } finally { fs.unlinkSync(file); }
}
export function deleteForms(...aliases) { if (aliases.length) sf('delete', ...aliases); }
export const formId = (alias) => Number(sql(`SELECT id FROM jos_squidform_forms WHERE alias='${esc(alias)}'`));

/** Sets Options (merged). Tests run with no minimum fill time and no rate limit unless they set them. */
export function params(obj) { sf('params', JSON.stringify(obj)); }
export function testDefaults(extra = {}) { params({ min_fill_seconds: 0, rate_limit_max: 0, ...extra }); }

/** Makes (or resets) a site user in the given group titles; returns their id. Password is PASS. */
export function ensureUser(username, groups = ['Registered'], { name, email } = {}) {
  let id = Number(sql(`SELECT id FROM jos_users WHERE username='${esc(username)}'`));
  const hash = execFileSync('php', ['-r', `echo password_hash($argv[1], PASSWORD_BCRYPT);`, PASS]).toString();
  if (!id) {
    sql(`INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, lastvisitDate, activation, params, lastResetTime, resetCount, otpKey, otep, requireReset)
         VALUES ('${esc(name || username)}', '${esc(username)}', '${esc(email || `${username}@example.test`)}', '${esc(hash)}', 0, 0, NOW(), NULL, '', '{}', NULL, 0, '', '', 0)`);
    id = Number(sql(`SELECT id FROM jos_users WHERE username='${esc(username)}'`));
  } else {
    sql(`UPDATE jos_users SET password='${esc(hash)}', block=0 WHERE id=${id}`);
  }
  sql(`DELETE FROM jos_user_usergroup_map WHERE user_id=${id}`);
  for (const g of groups) {
    const gid = ensureGroup(g);
    sql(`INSERT IGNORE INTO jos_user_usergroup_map (user_id, group_id) VALUES (${id}, ${gid})`);
  }
  return id;
}
/** A user group by title (made under Registered if it's missing). */
export function ensureGroup(title) {
  let gid = Number(sql(`SELECT id FROM jos_usergroups WHERE title='${esc(title)}'`));
  if (gid) return gid;
  const R = Number(sql("SELECT rgt FROM jos_usergroups WHERE title='Registered'"));
  const P = Number(sql("SELECT id FROM jos_usergroups WHERE title='Registered'"));
  sql(`UPDATE jos_usergroups SET rgt=rgt+2 WHERE rgt>=${R}`);
  sql(`UPDATE jos_usergroups SET lft=lft+2 WHERE lft>${R}`);
  sql(`INSERT INTO jos_usergroups (parent_id, lft, rgt, title) VALUES (${P}, ${R}, ${R + 1}, '${esc(title)}')`);
  return Number(sql(`SELECT id FROM jos_usergroups WHERE title='${esc(title)}'`));
}

// ------------------------------------------------------------------ mail
export function clearMail() { fs.mkdirSync(MAILDIR, { recursive: true }); for (const f of fs.readdirSync(MAILDIR)) fs.unlinkSync(path.join(MAILDIR, f)); }
/** Messages written since clearMail(): { to, subject, from, replyTo, html, text, raw, attachments: [{name, type}] } */
export function mails() {
  if (!fs.existsSync(MAILDIR)) return [];
  return fs.readdirSync(MAILDIR).sort().map((f) => parseMail(fs.readFileSync(path.join(MAILDIR, f), 'utf8')));
}
export async function waitMail(n = 1, ms = 8000) {
  const t = Date.now();
  while (Date.now() - t < ms) { if (mails().length >= n) return mails(); await wait(200); }
  return mails();
}
function decodeWords(s) {
  return s.replace(/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g, (_, cs, enc, txt) => enc.toUpperCase() === 'B'
    ? Buffer.from(txt, 'base64').toString('utf8')
    : Buffer.from(txt.replace(/_/g, ' ').replace(/=([0-9A-F]{2})/gi, (m, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8')).replace(/\?=\s+=\?/g, '');
}
function headers(block) {
  const h = {};
  block.replace(/\r?\n[ \t]+/g, ' ').split(/\r?\n/).forEach((l) => { const i = l.indexOf(':'); if (i > 0) h[l.slice(0, i).toLowerCase()] = l.slice(i + 1).trim(); });
  return h;
}
function body(part, h) {
  const enc = (h['content-transfer-encoding'] || '').toLowerCase();
  if (enc === 'base64') return Buffer.from(part.replace(/\s+/g, ''), 'base64').toString('utf8');
  if (enc === 'quoted-printable') return Buffer.from(part.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/gi, (m, x) => String.fromCharCode(parseInt(x, 16))), 'latin1').toString('utf8');
  return part;
}
export function parseMail(raw) {
  const [head, ...rest] = raw.split(/\r?\n\r?\n/);
  const h = headers(head);
  const out = { raw, to: decodeWords(h.to || ''), cc: decodeWords(h.cc || ''), bcc: decodeWords(h.bcc || ''), subject: decodeWords(h.subject || ''), from: decodeWords(h.from || ''), replyTo: decodeWords(h['reply-to'] || ''), html: '', text: '', attachments: [] };
  const walk = (ctype, content) => {
    const m = /boundary="?([^";]+)"?/i.exec(ctype);
    if (m) {
      for (const p of content.split(`--${m[1]}`).slice(1)) {
        if (p.startsWith('--')) break;
        const [ph, ...pb] = p.replace(/^\r?\n/, '').split(/\r?\n\r?\n/);
        const hh = headers(ph);
        walk(hh['content-type'] || 'text/plain', pb.join('\n\n'));
        if (/attachment|name=/i.test(hh['content-disposition'] || '') || /name=/i.test(hh['content-type'] || '')) {
          out.attachments.push({ name: decodeWords(((/name="?([^";]+)"?/i.exec(hh['content-disposition'] || hh['content-type'] || '') || [])[1]) || ''), type: (hh['content-type'] || '').split(';')[0], data: hh['content-transfer-encoding'] === 'base64' ? Buffer.from(pb.join('').replace(/\s+/g, ''), 'base64') : null });
        }
      }
    } else if (/text\/html/i.test(ctype)) out.html ||= body(content, h);
    else if (/text\/plain/i.test(ctype)) out.text ||= body(content, h);
  };
  const ct = h['content-type'] || 'text/plain';
  if (/boundary=/i.test(ct)) walk(ct, rest.join('\n\n'));
  else { const b = body(rest.join('\n\n'), h); if (/html/i.test(ct)) out.html = b; else out.text = b; }
  return out;
}

// ------------------------------------------------------------------ browser
let browser;
export async function launch() {
  browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  return browser;
}
export async function close() { if (browser) await browser.close(); }
export async function page(opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, ...opts });
  const p = await ctx.newPage();
  p.on('dialog', (d) => d.accept());
  return p;
}
/** An administrator page, signed in as admin (or another back-end user). */
export async function admin(username = 'admin', password = PASS) {
  const p = await page();
  await p.goto(`${BASE}/administrator/index.php`);
  await p.fill('#mod-login-username', username);
  await p.fill('#mod-login-password', password);
  await Promise.all([p.waitForNavigation(), p.click('#btn-login-submit')]);
  return p;
}
/** A site page, signed in as this user (or a guest when no user is given). */
export async function site(username = null, password = PASS, opts = {}) {
  const p = await page(opts);
  if (username) {
    await p.goto(`${BASE}/index.php?option=com_users&view=login`);
    await p.fill('#username', username);
    await p.fill('#password', password);
    await Promise.all([p.waitForNavigation(), p.locator('form.com-users-login__form button[type="submit"], .com-users-login__submit button').first().click()]);
  }
  return p;
}
export const formUrl = (alias, extra = '') => `${BASE}/index.php?option=com_squidform&view=form&form=${alias}${extra}`;
export const adminUrl = (q) => `${BASE}/administrator/index.php?option=com_squidform&${q}`;

/** The form's own <form> on the page (other modules may have forms too). */
export const theForm = (p, alias) => p.locator(`form[data-sf-alias="${alias}"]`).first();
export const field = (p, key) => p.locator(`[name="sf[${key}]"], [name="sf[${key}][]"]`).first();

/** Fills fields by key: strings for text, true/false for a tick box, arrays for tick-box lists, {select: v} for dropdowns. */
export async function fill(p, values) {
  for (const [k, v] of Object.entries(values)) {
    const el = p.locator(`[name="sf[${k}]"]:not([type="hidden"]), [name="sf[${k}][]"]:not([type="hidden"])`);
    const first = el.first();
    if (await el.count() === 0) { await p.locator(`[name="sf[${k}]"]`).evaluate((e, val) => { e.value = val; }, String(v)); continue; }
    const tag = await first.evaluate((e) => e.tagName.toLowerCase());
    const type = await first.getAttribute('type');
    if (tag === 'select') await first.selectOption(Array.isArray(v) ? v : String(v));
    else if (type === 'checkbox' && !Array.isArray(v)) { if (v) await first.check(); else await first.uncheck(); }
    else if (type === 'checkbox' || type === 'radio') {
      for (const one of [].concat(v)) {
        const box = p.locator(`[name="sf[${k}]"][value="${one}"], [name="sf[${k}][]"][value="${one}"]`);
        // Image choices hide the box behind the picture: tick it directly when a click can't.
        await box.check({ timeout: 3000 }).catch(() => box.evaluate((e) => { e.checked = true; e.dispatchEvent(new Event('change', { bubbles: true })); }));
      }
    } else await first.fill(String(v));
  }
}
/** Presses the page's Next/Send button and waits for the answer. */
export async function next(p, value = 'next') {
  await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.locator(`button[name="sf_nav"][value="${value}"]`).first().click()]);
}
/**
 * Dark mode as a dark-capable template gives it (Cassiopeia has none): [data-bs-theme="dark"]
 * on <html> plus Bootstrap 5.3's own dark colours, and Cassiopeia's colour names pointed at them.
 */
export async function darkTemplate(p) {
  await p.evaluate(() => {
    document.documentElement.setAttribute('data-bs-theme', 'dark');
    const st = document.createElement('style');
    st.id = 'sf-test-dark';
    st.textContent = `html[data-bs-theme=dark]{--bs-body-color:#dee2e6;--bs-body-color-rgb:222,226,230;--bs-body-bg:#212529;--bs-body-bg-rgb:33,37,41;--bs-emphasis-color:#fff;--bs-secondary-color:rgba(222,226,230,.75);--bs-secondary-bg:#343a40;--bs-tertiary-color:rgba(222,226,230,.5);--bs-tertiary-bg:#2b3035;--bs-heading-color:inherit;--bs-link-color:#6ea8fe;--bs-link-color-rgb:110,168,254;--bs-link-hover-color:#8bb9fe;--bs-border-color:#495057;--bs-border-color-translucent:rgba(255,255,255,.15);--bs-form-valid-color:#75b798;--bs-form-invalid-color:#ea868f;--bs-form-invalid-border-color:#ea868f;--bs-danger-text-emphasis:#ea868f;--bs-danger-bg-subtle:#2c0b0e;--bs-danger-border-subtle:#842029;--bs-success-text-emphasis:#75b798;--bs-info-text-emphasis:#6edff6;--bs-warning-text-emphasis:#ffda6a;--bs-primary-text-emphasis:#6ea8fe;--body-color:#dee2e6;--body-bg:#212529;--cassiopeia-color-text:#dee2e6;color-scheme:dark}
html[data-bs-theme=dark] body{background:#212529;color:#dee2e6}
html[data-bs-theme=dark] .card{--bs-card-bg:#212529;--bs-card-color:#dee2e6;background-color:var(--bs-card-bg);color:var(--bs-card-color)}
html[data-bs-theme=dark] .form-text,html[data-bs-theme=dark] .text-body-secondary{color:var(--bs-secondary-color)!important}
html[data-bs-theme=dark] .invalid-feedback{color:var(--bs-form-invalid-color)}
html[data-bs-theme=dark] .alert-danger{--bs-alert-color:var(--bs-danger-text-emphasis);--bs-alert-bg:var(--bs-danger-bg-subtle);--bs-alert-border-color:var(--bs-danger-border-subtle)}
html[data-bs-theme=dark] .form-control,html[data-bs-theme=dark] .form-select{background-color:var(--bs-body-bg);color:var(--bs-body-color);border-color:var(--bs-border-color)}`;
    document.head.appendChild(st);
  });
  await p.waitForTimeout(600); // buttons fade between colours; a real dark site is dark from the start
}
/** Next, posted straight to the server: skips the browser's checks and the form's own script (to test the server's). */
export async function rawNext(p, value = 'next') {
  await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.evaluate((v) => {
    const f = document.querySelector('form[data-sf-alias]');
    const i = document.createElement('input'); i.type = 'hidden'; i.name = 'sf_nav'; i.value = v; f.appendChild(i);
    HTMLFormElement.prototype.submit.call(f);
  }, value)]);
}
/** Through to the end: Next on every page, then Send on Check your answers if there is one.
 *  (The offline human check holds the submit until it's solved, then sends it by itself.) Stops at an error summary. */
export async function submit(p) {
  for (let i = 0; i < 20; i++) {
    if (await p.locator('.sf-error-summary').count()) return false;
    if (await p.locator('button[name="sf_nav"][value="send"]').count()) { await next(p, 'send'); return !(await p.locator('.sf-error-summary').count()); }
    if (!(await p.locator('button[name="sf_nav"][value="next"]').count())) return true;
    await next(p, 'next');
  }
  return false;
}
export const text = async (p, sel = 'main, body') => ((await p.locator(sel).first().textContent()) || '').replace(/\s+/g, ' ').trim();

/** Latest submission of a form: { id, uid, status, user_id, data } through SquidForm's reader. */
export function latest(alias, q = {}) {
  const rows = JSON.parse(sf('eval-api', alias, JSON.stringify({ status: ['draft', 'submitted', 'pending', 'approved', 'rejected', 'returned', 'withdrawn'], archived: null, test: true, limit: 1000, ...q })));
  return rows && rows.length ? rows.reduce((a, b) => (b.id > a.id ? b : a)) : null;
}
export function answers(alias, q = {}) { return JSON.parse(sf('eval-api', alias, JSON.stringify(q))) || []; }

export async function axe(p, what, include = null, exclude = []) {
  let b = new AxeBuilder({ page: p }).withTags(TAGS);
  if (include) b = b.include(include);
  for (const x of exclude) b = b.exclude(x);
  const r = await b.analyze();
  const v = r.violations.filter((x) => ['serious', 'critical'].includes(x.impact) || x.id === 'color-contrast')
    .map((x) => `${x.id} ×${x.nodes.length}: ${x.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
  return ok(v.length === 0, `${what}: no axe violations${v.length ? ` (${v.join('; ')})` : ''}`);
}

// ------------------------------------------------------------------ menu items and modules
/** A Main Menu item (alias must be unique); returns its id. link like "index.php?option=com_squidform&view=submissions&form_id=3". */
export function menuItem(alias, title, link, params = {}, access = 1) {
  deleteMenuItem(alias);
  const component = /option=([a-z_]+)/.exec(link)[1];
  const R = Number(sql('SELECT rgt FROM jos_menu WHERE id=1'));
  sql(`UPDATE jos_menu SET rgt=rgt+2 WHERE rgt>=${R}`); sql(`UPDATE jos_menu SET lft=lft+2 WHERE lft>${R}`);
  const cid = sql(`SELECT extension_id FROM jos_extensions WHERE element='${component}' AND type='component'`);
  sql(`INSERT INTO jos_menu (menutype,title,alias,note,path,link,type,published,parent_id,level,component_id,checked_out,checked_out_time,browserNav,access,img,template_style_id,params,lft,rgt,home,language,client_id,publish_up,publish_down)
       VALUES ('mainmenu','${esc(title)}','${esc(alias)}','','${esc(alias)}','${esc(link)}','component',1,1,1,${cid},NULL,NULL,0,${access},'',0,'${esc(JSON.stringify(params))}',${R},${R + 1},0,'*',0,NULL,NULL)`);
  return Number(sql(`SELECT id FROM jos_menu WHERE alias='${esc(alias)}' AND menutype='mainmenu'`));
}
export function deleteMenuItem(alias) {
  for (const id of sql(`SELECT id FROM jos_menu WHERE alias='${esc(alias)}' AND menutype='mainmenu'`).split('\n').filter(Boolean)) {
    const [l, r] = sql(`SELECT CONCAT(lft,' ',rgt) FROM jos_menu WHERE id=${id}`).split(' ').map(Number);
    sql(`DELETE FROM jos_menu WHERE id=${id}`);
    sql(`UPDATE jos_menu SET rgt=rgt-${r - l + 1} WHERE rgt>${r}`); sql(`UPDATE jos_menu SET lft=lft-${r - l + 1} WHERE lft>${r}`);
  }
}
/** A site module in a position, on all pages (or one menu item); returns its id. */
export function addModule(module, title, params, { position = 'sidebar-right', menuId = 0, access = 1 } = {}) {
  sql(`INSERT INTO jos_modules (title,note,content,ordering,position,checked_out,checked_out_time,publish_up,publish_down,published,module,access,showtitle,params,client_id,language)
       VALUES ('${esc(title)}','','',1,'${position}',NULL,NULL,NULL,NULL,1,'${module}',${access},1,'${esc(JSON.stringify(params))}',0,'*')`);
  const id = Number(sql('SELECT MAX(id) FROM jos_modules'));
  sql(`INSERT INTO jos_modules_menu (moduleid,menuid) VALUES (${id},${menuId})`);
  return id;
}
export function deleteModules(module) {
  for (const id of sql(`SELECT id FROM jos_modules WHERE module='${module}' AND client_id=0`).split('\n').filter(Boolean)) {
    sql(`DELETE FROM jos_modules_menu WHERE moduleid=${id}`); sql(`DELETE FROM jos_modules WHERE id=${id}`);
  }
}
/** Sends a form as this page's person with these answers (first page only, then through to the end). */
export async function send(p, alias, values, extra = '') {
  await p.goto(formUrl(alias, extra));
  await fill(p, values);
  return submit(p);
}
