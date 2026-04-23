# license-server v1.8

Standalone license server for DRWP.

## v1.8 notes
No protocol changes from v1.6. This package is version-aligned with the plugin.
Recommended next step is moving from SQLite to MySQL/PostgreSQL and adding admin auth hardening.

## Endpoints
- `GET /api/public-key`
- `POST /api/check`
- `GET /admin/licenses`
