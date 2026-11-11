# Changelog

## 1.5.0 — 2026-11-10
- Modules are internal capabilities: no longer registered in PluginRegistry, no own `versions` rows. Fixes "Downgrades are not supported" with leftover rows from former standalone plugins.
- Migration through the standard installer (`install.xml` / `upgrade.xml`) with a ledger table `tdb_migrations`, atomic lock, per-module state; failures are recorded, not thrown.
- Staged release manifest: only stage 1 (core, readiness, read-only Editorial Command Center) is loadable.
- Loading performs no DDL and no settings writes; modules are disabled by default.
- Per-journal branding storage; legacy shared files imported only on explicit request.
- Assignment-scoped visibility in the Command Center; read-only mode blocks reminders/digest (HTTP 403).
- New Readiness check page (Verified / Failed / Not verifiable / Info).
