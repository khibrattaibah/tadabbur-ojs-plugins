---
title: "Tadabbur Journal — OJS Plugin & Theme: Technical Review Package"
subtitle: "tadabburSuite 1.5.0 (generic plugin) · tadabbur 2.0.1 (theme plugin)"
date: "11 November 2026"
---

# 1. Package contents

| File | What it is | OJS product | Type | Version |
|---|---|---|---|---|
| `tadabburSuite-1.5.0.zip` | Full source of the generic plugin (PHP, templates, locales, assets) | `tadabburSuite` | `plugins.generic` | 1.5.0.0 |
| `tadabbur-theme-2.0.1.zip` | Full source of the theme plugin (child of the default theme) | `tadabbur` | `plugins.themes` | 2.0.1.0 |
| `TECHNICAL_REVIEW.pdf` | This document | — | — | — |
| `SHA256SUMS.txt` | Checksums of the two archives | — | — | — |

Both archives follow the standard OJS plugin layout (one top-level folder containing `version.xml`, the plugin class, `locale/`, `templates/`) and can be installed either through **Settings → Website → Plugins → Upload A New Plugin** or by extracting into `plugins/generic/` and `plugins/themes/` respectively. Each archive also contains a `docs/` folder with this documentation and the full test matrix (Arabic).

Licence: GPL-3.0-or-later (see `LICENSE`, `AUTHORS.md`, `THIRD_PARTY_NOTICES.md` in each archive).

# 2. Compatibility

| Item | Requirement | Tested |
|---|---|---|
| OJS | 3.5.0.x | **3.5.0.6** (local test instance). Not tested on other 3.5.0 builds; please confirm the production build. |
| PHP | ≥ 8.2 | 8.3.6 |
| Database | Any OJS-supported driver; only Laravel Schema Builder / Query Builder is used (no raw vendor-specific SQL in migrations) | **PostgreSQL 16 only**. MySQL/MariaDB **not yet tested** — we ask to run the first install on your staging MySQL/MariaDB. |
| PHP extensions | zip, dom, mbstring, intl, gd, curl, xml, json | all present in test |
| Third-party libraries | None bundled. PDF output (later stage) uses the mPDF copy shipped with OJS 3.5 (`lib/pkp/lib/vendor/mpdf`). Fonts: IBM Plex Sans Arabic (SIL OFL 1.1). | — |
| Upload size | Archive ≈ 3 MB (suite) — `upload_max_filesize` / `post_max_size` must be ≥ 8 MB for upload through the UI | default PHP limit (2 MB) fails with "File size error"; reproduced locally |

# 3. Architecture

## 3.1 One registered product

`tadabburSuite` is **the only product registered with OJS** (one row in `versions`: `plugins.generic / tadabburSuite`). It contains 34 internal *modules* (`modules/<key>/`), but modules are **not** registered in `PluginRegistry`, do **not** have their own `version.xml`, do **not** appear in the Installed Plugins grid and never create rows in `versions`.

This directly addresses the previous incident: *"You are trying to downgrade the product reviewerCertificate / editorialTransparency …"*. Root cause (reproduced locally on our former 1.4.0 package): modules were registered from a sub-folder without a `version.xml`. `Plugin::getCurrentVersion()` and `PluginGridHandler` then derived a product name from the folder and fell back to a default version 1.0.0.0, which `VersionDAO::insertVersion()` rejected because older standalone installations had left higher version rows (e.g. `reviewerCertificate 2.0.1.0`). In 1.5.0 modules never go through that path, so the old rows can no longer collide. Verified locally: with all the old version rows still present, the Plugins page loads (HTTP 200) and no downgrade exception is raised.

The theme is a separate, independent product (`plugins.themes / tadabbur`); it has no dependency on the suite and vice-versa.

## 3.2 Staged release

`classes/Manifest.php` declares every module's release stage. In **1.5.0 only stage 1 is loadable**:

| Stage | Contents | Status in 1.5.0 |
|---|---|---|
| 1 | Core, readiness check, *Editorial Command Center* (read-only dashboard) | **Active (opt-in)** |
| 2 | Documents & reviewer certificates, official letters, surveys, metadata quality | Code present, **not loaded, no tables created** |
| 3 | The remaining 28 modules | Code present, **not loaded, no tables created** |

A module cannot be enabled from the UI unless its stage is released, its dependencies are enabled and its migration succeeded. Raising the stage is an explicit release decision (new version), never a silent change. All modules are **disabled by default**; the journal manager enables them explicitly.

## 3.3 Loading rules

- `register()` performs **no DDL and no settings writes** — loading is read-only.
- Hooks used by the core: `LoadHandler` (own page routes), `TemplateManager::display`, `TemplateManager::setupBackendPage` (backend menu item).
- The Editorial Command Center adds its own page route and reads submissions, stage assignments and review assignments. In 1.5.0 it is **read-only**: reminder and digest actions return HTTP 403 (verified).

## 3.4 Access control and journal isolation

- Suite settings pages: Journal Manager and Site Administrator only.
- Editorial Command Center: managers see all submissions of *their* journal; section editors / assistants see **only submissions they are assigned to** (`stage_assignments`, same context). Users without an editorial role get nothing.
- All forms carry the OJS CSRF token; all queries are scoped by `context_id`.
- Journal branding files (signature, stamp, letterhead — used by later stages) are stored per journal under `files_dir/tadabburSuite/branding/c<contextId>/`. Files from older versions are **never** reused automatically; an explicit "import" button copies them.

# 4. Database changes

## 4.1 What 1.5.0 creates

| Object | When | Purpose |
|---|---|---|
| Table `tdb_migrations` (id, module, name, checksum, state, attempts, started_at, finished_at, error; unique(module,name)) | On install/upgrade, through the standard OJS installer (`install.xml` / `upgrade.xml` → `<migration class="…\classes\SuiteMigration"/>`) | Migration ledger with an atomic lock (prevents concurrent runs) and per-module state |
| Row in `versions` | By OJS itself on install | `plugins.generic / tadabburSuite 1.5.0.0` |
| Rows in `plugin_settings` (`plugin_name = 'tadabbursuiteplugin'`) | When the manager saves settings | `enabled`, `enabledModules`, `groupMenu` |

**Stage 1 creates no other table.** No OJS core table is created, altered or dropped. Nothing is dropped on uninstall/disable: data is preserved.

Migration safety: a failure inside our migration is **logged and recorded in `tdb_migrations`, not thrown**. This is deliberate — OJS deletes the plugin directory when an upgrade throws, which would leave the site without the plugin while its data remains. Instead, the failing module is simply not loaded and the failure is visible in the Readiness page.

## 4.2 Tables created by later stages (for information — not created by 1.5.0)

All created with Laravel Schema Builder, guarded by `Schema::hasTable()`, never dropped.

| Stage | Module | Tables |
|---|---|---|
| 2 | reviewerCertificate | `reviewer_certificate_records` — **if the table already exists from the former standalone plugin it is adopted, not recreated**: missing columns/indexes are added, existing rows and verification codes are kept |
| 2 | documentCenter | `tdb_documents`, `tdb_doc_prefs` |
| 2 | officialLetters | `official_letters` |
| 2 | surveys | `tdb_surveys`, `tdb_survey_invites`, `tdb_survey_answers` |
| 3 | others | `abstract_drafts`, `arabic_index_log`, `article_impact`, `author_responses`, `author_response_items`, `auto_announce_log`, `board_members`, `board_meetings`, `board_attendance`, `board_resolutions`, `board_ballots`, `board_votes`, `citation_checks`, `deposit_checks`, `desk_analyses`, `early_view`, `integrity_cases`, `issue_builder_pages`, `jats_sources`, `prereview_checks`, `proof_requests`, `quran_verifier_reports`, `reference_romanizations`, `reviewer_honors`, `editorial_tasks`, `editorial_task_comments` |

Before stage 2 is released we will provide the exact DDL per database driver and a read-only inventory of leftovers from the old standalone plugins on your servers.

## 4.3 The theme

No database tables. Theme options are stored by OJS in `plugin_settings` (standard `addOption()` mechanism).

# 5. Installation (staging first)

**Before installing**

1. Take a database and `files_dir` backup / snapshot of the staging instance.
2. Make sure the standalone copies of former plugins are **not** present in `plugins/generic/` (e.g. `reviewerCertificate`, `editorialTransparency`, …). Their old rows in `versions` / `plugin_settings` / tables may stay; please do **not** delete them — the Readiness page lists them and we will agree a migration plan with you.
3. Confirm `upload_max_filesize` and `post_max_size` ≥ 8 MB (or install by extraction).

**Install the suite**

1. Settings → Website → Plugins → *Upload A New Plugin* → `tadabburSuite-1.5.0.zip`; *or* extract to `plugins/generic/tadabburSuite/` (owner = web-server user, directories 755, files 644).
   If installed by extraction, run `php lib/pkp/tools/installPluginVersion.php plugins/generic/tadabburSuite/version.xml` so OJS registers the version and runs `install.xml`.
2. Enable *Tadabbur Tools Suite* in Installed Plugins → Generic.
3. Open *Tadabbur Tools → Suite Settings → Readiness check*; it must show the migration ledger as **Verified**. If not, use the "Run migration" button (idempotent) and check the error shown.
4. Optionally enable *Editorial Command Center* and save.

**Install the theme**

1. Upload `tadabbur-theme-2.0.1.zip` (or extract to `plugins/themes/tadabbur/`).
2. Enable it under Theme Plugins, then Settings → Website → Appearance → select *Tadabbur Theme*.

**Upgrade** — upload the newer archive through the same *Upload A New Plugin* dialog; OJS runs `upgrade.xml`. Settings and data are preserved.

**Rollback** — disable the plugin; then remove the folder `plugins/generic/tadabburSuite/`. Data is kept. For a full rollback restore the pre-install snapshot (the only schema object added is `tdb_migrations`).

**Writable paths** — `files_dir/tadabburSuite/` (created on demand) and OJS `cache/` (theme writes `cache/tdb-backend-c<contextId>-<hash>.css`, one file per journal, written atomically).

**Scheduled tasks / queue** — none in 1.5.0. Later stages will use the OJS job queue and mail templates only (no direct `mail()` calls) and must be idempotent on retry; we need to confirm with you how the job worker runs on your servers (§9).

# 6. Readiness check

A read-only page (*Suite Settings → Readiness check*) with four statuses:

- **Verified** — the real state was read and matched the requirement;
- **Failed** — the real state was read and did not match;
- **Not verifiable** — the application cannot prove it (e.g. actual mail delivery, worker running, backup restore); it is *never* shown as healthy just because a setting exists;
- **Info**.

Checks: OJS/PHP versions, extensions, database driver, mPDF availability, product identity (`version.xml` vs `versions` row), leftover standalone plugin folders, leftover module rows in `versions`, leftover `plugin_settings`, migration ledger per released module, `files_dir`/`cache` writability, mail transport, `jobs`/`failed_jobs` (stuck ≥ 15 min = Failed), cron, backups, journal count, enabled modules.

# 7. External services

1.5.0 makes **no outbound HTTP calls**. Modules in later stages use, when enabled by the manager: Crossref API, OpenAlex API, DOAJ, doi.org, ORCID (links only — authentication stays with the official ORCID plugin), and an AI API (abstract assistant / desk assistant / reference romanizer). AI modules will stay disabled until a privacy policy and data-minimisation rules are approved. Capabilities already provided by OJS (ORCID, Crossref/DOAJ deposit, OAI-PMH, Google Scholar meta tags, usage statistics, reviewer reminders) are **not re-implemented**; our modules only read their results.

The theme makes no external requests (all fonts are local).

# 8. Testing performed (local, OJS 3.5.0.6 / PHP 8.3 / PostgreSQL 16)

| # | Test | Result |
|---|---|---|
| T01 | Clean install through *Upload A New Plugin* | Pass — single `versions` row 1.5.0.0; `tdb_migrations` created by `install.xml` |
| T02 | Install with old module version rows still present (including `reviewerCertificate 2.0.1.0`) | Pass — Plugins grid HTTP 200, no downgrade exception; leftovers shown as *Failed* in Readiness, not deleted |
| — | Same scenario with the former 1.4.0 package | Fails with the downgrade exception (root cause confirmed) |
| T01b | Install of the **ZIP** archive through *Upload A New Plugin* (PHP limits raised to 64 MB) | Pass — same result as T01 |
| — | Same upload with PHP default limits (2 MB / 8 MB) | Fails with "File size error" — limits must be raised (§2) |
| T03 | Upgrade from an *installed* 1.4.0 → 1.5.0 by replacing the folder and running `installPluginVersion.php` | Pass — `versions` 1.4 → 1.5 current, `tdb_migrations` created, settings kept, Plugins grid HTTP 200. (UI upgrade from 1.4.0 is impossible because 1.4.0 itself breaks the grid; irrelevant for production, where 1.4.0 was removed — a fresh install applies.) |
| — | Theme 1.0.1 → 2.0.1 through the row's *Upgrade* action | Pass |
| T08 | Read-only mode: reminder / digest endpoints | Pass — HTTP 403 |
| T09 | Command Center opens for manager | Pass |
| T10 | Readiness statuses | Pass |
| — | `php -l` on every PHP file | Pass |
| — | Theme: backend stylesheet per journal (`c<contextId>`) | Pass |

**Not yet tested** (we ask to perform these together on your staging): MySQL/MariaDB install; injected migration failure; assignment-scoped visibility with a non-manager editor account; real mail delivery; job worker; multi-journal install. The full matrix (including stage-2 acceptance tests: concurrent issuance, re-run without duplicate certificate/e-mail, revocation, preserved survey answers) is in `docs/ar/TEST_MATRIX.md` (Arabic; module mapping in `docs/ar/MODULE_MAPPING.md`).

# 9. Information we need from OJT

1. Exact OJS build on production and staging (3.5.0.6 or another 3.5.0 tag), PHP version, database engine and version.
2. What was removed during the previous clean-up (files only, or also `versions` / `plugin_settings` rows and tables). If possible, the read-only output of:
   `SELECT product_type, product, major, minor, revision, build, current FROM versions WHERE product_type LIKE 'plugins.%';`
   and the list of tables named `reviewer_certificate_%`, `tdb_%`, `board_%`, `survey%`. Please do not delete existing reviewer-certificate records — their verification links are public.
3. How the version-mismatch incident was resolved (were `versions` rows edited?).
4. Current upload limits, `files_dir` location (inside/outside web root) and permissions.
5. How background jobs run (`job_runner` on request, a `jobs.php work` worker, or cron), and whether we can run a test job together.
6. Mail transport (sendmail / SMTP) and SPF/DKIM status.
7. Backup schedule and whether a restore has been tested; possibility of a snapshot before installation.
8. Your plugin acceptance checklist, if any, so we can align with it.

# 10. Contact

Developer: Moustafa Mahmoud Mahmoud — Editorial Secretary, Tadabbur Journal (ISSN 1658-9718) · ORCID 0009-0008-0991-3443 · https://github.com/mostafamr2011
Publisher: Khibrat Taibah for Research and Studies — https://khibrattaibah.com
