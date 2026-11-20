/**
 * SquidForm builder: the Placeholders tab (0.14.0). A reference of what can
 * go in curly brackets, with Copy buttons, built from this form.
 *
 *  - Form fields: placeholders worked out on the server, for this form's
 *    emails, messages, defaults and Read data rules.
 *  - Site-side: shortcodes used in articles and modules.
 *  - Added by code: add-ons (server, trusted) and this form's own JavaScript
 *    (browser only, untrusted: never in emails, saved data or checks).
 */
export function install(B) {
  const { h } = B;
  const S = B.state;

  const ADDRESS_US = ['line1', 'line2', 'city', 'state', 'zip'];
  const ADDRESS_INTL = ['line1', 'line2', 'city', 'region', 'postal', 'country'];
  const PARTS = {
    name: ['title', 'first', 'middle', 'last', 'suffix'],
    phone: ['country', 'number'],
    daterange: ['from', 'to'],
  };

  let status;
  const copy = async (text) => {
    try { await navigator.clipboard.writeText(text); status.textContent = `Copied ${text}`; } catch (e) { status.textContent = 'Copying isn’t allowed here: select the text and press Ctrl+C (⌘C).'; }
  };

  /** One table: [code, what it gives]. */
  function table(caption, rows, fid, note) {
    if (!rows.length) return null;
    return h('div.mb-4',
      h('h4.h6.mb-1', caption),
      note ? h('p.form-text.mt-0.mb-2', note) : null,
      h('table.table.table-sm.sfb-ph-table', { dataset: { fid } },
        h('caption.visually-hidden', caption),
        h('thead', h('tr', h('th', { scope: 'col' }, 'Placeholder'), h('th', { scope: 'col' }, 'Gives'), h('th', { scope: 'col' }, h('span.visually-hidden', 'Copy')))),
        h('tbody', ...rows.map(([code, what, flag], i) => h('tr',
          h('td', h('code', code)),
          h('td', what, flag ? h('span.badge.text-bg-warning.ms-2', flag) : null),
          h('td.text-end', B.button('Copy', () => copy(code), 'btn-outline-secondary', { 'aria-label': `Copy ${code}`, dataset: { fid: `${fid}-${i}` } })))))));
  }

  function answers() {
    const rows = [];
    B.allFields().forEach((x) => {
      const f = x.f;
      if (!B.valueType(f.type) || x.parent) return;
      const name = f.label || f.key;
      if (f.type === 'repeater') { rows.push([`{data:${f.key}}`, `${name}: every row, as a list`]); return; }
      rows.push([`{data:${f.key}}`, name]);
      if (f.options || f.type === 'imagechoice') rows.push([`{data.label:${f.key}}`, `${name}: the choice’s label, not its stored value`]);
      if (f.type === 'editor') rows.push([`{data.raw:${f.key}}`, `${name}: with its formatting (safe HTML only)`]);
      const parts = f.type === 'address' ? (f.format === 'intl' ? ADDRESS_INTL : (f.format && f.format !== 'us' ? [] : ADDRESS_US)) : PARTS[f.type];
      (parts || []).forEach((p) => rows.push([`{data:${f.key}.${p}}`, `${name}: ${p}`]));
    });
    rows.push(['{data:_all}', 'Every answer, as a table (HTML email) or a list (plain text)']);
    return rows;
  }

  function actions() {
    const rows = [];
    (S.model.pages || []).forEach((p) => Object.values(p.events || {}).forEach((list) => (list || []).forEach((a) => {
      if (!a.key) return;
      if (a.type === 'save_data') rows.push([`{action:${a.key}}`, 'The new submission’s number (after Save data has run)']);
      else if (a.type === 'read_data') {
        rows.push([`{action:${a.key}.count}`, `How many rows “${a.key}” found`]);
        rows.push([`{action:${a.key}.COLUMN}`, `A column of the row “${a.key}” found: put the column’s name in place of COLUMN`]);
      } else rows.push([`{action:${a.key}}`, `What “${a.key}” gave back, if anything`]);
    })));
    return rows;
  }

  /** Names used with sf.set('name', …) in this form's own JavaScript. */
  function jsNames() {
    const js = String(S.model.settings?.js || '');
    return [...new Set([...js.matchAll(/\bsf\.set\(\s*['"]([a-z][a-z0-9_]{0,39})['"]/g)].map((m) => m[1]))];
  }

  function render() {
    status = h('p.visually-hidden', { role: 'status' });
    const alias = document.getElementById('jform_alias')?.value || B.opts.alias || 'your-alias';
    const user = [['{user:id}', 'The signed-in person’s account number (0 for guests)'], ['{user:name}', 'Their name'], ['{user:username}', 'Their username'], ['{user:email}', 'Their email address']]
      .concat(B.catalog.userFields.map((u) => [`{user.field:${u.name}}`, `Their ${u.title} (Joomla user field). People can change their own profile.`]));
    const settings = Object.keys(S.model.settings || {}).filter((k) => !['css', 'js'].includes(k)).map((k) => [`{setting:${k}}`, `This form’s “${k}” setting`]);
    const addons = (B.catalog.addonPlaceholders || []).flatMap((p) => Object.entries(p.items).map(([k, label]) => [`{${p.ns}:${k}}`, `${label} (${p.title})`]));
    const own = jsNames().map((n) => [`{js:${n}}`, `Filled by this form’s own JavaScript: sf.set('${n}', …)`, 'untrusted']);

    return h('div.sfb-wide.sfb-placeholders',
      h('h3.h5', 'Placeholders'),
      h('p.form-text', 'Write these in curly brackets and SquidForm swaps in the value. Unknown ones become empty text. Copy puts the placeholder on your clipboard.'),
      status,

      h('section.mt-3', { 'aria-labelledby': 'sfb-ph-form' },
        h('h3.h6.text-uppercase.text-body-secondary', { id: 'sfb-ph-form' }, 'Form fields'),
        h('p.form-text', 'Worked out on the server, in this form’s emails, messages and thank-you text, starting values (“Where the value comes from”) and Read data rules. In email subjects and plain-text email they’re plain text; in HTML email they’re escaped.'),
        table('This form’s answers', answers(), 'ph-data', 'In emails and messages after the form is sent. Hidden values and named calculations work here too; the sender’s own copy leaves out what it never showed them.'),
        table('The submission', [['{submission:ref}', 'The short reference people see (once Save data has run)'], ['{submission:id}', 'The internal number'], ['{submission:uid}', 'The full reference']], 'ph-sub'),
        table('The person filling it in', user, 'ph-user', 'Empty for guests.'),
        (B.catalog.sessionKeys || []).length ? table('Session data (Options → Data Sources)', B.catalog.sessionPaths.map((p) => [`{session:${p.path}}`, p.list ? `A list of ${p.list} (one per line; .0 is the first)` : (p.sample ? `Now in your session: ${p.sample}` : 'Session value')]), 'ph-session', 'Put there by another script on the site; trusted, so it can choose email recipients. A field filled from it is locked unless you untick Lock. Shown here: what’s in your own session now.') : null,
        table('The form and site', [['{form:title}', 'This form’s title'], ['{form:alias}', `This form’s alias (${alias})`], ['{form:id}', 'Its number'], ['{site:name}', 'The site’s name'], ['{site:url}', 'The site’s address']], 'ph-form'),
        table('Dates', [['{date:Y-m-d}', 'Today, like 2026-11-12'], ['{date:j F Y}', 'Today, like 12 November 2026'], ['{date:Y-m-d H:i}', 'Now, with the time']], 'ph-date', 'In the person’s time zone. After “date:” comes a PHP date format.'),
        settings.length ? table('This form’s settings', settings, 'ph-setting') : null,
        table('Earlier actions', actions(), 'ph-action', 'Only in actions that run after the one named.'),
        table('The web address', [['{url:NAME}', 'A value from the address of the page the form is on: {url:dept} for ?dept=HR', 'anyone can change it']], 'ph-url', 'Not allowed in email recipients unless the Options allow typed-in recipients.'),
        table('Approvals and edits (Logic → when it’s approved, rejected, returned or edited)', [
          ['{approval:decision}', 'approved, rejected or returned'], ['{approval:step_title}', 'The step’s name'], ['{approval:comment}', 'The approver’s note'], ['{approval:by}', 'Who decided'],
          ['{submitter:name}', 'Who sent it'], ['{submitter:email}', 'Their email address'],
        ], 'ph-approval')),

      h('section.mt-3', { 'aria-labelledby': 'sfb-ph-site' },
        h('h3.h6.text-uppercase.text-body-secondary', { id: 'sfb-ph-site' }, 'Site-side'),
        h('p.form-text', 'Shortcodes for articles and modules (the System - SquidForm plugin swaps them in; by default it reads articles only).'),
        table('Shortcodes', [[`{squidform ${alias}}`, 'This form, inside an article'], ['{squidform ALIAS}', 'Any published form, by its alias']], 'ph-site')),

      h('section.mt-3', { 'aria-labelledby': 'sfb-ph-code' },
        h('h3.h6.text-uppercase.text-body-secondary', { id: 'sfb-ph-code' }, 'Added by code'),
        addons.length
          ? table('From add-ons (worked out on the server)', addons, 'ph-addon', 'Usable like the ones above. An add-on’s value may repeat what people typed, so these aren’t allowed to choose email recipients either.')
          : h('p.form-text', 'Add-ons can add their own placeholders (onSquidformPlaceholders); none are installed.'),
        h('h4.h6.mb-1', 'From this form’s own JavaScript (in the browser only)'),
        h('p.form-text.mt-0', 'Write {js:name} in a label, help text, text block or the intro, and set it in Settings → Your own JavaScript with sf.set(\'name\', value). It’s plain text on this page only. ',
          h('strong', 'Untrusted: '), 'anyone can change what runs in their own browser, so these never reach emails, saved answers or the server’s checks.'),
        own.length ? table('Set by this form', own, 'ph-js') : h('p.form-text', 'This form’s JavaScript doesn’t set any yet.')));
  }

  return { render, refresh() {} };
}
