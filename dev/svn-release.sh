#!/usr/bin/env bash
#
# Copy the built zip and .wordpress-org/ assets into a WordPress.org SVN checkout and tag the
# version. Commits nothing: it prints the commit command for you to run.
#
#   dev/svn-release.sh ~/cherrylink-svn
#
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SVN="${1:?usage: dev/svn-release.sh <svn checkout dir>}"
VER="$(sed -n 's/^[ *]*Version: *//p' "$REPO/cherrylink_main.php" | tr -d '\r')"

"$REPO/dev/build-org-zip.sh"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
unzip -q "$REPO/dist/cherrylink.zip" -d "$TMP"

rsync -a --delete --exclude .svn "$TMP/cherrylink/" "$SVN/trunk/"
mkdir -p "$SVN/assets"
cp "$REPO"/.wordpress-org/*.png "$SVN/assets/"
cd "$SVN"
svn add --force --quiet trunk assets
svn status trunk | awk '/^!/ {print $2}' | xargs -r svn rm --quiet
[ -d "tags/$VER" ] || svn cp --quiet trunk "tags/$VER"
svn status | tail -5
echo "Ready: version $VER. Review with 'svn status', then: svn ci -m \"Release $VER\" --username YOUR_WPORG_USERNAME"
