#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/wp-contract.yml"
MYSQL_IMAGE='mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb'
MARIADB_IMAGE='mariadb:10.6.28-jammy@sha256:92e50059ea0a5965a33ef751970eab37d421b91ebbd01ac909039cffe159e574'
TMP="$(mktemp -d "${TMPDIR:-/tmp}/connect-image-pins.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

fail() { echo "FAIL: $1" >&2; exit 1; }

validate_workflow() {
  local workflow="$1"

  [ "$(grep -Fxc "        image: $MYSQL_IMAGE" "$workflow" || true)" -eq 1 ] \
    || return 1
  [ "$(grep -Fxc "        image: $MARIADB_IMAGE" "$workflow" || true)" -eq 1 ] \
    || return 1

  if grep -Eq '^[[:space:]]+image:[[:space:]]+(mysql|mariadb):[^@[:space:]]+[[:space:]]*$' "$workflow"; then
    return 1
  fi
}

validate_workflow "$WORKFLOW" || fail "wp-contract database services are not pinned to the reviewed exact tag and digest"

sed "s#$MYSQL_IMAGE#mysql:8#" "$WORKFLOW" > "$TMP/mutable-mysql.yml"
if validate_workflow "$TMP/mutable-mysql.yml"; then
  fail "validator accepted a mutable MySQL tag"
fi

sed "s#$MARIADB_IMAGE#mariadb:10.6#" "$WORKFLOW" > "$TMP/mutable-mariadb.yml"
if validate_workflow "$TMP/mutable-mariadb.yml"; then
  fail "validator accepted a mutable MariaDB tag"
fi

echo "PEANUT CONNECT WORDPRESS SERVICE-IMAGE CONTRACT PASSED"
