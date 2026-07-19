#!/usr/bin/env bash
#
# Build the distribution zip for wordpress.org.
#
# Copies the plugin through .distignore into dist/announcer-for-sportspress/
# and zips it so the archive unpacks to a single announcer-for-sportspress/
# directory. Run from anywhere; paths resolve relative to the repo root.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="announcer-for-sportspress"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/"

( cd "$DIST" && zip -rq "$SLUG.zip" "$SLUG" )

echo "Built $ZIP"
unzip -l "$ZIP"
