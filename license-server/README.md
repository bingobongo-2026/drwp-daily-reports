# license-server v1.9

Standalone license server for DRWP.

操作手順 (起動・ライセンス発行・鍵ローテーション・トラブル
シューティング) は [MANUAL.md](./MANUAL.md) を参照してください。
こちらはエンドポイントと環境変数のリファレンスです。

## v1.9 notes

- SQLite-backed license store (`DRWP_LICENSE_DB`).
- Ed25519 signing of `/api/check` responses. The private key lives at
  `DRWP_SIGNING_KEY` and is generated on first start if missing. The
  public key is exposed in base64 form via `GET /api/public-key`.
- Admin CRUD (`/admin/licenses`, HTTP Basic auth via `DRWP_ADMIN_TOKEN`).
- HTML admin UI at `/admin/ui/licenses` for non-JSON operators. Same
  Basic auth realm as the JSON API.

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
- `GET    /admin/ui/licenses` (HTML list)
- `GET    /admin/ui/licenses/new` (HTML create form)
- `GET    /admin/ui/licenses/{license_key}/edit` (HTML edit form)
- `POST   /admin/ui/licenses` and `.../{license_key}/edit`, `.../{license_key}/delete`
  (form actions; redirect with a `?msg=...` flash)

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
