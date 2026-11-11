# Tadabbur Journal — OJS 3.5 plugin and theme

Source code of the two OJS products used by *Tadabbur Journal for Quranic Studies* (ISSN 1658-9718):

| Path | OJS product | Type | Version |
|---|---|---|---|
| `plugins/generic/tadabburSuite/` | `tadabburSuite` | `plugins.generic` | 1.5.0.0 |
| `plugins/themes/tadabbur/` | `tadabbur` | `plugins.themes` | 2.0.1.0 |

Each folder is a complete OJS plugin in the standard layout (`version.xml`, plugin class, `locale/`, `templates/`, `install.xml`/`upgrade.xml` for the suite) and can be copied as-is into the same path of an OJS 3.5 installation.

## Documentation

- **[docs/TECHNICAL_REVIEW.md](docs/TECHNICAL_REVIEW.md)** — architecture, database changes, compatibility, installation/upgrade/rollback, testing performed, open questions. Start here.
- `docs/ar/MODULE_MAPPING.md` — mapping of the 34 internal modules to target capabilities and release stages (Arabic).
- `docs/ar/TEST_MATRIX.md` — full test matrix for deliveries 1 and 2 (Arabic).
- `plugins/*/*/CHANGELOG.md` — per-product change log.

## Summary for reviewers

- **One registered product.** `tadabburSuite` is the only plugin registered with OJS; its 34 modules are internal and never create rows in `versions`. This removes the cause of the earlier *"Downgrades are not supported"* error (reviewerCertificate / editorialTransparency).
- **This release activates only stage 1**: core, a read-only readiness check, and a read-only editorial dashboard (opt-in). Other modules are present but not loaded and create no tables.
- **Database:** creates only `tdb_migrations` through the standard installer. No OJS core table is created, altered or dropped; nothing is dropped on uninstall.
- **Compatibility:** OJS 3.5.0.x (tested 3.5.0.6), PHP ≥ 8.2 (tested 8.3). Tested on PostgreSQL 16; MySQL/MariaDB to be tested on staging.

## Review release (ready to install)

The exact archives submitted for review are in **[`release/1.5.0/`](release/1.5.0/)**: `tadabburSuite-1.5.0.zip`, `tadabbur-theme-2.0.1.zip`, `TECHNICAL_REVIEW.pdf`, `SHA256SUMS.txt`, `RELEASE_NOTES.md`. Verify with `cd release/1.5.0 && sha256sum -c SHA256SUMS.txt`.

## Building installable archives

```bash
./scripts/build.sh      # → dist/tadabburSuite-<ver>.zip, dist/tadabbur-theme-<ver>.zip, dist/SHA256SUMS.txt
```

Upload through *Settings → Website → Plugins → Upload A New Plugin* (`upload_max_filesize` / `post_max_size` ≥ 8 MB), or extract into `plugins/generic/` and `plugins/themes/`. Install on **staging first**; see section 5 of the technical document.

## Licence and authorship

GPL-3.0-or-later. © 2026 Moustafa Mahmoud Mahmoud and Khibrat Taibah for Research and Studies — https://khibrattaibah.com · ORCID 0009-0008-0991-3443. Third-party notices are in each plugin folder.
