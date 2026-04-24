# license-server v1.9

Standalone license server for DRWP.

## v1.9 notes

- SQLite-backed license store (`DRWP_LICENSE_DB`).
- Ed25519 signing of `/api/check` responses. The private key lives at
  `DRWP_SIGNING_KEY` and is generated on first start if missing. The
  public key is exposed in base64 form via `GET /api/public-key`.
- Admin CRUD (`/admin/licenses`, HTTP Basic auth via `DRWP_ADMIN_TOKEN`).

## Environment

See `.env.example`. `DRWP_ADMIN_TOKEN` is required for any `/admin/*`
request and is checked with constant-time comparison.

## Endpoints

- `GET  /api/public-key` — public key (base64, raw 32-byte Ed25519).
- `POST /api/check` — `{license_key, domain}` → signed license state.
- `GET  /healthz`
- `GET    /admin/licenses` (list)
- `POST   /admin/licenses` (create)
- `GET    /admin/licenses/{license_key}` (read)
- `PATCH  /admin/licenses/{license_key}` (partial update)
- `DELETE /admin/licenses/{license_key}` (delete)

## Signature format

The server signs the canonical JSON of the response body with every
field except `signature` included, with keys sorted and no extra
whitespace (`sort_keys=True, separators=(",", ":")`). Verifiers must
reconstruct the same canonical form before calling `verify`.

## Running tests

```
pip install -r requirements.txt pytest httpx
pytest
```
