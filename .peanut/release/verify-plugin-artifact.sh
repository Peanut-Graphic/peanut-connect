#!/usr/bin/env bash
# Inspect the actual ZIP, not just the source tree. No key or network required.
set -euo pipefail
ZIP="${1:?usage: verify-plugin-artifact.sh <zip>}"
LIST="$(mktemp)"; trap 'rm -f "$LIST"' EXIT
unzip -tqq "$ZIP" || { echo "Invalid release ZIP" >&2; exit 1; }
unzip -Z1 "$ZIP" > "$LIST"
if grep -Eq '(^|/)\.git(/|$)' "$LIST"; then
  echo "Release ZIP contains Git metadata" >&2
  grep -E '(^|/)\.git(/|$)' "$LIST" >&2
  exit 1
fi
echo "Artifact contains no Git metadata"
