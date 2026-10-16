#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WRAPPER="$ROOT/scripts/run-pip-audit.sh"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/par510-pip-audit-test.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

fail() { echo "FAIL: $1" >&2; exit 1; }

cat > "$TMP/fake-pip-audit" <<'EOF'
#!/usr/bin/env bash
count=0
[ ! -f "$FAKE_COUNT" ] || count=$(<"$FAKE_COUNT")
count=$((count + 1))
printf '%s' "$count" > "$FAKE_COUNT"

case "$FAKE_MODE" in
  clean)
    printf '%s\n' '{"dependencies":[{"name":"safe","version":"1.0","vulns":[]}],"fixes":[]}'
    exit 0
    ;;
  finding)
    printf '%s\n' '{"dependencies":[{"name":"bad","version":"1.0","vulns":[{"id":"PYSEC-1","fix_versions":[]}]}],"fixes":[]}'
    exit 1
    ;;
  recover)
    if [ "$count" -lt 3 ]; then exit 1; fi
    printf '%s\n' '{"dependencies":[{"name":"safe","version":"1.0","vulns":[]}],"fixes":[]}'
    exit 0
    ;;
  exhaust)
    exit 1
    ;;
  mismatch)
    printf '%s\n' '{"dependencies":[{"name":"safe","version":"1.0","vulns":[]}],"fixes":[]}'
    exit 1
    ;;
  config)
    exit 2
    ;;
  malformed-success)
    printf '%s\n' '{"unexpected":true}'
    exit 0
    ;;
  *) exit 99 ;;
esac
EOF
chmod +x "$TMP/fake-pip-audit"

run_wrapper() {
  local mode="$1"
  local count_file="$2"
  FAKE_MODE="$mode" FAKE_COUNT="$count_file" PIP_AUDIT_RETRY_DELAY=0 \
    "$WRAPPER" "$TMP/fake-pip-audit" -r requirements.lock --no-deps --disable-pip
}

run_wrapper clean "$TMP/clean-count" >/dev/null || fail "clean result should pass"
[ "$(<"$TMP/clean-count")" -eq 1 ] || fail "clean result should run once"

set +e
run_wrapper finding "$TMP/finding-count" >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 1 ] || fail "valid finding result should stay red"
[ "$(<"$TMP/finding-count")" -eq 1 ] || fail "valid finding result must not retry"

run_wrapper recover "$TMP/recover-count" >/dev/null 2>&1 || fail "third-attempt valid recovery should pass"
[ "$(<"$TMP/recover-count")" -eq 3 ] || fail "recovery should use exactly three attempts"

set +e
exhaustion="$(run_wrapper exhaust "$TMP/exhaust-count" 2>&1)"
status=$?
set -e
[ "$status" -eq 1 ] || fail "unavailable result should fail closed"
[ "$(<"$TMP/exhaust-count")" -eq 3 ] || fail "unavailable result should stop after three attempts"
grep -q 'result unavailable after 3 attempt(s)' <<<"$exhaustion" || fail "exhaustion class should be explicit"

set +e
mismatch="$(run_wrapper mismatch "$TMP/mismatch-count" 2>&1)"
status=$?
set -e
[ "$status" -eq 1 ] || fail "exit/result mismatch should fail"
[ "$(<"$TMP/mismatch-count")" -eq 1 ] || fail "valid mismatched result must not retry"
grep -q 'exit/result mismatch' <<<"$mismatch" || fail "mismatch class should be explicit"

set +e
run_wrapper config "$TMP/config-count" >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 2 ] || fail "non-result exit 2 should be preserved"
[ "$(<"$TMP/config-count")" -eq 1 ] || fail "non-result exit 2 must not retry"

set +e
run_wrapper malformed-success "$TMP/malformed-count" >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 2 ] || fail "malformed success should become scanner-contract exit 2"
[ "$(<"$TMP/malformed-count")" -eq 1 ] || fail "malformed success must not retry"

set +e
PIP_AUDIT_MAX_ATTEMPTS=0 "$WRAPPER" "$TMP/fake-pip-audit" -r requirements.lock >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 2 ] || fail "invalid retry configuration should fail before scanning"

echo "ALL PIP-AUDIT TRANSPORT TESTS PASSED"
