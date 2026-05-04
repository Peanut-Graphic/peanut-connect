#!/bin/bash

# Peanut Connect - Full Release Workflow
# Bumps version, commits, packages, creates GitHub release

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Check for uncommitted changes
if [[ -n $(git status --porcelain) ]]; then
    echo "❌ Error: You have uncommitted changes. Please commit or stash them first."
    exit 1
fi

# Bump version
BUMP_TYPE="${1:-patch}"
echo ""
echo "📦 Starting release workflow..."
echo ""

bash "$SCRIPT_DIR/bump-version.sh" "$BUMP_TYPE"

# Get new version
VERSION=$(grep -m1 "Version:" "$ROOT_DIR/peanut-connect.php" | sed 's/.*Version: *\([0-9.]*\).*/\1/')

# Build SPA assets BEFORE the commit so the bundle in the zip matches the
# source the commit captures. Doing this AFTER the commit would require a
# force-push to amend, which is dangerous on main.
echo "🛠  Building SPA assets..."
npm run build

# Commit version bump + rebuilt bundle together.
echo "📝 Committing version bump + assets..."
git add -A
git commit -m "chore(release): bump version to $VERSION

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"

# Push to remote
echo "🚀 Pushing to remote..."
git push origin main

# Create package
echo "📦 Creating package..."
bash "$SCRIPT_DIR/package.sh"

# Create GitHub release
echo "🏷️  Creating GitHub release..."
gh release create "v$VERSION" \
    --title "Peanut Connect v$VERSION" \
    --notes "## Peanut Connect v$VERSION

### Installation
1. Download \`peanut-connect.zip\` below
2. Upload to WordPress via Plugins → Add New → Upload Plugin
3. Activate the plugin
4. Go to Settings → Peanut Connect to configure" \
    "$ROOT_DIR/dist/peanut-connect-$VERSION.zip#peanut-connect.zip"

echo ""
echo "✅ Release v$VERSION complete!"
echo "   - Committed and pushed"
echo "   - GitHub release created"
echo ""
