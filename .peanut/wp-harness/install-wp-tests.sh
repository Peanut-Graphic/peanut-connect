#!/usr/bin/env bash
# Peanut shared WordPress test-suite installer.
# Installs the WordPress core test library + a test DB so plugins can boot a REAL
# WordPress in PHPUnit (net 7 REST contract tests) instead of hand-rolled mocks.
#
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
# In CI (with a mysql service): install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
#
# Adapted from the canonical WP-CLI scaffold script; pinned to bash + mysqli, no svn required.
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
  if command -v curl >/dev/null 2>&1; then curl -fsSL "$1" -o "$2"
  else wget -nv -O "$2" "$1"; fi
}

# Resolve "latest" / "nightly" to a concrete version + the matching test-suite tag.
if [ "$WP_VERSION" = "latest" ]; then
  download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
  WP_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | head -1 | sed 's/.*"version":"//')
fi
echo "Installing WordPress ${WP_VERSION} test suite (DB ${DB_NAME}@${DB_HOST})"

install_wp() {
  [ -d "$WP_CORE_DIR" ] && return
  mkdir -p "$WP_CORE_DIR"
  download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
  tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
  download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
  mkdir -p "$WP_TESTS_DIR"
  # The phpunit test library lives in the WP develop repo under tests/phpunit/.
  # Patch releases (e.g. 7.0.1) ship a core tarball but aren't always tagged in
  # wordpress-develop — fall back full-version → X.Y series tag → trunk.
  local series; series=$(printf '%s' "$WP_VERSION" | grep -oE '^[0-9]+\.[0-9]+' || true)
  download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" /tmp/wp-develop.tar.gz \
    || { [ -n "$series" ] && download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${series}.tar.gz" /tmp/wp-develop.tar.gz; } \
    || download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.tar.gz" /tmp/wp-develop.tar.gz
  local tmp; tmp=$(mktemp -d)
  tar --strip-components=1 -zxmf /tmp/wp-develop.tar.gz -C "$tmp"
  cp -r "$tmp/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
  cp -r "$tmp/tests/phpunit/data" "$WP_TESTS_DIR/data"
  if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    cp "$tmp/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i.bak "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i.bak "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i.bak "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
  fi
}

create_db() {
  [ "$SKIP_DB_CREATE" = "true" ] && return
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp 2>/dev/null || true
}

install_wp
install_test_suite
create_db
echo "WP test suite ready: WP_TESTS_DIR=${WP_TESTS_DIR} WP_CORE_DIR=${WP_CORE_DIR}"
