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
  # An EXISTING directory is not an installed WordPress. The old guard returned
  # on `[ -d "$WP_CORE_DIR" ]`, so an empty or half-extracted /tmp/wordpress
  # skipped the download and the script still printed "WP test suite ready" --
  # the failure only surfaced later, inside PHPUnit, as a missing core file:
  #   Failed opening required '/tmp/wordpress/wp-includes/class-wp-phpmailer.php'
  # Key on a file core cannot boot without instead.
  if [ -f "$WP_CORE_DIR/wp-includes/version.php" ]; then
    return
  fi
  mkdir -p "$WP_CORE_DIR"
  download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
  tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
  download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

# Never report readiness we have not checked. This script exists so plugins can
# boot a REAL WordPress; saying so while core is absent is the exact failure it
# was written to remove.
verify_install() {
  local missing=0 f
  # Only files EVERY supported WordPress ships. Version-specific paths do not
  # belong here: class-wp-phpmailer.php, for one, does not exist before 6.7,
  # so requiring it would fail a perfectly good 6.4 install.
  for f in "$WP_CORE_DIR/wp-includes/version.php" \
           "$WP_CORE_DIR/wp-settings.php" \
           "$WP_TESTS_DIR/includes/functions.php" \
           "$WP_TESTS_DIR/includes/bootstrap.php" \
           "$WP_TESTS_DIR/wp-tests-config.php"; do
    if [ ! -f "$f" ]; then
      echo "install-wp-tests: MISSING $f" >&2
      missing=1
    fi
  done
  if [ "$missing" -ne 0 ]; then
    echo "install-wp-tests: WordPress test environment is incomplete; refusing to report success." >&2
    exit 1
  fi
}

install_test_suite() {
  mkdir -p "$WP_TESTS_DIR"
  # The phpunit test library lives in the WP develop repo under tests/phpunit/.
  #
  # wordpress-develop tags are ALWAYS three-part: 6.4.0, 6.5.0 -- there is no
  # tag named "6.4". The matrix passes two-part series ("6.4", "6.5"), so the
  # old chain missed on both the full version AND the series and silently fell
  # through to trunk. That put TRUNK's test library against 6.4 core, which is
  # how a green-looking install produced
  #   Failed opening required '.../wp-includes/class-wp-phpmailer.php'
  # -- a file trunk's mock-mailer expects and 6.4 does not ship. Normalise the
  # series to X.Y.0 instead of guessing.
  local candidates="$WP_VERSION"
  if printf '%s' "$WP_VERSION" | grep -qE '^[0-9]+\.[0-9]+$'; then
    candidates="${WP_VERSION}.0 $WP_VERSION"
  fi

  local got=""
  for tag in $candidates; do
    if download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${tag}.tar.gz" /tmp/wp-develop.tar.gz; then
      got="$tag"; break
    fi
  done

  # Only trunk/nightly may USE trunk. Falling back to it for a pinned version
  # silently tests a version nobody asked for; fail loudly instead.
  if [ -z "$got" ]; then
    case "$WP_VERSION" in
      trunk|nightly|master)
        download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.tar.gz" /tmp/wp-develop.tar.gz
        got="trunk"
        ;;
      *)
        echo "install-wp-tests: no wordpress-develop tag for WP '${WP_VERSION}' (tried: ${candidates})." >&2
        echo "install-wp-tests: refusing to fall back to trunk -- a mismatched test library is worse than a clear failure." >&2
        exit 1
        ;;
    esac
  fi
  echo "install-wp-tests: test library from wordpress-develop ${got}"
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
verify_install
echo "WP test suite ready: WP_TESTS_DIR=${WP_TESTS_DIR} WP_CORE_DIR=${WP_CORE_DIR}"
