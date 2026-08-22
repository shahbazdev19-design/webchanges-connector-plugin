#!/usr/bin/env bash
#
# Cut a new release of Webchanges Connector.
#
#   bin/release.sh 0.2.0 ["changelog line"]
#
# What it does:
#   1. Bumps the Version: header AND the WEBCHANGES_CONNECTOR_VERSION constant.
#   2. Prepends a dated entry to CHANGELOG.md (uses the optional message).
#   3. Commits, creates an annotated tag v<version>, and pushes both.
#
# The push of the tag triggers .github/workflows/release.yml, which builds the
# ZIP and publishes the GitHub Release. Every site running the plugin then sees
# the update on its Plugins screen.
#
# Run from the repo root.

set -euo pipefail

VERSION="${1:-}"
MESSAGE="${2:-Maintenance release.}"

if [ -z "$VERSION" ]; then
  echo "Usage: bin/release.sh <version> [changelog message]" >&2
  echo "Example: bin/release.sh 0.2.0 \"Add stock photo abilities\"" >&2
  exit 1
fi

if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z]+)?$'; then
  echo "Version '$VERSION' is not a valid semver (e.g. 0.2.0)." >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MAIN="webchanges-connector.php"
if [ ! -f "$MAIN" ]; then
  echo "Run this from the plugin repo root (can't find $MAIN)." >&2
  exit 1
fi

# Refuse to release a dirty tree (other than the files we're about to touch).
if [ -n "$(git status --porcelain | grep -vE 'webchanges-connector\.php|CHANGELOG\.md|readme\.txt' || true)" ]; then
  echo "Working tree has uncommitted changes outside the version files. Commit or stash first." >&2
  git status --short >&2
  exit 1
fi

# 1. Bump the header line:  * Version: X.Y.Z
sed -i -E "s/^(\s*\*\s*Version:\s*).*/\1${VERSION}/" "$MAIN"
# 2. Bump the constant:     define('WEBCHANGES_CONNECTOR_VERSION', 'X.Y.Z');
sed -i -E "s/(define\('WEBCHANGES_CONNECTOR_VERSION',\s*')[^']*('\);)/\1${VERSION}\2/" "$MAIN"
# 2b. Keep readme.txt "Stable tag" in lockstep so Plugin Check never flags a mismatch.
if [ -f readme.txt ]; then
  sed -i -E "s/^(Stable tag:\s*).*/\1${VERSION}/" readme.txt
fi

# 3. Changelog.
DATE="$(date +%Y-%m-%d)"
if [ ! -f CHANGELOG.md ]; then
  printf "# Changelog\n\n" > CHANGELOG.md
fi
TMP="$(mktemp)"
{
  head -n 1 CHANGELOG.md
  printf "\n## %s - %s\n\n- %s\n" "$VERSION" "$DATE" "$MESSAGE"
  tail -n +2 CHANGELOG.md
} > "$TMP"
mv "$TMP" CHANGELOG.md

echo "Bumped to $VERSION:"
grep -E "^\s*\*\s*Version:" "$MAIN"
grep -E "WEBCHANGES_CONNECTOR_VERSION'," "$MAIN"

git add "$MAIN" CHANGELOG.md
[ -f readme.txt ] && git add readme.txt
git commit -m "Release v${VERSION}: ${MESSAGE}"
git tag -a "v${VERSION}" -m "v${VERSION}: ${MESSAGE}"

echo
echo "Committed and tagged v${VERSION}. Pushing..."
git push origin HEAD
git push origin "v${VERSION}"

echo
echo "Done. GitHub Action will build the ZIP and publish the Release."
echo "Sites will show the update within ~12h, or immediately via Plugins -> Check for updates."
