#!/usr/bin/env bash
# Run a locked pip-audit binary with bounded, result-aware retries.
#
# pip-audit 2.10.1 exits 1 for both real findings and fatal service/source
# errors. JSON is therefore the evidence boundary: a structurally valid result
# is terminal (clean or findings), while a missing result may retry a bounded
# number of times. Exit/result disagreement is a scanner-contract failure and
# never becomes green.

set -u

if [ "$#" -lt 2 ]; then
  echo "usage: $0 AUDITOR PIP_AUDIT_ARGS..." >&2
  exit 2
fi

auditor="$1"
shift
max_attempts="${PIP_AUDIT_MAX_ATTEMPTS:-3}"
retry_delay="${PIP_AUDIT_RETRY_DELAY:-2}"

case "$max_attempts" in
  ''|*[!0-9]*|0) echo "invalid PIP_AUDIT_MAX_ATTEMPTS '$max_attempts'" >&2; exit 2 ;;
esac
case "$retry_delay" in
  ''|*[!0-9]*) echo "invalid PIP_AUDIT_RETRY_DELAY '$retry_delay'" >&2; exit 2 ;;
esac

tmp="$(mktemp -d "${TMPDIR:-/tmp}/peanut-pip-audit.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

attempt=1
while :; do
  audit_rc=0
  "$auditor" "$@" --format json > "$tmp/result.json" 2> "$tmp/stderr" || audit_rc=$?

  shape="$(python3 - "$tmp/result.json" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        data = json.load(handle)
    dependencies = data["dependencies"]
    fixes = data["fixes"]
    if not isinstance(dependencies, list) or not isinstance(fixes, list):
        raise ValueError("invalid root fields")
    vulnerabilities = 0
    for dependency in dependencies:
        if not isinstance(dependency, dict) or not isinstance(dependency.get("name"), str):
            raise ValueError("invalid dependency")
        if "skip_reason" in dependency:
            if not isinstance(dependency["skip_reason"], str):
                raise ValueError("invalid skip reason")
            continue
        if not isinstance(dependency.get("version"), str):
            raise ValueError("invalid dependency version")
        vulns = dependency.get("vulns")
        if not isinstance(vulns, list) or not all(isinstance(item, dict) for item in vulns):
            raise ValueError("invalid vulnerability list")
        vulnerabilities += len(vulns)
except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError):
    print("INVALID")
else:
    print(f"VALID\t{len(dependencies)}\t{vulnerabilities}")
PY
)"

  state="${shape%%$'\t'*}"
  if [ "$state" = "VALID" ]; then
    vulnerability_count="${shape##*$'\t'}"
    cat "$tmp/result.json"
    if [ "$audit_rc" -eq 0 ] && [ "$vulnerability_count" -eq 0 ]; then
      exit 0
    fi
    if [ "$audit_rc" -eq 1 ] && [ "$vulnerability_count" -gt 0 ]; then
      exit 1
    fi
    echo "pip-audit exit/result mismatch (exit $audit_rc, vulnerabilities $vulnerability_count); failing closed" >&2
    [ "$audit_rc" -ne 0 ] && exit "$audit_rc"
    exit 1
  fi

  if [ "$audit_rc" -ne 1 ]; then
    echo "pip-audit returned no valid JSON result (exit $audit_rc); non-retryable scanner failure" >&2
    [ "$audit_rc" -ne 0 ] && exit "$audit_rc"
    exit 2
  fi
  if [ "$attempt" -ge "$max_attempts" ]; then
    echo "pip-audit result unavailable after $attempt attempt(s); failing closed" >&2
    exit 1
  fi

  echo "pip-audit returned no valid result; retrying attempt $((attempt + 1)) of $max_attempts" >&2
  [ "$retry_delay" -eq 0 ] || sleep $((attempt * retry_delay))
  attempt=$((attempt + 1))
done
