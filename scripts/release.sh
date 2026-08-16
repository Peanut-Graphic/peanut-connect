#!/usr/bin/env bash
#
# Peanut Connect — release wrapper.
#
# This script used to do the release itself: bump, build, commit, push main, zip,
# and `gh release create`. It must not any more, and the reason is not process
# tidiness.
#
# Connect registers a fail-closed signed-update gate (peanut/formflow-core,
# since 3.21.3). Before installing an update, every site verifies the package's
# sha256 and a detached Ed25519 signature against a `.manifest.json` sidecar
# published beside it. This script produced neither. A release cut here would be
# an UNSIGNED artifact that every install correctly REFUSES — the update path
# would look published and be broken, which is the most expensive kind of
# broken: silent at the publisher, total at the client.
#
# The central publisher signs and ships the manifest, bumps the version
# constants, verifies them, updates the license-server option, and canaries on
# peanutgraphic.com. That is the only supported path.
#
#   Peanut-meta/scripts/publish-plugin.sh peanut-connect <version>          # dry run
#   Peanut-meta/scripts/publish-plugin.sh peanut-connect <version> --ship   # release
#
# PAR-403.
set -euo pipefail

VERSION="${1:-}"

# Look where the meta repo actually lives, in both known layouts.
CANDIDATES=(
  "$HOME/peanut-meta/scripts/publish-plugin.sh"
  "$HOME/Documents/Peanut-meta/scripts/publish-plugin.sh"
  "$HOME/Documents/Peanut/peanut-meta/scripts/publish-plugin.sh"
)

PUBLISHER=""
for c in "${CANDIDATES[@]}"; do
  [ -x "$c" ] && { PUBLISHER="$c"; break; }
done

printf '\033[1;33m%s\033[0m\n' "This script no longer releases Peanut Connect."
cat <<'WHY'

  Connect verifies its own updates. A package released from here carries no
  signature and no .manifest.json sidecar, so every install would refuse it.
  Use the central publisher, which signs the artifact and ships the manifest.

WHY

if [ -z "$VERSION" ]; then
  echo "  usage: scripts/release.sh <version>    (forwards to the central publisher)"
  echo
  [ -n "$PUBLISHER" ] && echo "  publisher: $PUBLISHER" \
                      || echo "  publisher: not found locally — clone Peanut-Graphic/peanut-meta"
  exit 64
fi

if [ -z "$PUBLISHER" ]; then
  echo "  Could not find publish-plugin.sh. Clone Peanut-Graphic/peanut-meta, then:" >&2
  echo "    <meta>/scripts/publish-plugin.sh peanut-connect $VERSION" >&2
  exit 69
fi

# Deliberately forwards the DRY RUN. --ship is outward-facing (GitHub release,
# license-server option bump, canary) and stays an explicit, separate decision
# rather than something a wrapper can do on your behalf.
echo "  forwarding to: $PUBLISHER peanut-connect $VERSION"
echo "  (dry run — add --ship yourself when you mean it)"
echo
exec "$PUBLISHER" peanut-connect "$VERSION"
