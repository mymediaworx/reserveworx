#!/usr/bin/env bash
# Activate a release for app.reserveworx.com, or roll back to the one
# previous release. When www/harbor.reserveworx.com/public_html already
# exists as a real directory, a deploy also writes that site's index.php
# so it boots app.reserveworx.com/laravel/current. It never creates a site
# and never writes under www/reserveworx.com. Migrations run only for action=deploy when
# RUN_MIGRATIONS=true, shared .env exists, and this run already wrote a
# verified gzip under laravel/shared/backups. The dump is checked before the
# previous release directory is removed and before the document root changes.
# Rollback never runs migrate:rollback. The previous release stays on disk
# until the next deploy replaces that one rollback slot.
#
# Inode budget: at most the current release tree and one previous release tree.
# The upload archive is deleted after extract. Vendor lives only inside those
# release trees. Composer is not run on the server. Database dumps are at most
# two gzip files under laravel/shared/backups, outside public_html.
set -euo pipefail

: "${ACTION:?ACTION is required}"

if [[ "$ACTION" != "deploy" && "$ACTION" != "rollback" && "$ACTION" != "backup" ]]; then
  echo "ACTION must be deploy, rollback, or backup." >&2
  exit 1
fi

domain="${HOME}/www/app.reserveworx.com"
case "$domain" in
  */www/app.reserveworx.com) ;;
  *)
    echo "Refusing to deploy because the domain path is not app.reserveworx.com." >&2
    exit 1
    ;;
esac

if [[ ! -d "$domain" ]]; then
  echo "Domain directory is missing. Create the app.reserveworx.com site in Site Tools first. No other site was touched." >&2
  exit 1
fi

laravel="${domain}/laravel"
releases="${laravel}/releases"
incoming="${laravel}/incoming"
shared="${laravel}/shared"
current="${laravel}/current"
previous="${laravel}/previous"
docroot="${domain}/public_html"
archive=""

if [[ -n "${RELEASE:-}" ]]; then
  archive="${incoming}/${RELEASE}.tar.gz"
fi

mkdir -p \
  "$releases" \
  "$incoming" \
  "${shared}/storage/framework/cache/data" \
  "${shared}/storage/framework/sessions" \
  "${shared}/storage/framework/views" \
  "${shared}/storage/logs" \
  "${shared}/storage/app/public"

cleanup_mail_password_file() {
  local password_file="${shared}/.mail-password"
  case "$password_file" in
    */www/app.reserveworx.com/laravel/shared/.mail-password) ;;
    *)
      return 0
      ;;
  esac
  case "$password_file" in
    *public_html*|*www/reserveworx.com/*)
      return 0
      ;;
  esac
  rm -f "$password_file" "${password_file}.tmp"
}
trap cleanup_mail_password_file EXIT

find_php() {
  local candidate version major minor
  local candidates=()
  shopt -s nullglob
  candidates+=(
    /usr/local/php82/bin/php
    /usr/bin/php8.2
    /usr/local/php83/bin/php
    /usr/bin/php8.3
    /usr/local/php84/bin/php
    /usr/bin/php8.4
    /usr/local/php85/bin/php
    /usr/bin/php8.5
  )
  candidates+=(/usr/local/php*/bin/php)
  if command -v php >/dev/null 2>&1; then
    candidates+=("$(command -v php)")
  fi
  shopt -u nullglob

  for candidate in "${candidates[@]}"; do
    [[ -x "$candidate" ]] || continue
    # SiteGround's /usr/local/php*/bin/php binaries are CGI and reject -r.
    version="$("$candidate" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;' 2>/dev/null)" || continue
    [[ "$version" =~ ^[0-9]+\.[0-9]+$ ]] || continue
    major="${version%%.*}"
    minor="${version#*.}"
    if [[ "$major" -gt 8 ]] || [[ "$major" -eq 8 && "$minor" -ge 2 ]]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

release_path() {
  local link="$1"
  if [[ -L "$link" ]]; then
    readlink -f "$link"
  fi
}

prune_releases() {
  local keep_current keep_previous dir full
  keep_current="$(release_path "$current")"
  keep_previous="$(release_path "$previous")"

  shopt -s nullglob
  for dir in "$releases"/*; do
    [[ -d "$dir" ]] || continue
    full="$(readlink -f "$dir")"
    if [[ "$full" == "$keep_current" || "$full" == "$keep_previous" ]]; then
      continue
    fi
    rm -rf "$dir"
    echo "pruned=${dir}"
  done
  shopt -u nullglob

  rm -f "$incoming"/*.tar.gz
  echo "archives=deleted"
}

drop_old_rollback() {
  local prev curr
  prev="$(release_path "$previous")"
  curr="$(release_path "$current")"
  if [[ -n "$prev" && "$prev" != "$curr" && "$prev" == "$releases/"* ]]; then
    rm -rf "$prev"
    echo "removed_old_rollback=${prev}"
  fi
  rm -f "$previous"
}

configure_production_mail() {
  local env_file password_file php
  env_file="${shared}/.env"
  password_file="${shared}/.mail-password"
  case "$env_file" in
    */www/app.reserveworx.com/laravel/shared/.env) ;;
    *)
      echo "mail_env=rejected" >&2
      return 1
      ;;
  esac
  case "$env_file" in
    *public_html*|*www/reserveworx.com/*)
      echo "mail_env=rejected" >&2
      return 1
      ;;
  esac
  case "$password_file" in
    */www/app.reserveworx.com/laravel/shared/.mail-password) ;;
    *)
      echo "mail_env=rejected" >&2
      return 1
      ;;
  esac
  case "$password_file" in
    *public_html*|*www/reserveworx.com/*)
      echo "mail_env=rejected" >&2
      return 1
      ;;
  esac
  if [[ ! -f "$env_file" ]]; then
    echo "shared_env=missing" >&2
    return 1
  fi
  php="${php_bin:-}"
  if [[ -z "$php" ]]; then
    php="$(find_php)" || {
      echo "mail_env=php" >&2
      return 1
    }
  fi
  # The password file is read here and deleted. Nothing in this script prints it.
  set +e
  RW_ENV_FILE="$env_file" RW_MAIL_FILE="$password_file" "$php" << 'PHP'
<?php

// STDIN is the script when php reads it from a pipe, so STDOUT and STDERR
// are not defined. Open the streams directly.
if (! defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}
if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

$path = getenv('RW_ENV_FILE');
$mailFile = getenv('RW_MAIL_FILE');
if (! is_string($path) || ! is_string($mailFile)) {
    fwrite(STDERR, "mail_env=rejected\n");
    exit(1);
}

$real = realpath($path);
if ($real === false || ! is_file($real)) {
    fwrite(STDERR, "shared_env=missing\n");
    exit(1);
}

$allowed = static function (string $candidate): bool {
    if (str_contains($candidate, 'public_html')) {
        return false;
    }
    if (! preg_match('#/www/app\.reserveworx\.com/laravel/shared/(?:\.env|\.mail-password)$#', $candidate)) {
        return false;
    }
    if (preg_match('#/www/reserveworx\.com(/|$)#', $candidate)) {
        return false;
    }

    return true;
};

$mailReal = $mailFile;
if (file_exists($mailFile)) {
    $resolved = realpath($mailFile);
    if ($resolved === false) {
        fwrite(STDERR, "mail_env=rejected\n");
        exit(1);
    }
    $mailReal = $resolved;
}

if (! $allowed($real) || ! $allowed($mailReal)) {
    fwrite(STDERR, "mail_env=rejected\n");
    exit(1);
}

$password = '';
if (is_file($mailFile)) {
    $raw = file_get_contents($mailFile);
    if ($raw === false) {
        fwrite(STDERR, "mail_env=unreadable\n");
        exit(1);
    }
    $password = $raw;
    if (! unlink($mailFile)) {
        fwrite(STDERR, "mail_env=cleanup\n");
        exit(1);
    }
}

$password = rtrim($password, "\r\n");
if (str_contains($password, "\0") || str_contains($password, "\n") || str_contains($password, "\r")) {
    fwrite(STDERR, "mail_env=rejected\n");
    exit(1);
}

$configured = $password !== '';
$updates = $configured ? [
    'MAIL_MAILER' => 'smtp',
    'MAIL_HOST' => 'mail.reserveworx.com',
    'MAIL_PORT' => '465',
    'MAIL_SCHEME' => 'smtps',
    'MAIL_USERNAME' => 'noreply@reserveworx.com',
    'MAIL_FROM_ADDRESS' => 'noreply@reserveworx.com',
    'MAIL_FROM_NAME' => 'ReserveWorx',
    'MAIL_PASSWORD' => $password,
] : [
    'MAIL_MAILER' => 'log',
];

$quote = static function (string $value): string {
    // phpdotenv double quotes: \\ \" and \$ are literal. Other backslash escapes are rejected.
    $value = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

    return '"'.$value.'"';
};

$contents = file_get_contents($real);
if ($contents === false) {
    fwrite(STDERR, "mail_env=unreadable\n");
    exit(1);
}

$lines = preg_split("/\r\n|\n|\r/", $contents);
if ($lines === false) {
    fwrite(STDERR, "mail_env=unreadable\n");
    exit(1);
}

$seen = [];
$out = [];
foreach ($lines as $line) {
    if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $matches) === 1
        && array_key_exists($matches[1], $updates)) {
        $key = $matches[1];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $key.'='.$quote($updates[$key]);
        continue;
    }
    $out[] = $line;
}
if ($out !== [] && $out[count($out) - 1] === '') {
    array_pop($out);
}
foreach ($updates as $key => $value) {
    if (! isset($seen[$key])) {
        $out[] = $key.'='.$quote($value);
    }
}

if (file_put_contents($real, implode("\n", $out)."\n", LOCK_EX) === false) {
    fwrite(STDERR, "mail_env=unwritable\n");
    exit(1);
}
chmod($real, 0600);
fwrite(STDOUT, $configured ? "mail_configured=yes\n" : "mail_configured=no\n");
PHP
  local status=$?
  set -e
  return "$status"
}

link_shared() {
  local target="$1"
  ln -sfn "${shared}/.env" "${target}/.env"
  rm -rf "${target}/storage"
  ln -sfn "${shared}/storage" "${target}/storage"
  if [[ -e "${target}/public/storage" || -L "${target}/public/storage" ]]; then
    rm -rf "${target}/public/storage"
  fi
  ln -sfn "${shared}/storage/app/public" "${target}/public/storage"
}

install_front_controller() {
  local release_public
  release_public="$(readlink -f "$current")/public"
  mkdir -p "$docroot"
  cat > "${docroot}/index.php" << 'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$root = __DIR__.'/../laravel/current';

if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
PHP
  if [[ -f "${release_public}/.htaccess" ]]; then
    cp "${release_public}/.htaccess" "${docroot}/.htaccess"
  fi
  if [[ -f "${release_public}/robots.txt" ]]; then
    cp "${release_public}/robots.txt" "${docroot}/robots.txt"
  fi
  if [[ -f "${release_public}/favicon.ico" ]]; then
    cp "${release_public}/favicon.ico" "${docroot}/favicon.ico"
  fi
  chmod 755 "$docroot"
  chmod 644 "${docroot}/index.php" "${docroot}/.htaccess" "${docroot}/robots.txt" "${docroot}/favicon.ico" 2>/dev/null || true
  echo "document_root=directory ${docroot} front_controller=../laravel/current"
}

switch_docroot() {
  # Nginx on this host returns 403 for a symlinked document root. public_html
  # stays a real directory whose index.php boots ../laravel/current.
  if [[ -L "$docroot" ]]; then
    rm -f "$docroot"
  fi

  if [[ -d "$docroot" && -f "${docroot}/index.php" ]] && grep -q "laravel/current" "${docroot}/index.php"; then
    install_front_controller
    return 0
  fi

  if [[ -d "$docroot" ]]; then
    local allowed=1 name list
    list="$(mktemp)"
    find "$docroot" -mindepth 1 -maxdepth 1 -printf '%f\n' > "$list"
    while IFS= read -r name; do
      [[ -z "$name" ]] && continue
      lower="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"
      case "$lower" in
        index.html|index.php|cgi-bin|.well-known|default.html) ;;
        *) allowed=0 ;;
      esac
    done < "$list"
    rm -f "$list"

    if [[ "$allowed" -ne 1 ]]; then
      echo "document_root=left unchanged because ${docroot} contains files outside the placeholder list. No other site was touched." >&2
      return 1
    fi

    local stamp
    stamp="$(date -u +%Y%m%d%H%M%S)"
    mv "$docroot" "${domain}/public_html.pre-reserveworx-${stamp}"
  elif [[ -e "$docroot" ]]; then
    echo "document_root=left unchanged because ${docroot} is not a directory or symlink." >&2
    return 1
  fi

  install_front_controller
}

purge_dynamic_cache() {
  local body code
  body="$(mktemp)"
  code="$(curl -sS --globoff --path-as-is -o "$body" --max-time 20 -X PURGE -H "Host: app.reserveworx.com" -w '%{http_code}' "http://127.0.0.1/*" || true)"
  if [[ "$code" == "200" ]] && grep -q "Successful purge" "$body"; then
    echo "dynamic_cache=purged host=app.reserveworx.com"
    rm -f "$body"
    return 0
  fi
  rm -f "$body"
  echo "dynamic_cache=not confirmed (http ${code:-none}). HTTP 202 is not a successful purge." >&2
  echo "Flush only app.reserveworx.com in Site Tools, Speed, Caching, Dynamic Cache. Do not flush other sites." >&2
  return 1
}

https_get() {
  local url body_file header_file code
  url="$1"
  body_file="$2"
  header_file="$3"
  case "$url" in
    https://app.reserveworx.com/*|https://harbor.reserveworx.com/*) ;;
    *)
      echo "probe=rejected url" >&2
      return 1
      ;;
  esac
  code="$(curl -sS -D "$header_file" -o "$body_file" --max-time 20 -w '%{http_code}' \
    -H "Cache-Control: no-cache" \
    -H "Cookie: wpSGCacheBypass=1" \
    "$url" || true)"
  printf '%s' "${code:-none}"
}

health_check() {
  local body headers code
  body="$(mktemp)"
  headers="$(mktemp)"
  code="$(https_get "https://app.reserveworx.com/up" "$body" "$headers")"
  echo "health_http=${code:-none}"
  echo "rollback_probe=https://app.reserveworx.com/up"
  if [[ "$code" == "202" ]]; then
    echo "health=not proven. HTTP 202 is the SiteGround bot check, not a healthy application." >&2
    rm -f "$body" "$headers"
    return 1
  fi
  if [[ "$code" == "200" ]] && grep -q "Application up" "$body"; then
    echo "health=ok"
    rm -f "$body" "$headers"
    return 0
  fi
  echo "health=failed. A 200 that contains Application up is required. HTTPS must be enforced for app.reserveworx.com in Site Tools." >&2
  rm -f "$body" "$headers"
  return 1
}

swap_to_previous() {
  local prev_target curr_target
  if [[ ! -L "$previous" ]]; then
    echo "rollback=unavailable" >&2
    return 1
  fi
  prev_target="$(release_path "$previous")"
  if [[ ! -d "$prev_target" ]]; then
    echo "rollback=unavailable" >&2
    return 1
  fi
  if [[ -L "$current" ]]; then
    curr_target="$(release_path "$current")"
    ln -sfn "$prev_target" "$current"
    ln -sfn "$curr_target" "$previous"
  else
    ln -sfn "$prev_target" "$current"
  fi
  echo "rollback=complete"
  echo "migrate_rollback=not run"
  echo "rollback_probe=https://app.reserveworx.com/up"
  echo "current=$(readlink -f "$current")"
}

platform_check() {
  local body headers code
  body="$(mktemp)"
  headers="$(mktemp)"
  code="$(https_get "https://app.reserveworx.com/platform" "$body" "$headers")"
  echo "platform_http=${code:-none}"
  if [[ "$code" == "200" ]] && grep -q "Workspaces" "$body"; then
    echo "platform=ok"
    rm -f "$body" "$headers"
    return 0
  fi
  if [[ "$code" == "302" ]] && grep -qi '^location: .*/platform/login' "$headers"; then
    echo "platform=ok"
    rm -f "$body" "$headers"
    return 0
  fi
  echo "platform=failed" >&2
  rm -f "$body" "$headers"
  return 1
}

find_dump() {
  local candidate
  local candidates=()
  shopt -s nullglob
  if command -v mysqldump >/dev/null 2>&1; then
    candidates+=("$(command -v mysqldump)")
  fi
  if command -v mariadb-dump >/dev/null 2>&1; then
    candidates+=("$(command -v mariadb-dump)")
  fi
  candidates+=(
    /usr/bin/mysqldump
    /usr/bin/mariadb-dump
    /usr/local/bin/mysqldump
    /usr/local/mariadb/bin/mysqldump
  )
  shopt -u nullglob

  for candidate in "${candidates[@]}"; do
    [[ -x "$candidate" ]] || continue
    printf '%s\n' "$candidate"
    return 0
  done
  return 1
}

# A dump is acceptable only when it is a private gzip of a database dump.
# gunzip -t checks the archive. A header check rejects a gzip of an empty
# stream. The password never enters this function.
assert_dump_file() {
  local file="$1" size marker mode
  case "$file" in
    */www/app.reserveworx.com/laravel/shared/backups/*.sql.gz) ;;
    *)
      echo "backup=failed path" >&2
      return 1
      ;;
  esac
  case "$file" in
    *public_html*|*www/reserveworx.com/*)
      echo "backup=failed path" >&2
      return 1
      ;;
  esac
  if [[ ! -f "$file" ]]; then
    echo "backup=failed missing" >&2
    return 1
  fi
  mode="$(stat -c '%a' "$file" 2>/dev/null || true)"
  if [[ "$mode" != "600" ]]; then
    echo "backup=failed mode" >&2
    return 1
  fi
  if ! gunzip -t "$file"; then
    echo "backup=failed archive" >&2
    return 1
  fi
  size="$(wc -c < "$file" | tr -d ' ')"
  if [[ ! "$size" =~ ^[0-9]+$ ]] || [[ "$size" -lt 1 ]]; then
    echo "backup=failed archive" >&2
    return 1
  fi
  marker="$(gzip -dc "$file" | head -n 40 || true)"
  if ! grep -q -E 'MySQL dump|MariaDB dump|CREATE TABLE|Dump completed' <<<"$marker"; then
    echo "backup=failed unrecognized" >&2
    unset marker
    return 1
  fi
  unset marker
  echo "gunzip_test=ok"
  echo "backup_bytes=${size}"
  return 0
}

# Read DB_* from shared .env inside PHP so the password never enters this
# script's arguments or its log. The client defaults file is mode 600 and is
# removed before the function returns. The gzip stays under
# laravel/shared/backups, which is not public_html and is not another site.
backup_database() {
  local backups outfile cnf namefile dump_bin db_name
  local -a dump_args=()
  backups="${shared}/backups"
  mkdir -p "$backups"
  chmod 700 "$backups"
  outfile="${backups}/$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
  cnf="$(mktemp "${backups}/.client.XXXXXX")"
  namefile="$(mktemp "${backups}/.dbname.XXXXXX")"
  chmod 600 "$cnf" "$namefile"
  RW_BACKUP_CNF="$cnf"
  RW_BACKUP_NAME="$namefile"
  rm_backup_secrets() {
    rm -f "${RW_BACKUP_CNF:-}" "${RW_BACKUP_NAME:-}"
    unset RW_BACKUP_CNF RW_BACKUP_NAME
  }
  trap rm_backup_secrets EXIT

  if [[ -e "$outfile" ]]; then
    echo "backup=failed path" >&2
    return 1
  fi
  case "$outfile" in
    */www/app.reserveworx.com/laravel/shared/backups/*.sql.gz) ;;
    *)
      echo "backup=failed path" >&2
      return 1
      ;;
  esac
  case "$outfile" in
    *public_html*|*www/reserveworx.com/*)
      echo "backup=failed path" >&2
      return 1
      ;;
  esac

  if ! RW_ENV_FILE="${shared}/.env" RW_CNF="$cnf" RW_DB_NAME_FILE="$namefile" "$php_bin" -r '
ini_set("display_errors", "0");
try {
    $path = getenv("RW_ENV_FILE");
    $cnf = getenv("RW_CNF");
    $nameFile = getenv("RW_DB_NAME_FILE");
    if (!is_string($path) || !is_string($cnf) || !is_string($nameFile) || !is_readable($path)) {
        throw new RuntimeException("unreadable");
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === "") {
        throw new RuntimeException("empty");
    }
    $vals = [];
    foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
        if (!is_string($line)) {
            continue;
        }
        $eq = strpos($line, "=");
        if ($eq === false) {
            continue;
        }
        $key = substr($line, 0, $eq);
        if (!in_array($key, ["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"], true)) {
            continue;
        }
        $value = substr($line, $eq + 1);
        $quoted = strlen($value) >= 2 && $value[0] === "\"" && str_ends_with($value, "\"");
        if ($quoted) {
            $value = substr($value, 1, -1);
            $value = str_replace(["\\\"", "\\\\"], ["\"", "\\"], $value);
        }
        $vals[$key] = $value;
    }
    foreach (["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"] as $key) {
        if (!isset($vals[$key]) || $vals[$key] === "") {
            throw new RuntimeException("missing");
        }
    }
    $host = $vals["DB_HOST"];
    $port = $vals["DB_PORT"];
    $name = $vals["DB_DATABASE"];
    $user = $vals["DB_USERNAME"];
    $pass = $vals["DB_PASSWORD"];
    if (!preg_match("/^[A-Za-z0-9._-]+$/", $host)) {
        throw new RuntimeException("host");
    }
    if (!preg_match("/^[0-9]+$/", $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException("port");
    }
    if (!preg_match("/^[A-Za-z0-9_]+$/", $name) || !preg_match("/^[A-Za-z0-9_]+$/", $user)) {
        throw new RuntimeException("name");
    }
    $quote = static function (string $value): string {
        return "\"".str_replace(["\\", "\""], ["\\\\", "\\\""], $value)."\"";
    };
    $ini = "[client]\nhost=".$quote($host)."\nport=".$quote($port)."\nuser=".$quote($user)."\npassword=".$quote($pass)."\n";
    if (file_put_contents($cnf, $ini, LOCK_EX) === false || file_put_contents($nameFile, $name, LOCK_EX) === false) {
        throw new RuntimeException("write");
    }
    chmod($cnf, 0600);
    chmod($nameFile, 0600);
} catch (Throwable $exception) {
    fwrite(STDERR, "backup=failed settings\n");
    exit(1);
}
'; then
    return 1
  fi

  if ! dump_bin="$(find_dump)"; then
    echo "backup=failed client" >&2
    return 1
  fi

  db_name="$(tr -d '\r\n' < "$namefile")"
  if [[ ! "$db_name" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "backup=failed settings" >&2
    return 1
  fi

  dump_args=(
    --defaults-extra-file="$cnf"
    --single-transaction
    --quick
    --skip-lock-tables
    --default-character-set=utf8mb4
  )
  if "$dump_bin" --help 2>&1 | grep -q 'no-tablespaces'; then
    dump_args+=(--no-tablespaces)
  fi
  if "$dump_bin" --help 2>&1 | grep -q 'column-statistics'; then
    dump_args+=(--column-statistics=0)
  fi

  if ! "$dump_bin" "${dump_args[@]}" --databases "$db_name" | gzip -c > "$outfile"; then
    rm -f "$outfile"
    echo "backup=failed dump" >&2
    return 1
  fi

  chmod 600 "$outfile"
  rm_backup_secrets
  if ! assert_dump_file "$outfile"; then
    rm -f "$outfile"
    return 1
  fi
  echo "backup_path=${outfile}"
  echo "dump_client=${dump_bin}"

  # This host has no /dev/fd, so the file list cannot use process substitution.
  local count=0 file list
  list="$(mktemp)"
  find "$backups" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2- > "$list"
  while IFS= read -r file; do
    [[ -n "$file" ]] || continue
    count=$((count + 1))
    if [[ "$count" -gt 2 ]]; then
      rm -f "$file"
      echo "backup_pruned=1"
    fi
  done < "$list"
  rm -f "$list"
}

harbor_docroot_verified() {
  local doc resolved list name
  doc="${HOME}/www/harbor.reserveworx.com/public_html"
  if [[ ! -d "$doc" || -L "$doc" ]]; then
    echo "harbor_docroot=missing" >&2
    echo "Expected a real directory at ${doc}. No site was created and reserveworx.com was not touched." >&2
    if [[ -d "${HOME}/www" ]]; then
      list="$(mktemp)"
      find "${HOME}/www" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort > "$list"
      echo "www_entries_begin" >&2
      while IFS= read -r name; do
        [[ -n "$name" ]] || continue
        echo "www_entry=${name}" >&2
      done < "$list"
      echo "www_entries_end" >&2
      rm -f "$list"
    fi
    return 1
  fi
  resolved="$(readlink -f "$doc")"
  case "$resolved" in
    */www/harbor.reserveworx.com/public_html) ;;
    *)
      echo "harbor_docroot=rejected resolved=${resolved}" >&2
      return 1
      ;;
  esac
  case "$resolved" in
    *www/reserveworx.com*)
      echo "harbor_docroot=rejected because it is under reserveworx.com" >&2
      return 1
      ;;
  esac
  echo "harbor_docroot=${resolved}"
}

install_harbor_front_controller() {
  local doc boot htaccess
  doc="$(harbor_docroot_verified | sed -n 's/^harbor_docroot=//p' | tail -n 1)"
  if [[ -z "$doc" ]]; then
    return 1
  fi
  boot="${domain}/laravel/current"
  case "$boot" in
    */www/app.reserveworx.com/laravel/current) ;;
    *)
      echo "harbor_front_controller=rejected" >&2
      return 1
      ;;
  esac
  cat > "${doc}/index.php" << EOF
<?php

use Illuminate\\Foundation\\Application;
use Illuminate\\Http\\Request;

define('LARAVEL_START', microtime(true));

\$root = '${boot}';

if (file_exists(\$maintenance = \$root.'/storage/framework/maintenance.php')) {
    require \$maintenance;
}

require \$root.'/vendor/autoload.php';

/** @var Application \$app */
\$app = require_once \$root.'/bootstrap/app.php';

\$app->handleRequest(Request::capture());
EOF
  htaccess="${docroot}/.htaccess"
  if [[ ! -f "$htaccess" ]]; then
    htaccess="$(readlink -f "$current")/public/.htaccess"
  fi
  if [[ -f "$htaccess" ]]; then
    cp "$htaccess" "${doc}/.htaccess"
    chmod 644 "${doc}/.htaccess"
  fi
  chmod 644 "${doc}/index.php"
  if ! grep -q "$boot" "${doc}/index.php"; then
    echo "harbor_front_controller=missing boot path" >&2
    return 1
  fi
  echo "harbor_front_controller=absolute ${boot}"
}

harbor_login_check() {
  local body headers code
  body="$(mktemp)"
  headers="$(mktemp)"
  code="$(https_get "https://harbor.reserveworx.com/staff/login" "$body" "$headers")"
  echo "harbor_login_http=${code:-none}"
  if [[ "$code" == "200" ]] && grep -q "Team sign in" "$body"; then
    echo "harbor_login=ok"
    rm -f "$body" "$headers"
    return 0
  fi
  echo "harbor_login=failed" >&2
  note_laravel_log
  rm -f "$body" "$headers"
  return 1
}

harbor_home_check() {
  local body headers code
  body="$(mktemp)"
  headers="$(mktemp)"
  code="$(https_get "https://harbor.reserveworx.com/" "$body" "$headers")"
  echo "harbor_home_http=${code:-none}"
  if [[ "$code" == "200" ]] && grep -q "Harbor Demo bookings" "$body" && grep -q "<h1>Harbor Demo</h1>" "$body" && grep -q "Single kayak rental" "$body" && grep -q "Paddleboard rental" "$body" && grep -q "Sunset paddle tour" "$body"; then
    echo "harbor_home=ok"
    rm -f "$body" "$headers"
    return 0
  fi
  echo "harbor_home=failed" >&2
  note_laravel_log
  rm -f "$body" "$headers"
  return 1
}

note_laravel_log() {
  local log
  log="${shared}/storage/logs/laravel.log"
  if [[ -f "$log" ]]; then
    echo "laravel_log_tail_begin" >&2
    tail -n 40 "$log" >&2 || true
    echo "laravel_log_tail_end" >&2
  fi
}

seed_demo_shop() {
  (cd "$current" && "$php_bin" artisan db:seed --class=DemoShopSeeder --force --no-interaction)
}

grant_demo_owner() {
  (cd "$current" && "$php_bin" artisan reserveworx:grant-demo-owner michael@brandworx.ai harbor-demo --no-interaction)
}

grant_platform_admin() {
  local password_file
  password_file="${shared}/platform-admin.txt"
  case "$password_file" in
    */www/app.reserveworx.com/laravel/shared/platform-admin.txt) ;;
    *)
      echo "platform_admin=rejected" >&2
      return 1
      ;;
  esac
  case "$password_file" in
    *public_html*|*www/reserveworx.com*)
      echo "platform_admin=rejected" >&2
      return 1
      ;;
  esac
  (cd "$current" && "$php_bin" artisan reserveworx:grant-platform-admin michael@brandworx.ai --password-file="$password_file" --no-interaction)
  if [[ -f "$password_file" ]]; then
    chmod 600 "$password_file"
    echo "platform_admin_file=${password_file}"
  fi
}

# Inode cleanup for app.reserveworx.com only. Every path is checked against
# the app's laravel dir before anything is removed. Nothing outside
# laravel/releases and laravel/shared/storage cache dirs is deleted, and
# .env, platform-admin.txt and demo-logins.txt are never touched.
guard_app_path() {
  local path="$1"
  if [[ -z "$path" || -z "${laravel:-}" || -z "${releases:-}" || -z "${shared:-}" ]]; then
    echo "inode_cleanup=refused empty path" >&2
    return 1
  fi
  case "$path" in
    */www/app.reserveworx.com/laravel/releases/?*|*/www/app.reserveworx.com/laravel/shared/storage/?*) ;;
    *)
      echo "inode_cleanup=refused ${path}" >&2
      return 1
      ;;
  esac
  case "$path" in
    *public_html*|*www/reserveworx.com/*|*/../*|*.env*|*platform-admin.txt*|*demo-logins.txt*)
      echo "inode_cleanup=refused ${path}" >&2
      return 1
      ;;
  esac
  if [[ "$path" != "${laravel}/"* ]]; then
    echo "inode_cleanup=refused ${path}" >&2
    return 1
  fi
}

count_files() {
  find "$1" -type f 2>/dev/null | wc -l | tr -d ' '
}

# Delete regular files under one cache dir that are older than N minutes.
prune_old_files() {
  local dir="$1" minutes="$2" name="$3" before after
  if [[ ! -d "$dir" || -L "$dir" ]]; then
    echo "inode_cleanup_${name}=absent"
    return 0
  fi
  guard_app_path "$dir" || return 0
  [[ "$minutes" =~ ^[0-9]+$ ]] || return 0
  before="$(count_files "$dir")"
  find "$dir" -xdev -type f -mmin "+${minutes}" ! -name '.gitignore' -delete 2>/dev/null || true
  find "$dir" -xdev -mindepth 1 -type d -empty -delete 2>/dev/null || true
  after="$(count_files "$dir")"
  echo "inode_cleanup_${name}_removed=$((before - after)) remaining=${after}"
}

report_inodes() {
  local label="$1" entry n total
  echo "inodes_${label}_begin"
  shopt -s nullglob dotglob
  for entry in "$domain"/* "$laravel"/* "$shared"/* "$shared"/storage/* "$shared"/storage/framework/*; do
    [[ -e "$entry" && ! -L "$entry" ]] || continue
    case "$entry" in *.env|*platform-admin.txt|*demo-logins.txt|*.mail-password) continue ;; esac
    n="$(du --inodes -s -x "$entry" 2>/dev/null | cut -f1 || true)"
    echo "inodes_${label} ${n:-?} ${entry#"${domain}/"}"
  done
  shopt -u nullglob dotglob
  total="$(du --inodes -s -x "$domain" 2>/dev/null | cut -f1 || true)"
  echo "inodes_${label}_total=${total:-?} path=www/app.reserveworx.com"
  echo "release_count=$(find "$releases" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')"
  echo "inodes_${label}_end"
}

# Read-only: reports inode use of app-owned paths and common home caches so
# the account-wide count can be explained. Deletes nothing and never descends
# into www/reserveworx.com or any other site.
report_home_inodes() {
  local entry n
  echo "inodes_home_begin"
  for entry in "$HOME/.composer" "$HOME/.cache" "$HOME/.npm" "$HOME/.config/composer" "$HOME/tmp" "$HOME/.local" "$HOME/www/harbor.reserveworx.com"; do
    [[ -e "$entry" && ! -L "$entry" ]] || { echo "inodes_home - ${entry#"${HOME}/"} (absent)"; continue; }
    n="$(du --inodes -s -x "$entry" 2>/dev/null | cut -f1 || true)"
    echo "inodes_home ${n:-?} ${entry#"${HOME}/"}"
  done
  shopt -s nullglob dotglob
  for entry in "$HOME/.cache"/* "$HOME/.composer"/* "$HOME/tmp"/*; do
    [[ -e "$entry" && ! -L "$entry" ]] || continue
    n="$(du --inodes -s -x "$entry" 2>/dev/null | cut -f1 || true)"
    echo "inodes_home ${n:-?} ${entry#"${HOME}/"}"
  done
  shopt -u nullglob dotglob
  du --inodes -x -d2 "$domain" 2>/dev/null | sort -nr | head -40 | while read -r n entry; do
    case "$entry" in *.env|*platform-admin.txt|*demo-logins.txt) continue ;; esac
    echo "inodes_app_d2 ${n} ${entry#"${HOME}/"}"
  done
  echo "inodes_home_end"
}

# Keeps current plus one rollback release, removes build leftovers and stale
# framework caches, sessions and old logs, then reports inode counts.
inode_cleanup() {
  local dir rel
  report_inodes before
  prune_releases
  shopt -s nullglob
  for rel in "$releases"/*; do
    [[ -d "$rel" && ! -L "$rel" ]] || continue
    for dir in "$rel/node_modules" "$rel/.composer" "$rel/.cache" "$rel/tests"; do
      if [[ -d "$dir" && ! -L "$dir" ]] && guard_app_path "$dir"; then
        echo "inode_cleanup_build_removed=${dir#"${laravel}/"} files=$(count_files "$dir")"
        rm -rf -- "$dir"
      fi
    done
  done
  shopt -u nullglob
  # Compiled views are rebuilt by view:cache on each deploy and lazily after.
  prune_old_files "${shared}/storage/framework/views" 180 views
  # Session lifetime is 120 minutes, so file sessions older than a day are dead.
  prune_old_files "${shared}/storage/framework/sessions" 1440 sessions
  prune_old_files "${shared}/storage/framework/cache/data" 10080 cache
  prune_old_files "${shared}/storage/framework/testing" 0 testing
  prune_old_files "${shared}/storage/pail" 1440 pail
  prune_old_files "${shared}/storage/logs" 20160 logs
  if [[ -d "${shared}/vendor" || -d "${laravel}/vendor" ]]; then
    echo "vendor_outside_releases=present (not removed, review)"
  else
    echo "vendor_outside_releases=none"
  fi
  report_inodes after
  report_home_inodes || true
}

if [[ "${RW_DEPLOY_LIBRARY:-}" == "1" ]]; then
  return 0
fi

if [[ "$ACTION" == "backup" ]]; then
  if ! php_bin="$(find_php)"; then
    echo "backup=failed php" >&2
    exit 1
  fi
  if [[ ! -f "${shared}/.env" ]]; then
    echo "shared_env=missing" >&2
    exit 1
  fi
  backup_database
  exit 0
fi

echo "https_enforcement=required for app.reserveworx.com only, in Site Tools, Security, HTTPS Enforce"

if [[ "$ACTION" == "rollback" ]]; then
  if ! swap_to_previous; then
    echo "No previous release is recorded. Nothing was changed." >&2
    exit 1
  fi
  prune_releases
  purge_dynamic_cache || echo "dynamic_cache=flush app.reserveworx.com manually in Site Tools"
  health_check
  exit 0
fi

if [[ ! "${RELEASE:-}" =~ ^[0-9a-f]{40}$ ]]; then
  echo "RELEASE must be a 40-character commit sha." >&2
  exit 1
fi

if [[ ! -f "$archive" ]]; then
  echo "The release archive is missing. Nothing was extracted." >&2
  exit 1
fi

if ! php_bin="$(find_php)"; then
  echo "No PHP 8.2 or newer binary was found. Document root was not changed." >&2
  rm -f "$archive"
  exit 1
fi

echo "php_binary=${php_bin}"
echo "php_version=$("$php_bin" -r 'echo PHP_VERSION;')"

if [[ ! -f "${shared}/.env" ]]; then
  echo "shared_env=missing"
  echo "document_root=not changed. Create ${shared}/.env from .env.example before the document root is switched." >&2
  rm -f "$archive"
  exit 1
fi

if ! configure_production_mail; then
  rm -f "$archive"
  echo "Mail settings were not written. Document root was not changed." >&2
  exit 1
fi

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  if ! assert_dump_file "${BACKUP_PATH:-}"; then
    rm -f "$archive"
    echo "Migrations were not run. Document root was not changed." >&2
    exit 1
  fi
fi

if ! harbor_docroot_verified >/dev/null; then
  rm -f "$archive"
  echo "Harbor document root was not verified. Migrations were not run. Document root was not changed." >&2
  exit 1
fi

drop_old_rollback

target="${releases}/${RELEASE}"
rm -rf "$target"
mkdir -p "$target"
tar -xzf "$archive" -C "$target"
rm -f "$archive"
echo "archive=deleted"

if [[ ! -f "${target}/artisan" || ! -f "${target}/vendor/autoload.php" ]]; then
  echo "The release is missing artisan or vendor. Document root was not changed." >&2
  rm -rf "$target"
  exit 1
fi

if [[ -d "${target}/vendor" && -d "${shared}/vendor" ]]; then
  echo "Refusing a second vendor directory under shared." >&2
  rm -rf "$target"
  exit 1
fi

link_shared "$target"

# Migrations, role sync, and caches run from the new release before it goes
# live. Until current moves, every request is still served by the old release,
# so new code never runs against the old schema. A failure here leaves current
# where it was and removes the new release directory.
abandon_release() {
  echo "$1 current was not changed." >&2
  rm -rf "$target"
  exit 1
}

migrate="skipped"
if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  (cd "$target" && "$php_bin" artisan migrate --force --no-interaction) || abandon_release "migrate=failed."
  migrate="ran"
fi
echo "migrations=${migrate}"
# System roles and permissions are defined in code (RoleAndPermissionSeeder, idempotent
# updateOrCreate + sync). Sync them on every deploy so new permissions reach existing roles.
(cd "$target" && "$php_bin" artisan db:seed --class=RoleAndPermissionSeeder --force --no-interaction) || abandon_release "roles_synced=failed."
echo "roles_synced=yes"
echo "composer_on_server=not run"

(cd "$target" && "$php_bin" artisan config:cache --no-interaction) || abandon_release "config_cache=failed."
(cd "$target" && "$php_bin" artisan route:cache --no-interaction) || abandon_release "route_cache=failed."
(cd "$target" && "$php_bin" artisan view:cache --no-interaction) || abandon_release "view_cache=failed."
echo "caches=written"

if [[ -L "$current" ]]; then
  ln -sfn "$(release_path "$current")" "$previous"
fi
ln -sfn "$target" "$current"
echo "current=$(readlink "$current")"
prune_releases

(cd "$current" && "$php_bin" artisan reserveworx:mail-status --no-interaction) || echo "mail_status=failed" >&2

switch_docroot
purge_dynamic_cache || echo "dynamic_cache=flush app.reserveworx.com manually in Site Tools"
if ! health_check; then
  echo "health=failed. Rolling the code release back. migrate:rollback is not run." >&2
  swap_to_previous || true
  exit 1
fi
inode_cleanup || echo "inode_cleanup=failed" >&2
grant_platform_admin
install_harbor_front_controller
(cd "$current" && "$php_bin" artisan reserveworx:attach-verified-domain harbor-demo harbor.reserveworx.com --no-interaction)
post_failed=0
seed_demo_shop || post_failed=1
grant_demo_owner || post_failed=1
platform_check || post_failed=1
harbor_login_check || post_failed=1
harbor_home_check || post_failed=1
if [[ "$post_failed" -ne 0 ]]; then
  exit 1
fi
# Smoke runs against the release that is already current. A failure stops
# the job. It does not swap current back and it does not roll migrations back.
if ! (cd "$current" && "$php_bin" artisan reserveworx:smoke --no-interaction); then
  exit 1
fi
echo "deploy=complete"
echo "cron_hint=Site Tools cron, every minute, only this command: ${php_bin} ${current}/artisan schedule:run"
echo "cron_command_body=<not installed by this script>"
