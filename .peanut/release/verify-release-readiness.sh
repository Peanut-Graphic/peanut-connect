#!/usr/bin/env bash
# verify-release-readiness.sh [slug] — can this repository be released AT ALL?
#
# The dry run that runs in CI, on every PR, with no signing key and no network.
#
# The fleet already has verify-plugin-release.sh, but it inspects a release that
# has ALREADY been published — by which point a packaging mistake is in front of
# clients. This is the other half: it proves, before anything ships, that the
# artifact the publisher would build is coherent.
#
# It deliberately checks the things that have actually gone wrong here:
#   - a version constant the publisher's bump silently failed to match
#   - a header version and a constant that disagree
#   - an INCLUDE list naming a path that no longer exists, so the package would
#     ship without a whole directory
#   - a composer build with no vendor/ in INCLUDE, so a bundled signature
#     verifier never reaches the site and every update goes unverified
#   - a changelog with nothing about the version being shipped
#   - a readme whose Stable tag line the publisher's bump cannot match, so it
#     silently ships a readme advertising the wrong version
#
# Read-only. No network, no key, no publish. PAR-406.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./plugin-release-config.sh
. "$HERE/plugin-release-config.sh"

ROOT="${PLUGIN_ROOT:-$PWD}"
SLUG="${1:-}"

# Infer the slug from the checkout when CI does not pass one.
if [ -z "$SLUG" ]; then
  SLUG="$(basename "$ROOT")"
fi

FAILED=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; FAILED=1; }
note() { printf '  \033[33m·\033[0m %s\n' "$1"; }
say()  { printf '\n\033[1;34m== %s\033[0m\n' "$1"; }

if ! peanut_release_config "$SLUG"; then
  echo "verify-release-readiness: '$SLUG' is not a released plugin — nothing to check." >&2
  exit 0
fi

say "Release readiness: $SLUG"
cd "$ROOT" || { echo "cannot enter $ROOT" >&2; exit 2; }

# ---- 1. main file + version agreement ---------------------------------------
if [ ! -f "$MAINPHP" ]; then
  bad "main plugin file $MAINPHP is missing"
else
  ok "main file $MAINPHP"
  HEADER_V="$(grep -m1 -oE 'Version: *[0-9][0-9.]*' "$MAINPHP" | grep -oE '[0-9][0-9.]*' || true)"
  [ -n "$HEADER_V" ] && ok "header version $HEADER_V" || bad "no 'Version:' header in $MAINPHP"

  for c in $VCONST; do
    # Spacing-tolerant on purpose: Booker writes define( 'X', '1.2.3' ) and a
    # no-space pattern silently matched nothing, which is how a signed package
    # ends up advertising the wrong version.
    CONST_V="$(grep -oE "define\(\s*'$c'\s*,\s*'[0-9][0-9.]*'" "$MAINPHP" | grep -oE "[0-9][0-9.]*" | head -1 || true)"
    if [ -z "$CONST_V" ]; then
      bad "version constant $c not found in $MAINPHP (the publisher's bump would silently do nothing)"
    elif [ "$CONST_V" != "$HEADER_V" ]; then
      bad "version constant $c is $CONST_V but the header says $HEADER_V"
    else
      ok "constant $c agrees with the header ($CONST_V)"
    fi
  done
fi

# ---- 2. everything the publisher would package must exist -------------------
if [ "${#INCLUDE[@]}" -eq 0 ]; then
  note "no INCLUDE list (BUILD=$BUILD ships the whole tree)"
else
  MISSING=0
  for path in "${INCLUDE[@]}"; do
    # vendor/ is produced by the build, not tracked — absence here is expected.
    [ "$path" = "vendor" ] && continue
    [ -e "$path" ] || { bad "INCLUDE names '$path', which does not exist — the package would ship without it"; MISSING=1; }
  done
  [ "$MISSING" -eq 0 ] && ok "all ${#INCLUDE[@]} INCLUDE paths present"
fi

# ---- 3. a composer build must actually ship vendor/ -------------------------
if [ "$BUILD" = "composer" ]; then
  if printf '%s\n' "${INCLUDE[@]}" | grep -qx vendor; then
    ok "BUILD=composer ships vendor/"
  else
    bad "BUILD=composer but vendor/ is not in INCLUDE — a bundled update verifier would never reach the site"
  fi
  [ -f composer.json ] || bad "BUILD=composer but composer.json is missing"
  [ -f composer.lock ] || bad "BUILD=composer but composer.lock is missing (an unlocked release is not reproducible)"
fi

# ---- 4. changelog mentions the version being shipped ------------------------
if [ -n "${HEADER_V:-}" ]; then
  # Check EVERY candidate, not just the first: some plugins keep their changelog
  # in readme.txt while also carrying a CHANGELOG.md, and stopping at the first
  # file found would report a fault that isn't there.
  FOUND_ANY=0; MENTIONS=0; SEARCHED=""
  for f in CHANGELOG.md changelog.md readme.txt README.md; do
    [ -f "$f" ] || continue
    FOUND_ANY=1; SEARCHED="$SEARCHED $f"
    grep -qF "$HEADER_V" "$f" && { MENTIONS=1; ok "$f documents $HEADER_V"; break; }
  done
  if [ "$FOUND_ANY" -eq 0 ]; then
    note "no changelog file found"
  elif [ "$MENTIONS" -eq 0 ]; then
    bad "nothing in$SEARCHED mentions $HEADER_V — clients would get an unexplained update"
  fi
fi

# ---- 5. the publisher's stable-tag bump can actually match -------------------
# NOT "does the tag equal the header". The publisher rewrites the header, the
# version constants AND the Stable tag from whatever main says to the version
# being released, so a tag trailing the header in main is the normal state
# between a version bump and its release -- Peanut Connect sat at header 3.37.0
# with Stable tag 3.36.0, which is correct, since 3.36.0 is what is released.
# Failing on that would have made this check red on every plugin with an
# unreleased bump pending.
#
# What IS worth asserting is the same failure the constant bump had: publish
# does `[ -f "$STABLE" ] && perl … || true`, which CANNOT fail. If the line is
# missing or written in a shape the pattern does not match, the bump silently
# does nothing and the released readme advertises the wrong version.
if [ -n "$STABLE" ]; then
  if [ ! -f "$STABLE" ]; then
    bad "$STABLE is declared for this plugin but does not exist"
  else
    TAG_V="$(grep -m1 -oE '^Stable tag: *[0-9][0-9.]*' "$STABLE" | grep -oE '[0-9][0-9.]*' || true)"
    if [ -z "$TAG_V" ]; then
      bad "$STABLE has no 'Stable tag: <version>' line the publisher's bump can match"
    else
      ok "Stable tag line is present and matchable (currently $TAG_V)"
      if [ -n "${HEADER_V:-}" ] && [ "$TAG_V" != "$HEADER_V" ]; then
        note "Stable tag $TAG_V trails header $HEADER_V — expected for an unreleased bump; publish rewrites it"
      fi
    fi
  fi
fi

echo
if [ "$FAILED" -ne 0 ]; then
  printf '\033[1;31mNOT RELEASABLE\033[0m — fix the above before cutting %s.\n' "$SLUG"
  exit 1
fi
printf '\033[1;32mRELEASABLE\033[0m — %s could be packaged and signed as it stands.\n' "$SLUG"
