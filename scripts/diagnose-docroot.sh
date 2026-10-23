#!/usr/bin/env bash
# Read-only explanation of the app.reserveworx.com document root. No secrets.
set -u
domain="${HOME}/www/app.reserveworx.com"
docroot="${domain}/public_html"
current="${domain}/laravel/current"
echo "docroot_link=$(readlink "$docroot" 2>/dev/null || echo not_a_symlink)"
echo "current_link=$(readlink "$current" 2>/dev/null || echo not_a_symlink)"
if [[ -e "$docroot" || -L "$docroot" ]]; then
  find "$docroot" -maxdepth 1 -printf '%m %y %f\n' 2>/dev/null | head -n 30
fi
target=""
if [[ -L "$current" ]]; then
  target="$(readlink -f "$current" 2>/dev/null || true)"
fi
echo "release_resolved=${target:-missing}"
if [[ -n "$target" && -d "$target/public" ]]; then
  find "$target/public" -maxdepth 1 -printf '%m %y %f\n' | head -n 30
  echo "index_php=$([[ -f "$target/public/index.php" ]] && echo yes || echo no)"
fi
body="$(mktemp)"
headers="$(mktemp)"
code="$(curl -sS -D "$headers" -o "$body" --max-time 20 -w '%{http_code}' -H 'Cache-Control: no-cache' -H 'Cookie: wpSGCacheBypass=1' "https://app.reserveworx.com/up" || true)"
echo "up_http=${code:-none}"
awk 'BEGIN{IGNORECASE=1} /^HTTP\/|^content-type:|^server:|^location:/ { print }' "$headers"
BODY_PATH="$body" php << 'PHP'
<?php
$raw = (string) file_get_contents(getenv('BODY_PATH'));
$text = trim((string) preg_replace('/\s+/', ' ', strip_tags($raw)));
$text = (string) preg_replace('/(APP_KEY|DB_PASSWORD|DB_USERNAME|DB_DATABASE)[^ ]*/', '$1=[redacted]', $text);
echo 'body_len='.strlen($raw)."\n";
echo 'body_text='.substr($text, 0, 240)."\n";
PHP
rm -f "$body" "$headers"
if [[ -n "$target" && -f "$target/public/index.php" ]]; then
  out="$(mktemp)"
  err="$(mktemp)"
  (
    cd "$target/public" && REQUEST_URI=/up SCRIPT_NAME=/index.php REQUEST_METHOD=GET HTTP_HOST=app.reserveworx.com /usr/bin/php index.php > "$out" 2>"$err" || true
  )
  if grep -q "Application up" "$out"; then
    echo "cli_up=yes"
  else
    echo "cli_up=no"
    ERR_PATH="$err" php << 'PHP'
<?php
$raw = (string) file_get_contents(getenv('ERR_PATH'));
$raw = (string) preg_replace('/(APP_KEY|DB_PASSWORD|DB_USERNAME|DB_DATABASE)=.*/', '$1=[redacted]', $raw);
echo 'cli_err='.substr(trim((string) preg_replace('/\s+/', ' ', $raw)), 0, 300)."\n";
PHP
  fi
  rm -f "$out" "$err"
fi
