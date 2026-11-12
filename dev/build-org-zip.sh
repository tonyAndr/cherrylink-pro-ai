#!/usr/bin/env bash
#
# Build the WordPress.org zip: `git archive` of HEAD (honours .gitattributes
# export-ignore) nested in `cherrylink/`, with the GitHub updater (updater/) removed
# (WordPress.org guideline 8). Writes dist/cherrylink.zip. Purely local: no remote access.
#
#   dev/build-org-zip.sh [output.zip]
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/dist/cherrylink.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$(dirname "$OUT")" "$TMP/cherrylink"
git -C "$REPO" archive --format=tar HEAD | tar -x -C "$TMP/cherrylink"
rm -rf "$TMP/cherrylink/updater"

rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" cherrylink)
echo "Built $OUT"
unzip -l "$OUT" | grep -c ' cherrylink/updater/' | sed 's/^/updater entries (must be 0): /' || true
