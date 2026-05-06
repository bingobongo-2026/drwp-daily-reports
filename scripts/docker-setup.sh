#!/usr/bin/env bash
# One-command bootstrap for the local Docker stack.
#
# - Brings up db / wordpress / license
# - Installs WordPress core and an admin user
# - Activates the plugin
# - Creates a demo license on the license server
# - Configures the plugin to point at the license server, fetches the
#   public key, and runs an initial check
#
# Re-running this script is safe (idempotent).
set -euo pipefail

cd "$(dirname "$0")/.."

# Tunables (override via env or .env).
WP_URL="${WP_URL:-http://localhost:${WP_PORT:-8080}}"
WP_TITLE="${WP_TITLE:-DRWP Demo}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-adminpass}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.test}"

LICENSE_PORT_HOST="${LICENSE_PORT:-8001}"
LICENSE_URL_HOST="http://localhost:${LICENSE_PORT_HOST}"
# What the plugin (running inside the wordpress container) uses to
# reach the license container — service name on the docker network.
LICENSE_URL_INTERNAL="${LICENSE_URL_INTERNAL:-http://license:8000}"
LICENSE_DEMO_KEY="${LICENSE_DEMO_KEY:-DEMO-LOCAL}"
DRWP_ADMIN_TOKEN="${DRWP_ADMIN_TOKEN:-demo-token}"

say() { printf '\033[1;36m==>\033[0m %s\n' "$*"; }

say "Building and starting the stack"
docker compose up -d --build

say "Waiting for WordPress to come up at $WP_URL"
for i in $(seq 1 60); do
  if curl -fsS -o /dev/null "$WP_URL/wp-login.php"; then break; fi
  sleep 2
done
curl -fsS -o /dev/null "$WP_URL/wp-login.php" || { echo "WordPress did not respond"; exit 1; }

say "Waiting for the license server"
for i in $(seq 1 30); do
  if curl -fsS -o /dev/null "$LICENSE_URL_HOST/healthz"; then break; fi
  sleep 1
done
curl -fsS -o /dev/null "$LICENSE_URL_HOST/healthz" || { echo "License server did not respond"; exit 1; }

# Install WP if it isn't installed yet.
if ! docker compose run --rm -T wpcli core is-installed >/dev/null 2>&1; then
  say "Installing WordPress core"
  docker compose run --rm -T wpcli core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
else
  say "WordPress is already installed"
fi

say "Activating the DRWP plugin"
docker compose run --rm -T wpcli plugin activate drwp-daily-reports

# Create or upsert the demo license. POST returns 409 on duplicate, which
# is fine — the existing row is what we want anyway.
DOMAIN="$(printf '%s' "$WP_URL" | sed -E 's#^https?://##' | cut -d/ -f1 | cut -d: -f1)"
say "Creating demo license '$LICENSE_DEMO_KEY' for domain '$DOMAIN'"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
  -u "admin:$DRWP_ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -X POST "$LICENSE_URL_HOST/admin/licenses" \
  --data "{\"license_key\":\"$LICENSE_DEMO_KEY\",\"domain\":\"$DOMAIN\",\"plan\":\"pro\",\"status\":\"active\"}")
case "$HTTP_CODE" in
  201) echo "  created" ;;
  409) echo "  already exists" ;;
  *)   echo "  unexpected HTTP $HTTP_CODE"; exit 1 ;;
esac

say "Pointing the plugin at the license server and checking"
docker compose run --rm -T wpcli option update drwp_license_api_url "$LICENSE_URL_INTERNAL"
docker compose run --rm -T wpcli option update drwp_license_key "$LICENSE_DEMO_KEY"

# Trigger fetch_public_key + check_now via wp eval. Output mirrors the
# license admin screen so the operator can confirm the round-trip.
docker compose run --rm -T wpcli eval '
$pk = DRWP_License::fetch_public_key();
$cn = DRWP_License::check_now();
$state = DRWP_License::state();
echo "fetch_public_key: " . (is_wp_error($pk) ? $pk->get_error_message() : "OK") . PHP_EOL;
echo "check_now: " . (is_wp_error($cn) ? $cn->get_error_message() : $cn) . PHP_EOL;
echo "status=" . $state["status"] . " signature_valid=" . $state["signature_valid"] . PHP_EOL;
'

cat <<EOF

============================================================
Done.

  WordPress:       $WP_URL
  Admin login:     $WP_ADMIN_USER / $WP_ADMIN_PASSWORD
  License server:  $LICENSE_URL_HOST
  Admin token:     $DRWP_ADMIN_TOKEN

  License HTML UI: $LICENSE_URL_HOST/admin/ui/licenses (Basic auth: admin / $DRWP_ADMIN_TOKEN)
  Plugin pages:    $WP_URL/wp-admin/admin.php?page=drwp_reports

  Stop:    docker compose down
  Reset:   docker compose down -v   (drops volumes too)
============================================================
EOF
