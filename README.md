# DRWP Daily Reports

Two pieces in this repo:

- **`drwp-daily-reports/`** — the WordPress plugin (admin pages, REST
  API, dashboard widget, CSV import, photo attachments, post
  conversion). See `drwp-daily-reports/README.md` for the full feature
  list.
- **`license-server/`** — a standalone FastAPI server that issues
  Ed25519-signed license check responses. The plugin verifies these
  with libsodium so a tampered status can't unlock features. See
  `license-server/README.md` for endpoints.

## Local Docker quickstart

```sh
# 1. (optional) copy env defaults
cp .env.example .env

# 2. one-shot bootstrap: build, install WP, activate plugin,
#    seed a demo license, run the first license check
bash scripts/docker-setup.sh
```

When it finishes you have:

| Service           | URL                                                     |
| ----------------- | ------------------------------------------------------- |
| WordPress         | http://localhost:8080                                   |
| WP admin login    | `admin` / `adminpass`                                   |
| License JSON API  | http://localhost:8001                                   |
| License HTML UI   | http://localhost:8001/admin/ui/licenses                 |
| License admin tok | `demo-token` (Basic auth user `admin`, pwd = the token) |

The plugin source under `./drwp-daily-reports/` is **bind-mounted**
into the container, so editing PHP on the host shows up immediately
in WordPress without a rebuild.

### Day-to-day commands

```sh
# Tail the WordPress error log
docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log

# Run wp-cli inside the stack (any wp subcommand works)
docker compose run --rm wpcli plugin list

# Run the license server's pytest suite (locally — not in the stack)
cd license-server && pip install -r requirements.txt pytest httpx && pytest

# Stop everything (volumes preserved)
docker compose down

# Nuke and start fresh (drops MySQL, WP uploads, license keys + DB)
docker compose down -v
```

### File layout

```
docker-compose.yml         WP + MySQL + license-server stack
scripts/docker-setup.sh    Idempotent bootstrap script
.env.example               Tunables (host ports, admin creds, etc.)

drwp-daily-reports/        WordPress plugin source (bind-mounted into
                           wordpress container)
license-server/            FastAPI server source (built from Dockerfile)
.github/workflows/ci.yml   php -l matrix + pytest on PRs
```

## Production notes

- The Docker stack is for local development. For production, deploy
  WordPress and the license server separately, use TLS in front of
  both, set a strong `DRWP_ADMIN_TOKEN`, and persist the signing key
  outside the container image.
- The plugin-side `Requires PHP: 7.4` baseline is enforced by the CI
  matrix. The bundled WordPress image runs PHP 8.2.
- Public keys rotate via `POST /admin/rotate-signing-key`; old
  signatures keep validating against the previous-key set (rolling
  cap of 3) until clients refresh `/api/public-key`.
