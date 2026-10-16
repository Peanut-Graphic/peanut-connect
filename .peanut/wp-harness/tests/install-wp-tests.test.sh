#!/usr/bin/env bash
# Regression test for the vendored WordPress test-suite installer.
#
# GAP-03: proven to fail on the unfixed harness.
#
# The bug it pins: wordpress-develop tags are ALWAYS three-part (6.4.0), but
# CI matrices pass two-part series ("6.4"). The old chain missed on the full
# version AND the series, then silently fell back to trunk -- pairing TRUNK's
# test library with pinned core. The symptom appears much later inside PHPUnit
# as a missing core file and reads like a WordPress bug rather than ours. It
# cost peanut-webcomic-engine 84 days of red.
#
# No network: curl is stubbed on PATH and every download is logged.

set -uo pipefail

HARNESS="${1:-$(dirname "$0")/../install-wp-tests.sh}"
[ -f "$HARNESS" ] || { echo "harness not found: $HARNESS" >&2; exit 1; }

fails=0
check() { # check <description> <condition-result>
  if [ "$2" = "0" ]; then echo "  ok   - $1"; else echo "  FAIL - $1"; fails=$((fails+1)); fi
}

workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT

# Stub curl: log the requested URL, then fail as a 404 would.
mkdir -p "$workdir/bin"
cat > "$workdir/bin/curl" <<'STUB'
#!/usr/bin/env bash
for a in "$@"; do case "$a" in http*) echo "$a" >> "$CURL_LOG";; esac; done
exit 22
STUB
chmod +x "$workdir/bin/curl"
# wget is the fallback branch; stub it too so the test cannot reach the network.
cp "$workdir/bin/curl" "$workdir/bin/wget"
export CURL_LOG="$workdir/urls.log"
: > "$CURL_LOG"

run_harness() { # run_harness <wp-version> <core-dir>
  : > "$CURL_LOG"
  PATH="$workdir/bin:$PATH" \
  WP_TESTS_DIR="$workdir/tests-lib" WP_CORE_DIR="$2" \
    bash "$HARNESS" wordpress_test root root 127.0.0.1 "$1" true \
    > "$workdir/out.txt" 2> "$workdir/err.txt"
  echo $?
}

echo "harness tag resolution:"

# Core pre-seeded so install_wp() is satisfied on fixed AND unfixed alike,
# isolating the test-library tag logic.
core="$workdir/core"
mkdir -p "$core/wp-includes"
echo "<?php \$wp_version='6.4';" > "$core/wp-includes/version.php"
touch "$core/wp-settings.php"

status=$(run_harness 6.4 "$core")

grep -q "tags/6.4.0.tar.gz" "$CURL_LOG"; check "asks for the three-part tag 6.4.0 when given the 6.4 series" $?
! grep -q "refs/heads/trunk" "$CURL_LOG"; check "never silently falls back to trunk for a pinned version" $?
[ "$status" != "0" ]; check "exits non-zero when no matching tag can be fetched" $?
grep -qi "refusing to fall back to trunk" "$workdir/err.txt"; check "says why it refused, instead of failing obscurely later" $?

echo "core install guard:"

# An EMPTY directory is not an installed WordPress. The old guard returned on
# `[ -d "$WP_CORE_DIR" ]`, skipped the download, and still printed "ready".
empty="$workdir/empty-core"
mkdir -p "$empty"
run_harness 6.4 "$empty" > /dev/null

grep -q "wordpress.org/wordpress-6.4.tar.gz" "$CURL_LOG"; check "downloads core when the target directory exists but is empty" $?

echo
if [ "$fails" -ne 0 ]; then echo "$fails assertion(s) failed"; exit 1; fi
echo "all assertions passed"
