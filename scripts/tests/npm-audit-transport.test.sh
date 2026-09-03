#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WRAPPER="$ROOT/scripts/run-dependency-audit-transport.sh"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/par510-composer-npm-test.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
fail() { echo "FAIL: $1" >&2; exit 1; }

cat >"$TMP/composer" <<'EOF'
#!/usr/bin/env bash
count=0
[ ! -f "$FAKE_COUNT" ] || count=$(<"$FAKE_COUNT")
count=$((count + 1))
printf '%s' "$count" >"$FAKE_COUNT"
case "$FAKE_MODE" in
  clean) printf '%s\n' '{"advisories":[],"abandoned":[]}' ; exit 0 ;;
  finding) printf '%s\n' '{"advisories":{"CVE-1":{}},"abandoned":[]}' ; exit 1 ;;
  recover) [ "$count" -lt 3 ] && exit 100; printf '%s\n' '{"advisories":[],"abandoned":[]}' ; exit 0 ;;
  exhaust) exit 100 ;;
  config) exit 2 ;;
  mismatch) printf '%s\n' '{"advisories":[],"abandoned":[]}' ; exit 2 ;;
  false-green) printf '%s\n' '{"advisories":{"CVE-1":{}},"abandoned":[]}' ; exit 0 ;;
  malformed) printf '%s\n' '{"unexpected":true}' ; exit 0 ;;
esac
EOF
cat >"$TMP/npm" <<'EOF'
#!/usr/bin/env bash
count=0
[ ! -f "$FAKE_COUNT" ] || count=$(<"$FAKE_COUNT")
count=$((count + 1))
printf '%s' "$count" >"$FAKE_COUNT"
case "$FAKE_MODE" in
  clean) printf '%s\n' '{"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0}}}' ; exit 0 ;;
  low) printf '%s\n' '{"metadata":{"vulnerabilities":{"info":0,"low":1,"moderate":0,"high":0,"critical":0,"total":1}}}' ; exit 0 ;;
  finding) printf '%s\n' '{"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":1,"critical":0,"total":1}}}' ; exit 1 ;;
  recover) [ "$count" -lt 3 ] && { printf '%s\n' '{"error":{"code":"E503"}}'; exit 1; }; printf '%s\n' '{"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0}}}' ; exit 0 ;;
  exhaust) printf '%s\n' '{"error":{"code":"E503"}}'; exit 1 ;;
  config) exit 2 ;;
  mismatch) printf '%s\n' '{"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0}}}' ; exit 2 ;;
  false-green) printf '%s\n' '{"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":1,"critical":0,"total":1}}}' ; exit 0 ;;
  malformed) printf '%s\n' '{"unexpected":true}' ; exit 0 ;;
esac
EOF
chmod +x "$TMP/composer" "$TMP/npm"

run_case() {
  local scanner="$1" mode="$2" expected="$3" expected_calls="$4"
  local count="$TMP/$scanner-$mode-count"
  set +e
  PATH="$TMP:$PATH" FAKE_MODE="$mode" FAKE_COUNT="$count" PEANUT_AUDIT_RETRY_DELAY=0 "$WRAPPER" "$scanner" >/dev/null 2>&1
  status=$?
  set -e
  [ "$status" -eq "$expected" ] || fail "$scanner $mode returned $status, expected $expected"
  [ "$(<"$count")" -eq "$expected_calls" ] || fail "$scanner $mode used wrong attempt count"
}

run_case npm clean 0 1
run_case npm low 0 1
run_case npm finding 1 1
run_case npm recover 0 3
run_case npm exhaust 70 3
run_case npm config 2 1
run_case npm mismatch 2 1
run_case npm false-green 2 1
run_case npm malformed 70 3

set +e
PEANUT_AUDIT_MAX_ATTEMPTS=0 "$WRAPPER" npm >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 2 ] || fail "invalid retry configuration did not fail before scanning"

echo "PEANUT CONNECT NPM AUDIT TRANSPORT CONTRACT PASSED"
