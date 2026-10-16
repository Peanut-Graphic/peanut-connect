#!/usr/bin/env bash
set -euo pipefail

validator="${1:-.peanut/platform/validate.py}"
validator_dir="$(cd "$(dirname "$validator")" && pwd)"
repository_root="$(cd "$validator_dir/../.." && pwd)"
validator="$validator_dir/$(basename "$validator")"
expected_lock="$repository_root/.peanut/platform/requirements.lock"

set +e
output="$(
    python3 -S "$validator" \
        --strict \
        --schema "$repository_root/.peanut/platform/schema.json" \
        "$repository_root/.peanut/platform.yml" 2>&1
)"
status=$?
set -e

test "$status" -eq 2
printf '%s\n' "$output" | grep -F -- '--require-hashes --no-deps'
printf '%s\n' "$output" | grep -F "$expected_lock"

printf '%s\n' "platform validator lock-message contract: PASS"
