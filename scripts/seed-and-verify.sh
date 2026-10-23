#!/usr/bin/env bash
# Seed demo tenants and check /up from this server. Does not print passwords.
set -euo pipefail

domain="${HOME}/www/app.reserveworx.com"
current="${domain}/laravel/current"
login_file="${domain}/laravel/shared/demo-logins.txt"
php_bin="/usr/bin/php"

if [[ ! -L "$current" || ! -f "${current}/artisan" ]]; then
  echo "current_release=missing"
  exit 1
fi

if ! "$php_bin" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
  echo "php_cli=unsuitable"
  exit 1
fi

export DEMO_LOGIN_FILE="$login_file"
(cd "$current" && "$php_bin" artisan db:seed --force --no-interaction)
echo "demo_login_file=${login_file}"
if [[ -f "$login_file" ]]; then
  awk -F= '$1=="email" || $1=="tenant" || $1=="fee_payer" || $1=="hostname" { print }' "$login_file"
else
  echo "demo_login_file=missing"
  exit 1
fi

health_ok=0
probe() {
  local label="$1"
  shift
  local body code
  body="$(mktemp)"
  code="$("$@" -o "$body" -w '%{http_code}' || true)"
  local marker="no"
  if grep -q "Application up" "$body"; then
    marker="yes"
  fi
  local home="no"
  if grep -q "ReserveWorx" "$body"; then
    home="yes"
  fi
  echo "${label}_http=${code:-none} application_up=${marker} contains_reserveworx=${home}"
  if [[ "$label" == up_* && "$code" == "200" && "$marker" == "yes" ]]; then
    health_ok=1
  fi
  rm -f "$body"
}

probe up_local curl -sS --max-time 20 -H "Host: app.reserveworx.com" -H "Cache-Control: no-cache" "http://127.0.0.1/up"
probe up_public curl -sS --max-time 20 -H "Cache-Control: no-cache" -H "Cookie: wpSGCacheBypass=1" "https://app.reserveworx.com/up"
probe home_local curl -sS --max-time 20 -H "Host: app.reserveworx.com" -H "Cache-Control: no-cache" "http://127.0.0.1/"
probe home_public curl -sS --max-time 20 -H "Cache-Control: no-cache" -H "Cookie: wpSGCacheBypass=1" "https://app.reserveworx.com/"

if [[ "$health_ok" != "1" ]]; then
  echo "health=failed"
  exit 1
fi
echo "health=ok"
