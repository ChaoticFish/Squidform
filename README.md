# SquidForm

A form builder for Joomla, by Chaotic Fish Design. It replaces ChronoForms: build forms in the admin, store and review submissions, send emails, run approvals, and import your ChronoForms forms (read only; their PHP is never imported).

- **Version:** 1.0.3 (next update in progress: see [CHANGELOG.md](CHANGELOG.md), "Unreleased")
- **Needs:** Joomla 5.4 or 6; PHP 8.2 or later (8.3 or later on Joomla 6); MySQL or MariaDB
- **Licence:** GPL v2 or later
- **Works offline:** no CDN, no outside calls. Anything that goes online is a separate, optional add-on.
- **Accessibility:** built and tested for Section 508 / WCAG 2.1 AA, light and dark.

## Install

System → Install → Extensions, upload `pkg_squidform-<version>.zip` from the [Releases](https://github.com/ChaoticFish/squidform/releases). It installs over any earlier version and keeps your forms and submissions.

## Add-ons

Optional extras, each in its own repo and installed separately after SquidForm. SquidForm works without any of them.

| Add-on | What it does | Needs | Status |
|---|---|---|---|
| [Scanner Tools](https://github.com/ChaoticFish/squidform-scannertools) | Scans uploads by running ClamAV's `clamdscan` / `clamscan`, and adds the EICAR test to *Check this server*. Keep it off networks whose filters block test-virus files. | SquidForm 1.0.0+ | Released |
| [Tide Table](https://github.com/ChaoticFish/squidform-tidetable) | Events kept in SquidForm, shown by a module in six layouts, with an archive task. | SquidForm 1.0.3+ | Released |
| [Sonar](https://github.com/ChaoticFish/squidform-sonar) | Surveys and polls: question patterns, charts and a results dashboard. | — | Planned |
| [Languages](https://github.com/ChaoticFish/squidform-languages) | More visitor language packs, and a tool to make your own translation. | — | Planned |

All of them work offline. Every add-on repo has the `squidform-addon` topic, so [this search lists them all](https://github.com/search?q=user%3AChaoticFish+topic%3Asquidform-addon&type=repositories), including new ones.

## Docs

- [Training manuals](docs/training/) (PDF, 1.0.2): Building Forms, Handling Submissions, Site Admin and IT, Moving from ChronoForms
- [IT review template](docs/IT-REVIEW.md)

## Building

`./build.sh` (needs `zip` and `php`) checks the source, then writes into `dist/`:

- `pkg_squidform-<version>.zip`: the package to install
- `pkg_squidform-<version>-source.zip`: everything, including tests and docs

To build the Scanner Tools zip too, clone [squidform-scannertools](https://github.com/ChaoticFish/squidform-scannertools) beside this repo.

## Tests

Playwright suites in [`tests/`](tests/), run against Joomla 6 and Joomla 5.4 test sites.
