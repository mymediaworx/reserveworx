#!/usr/bin/env bash
# Read-only page check for app.reserveworx.com. Does not deploy or write.
set -euo pipefail

current="$(readlink -f "${HOME}/www/app.reserveworx.com/laravel/current" || true)"
echo "current=${current}"

probe() {
  local label="$1"
  local path="$2"
  local body code marker named title
  body="$(mktemp)"
  code="$(curl -sS --max-time 20 -o "$body" -w '%{http_code}' \
    -H "Cache-Control: no-cache" \
    -H "Cookie: wpSGCacheBypass=1" \
    "https://app.reserveworx.com${path}" || true)"
  marker=no
  if grep -q "Application up" "$body"; then marker=yes; fi
  named=no
  if grep -q "ReserveWorx" "$body"; then named=yes; fi
  title="$(grep -o '<title>[^<]*</title>' "$body" | head -n 1 || true)"
  echo "${label}_http=${code:-none} application_up=${marker} reserveworx=${named} title=${title:-none}"
  rm -f "$body"
}

probe up /up
probe home /
probe platform_login /platform/login
probe css /ui/app.css
probe platform /platform
