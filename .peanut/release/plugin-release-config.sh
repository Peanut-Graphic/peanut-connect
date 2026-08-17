#!/usr/bin/env bash
# plugin-release-config.sh — the single definition of how each Peanut WordPress
# plugin is packaged for release.
#
# Sourced by BOTH publish-plugin.sh (which builds and signs the artifact) and
# verify-release-readiness.sh (which proves in CI that a release could be cut
# correctly). One definition, two consumers, on purpose: when the publisher's
# idea of a plugin and CI's idea of it drift apart, the drift is invisible until
# a release is already out. PAR-405.
#
# Usage:  peanut_release_config <slug>
#   sets: REPO BUILD MAINPHP VCONST STABLE INCLUDE
#   exits non-zero on an unknown slug.
#
# BUILD:  none | composer | assets | fulltree
#   none      -> ship the tracked files in INCLUDE
#   composer  -> composer install --no-dev, ship INCLUDE (must list vendor)
#   assets    -> reuse assets/dist from the previous GitHub release
#   fulltree  -> whole tree minus .git/vendor (license server only)

peanut_release_config() {
  local SLUG="${1:?slug required}"
  case "$SLUG" in
    peanut-connect)
      # BUILD=composer since 3.21.3: Connect's self-updater now delegates its
      # signature verification to peanut/formflow-core (vendor/). Without vendor in
      # the package the verifier cannot load and updates go unverified.
      REPO=Peanut-Graphic/peanut-connect; BUILD=composer; MAINPHP=peanut-connect.php
      VCONST=PEANUT_CONNECT_VERSION; STABLE=readme.txt
      INCLUDE=(admin assets blocks composer.json composer.lock includes peanut-connect.php readme.txt vendor) ;;
    peanut-suite)
      # BUILD=assets, deliberately. Suite verifies its own updates via
      # core/services/class-peanut-updater.php — tracked source, not vendor/ —
      # so it does NOT need a composer build to carry a verifier the way Connect
      # and FormFlow Lite do.
      REPO=Peanut-Graphic/peanut-suite; BUILD=assets; MAINPHP=peanut-suite.php
      VCONST="PEANUT_VERSION PEANUT_SUITE_VERSION"; STABLE=""
      INCLUDE=(assets core modules peanut-suite.php uninstall.php) ;;
    formflow)
      REPO=Peanut-Graphic/formflow; BUILD=composer; MAINPHP=formflow.php
      VCONST=ISF_VERSION; STABLE=""
      INCLUDE=(.prettierrc admin composer.json composer.lock connectors formflow.php includes logs public uninstall.php vendor) ;;
    formflow-lite)
      # BUILD=composer since 3.2.25: Lite now bundles peanut/formflow-core, which
      # carries the signed-update verifier it registers at boot. Without vendor/ in
      # the package the gate cannot load and updates go unverified (audit L1).
      REPO=Peanut-Graphic/formflow-lite; BUILD=composer; MAINPHP=formflow-lite.php
      # No WordPress readme.txt in this repo (it ships README.md), so there is
      # no stable tag to rewrite. Declaring one made publish's `|| true` bump a
      # silent no-op and the readiness check red.
      VCONST=FFFL_VERSION; STABLE=""
      INCLUDE=(admin assets composer.json composer.lock connectors frontend includes public formflow-lite.php README.md CHANGELOG.md vendor) ;;
    peanut-license-server)
      REPO=Peanut-Graphic/peanut-license-server; BUILD=fulltree; MAINPHP=peanut-license-server.php
      # Same as formflow-lite: README.md, no readme.txt, nothing to rewrite.
      VCONST=PEANUT_LICENSE_SERVER_VERSION; STABLE=""; INCLUDE=() ;;
    peanut-festival)
      # BUILD=composer since 1.3.2: Festival now bundles peanut/formflow-core,
      # which carries the signed-update verifier it registers at boot. Without
      # vendor/ in the package the gate cannot load and updates go unverified.
      REPO=Peanut-Graphic/peanut-festival; BUILD=composer; MAINPHP=peanut-festival.php
      VCONST=PEANUT_FESTIVAL_VERSION; STABLE=""
      INCLUDE=(admin assets composer.json composer.lock includes public peanut-festival.php CHANGELOG.md README.md vendor) ;;
    peanut-booker)
      # BUILD=composer for the same reason as Festival.
      REPO=Peanut-Graphic/peanut-booker; BUILD=composer; MAINPHP=peanut-booker.php
      VCONST=PEANUT_BOOKER_VERSION; STABLE=""
      INCLUDE=(admin assets composer.json composer.lock includes public templates peanut-booker.php uninstall.php CHANGELOG.md README.md vendor) ;;
    *) return 1 ;;   # caller decides how to report an unknown slug
    esac
}
