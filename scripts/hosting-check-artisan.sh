#!/usr/bin/env bash
# Run reserveworx:hosting-check on the active release.
# The caller must already have an SSH session. This script does not open one
# and does not retry. It prints the command's stdout and stderr and no secrets.
set -euo pipefail

export ACTION=deploy
export RW_DEPLOY_LIBRARY=1
# shellcheck disable=SC1091
source "${HOME}/www/app.reserveworx.com/laravel/current/scripts/remote-deploy.sh"

if ! php_bin="$(find_php)"; then
  echo "php=not found" >&2
  exit 1
fi

cd "${HOME}/www/app.reserveworx.com/laravel/current"
"$php_bin" artisan reserveworx:hosting-check --no-interaction
