#!/usr/bin/env bash
# Install WordPress test library + a test database. Adapted from the
# scaffolding script that wp-cli/scaffold-plugin generates.
#
# Usage:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

WP_TESTS_DIR="${WP_TESTS_DIR-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-/tmp/wordpress}"

download() {
  if command -v curl >/dev/null; then
    curl -fsSL -o "$2" "$1"
  else
    wget -nv -O "$2" "$1"
  fi
}

install_wp() {
  if [ -d "$WP_CORE_DIR" ]; then
    return
  fi
  mkdir -p "$WP_CORE_DIR"

  if [ "$WP_VERSION" = "latest" ]; then
    local archive="https://wordpress.org/latest.tar.gz"
  else
    local archive="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
  fi
  echo "Downloading WordPress core: $archive"
  download "$archive" /tmp/wordpress.tar.gz
  tar --strip-components=1 -xzf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

  download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php" || true
}

install_test_suite() {
  if [ ! -d "$WP_TESTS_DIR" ]; then
    mkdir -p "$WP_TESTS_DIR"
    echo "Cloning WordPress develop test library"
    if command -v svn >/dev/null; then
      svn export --quiet --ignore-externals \
        "https://develop.svn.wordpress.org/${WP_VERSION/#latest/trunk}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
      svn export --quiet --ignore-externals \
        "https://develop.svn.wordpress.org/${WP_VERSION/#latest/trunk}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    else
      # Fallback to git tarball.
      download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.tar.gz" /tmp/wp-develop.tar.gz
      tar -xzf /tmp/wp-develop.tar.gz -C /tmp
      cp -r /tmp/wordpress-develop-trunk/tests/phpunit/includes "$WP_TESTS_DIR/"
      cp -r /tmp/wordpress-develop-trunk/tests/phpunit/data "$WP_TESTS_DIR/"
    fi
  fi

  if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    download \
      "https://develop.svn.wordpress.org/${WP_VERSION/#latest/trunk}/wp-tests-config-sample.php" \
      "$WP_TESTS_DIR/wp-tests-config.php" || \
    cp /tmp/wordpress-develop-trunk/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"

    sed -i.bak \
      -e "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" \
      -e "s/youremptytestdbnamehere/$DB_NAME/" \
      -e "s/yourusernamehere/$DB_USER/" \
      -e "s/yourpasswordhere/$DB_PASS/" \
      -e "s|localhost|$DB_HOST|" \
      "$WP_TESTS_DIR/wp-tests-config.php"
  fi
}

install_db() {
  if [ "$SKIP_DB_CREATE" = "true" ]; then
    return
  fi
  local creds=("-u$DB_USER" "-h$DB_HOST")
  [ -n "$DB_PASS" ] && creds+=("-p$DB_PASS")
  mysqladmin "${creds[@]}" create "$DB_NAME" --force
}

install_wp
install_test_suite
install_db

echo "Done."
echo "  WP_CORE_DIR  = $WP_CORE_DIR"
echo "  WP_TESTS_DIR = $WP_TESTS_DIR"
