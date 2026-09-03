#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/tests.yml"
fail() { echo "FAIL: $1" >&2; exit 1; }

[ -x "$ROOT/scripts/run-dependency-audit-transport.sh" ] || fail "audit transport wrapper is missing or not executable"
[ -x "$ROOT/scripts/tests/composer-audit-transport.test.sh" ] || fail "Composer adversarial contract is missing or not executable"
[ -x "$ROOT/scripts/tests/npm-audit-transport.test.sh" ] || fail "npm adversarial contract is missing or not executable"
grep -q 'bash scripts/run-dependency-audit-transport.sh composer' "$WORKFLOW" || fail "Composer audit bypasses wrapper"
grep -q 'bash scripts/tests/composer-audit-transport.test.sh' "$WORKFLOW" || fail "Composer adversarial contract is not executed"
grep -q 'bash scripts/tests/composer-npm-audit-workflow.test.sh' "$WORKFLOW" || fail "workflow bypass contract is not executed"
grep -q 'bash ../scripts/run-dependency-audit-transport.sh npm' "$WORKFLOW" || fail "npm audit bypasses wrapper"
grep -q 'bash ../scripts/tests/npm-audit-transport.test.sh' "$WORKFLOW" || fail "npm adversarial contract is not executed"
! grep -Eq 'run:[[:space:]]+(composer|npm) audit' "$WORKFLOW" || fail "workflow retains a direct audit command"

echo "PEANUT CONNECT COMPOSER/NPM AUDIT WORKFLOW CONTRACT PASSED"
