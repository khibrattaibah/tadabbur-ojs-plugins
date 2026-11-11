Review release for the OJT technical review (staging first).

**Assets**
- `tadabburSuite-1.5.0.zip` — generic plugin `plugins.generic/tadabburSuite` 1.5.0.0
- `tadabbur-theme-2.0.1.zip` — theme plugin `plugins.themes/tadabbur` 2.0.1.0
- `TECHNICAL_REVIEW.pdf` — architecture, database changes, compatibility, installation/upgrade/rollback, tests, open questions
- `SHA256SUMS.txt` — checksums of the two archives

**Highlights**
- `tadabburSuite` is the only product registered with OJS; internal modules never write to `versions` (fixes the earlier "Downgrades are not supported" error).
- Only stage 1 is active: core, read-only readiness check, read-only editorial dashboard (opt-in).
- Creates only one table (`tdb_migrations`) through the standard installer; no OJS core table is changed; nothing is dropped on uninstall.
- OJS 3.5.0.x (tested 3.5.0.6), PHP ≥ 8.2. Tested on PostgreSQL; MySQL/MariaDB to be tested on staging.

The archives are identical in content to `plugins/generic/tadabburSuite` and `plugins/themes/tadabbur` in this commit; `scripts/build.sh` rebuilds them from source (checksums differ only because of file timestamps).
