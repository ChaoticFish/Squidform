# Changelog

The detailed entries before 1.0.3 were lost with the old source folder (2026-11-20) and were rebuilt from the release notes, the roadmap and the landing page's changelog, so they're shorter than the originals.

## Unreleased (next update)

### Fixed
- **Site viewer filters** didn't work with search-engine-friendly URLs switched off: the filter form's hidden inputs came out as `amp;view`. (Found by the rebuilt viewer test.)
- **Builder:** a dropdown's, radio list's or tick-box list's Choices settings showed a stray "null" (screen readers read it out). A new test opens every field type's settings and checks for this.
- **Accessibility (WCAG 2.4.2):** a form's page now has its own browser title: the form's title (or the menu item's page title), with the step first on a form with several pages ("Step 2 of 3: Contact details - Visitor request"). Before, it kept the home page's title.
- **Accessibility:** a submission whose summary was blank had a link with no words in the admin Submissions list (now "(no values)"). In Atum's dark mode, the Reject, Withdraw and Remove delegate buttons and the Delete permanently heading were too faint; they now use colours that read in both modes.

### Scanner Tools add-on 1.0.3
- **Rebuilt** from SquidForm's `ScannerAddonInterface` (the 1.0.1/1.0.2 source was lost). Same job: the "ClamAV program" scan mode and the EICAR test. Installs over 1.0.1/1.0.2.
- The EICAR test string is now stored base64-encoded **in reversed pieces**, so the add-on's zip no longer trips upload filters that decode base64. The build refuses a file holding it in one piece, encoded or not.
- Program mode runs only a program named `clamdscan` or `clamscan` (checked by name and by its real path), with a fixed argument list and `--` before the file, and stops a scan after 120 seconds.
- New test suite (`e2e-scanner.mjs`) with stand-ins for the ClamAV daemon and `clamdscan` that know the EICAR file only by its SHA-256.

## 1.0.3 (2026-11-20): add-on hooks

Code only: no new tables, nothing changes for existing forms. Everything here lies dormant until an add-on (or a form) uses it.

### Added
- **A reader for other extensions** (`Api\Submissions`): a module or add-on reads a form's answers by its alias, with filters, sorting and paging, without knowing how SquidForm stores them. Read only, plus archive and restore (logged in Activity). Tide Table uses it first; Sonar (surveys) and Pecking Order can use it later. See DEVELOPER.md → Reading answers from another extension.
- **Choices from another form** (`"options": {"form": {…}}`): a dropdown, radio or tick-box list can offer another form's answers. The other form has to opt in, field by field, in **Settings → Share as choices** (`settings.shared_fields`); only sent or approved, unarchived, non-test answers are used. In the builder: "Where the choices come from" → **Another form**.
- **Add-on hooks**: `onSquidformOptionSources` (an add-on's choice list, `"options": {"addon": "name"}`), `onSquidformLanguages` (an add-on's visitor language pack), `onSquidformStarters` (an add-on's starter forms). Each hook checks what it's handed and leaves out (and logs) anything broken.

## 1.0.2 (2026-11-16): starters built in, dark mode, hardening

- **Starter handlers are built in.** Tick the ones forms may use in Options → Developers (none at install). Unchanged `-example.txt` copies are removed on update; `TicketNumber-example.txt` stays as the developer example.
- **New Session user starter:** one action fills every field named after a value your sign-on keeps in the session.
- **Next number never repeats** (`#__squidform_counters`, a locked row inside the save), even after a deletion or when two people send at once. Take turns shares it. Test mode has its own counter.
- **Tougher starters:** Write a file only writes to the folder set in Options; Look up a person answers signed-in visitors unless a form allows guests; Blocked list can't be dodged with look-alike or invisible characters; Tidy text leaves phone numbers, states, ZIPs and countries alone; Working days takes strict dates.
- **Dark mode:** five buttons and the code in the builder's notes are readable again; a new dark-mode accessibility test of every admin screen.
- The install script runs every `CREATE TABLE IF NOT EXISTS` on each install and update. Abandoned CSV uploads are cleared after a day. Hints name the real Options tabs.

## 1.0.1 (2026-11-13)

- Consent terms and their "I agree" tick box can be translated.
- The Chaotic look's paint splat no longer sits on the last language tab.

## 1.0.0 (2026-11-12): first release

- Updates through Joomla (the Chaotic Fish Design update site; closed networks can switch it off).
- Accessibility review of every admin screen, builder section, Preview and look, in light and dark mode: two fixes.
- Security review of everything since 0.12: one fix (Look up a person can't choose who gets an email).
- An upgrade can no longer stop with "Duplicate column". A half-renamed handler file is explained.

## 0.15.0 (2026-11-12): languages, test mode, session data, handlers

- Visitor languages: English, Spanish, French, German, Esperanto, and Swedish Chef, Feline and Canine. Language tabs, a Translations tab, confirmation emails in the sender's language.
- Test mode: emails only to the tester, test submissions marked and cleared in one step.
- Session data: fill fields and show or hide parts from values a sign-on script keeps in the session (allow-listed in Options).
- Seven starter PHP handlers (as examples) and handlers that can turn a person back with their answers kept.
- Preview in light or dark, in any language. Plain is now Simple.

## 0.14.x (2026-11-12): looks, user data, My submissions

- Five looks (Simple, Elegant, Chaotic, Civic, Friendly), per form or site-wide.
- Joomla user data, read only: rules by group or profile field; choices from a profile field.
- My submissions; a guest's saved form moves into their new account. Data tables screen. Placeholders tab, choice counter, striped option lists.

## 0.13.x (2026-11-11): logic, logs, ChronoForms 7

- Import from ChronoForms 7. Activity screen. Date limits, named values, a CSV attached to emails, a searchable dropdown.
- Dark mode in the builder and admin screens, column layouts for choices, allowed HTML per Formatted text field, add-on hooks (editor, builder info, submissions links, address formats), "Report this problem" for error pages.

## 0.12.0: moving in from ChronoForms

- Import from ChronoForms 8: forms, listings and old submissions (PHP never comes across; custom JavaScript comes across switched off). Import submissions from any CSV. A report for every import. Security review.

## 0.11.x: finish later, PDFs, e-signatures

- Save and finish later (signed in or by emailed link) with a Saved for later list. PDFs (print, download, a copy for the sender; tagged). Emails by answer and confirmation emails. E-signatures with a sealed record. Sample forms.

## 0.10.x: behaviour and IT review

- Calculations, skip pages, Check your answers, prefill from the web address, open and close dates and limits, links that fill in a form, check-out and check-in, Read data with an allow-list, PHP handler files. The IT review kit; scanner tools moved to an optional add-on. PHP 8.2 or later.

## 0.9.x: uploads and more fields

- File uploads with ClamAV scanning and Check this server. Each form's own CSS and JavaScript. Duplicate, export and import forms. Confirm email, ranking, slider, date range, phone with country code, consent, image choice.

## 0.7–0.8: the visual builder

- The builder (fields, pages, logic, approvals, settings, viewer; drafts, preview, undo, starter forms). A 12-column layout and sections. Signature, grid, look-up, name and address blocks.

## 0.5–0.6: approvals and spam

- Approvals from one group to ordered steps with conditions, delegates and a user picker. Submitters follow, edit, withdraw and resubmit. Rate limiting, an offline human check, short references.

## 0.1–0.4: the foundations

- Forms from a versioned JSON definition with server-side checks and show/hide. CSV and JSON export, a site viewer, editing, per-form permissions. Each form's answers in its own tables, archive instead of delete, a viewer module.
