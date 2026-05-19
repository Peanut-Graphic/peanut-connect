#!/bin/bash

# Peanut Connect - WordPress Plugin Packaging Script
# Creates a distributable ZIP file of the plugin

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_NAME="peanut-connect"

cd "$ROOT_DIR"

# Get version from main plugin file
VERSION=$(grep -m1 "Version:" "$ROOT_DIR/peanut-connect.php" | sed 's/.*Version: *\([0-9.]*\).*/\1/')

# Guardrail: refuse to package a version whose source isn't fully committed.
# This is the check that would have caught 3.7.5–3.7.9 — those versions had
# their version constant bumped, were packaged into dist/, and were documented
# in CHANGELOG, but the source for them was never committed. Customers stayed
# on 3.7.4 forever while local "releases" piled up uncommittedly.
#
# Override only with PEANUT_PACKAGE_FORCE=1 (real reason: e.g., packaging an
# old release for archival from a worktree).
if [[ -n $(git status --porcelain 2>/dev/null) ]] && [[ -z "$PEANUT_PACKAGE_FORCE" ]]; then
    echo "❌ package.sh refuses to run with a dirty working tree."
    echo ""
    echo "   Uncommitted changes mean the zip you're about to build won't match"
    echo "   any real commit, and there's no way to recover the exact source."
    echo "   This is the failure mode that produced phantom versions 3.7.5–3.7.9."
    echo ""
    echo "   Either:"
    echo "     • Commit your changes and re-run, or"
    echo "     • Use scripts/release.sh which handles commit + build + package + release"
    echo "     • Set PEANUT_PACKAGE_FORCE=1 if you genuinely need to override"
    echo ""
    git status --short
    exit 1
fi

# Sanity check: the version constant should match what HEAD claims to be.
# If someone hand-edits the version without going through release.sh, this
# catches the divergence before a zip ships under a fake version stamp.
HEAD_VERSION=$(git show HEAD:peanut-connect.php 2>/dev/null | grep -m1 "Version:" | sed 's/.*Version: *\([0-9.]*\).*/\1/')
if [[ -n "$HEAD_VERSION" ]] && [[ "$HEAD_VERSION" != "$VERSION" ]] && [[ -z "$PEANUT_PACKAGE_FORCE" ]]; then
    echo "❌ package.sh: version mismatch."
    echo "   Working tree version constant: $VERSION"
    echo "   HEAD's committed version:      $HEAD_VERSION"
    echo ""
    echo "   The version was bumped without a commit. Use scripts/release.sh"
    echo "   for the end-to-end flow, or commit the bump first."
    echo "   (Override with PEANUT_PACKAGE_FORCE=1 if you really mean it.)"
    exit 1
fi

echo ""
echo "📦 Packaging $PLUGIN_NAME v$VERSION..."
echo ""

# Create dist directory
DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$DIST_DIR/$PLUGIN_NAME"
mkdir -p "$BUILD_DIR"

# Clean previous build
rm -rf "$BUILD_DIR"/*

echo "📁 Copying files..."

# Copy main plugin files
cp "$ROOT_DIR/peanut-connect.php" "$BUILD_DIR/"
cp "$ROOT_DIR/readme.txt" "$BUILD_DIR/"

# Copy directories
cp -r "$ROOT_DIR/includes" "$BUILD_DIR/"
cp -r "$ROOT_DIR/admin" "$BUILD_DIR/"

# Copy Gutenberg block definitions if present (register_block_type reads
# blocks/<name>/block.json at the plugin root — omitting this silently
# breaks block registration on installed sites).
if [ -d "$ROOT_DIR/blocks" ]; then
    cp -r "$ROOT_DIR/blocks" "$BUILD_DIR/"
fi

# Copy built assets if exists
if [ -d "$ROOT_DIR/assets" ]; then
    cp -r "$ROOT_DIR/assets" "$BUILD_DIR/"
fi

# Copy frontend dist if exists
if [ -d "$ROOT_DIR/frontend/dist" ]; then
    mkdir -p "$BUILD_DIR/assets"
    cp -r "$ROOT_DIR/frontend/dist" "$BUILD_DIR/assets/"
fi

# Remove any development files that might have been copied
find "$BUILD_DIR" -name ".DS_Store" -delete 2>/dev/null || true
find "$BUILD_DIR" -name "*.map" -delete 2>/dev/null || true
find "$BUILD_DIR" -name ".gitkeep" -delete 2>/dev/null || true

# Create ZIP file
cd "$DIST_DIR"
ZIP_FILE="$PLUGIN_NAME-$VERSION.zip"
rm -f "$ZIP_FILE"
zip -r "$ZIP_FILE" "$PLUGIN_NAME" -x "*.DS_Store" -x "*/.git/*"

# Get file size
SIZE=$(du -h "$ZIP_FILE" | cut -f1)

echo ""
echo "✅ Package created successfully!"
echo "   📁 $DIST_DIR/$ZIP_FILE"
echo "   📊 Size: $SIZE"
echo ""

# Clean up build directory
rm -rf "$BUILD_DIR"
