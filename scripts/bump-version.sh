#!/bin/bash

# Peanut Connect - Version Bump Script
# Updates version across all files that contain version numbers

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Warn if working tree has uncommitted changes — bumping version on a dirty
# tree is how 3.7.5–3.7.9 became phantom versions (source uncommitted, version
# stamp + zip + CHANGELOG ahead of git). Use scripts/release.sh for the
# end-to-end flow; bumping in isolation is for unusual cases.
if [[ -n $(git status --porcelain 2>/dev/null) ]]; then
    echo "⚠️  Warning: Working tree has uncommitted changes."
    echo "   Bumping the version constant without committing source means"
    echo "   the next 'package.sh' will refuse to run (version-vs-commit mismatch)."
    echo "   Prefer scripts/release.sh which enforces the full flow."
    echo ""
fi

# Get current version from main plugin file
CURRENT_VERSION=$(grep -m1 "Version:" "$ROOT_DIR/peanut-connect.php" | sed 's/.*Version: *\([0-9.]*\).*/\1/')

# Parse version components
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"

# Determine new version based on argument
case "${1:-patch}" in
    major)
        NEW_VERSION="$((MAJOR + 1)).0.0"
        ;;
    minor)
        NEW_VERSION="$MAJOR.$((MINOR + 1)).0"
        ;;
    patch|"")
        NEW_VERSION="$MAJOR.$MINOR.$((PATCH + 1))"
        ;;
    *)
        # Assume it's a specific version number
        if [[ $1 =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            NEW_VERSION="$1"
        else
            echo "❌ Invalid version format: $1"
            echo ""
            echo "Usage: $0 [major|minor|patch|x.y.z]"
            echo ""
            echo "  major  - Bump major version (1.0.0 -> 2.0.0)"
            echo "  minor  - Bump minor version (1.0.0 -> 1.1.0)"
            echo "  patch  - Bump patch version (1.0.0 -> 1.0.1) [default]"
            echo "  x.y.z  - Set specific version"
            echo ""
            exit 1
        fi
        ;;
esac

echo ""
echo "🔄 Bumping version: $CURRENT_VERSION → $NEW_VERSION"
echo ""

# Files to update
FILES_UPDATED=0

# 1. Update peanut-connect.php (header comment)
if grep -q "Version: $CURRENT_VERSION" "$ROOT_DIR/peanut-connect.php"; then
    sed -i '' "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/" "$ROOT_DIR/peanut-connect.php"
    echo "   ✓ peanut-connect.php (header)"
    FILES_UPDATED=$((FILES_UPDATED + 1))
fi

# 2. Update peanut-connect.php (PEANUT_CONNECT_VERSION constant)
if grep -q "PEANUT_CONNECT_VERSION', '$CURRENT_VERSION'" "$ROOT_DIR/peanut-connect.php"; then
    sed -i '' "s/PEANUT_CONNECT_VERSION', '$CURRENT_VERSION'/PEANUT_CONNECT_VERSION', '$NEW_VERSION'/" "$ROOT_DIR/peanut-connect.php"
    echo "   ✓ peanut-connect.php (PEANUT_CONNECT_VERSION)"
    FILES_UPDATED=$((FILES_UPDATED + 1))
fi

# 3. Update readme.txt (Stable tag)
if [ -f "$ROOT_DIR/readme.txt" ]; then
    sed -i '' "s/Stable tag: $CURRENT_VERSION/Stable tag: $NEW_VERSION/" "$ROOT_DIR/readme.txt"
    echo "   ✓ readme.txt (Stable tag)"
    FILES_UPDATED=$((FILES_UPDATED + 1))
fi

# 4. Update frontend/package.json
if [ -f "$ROOT_DIR/frontend/package.json" ]; then
    sed -i '' "s/\"version\": \"$CURRENT_VERSION\"/\"version\": \"$NEW_VERSION\"/" "$ROOT_DIR/frontend/package.json"
    # Also update if it was out of sync
    sed -i '' "s/\"version\": \"[0-9]*\.[0-9]*\.[0-9]*\"/\"version\": \"$NEW_VERSION\"/" "$ROOT_DIR/frontend/package.json"
    echo "   ✓ frontend/package.json"
    FILES_UPDATED=$((FILES_UPDATED + 1))
fi

echo ""
echo "✅ Version bumped to $NEW_VERSION ($FILES_UPDATED files updated)"
echo ""
