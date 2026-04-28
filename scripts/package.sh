#!/bin/bash

# Peanut Connect - WordPress Plugin Packaging Script
# Creates a distributable ZIP file of the plugin

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_NAME="peanut-connect"
# Get version from main plugin file
VERSION=$(grep -m1 "Version:" "$ROOT_DIR/peanut-connect.php" | sed 's/.*Version: *\([0-9.]*\).*/\1/')

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
