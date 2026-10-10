#!/usr/bin/env bash
# Builds the release ZIP: application code plus all libraries (vendor/),
# so the server needs no Composer and loads nothing from the internet.
#
#   bin/build-release.sh [version]
#
# Result: build/lapfund-<version>.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(php -r 'require "'"$ROOT"'/src/App.php"; echo LapFund\App::VERSION;')}"
BUILD="$ROOT/build"
STAGE="$BUILD/lapfund"

rm -rf "$STAGE" "$BUILD/lapfund-$VERSION.zip"
mkdir -p "$STAGE"

# Copy tracked files only (no local config, uploads, logs or dev files).
cd "$ROOT"
git ls-files -z \
  | grep -zv -E '^(tests/|\.github/|phpunit\.xml\.dist$|\.gitignore$|bin/build-release\.sh$)' \
  | xargs -0 -I{} cp --parents {} "$STAGE"

# Production libraries exactly as pinned in composer.lock.
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
# Never ship version-control metadata of the libraries (present when installed from source).
find "$STAGE/vendor" -type d -name .git -prune -exec rm -rf {} +

# Folders the installer needs to write to.
mkdir -p "$STAGE/storage/uploads" "$STAGE/storage/logs" "$STAGE/storage/cache"

# Licenses of all bundled libraries, for transparency (LGPL/MIT/BSD notices).
composer licenses --working-dir="$STAGE" --no-dev --format=text > "$STAGE/THIRD-PARTY-LICENSES.txt" 2>/dev/null || true

cd "$BUILD"
zip -qr "lapfund-$VERSION.zip" lapfund
rm -rf "$STAGE"
echo "Built $BUILD/lapfund-$VERSION.zip"
