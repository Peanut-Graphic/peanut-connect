#!/usr/bin/env bash
# Offline artifact regression: source-installed Composer dependency + gitfile.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/stage/plugin/vendor/core/.git/objects" "$WORK/stage/plugin/submodule"
printf metadata > "$WORK/stage/plugin/vendor/core/.git/objects/example"
printf 'gitdir: /private/source' > "$WORK/stage/plugin/submodule/.git"
printf runtime > "$WORK/stage/plugin/vendor/core/runtime.php"
printf ignored > "$WORK/stage/plugin/vendor/core/.gitignore"
printf autoload > "$WORK/stage/plugin/vendor/autoload.php"
# The checker must reject both nested directories and standalone gitfiles.
(cd "$WORK/stage" && zip -rq "$WORK/dirty.zip" plugin)
if bash "$HERE/verify-plugin-artifact.sh" "$WORK/dirty.zip" >/dev/null 2>&1; then echo 'Accepted Git directory' >&2; exit 1; fi
(cd "$WORK/stage" && zip -q "$WORK/gitfile.zip" plugin/submodule/.git)
if bash "$HERE/verify-plugin-artifact.sh" "$WORK/gitfile.zip" >/dev/null 2>&1; then echo 'Accepted gitfile' >&2; exit 1; fi
bash "$HERE/package-plugin-artifact.sh" "$WORK/stage" plugin "$WORK/clean.zip"
[ "$(unzip -p "$WORK/clean.zip" plugin/vendor/core/runtime.php)" = runtime ]
[ "$(unzip -p "$WORK/clean.zip" plugin/vendor/autoload.php)" = autoload ]
[ "$(unzip -p "$WORK/clean.zip" plugin/vendor/core/.gitignore)" = ignored ]
printf invalid > "$WORK/invalid.zip"
if bash "$HERE/verify-plugin-artifact.sh" "$WORK/invalid.zip" >/dev/null 2>&1; then echo 'Accepted invalid ZIP' >&2; exit 1; fi
echo 'Artifact regression passed: metadata rejected/stripped; runtime preserved'
