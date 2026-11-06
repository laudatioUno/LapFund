#!/usr/bin/env bash
# composer install for CI and releases, robust against short GitHub outages.
#
# Composer downloads the libraries as ZIP files from api.github.com. When that
# answers with errors (e.g. HTTP 504), try again after a pause, and as a last
# resort fetch the libraries with git from github.com instead (--prefer-source).
# The libraries are the same in every case: exactly the versions in composer.lock.
#
# Usage: bin/composer-install.sh [composer install options…]
set -uo pipefail

for attempt in 1 2 3; do
  if composer install "$@"; then
    exit 0
  fi
  if [ "$attempt" -lt 3 ]; then
    wait=$((attempt * 30))
    echo "::warning::composer install failed (attempt $attempt of 3), trying again in ${wait}s"
    sleep "$wait"
  fi
done

echo "::warning::Downloads from api.github.com keep failing – installing from git sources instead"
composer install --prefer-source "$@"
