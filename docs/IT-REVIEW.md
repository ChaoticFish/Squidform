<!-- Template for the IT review kit: build.sh fills in {VERSION}, {ADDON_VERSION}, {FINGERPRINTS} and {FILECOUNT}. Edit this file, not the copy in dist/. -->
# SquidForm {VERSION}: notes for an IT or security review

SquidForm is a form builder for Joomla 5.4 and 6 (PHP 8.2 or later, MySQL or MariaDB). These notes are for whoever approves it for a site: what it is, what it does on the server, and the things a code or malware scanner may point out, with where they are and why.

## What it promises

- **No calls to the outside world from SquidForm.** No fonts, scripts or images from other sites, no online lookups, no tracking, no licence check. It works on a closed network. The build fails if a shipped JavaScript or CSS file contains an outside address.
- **One address in the package, for Joomla's own updater** (1.0.0): `pkg_squidform.xml` names the Chaotic Fish Design update site (`https://chaoticfishdesign.com/updates/cfd.xml`), as Joomla extensions usually do. Joomla, not SquidForm, reads it, only when updates are checked. On a closed network it simply can't be reached; to stop Joomla trying, disable **Chaotic Fish Design** in System → Update Sites after installing, and check it again after each upgrade.
- **No outside PDF or signing service.** PDFs (0.11.0) are made by a small writer inside SquidForm; e-signatures are sealed with the site's own secret. Nothing is sent anywhere.
- **No server code stored in forms.** A form is JSON. Its own optional CSS and JavaScript run only in visitors' browsers, never on the server, and never in the admin's Preview. Calculations are parsed by a small formula parser; they are never run as PHP or JavaScript.
- **No SQL taken from forms or visitors.** Every query uses Joomla's query builder with bound values. Tables a form may read or write are on allow-lists in the Options, both empty after install; Joomla's tables that hold secrets (users, sessions, keys and others) and SquidForm's own tables can never be read through a form.
- **Every answer is checked on the server**, whatever the browser did: required fields, choices against their lists, calculations worked out again, limits counted under a database lock.

## The two downloads

| File | What it is |
|---|---|
| `pkg_squidform-{VERSION}.zip` | The package: the component, two modules (a form and a viewer) and a system plugin (`{squidform alias}` in articles). |
| `plg_squidform_scannertools-{ADDON_VERSION}.zip` | **Optional** add-on: the "ClamAV program" scan mode and the EICAR test. See below. |

Install the package through System → Install → Extensions. Nothing in it needs the add-on.

## What a scanner may notice in the package, and why

| What | Where | Why |
|---|---|---|
| A socket to ClamAV | `admin/src/Engine/Scanner.php` (`stream_socket_client`) | Virus-scanning uploads with the ClamAV daemon on the same server (a Unix socket or `127.0.0.1:3310`). Only when an admin turns it on. |
| The site calling its own address | `admin/src/Engine/ServerCheck.php` (Joomla's `HttpFactory`) | **Check this server** writes a small test file into the upload folder and asks the site for it, to prove uploaded files can't be downloaded directly. Only the site's own address; only when an admin opens that page. |
| Writing and deleting files | `Uploads.php`, `ServerCheck.php`, `Scanner.php`, `script.php` | Storing uploaded files under random names in the upload folder, the test file above, a temporary copy for a scan, and the deny-all `.htaccess`, `web.config` and `index.html` in the upload and handler folders. |
| Loading PHP files from a folder | `admin/src/Engine/Handlers.php` (`include_once`, via an autoloader) | **PHP handlers**: a developer's own code, one class per file, in the handler folder (`administrator/squidform-handlers` by default; Options → Developers). Only someone who can put files on the server can add one; a form can only pick one from a list. The folder ships with a deny-all and one example, `TicketNumber-example.txt`, which never runs (it isn't a `.php` file). |
| `base64_encode` / `base64_decode` | `Processor.php`, `FormGuard.php`, `Captcha.php`, `SignatureField.php`, `ApprovalTasksTrait.php`, `Viewer.php`, layouts | The page to return to after a post, signed one-time form tokens, the offline proof-of-work check, and signature drawings (PNG data, size- and format-checked). No code is ever decoded and run. |
| `random_bytes` | `FormGuard.php`, `Captcha.php`, `SubmissionStore.php`, `Uploads.php`, `ServerCheck.php` | Tokens, submission ids and random file names. |
| `finfo` | `Uploads.php`, `ServerCheck.php` | Checking what an uploaded file really is, not trusting its name. Programs, scripts, web pages and SVG are never accepted, whatever the Options say. |
| Email | `EmailAction.php`, `ApprovalMail.php` | Through Joomla's own mailer (Global Configuration), after the database transaction commits. Addresses typed into a form can't choose recipients unless an admin allows it. |
| `gzcompress` / `gzuncompress` | `Engine/Pdf/SimplePdf.php` | SquidForm's own PDF writer (0.11.0): compressing page contents and reading the drawn signature PNG to keep its transparency. No PDF library is shipped. |
| `hash_hmac` | `Engine/ESign.php` | The seal on an e-signature record (0.11.0): an HMAC of the record's SHA-256 with a key derived from Joomla's secret. |
| Email attachments | `ConfirmEmailAction.php`, `ESign.php` | A PDF copy of the answers for the sender, and the signed copy for an e-signer, built in memory and attached (0.11.0). |
| `DROP TABLE`, `ALTER TABLE … DROP COLUMN` | `admin/src/Engine/DataTables.php` | **Data tables → tidy-up** (0.14.0): drop a column whose field was removed, or a SquidForm table no form owns. Only for people with Admin and Permanently Delete Submissions, after typing the name in full, with the session token; names come only from SquidForm's registry or `information_schema`, must be SquidForm data tables (`sfd_`/`sfa_`), and are quoted. Written to Activity. (Permanent deletion of an archived form, in `FormPurge.php`, drops its tables the same way.) |
| Starter PHP handlers | `admin/src/Engine/Handler/Starter/*.php`, `admin/src/Engine/Counters.php` | (1.0.2) Eight ready-made handlers, part of the package and each **off** until an admin ticks it in Options → Developers. **Write a file** appends a CSV/JSON line only to the folder an admin sets in Options (outside the website, checked with `realpath`), after the save commits. **Next number** and **Take turns** keep a counter row updated inside the save's transaction (the first time, one `INSERT … ON DUPLICATE KEY UPDATE` built from integers and a cleaned, quoted name). **Look up a person** reads users (signed-in visitors only by default). **Session user** reads allow-listed session values. The only example left as text is `examples/handlers/TicketNumber-example.txt`, copied to the handler folder, never run until renamed. |
| Reading the Joomla session | `admin/src/Engine/SessionData.php` | (0.15.0) Reads values another site script stored in the session (a sign-on script's details), only for top-level keys an admin lists in Options (none at install; Joomla's own refused). Read only. |
| Language files for visitors | `site/language/*/com_squidform.ini` | (0.15.0) Spanish, French, German, Esperanto and three joke packs (`en-XC`, `en-XF`, `en-XD`: Swedish Chef, meows and woofs). Plain Joomla `.ini` text; the joke ones are made by `tests/make-joke-packs.php` from the English. |
| Reading answers for other extensions | `admin/src/Api/Submissions.php`, `admin/src/Engine/Addons.php` | (1.0.3) A read-only way for a module or add-on (Tide Table, say) to list a form's answers, plus archive/restore (logged). Field keys are checked against the form and never put into SQL as text; values are bound. Add-on hooks for choice lists, language packs and starter forms: each entry is checked and a broken one left out. No network, no new tables. |
| Per-form CSS and JavaScript | stored in the form definition | Added to the page through Joomla's asset manager (so a CSP nonce applies); run in visitors' browsers only. Anyone who can edit forms can add browser JavaScript, so form-editing rights should go to trusted staff (Joomla permissions, per form). |

The build refuses to make a package containing `proc_open`, `shell_exec`, `passthru`, `eval(`, the EICAR test string, or a file named like `something.php.ext`.

## The optional add-on

`plg_squidform_scannertools` adds two things, kept out of the package on purpose because they are what upload and malware filters most often block:

- **"ClamAV program" mode**: runs `clamdscan` or `clamscan` on an uploaded file with PHP's `proc_open`, with a fixed argument list (no shell, nothing from the form or the visitor). The daemon mode in the package needs none of this.
- **The EICAR test**: the industry-standard harmless test file (kept base64-encoded), sent from memory to the scanner when an admin presses **Test with the EICAR file** in Check this server. It's never written to the site's folders; in program mode a temporary copy is scanned and deleted.

Without the add-on, Check this server says it's optional, the EICAR button is off, and if "ClamAV program" mode is set, uploads report that the add-on is needed (the "if scanning fails" Option decides whether they're kept).

## Data it keeps

- Its own tables (`#__squidform_*`) and one table set per form (`#__sfd_<alias>`): the answers, who sent them (user id, IP address), versions of each form, an audit trail (views, edits, approvals, exports, downloads) and edit history.
- Uploaded files in the upload folder (best outside the web site; Check this server suggests one), served only through a permission-checked download with `nosniff` and a sandboxing Content-Security-Policy.
- A record of each form imported from ChronoForms (`#__squidform_imports`): the old and new field names and the import report. ChronoForms' own tables are only read.
- A CSV being imported sits in Joomla's temporary folder between the steps, and is deleted after.
- **Joomla user data is only read** (0.14.0): a signed-in person's user groups and their own Joomla user fields, to show or hide parts of a form and to offer a field's list of choices. Worked out on the server; nothing about the person is sent to the page except true or false. SquidForm never creates or changes users, groups or fields.
- **Session data is only read** (0.15.0), and only for keys an admin lists. A field filled from it is saved with the answers, like any answer.
- **Each submission records its language** and whether it was sent in **test mode** (0.15.0). Test submissions can be cleared in one step.
- Uninstalling keeps the data. Removing it is a separate, confirmed step (Forms → Archive → permanent deletion).

## Accessibility

Built to WCAG 2.1 AA / Section 508: every release is checked with axe (no serious or critical issues) and by keyboard; see `docs/ACCESSIBILITY.md` in the source.

## Checking the files

Each zip's SHA-256 is below. `squidform-{VERSION}-SHA256SUMS.txt` lists every file inside them, as it's installed; for example, `sha256sum administrator/components/com_squidform/src/Engine/Scanner.php` on the site should match the `com_squidform/admin/src/Engine/Scanner.php` line.

## Fingerprints of this release

```
{FINGERPRINTS}
```

Every file inside them: `squidform-{VERSION}-SHA256SUMS.txt` ({FILECOUNT} files).
