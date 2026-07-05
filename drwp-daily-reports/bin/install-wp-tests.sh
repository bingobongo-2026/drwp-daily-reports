#!/usr/bin/env bash
# Install WordPress test library + a test database.
#
# 取得元は github の WordPress/wordpress-develop 一本に統一している。
# 旧実装は wordpress.org (コア) と develop.svn.wordpress.org (テスト
# スイート) からダウンロードしていたが、CI ランナーからこれらへの
# 到達性が不安定で毎回 PHPUnit ジョブが落ちていた。wordpress-develop
# は「src/ を ABSPATH、tests/phpunit をテストスイート」とする WP 公式の
# 開発リポジトリで、これ一つでコア + テスト一式が揃う。github は
# checkout / raw 取得が通る環境なので安定する。
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

# wordpress-develop のどのブランチ / タグを使うか。
#   latest      -> trunk (最新の開発版)
#   X.Y[.Z]     -> tags/X.Y[.Z]
if [ "$WP_VERSION" = "latest" ]; then
  DEV_REF="refs/heads/trunk"
else
  DEV_REF="refs/tags/${WP_VERSION}"
fi
DEV_TARBALL="https://github.com/WordPress/wordpress-develop/archive/${DEV_REF}.tar.gz"
DEV_DIR="/tmp/wordpress-develop-src"

download() {
  # $1 url, $2 dest — transient なネットワーク / HTTP エラーはリトライ。
  if command -v curl >/dev/null; then
    curl -fsSL --retry 3 --retry-delay 2 -o "$2" "$1"
  else
    wget -nv --tries=3 -O "$2" "$1"
  fi
}

fetch_develop() {
  if [ -d "$DEV_DIR" ]; then
    return
  fi
  echo "Downloading wordpress-develop: $DEV_TARBALL"
  download "$DEV_TARBALL" /tmp/wp-develop.tar.gz
  local extract=/tmp/wp-develop-extract
  rm -rf "$extract"
  mkdir -p "$extract"
  # --strip-components=1 で `wordpress-develop-<ref>/` の 1 階層を剥がす。
  tar --strip-components=1 -xzf /tmp/wp-develop.tar.gz -C "$extract"
  mv "$extract" "$DEV_DIR"
}

install_wp() {
  # WP コア = wordpress-develop の src/ (テスト設定の既定 ABSPATH)。
  if [ -d "$WP_CORE_DIR" ]; then
    return
  fi
  fetch_develop
  mkdir -p "$WP_CORE_DIR"
  cp -a "$DEV_DIR/src/." "$WP_CORE_DIR/"
  # mysqli ドロップイン (無くても最近の PHP なら動くので任意)。
  download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php \
    "$WP_CORE_DIR/wp-content/db.php" || true
}

install_test_suite() {
  # テストスイート = wordpress-develop の tests/phpunit/{includes,data}。
  if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    fetch_develop
    mkdir -p "$WP_TESTS_DIR"
    cp -a "$DEV_DIR/tests/phpunit/includes" "$WP_TESTS_DIR/"
    cp -a "$DEV_DIR/tests/phpunit/data" "$WP_TESTS_DIR/"
  fi

  if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    fetch_develop
    cp "$DEV_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    # ABSPATH を WP_CORE_DIR に、DB 資格情報を引数の値に差し替える。
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
  # DB が既に存在する場合 (CI の MySQL service が MYSQL_DATABASE で
  # 先に作成しているケース等) はエラーを握りつぶして続行する。
  # set -e 下で「database exists」で全体が落ちるのを防ぐ。
  if ! mysqladmin "${creds[@]}" create "$DB_NAME" 2>/dev/null; then
    echo "Database '$DB_NAME' already exists or could not be created; continuing."
  fi
}

install_wp
install_test_suite
install_db

echo "Done."
echo "  WP_CORE_DIR  = $WP_CORE_DIR"
echo "  WP_TESTS_DIR = $WP_TESTS_DIR"
