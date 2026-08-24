#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/tests.yml"

fail() { echo "FAIL: $1" >&2; exit 1; }

[ -x "$ROOT/scripts/run-pip-audit.sh" ] || fail "pip-audit transport wrapper is missing or not executable"
[ -x "$ROOT/scripts/tests/dependency-audit-transport.test.sh" ] \
  || fail "pip-audit adversarial contract is missing or not executable"

wrapper_calls=$(grep -c 'scripts/run-pip-audit.sh' "$WORKFLOW" || true)
[ "$wrapper_calls" -eq 2 ] || fail "expected two wrapped pip-audit calls, found $wrapper_calls"

auditor_calls=$(grep -c 'dependency-audit-venv/bin/pip-audit' "$WORKFLOW" || true)
[ "$auditor_calls" -eq 2 ] || fail "expected two locked auditor calls, found $auditor_calls"

while IFS= read -r line; do
  [[ "$line" == *'scripts/run-pip-audit.sh'* ]] \
    || fail "locked pip-audit binary bypasses the result-aware wrapper"
done < <(grep 'dependency-audit-venv/bin/pip-audit' "$WORKFLOW")

grep -q 'bash scripts/tests/dependency-audit-transport.test.sh' "$WORKFLOW" \
  || fail "workflow does not execute the adversarial transport contract"
grep -q 'bash scripts/tests/dependency-audit-workflow.test.sh' "$WORKFLOW" \
  || fail "workflow does not execute its own wrapper-bypass contract"

echo "PEANUT CONNECT DEPENDENCY-AUDIT WORKFLOW CONTRACT PASSED"
