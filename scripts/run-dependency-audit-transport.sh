#!/usr/bin/env bash
# Run Composer/npm advisory checks with bounded, result-aware transport retries.
# Valid findings are terminal; cached or offline results are never accepted.
set -uo pipefail

mode="${1:-all}"
max_attempts="${PEANUT_AUDIT_MAX_ATTEMPTS:-3}"
retry_delay="${PEANUT_AUDIT_RETRY_DELAY:-2}"

case "$mode" in
  all|composer|npm) ;;
  *) echo "invalid audit mode: $mode" >&2; exit 2 ;;
esac
case "$max_attempts" in
  ''|*[!0-9]*|0) echo "PEANUT_AUDIT_MAX_ATTEMPTS must be a positive integer" >&2; exit 2 ;;
esac
case "$retry_delay" in
  ''|*[!0-9]*) echo "PEANUT_AUDIT_RETRY_DELAY must be a non-negative integer" >&2; exit 2 ;;
esac

retry_pause() {
  local attempt="$1"
  [ "$retry_delay" -eq 0 ] || sleep "$((attempt * retry_delay))"
}

expected_composer_exit() {
  # The variables in the next line belong to PHP, not the shell.
  # shellcheck disable=SC2016
  php -r '$r=json_decode(file_get_contents($argv[1]), true); if (!is_array($r) || !isset($r["advisories"], $r["abandoned"]) || !is_array($r["advisories"]) || !is_array($r["abandoned"])) { exit(2); } echo count($r["advisories"]) + count($r["abandoned"]) > 0 ? "1" : "0";' "$1"
}

expected_npm_exit() {
  node -e 'const fs = require("node:fs"); try { const result = JSON.parse(fs.readFileSync(process.argv[1], "utf8")); const counts = result?.metadata?.vulnerabilities; const keys = ["info", "low", "moderate", "high", "critical"]; if (!counts || !keys.every((key) => Number.isInteger(counts[key]) && counts[key] >= 0) || !Number.isInteger(counts.total) || counts.total < 0 || keys.reduce((sum, key) => sum + counts[key], 0) !== counts.total) process.exit(2); process.stdout.write(counts.moderate + counts.high + counts.critical > 0 ? "1" : "0"); } catch { process.exit(2); }' "$1"
}

run_composer_audit() {
  local attempt=1 audit_rc=0 result_file error_file expected_rc
  result_file="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/peanut-composer-audit.XXXXXX")"
  error_file="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/peanut-composer-audit-error.XXXXXX")"
  while :; do
    audit_rc=0
    composer audit --locked --no-interaction --format=json >"$result_file" 2>"$error_file" || audit_rc=$?
    cat "$result_file"
    cat "$error_file" >&2
    if expected_rc="$(expected_composer_exit "$result_file")"; then
      rm -f "$result_file" "$error_file"
      if [ "$audit_rc" -eq "$expected_rc" ]; then
        return "$audit_rc"
      fi
      echo "Composer audit exit/result mismatch (exit $audit_rc); failing closed." >&2
      [ "$audit_rc" -ne 0 ] && return "$audit_rc"
      return 2
    fi
    if [ "$audit_rc" -ne 100 ]; then
      rm -f "$result_file" "$error_file"
      echo "Composer audit produced no complete result (exit $audit_rc); non-retryable scanner failure." >&2
      [ "$audit_rc" -ne 0 ] && return "$audit_rc"
      return 2
    fi
    if [ "$attempt" -ge "$max_attempts" ]; then
      rm -f "$result_file" "$error_file"
      echo "Composer audit transport unavailable after $attempt attempt(s)." >&2
      return 70
    fi
    echo "Composer audit transport unavailable; retrying attempt $((attempt + 1)) of $max_attempts." >&2
    retry_pause "$attempt"
    attempt=$((attempt + 1))
  done
}

run_npm_audit() {
  local attempt=1 audit_rc=0 result_file expected_rc
  result_file="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/peanut-npm-audit.XXXXXX")"
  while :; do
    audit_rc=0
    npm audit --omit=dev --audit-level=moderate --json >"$result_file" || audit_rc=$?
    cat "$result_file"
    if expected_rc="$(expected_npm_exit "$result_file")"; then
      rm -f "$result_file"
      if [ "$audit_rc" -eq "$expected_rc" ]; then
        return "$audit_rc"
      fi
      echo "npm audit exit/result mismatch (exit $audit_rc); failing closed." >&2
      [ "$audit_rc" -ne 0 ] && return "$audit_rc"
      return 2
    fi
    if [ "$audit_rc" -ne 0 ] && [ "$audit_rc" -ne 1 ]; then
      rm -f "$result_file"
      echo "npm audit produced no usable result (exit $audit_rc); non-retryable scanner failure." >&2
      return "$audit_rc"
    fi
    if [ "$attempt" -ge "$max_attempts" ]; then
      rm -f "$result_file"
      echo "npm audit transport unavailable after $attempt attempt(s)." >&2
      return 70
    fi
    echo "npm audit transport unavailable; retrying attempt $((attempt + 1)) of $max_attempts." >&2
    retry_pause "$attempt"
    attempt=$((attempt + 1))
  done
}

overall=0
if [ "$mode" = all ] || [ "$mode" = composer ]; then
  run_composer_audit || { audit_rc=$?; [ "$overall" -ne 0 ] || overall="$audit_rc"; }
fi
if [ "$mode" = all ] || [ "$mode" = npm ]; then
  run_npm_audit || { audit_rc=$?; [ "$overall" -ne 0 ] || overall="$audit_rc"; }
fi
exit "$overall"
