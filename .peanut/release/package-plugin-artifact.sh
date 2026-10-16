#!/usr/bin/env bash
# Package an already staged plugin; strip VCS metadata only from this copy.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAGE="${1:?usage: package-plugin-artifact.sh <stage-dir> <slug> <output-zip>}"
SLUG="${2:?plugin slug required}"
ZIP="${3:?absolute output ZIP required}"
[[ "$SLUG" =~ ^[a-z0-9][a-z0-9-]*$ ]] || exit 1
[[ "$ZIP" = /* && ! -e "$ZIP" ]] || { echo "Use a fresh absolute ZIP path" >&2; exit 1; }
cd "$STAGE"
[ -d "$SLUG" ] && [ ! -L "$SLUG" ] || { echo "Stage must contain a real plugin directory" >&2; exit 1; }
# Includes nested Composer source checkouts, submodule gitfiles, and symlinks.
find "$SLUG" -name .git -prune -exec rm -rf -- {} +
find "$SLUG" -name .DS_Store -delete
zip -rq "$ZIP" "$SLUG"
bash "$HERE/verify-plugin-artifact.sh" "$ZIP"
