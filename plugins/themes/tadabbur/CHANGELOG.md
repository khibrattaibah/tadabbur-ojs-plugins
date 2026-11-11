# Changelog

## 2.0.1 — 2026-11-11
- Backend stylesheet cache is now per journal (`cache/tdb-backend-c<contextId>-<hash>.css`); regenerating it for one journal no longer deletes other journals' files.
- Atomic write of the generated stylesheet (temp file + rename).
