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
# Inode guard: ACTION=preflight (run by the Deploy workflow before the release
# upload) and the deploy itself count the whole account with
# du --inodes -s -x "$HOME", add RW_INODE_EXTERNAL (usage outside this SSH
# account, set as a repo variable), warn at 450000 and abort at 540000 of the
# 600000 cap. Rollback and backup never run the guard.
#
# Inode budget: at most the current release tree and one previous release tree.
# The upload archive is deleted after extract. Vendor lives only inside those
# release trees. Composer is not run on the server. Database dumps are at most
# two gzip files under laravel/shared/backups, outside public_html.
set -euo pipefail

: "${ACTION:?ACTION is required}"

if [[ "$ACTION" != "deploy" && "$ACTION" != "rollback" && "$ACTION" != "backup" && "$ACTION" != "preflight" && "$ACTION" != "maintenance" ]]; then
  echo "ACTION must be deploy, rollback, backup, preflight, or maintenance." >&2
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
# Daily logs older than this are deleted: seven days.
log_retention_minutes=10080
# Sessions older than 2 days only. Never all sessions: the app has live users.
session_retention_minutes=2880

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
# Any failed deploy leaves no uploaded archive behind, whichever step failed.
remove_incoming_archives() {
  case "$incoming" in
    */www/app.reserveworx.com/laravel/incoming) ;;
    *) return 0 ;;
  esac
  [[ -d "$incoming" && ! -L "$incoming" ]] || return 0
  find "$incoming" -maxdepth 1 -type f -name '*.tar.gz' -delete 2>/dev/null || true
}

on_exit() {
  local status=$?
  cleanup_mail_password_file
  if [[ "$status" -ne 0 && "$ACTION" == "deploy" ]]; then
    remove_incoming_archives
  fi
  return "$status"
}
trap on_exit EXIT

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

# Delete release directories other than current and the one rollback. It
# refuses to delete anything when current cannot be resolved to a release
# directory, only removes real directories named with a 40-character commit
# sha directly under laravel/releases, and leaves anything else for review.
prune_releases() {
  local keep_current keep_previous dir full name releases_real
  releases_real="$(readlink -f "$releases")"
  if [[ -z "$releases_real" || -L "$releases" || "$releases_real" != */www/app.reserveworx.com/laravel/releases ]]; then
    echo "prune_releases=skipped releases path is not a plain directory" >&2
    return 0
  fi
  keep_current="$(release_path "$current")"
  keep_previous="$(release_path "$previous")"
  if [[ -z "$keep_current" || ! -d "$keep_current" || "$keep_current" != "${releases_real}/"* ]]; then
    echo "prune_releases=skipped current release could not be resolved" >&2
    return 0
  fi

  shopt -s nullglob
  for dir in "$releases"/*; do
    [[ -d "$dir" && ! -L "$dir" ]] || continue
    name="$(basename "$dir")"
    full="$(readlink -f "$dir")"
    if [[ "$full" == "$keep_current" || "$full" == "$keep_previous" ]]; then
      continue
    fi
    if [[ ! "$name" =~ ^[0-9a-f]{40}$ || "$full" != "${releases_real}/${name}" ]]; then
      echo "prune_skipped_unrecognized=${name}"
      continue
    fi
    rm -rf -- "$dir"
    echo "pruned=${dir}"
  done
  shopt -u nullglob

  remove_incoming_archives
  echo "archives=deleted"
}

drop_old_rollback() {
  local prev curr
  prev="$(release_path "$previous")"
  curr="$(release_path "$current")"
  if [[ -n "$curr" && -d "$curr" && -n "$prev" && "$prev" != "$curr" && "$prev" == "$releases/"* && "$(basename "$prev")" =~ ^[0-9a-f]{40}$ ]]; then
    rm -rf -- "$prev"
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

  prune_db_dumps
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

# Customer workspaces from config/tenants.php. For each tenant_host line printed
# by reserveworx:tenant:sync, write a front controller into
# www/<host>/public_html only when Site Tools already created that subdomain
# folder as a real directory. Never creates a site, never touches
# www/reserveworx.com or www/app.reserveworx.com. A missing folder is reported
# and does not fail the deploy.
install_tenant_front_controller() {
  local host doc resolved boot
  host="$1"
  if [[ ! "$host" =~ ^[a-z0-9]+(-[a-z0-9]+)*\.reserveworx\.com$ ]] || [[ "$host" == "app.reserveworx.com" ]]; then
    echo "tenant_front_controller=rejected host=${host}" >&2
    return 1
  fi
  doc="${HOME}/www/${host}/public_html"
  if [[ ! -d "$doc" || -L "$doc" || -L "${HOME}/www/${host}" ]]; then
    echo "tenant_docroot=missing host=${host} expected=${doc} (create the subdomain in Site Tools)"
    return 0
  fi
  resolved="$(readlink -f "$doc")"
  case "$resolved" in
    */www/"${host}"/public_html) ;;
    *)
      echo "tenant_docroot=rejected host=${host} resolved=${resolved}" >&2
      return 1
      ;;
  esac
  case "$resolved" in
    */www/reserveworx.com/*|*/www/app.reserveworx.com/*)
      echo "tenant_docroot=rejected host=${host}" >&2
      return 1
      ;;
  esac
  boot="${domain}/laravel/current"
  cat > "${resolved}/index.php" << EOF
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
  if [[ -f "${docroot}/.htaccess" ]]; then
    cp "${docroot}/.htaccess" "${resolved}/.htaccess"
    chmod 644 "${resolved}/.htaccess"
  fi
  chmod 644 "${resolved}/index.php"
  echo "tenant_front_controller=written host=${host} boot=${boot}"
}

sync_tenants() {
  local out host status=0 list
  out="$(cd "$current" && "$php_bin" artisan reserveworx:tenant:sync --no-interaction 2>&1)" || status=1
  printf '%s\n' "$out"
  # This host has no /dev/fd, so the host list goes through a temp file.
  list="$(mktemp)"
  printf '%s\n' "$out" | sed -n 's/^tenant_host=//p' > "$list"
  while IFS= read -r host; do
    [[ -n "$host" ]] || continue
    install_tenant_front_controller "$host" || status=1
  done < "$list"
  rm -f "$list"
  return "$status"
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
  if [[ ! -f "$log" ]]; then
    # The daily channel writes laravel-YYYY-MM-DD.log, newest name last.
    log="$(ls -1 "${shared}/storage/logs"/laravel-*.log 2>/dev/null | tail -n 1 || true)"
  fi
  if [[ -n "$log" && -f "$log" ]]; then
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


# Read-only, count-only (approved by Michael 2026-10-26): inode counts for the
# main marketing site folder and home top-level dirs. Lists directory names and
# counts only; reads no file contents, changes nothing.
report_site_inodes() {
  local site="$HOME/www/reserveworx.com" n entry
  echo "inodes_site_begin"
  if [[ -d "$site" && ! -L "$site" ]]; then
    n="$(du --inodes -s -x "$site" 2>/dev/null | cut -f1 || true)"
    echo "inodes_site_total=${n:-?} path=www/reserveworx.com"
    du --inodes -x -d2 "$site" 2>/dev/null | sort -nr | head -40 | while read -r n entry; do
      echo "inodes_site_d2 ${n} ${entry#"${HOME}/"}"
    done
  else
    echo "inodes_site_total=absent"
  fi
  du --inodes -x -d1 "$HOME" 2>/dev/null | sort -nr | head -30 | while read -r n entry; do
    echo "inodes_home_d1 ${n} ${entry#"${HOME}/"}"
  done
  du --inodes -x -d1 "$HOME/www" 2>/dev/null | sort -nr | head -30 | while read -r n entry; do
    echo "inodes_www_d1 ${n} ${entry#"${HOME}/"}"
  done
  echo "inodes_site_end"
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

# Keep at most two database dumps, newest first, and drop any client or
# database-name temp file a killed backup left behind (it holds DB settings).
# Runs after every backup and from inode_cleanup, so the cap holds even when a
# backup is skipped.
prune_db_dumps() {
  local backups="${shared}/backups" keep=2 count=0 file list
  [[ -d "$backups" && ! -L "$backups" ]] || return 0
  case "$backups" in
    */www/app.reserveworx.com/laravel/shared/backups) ;;
    *) return 0 ;;
  esac
  find "$backups" -maxdepth 1 -type f \( -name '.client.*' -o -name '.dbname.*' \) -mmin +60 -delete 2>/dev/null || true
  # This host has no /dev/fd, so the file list cannot use process substitution.
  list="$(mktemp)"
  find "$backups" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2- > "$list"
  while IFS= read -r file; do
    [[ -n "$file" ]] || continue
    count=$((count + 1))
    if [[ "$count" -gt "$keep" ]]; then
      rm -f -- "$file"
      echo "backup_pruned=1"
    fi
  done < "$list"
  rm -f "$list"
  echo "db_dumps_kept=$((count > keep ? keep : count))"
}

# Regenerable files only, used by the guard before it decides. The release
# archive that is about to be extracted is kept; every other archive in
# incoming is stale.
inode_guard_cleanup() {
  case "$incoming" in
    */www/app.reserveworx.com/laravel/incoming) ;;
    *) return 0 ;;
  esac
  if [[ -d "$incoming" && ! -L "$incoming" ]]; then
    find "$incoming" -maxdepth 1 -type f -name '*.tar.gz' ! -name "${RELEASE:-none}.tar.gz" -delete 2>/dev/null || true
  fi
  prune_old_files "${shared}/storage/framework/views" 180 views
  prune_old_files "${shared}/storage/framework/sessions" "$session_retention_minutes" sessions
  prune_old_files "${shared}/storage/framework/cache/data" 10080 cache
  prune_old_files "${shared}/storage/framework/testing" 0 testing
  prune_old_files "${shared}/storage/pail" 1440 pail
  prune_old_files "${shared}/storage/logs" "$log_retention_minutes" logs
  prune_db_dumps
}

# du --inodes -s -x of the real home, with a trailing slash so a symlinked home
# is followed. On a jailed SiteGround account -x can stop at a mount boundary and
# report 1 for the whole home, so the same count without -x is taken too and the
# larger of the two is used. Both are printed by the maintenance report.
count_account_inodes() {
  local home x all
  home="$(cd "$HOME" 2>/dev/null && pwd -P)" || home="$HOME"
  x="$(du --inodes -s -x "${home}/" 2>/dev/null | cut -f1 || true)"
  all="$(du --inodes -s "${home}/" 2>/dev/null | cut -f1 || true)"
  [[ "$x" =~ ^[0-9]+$ ]] || x=0
  [[ "$all" =~ ^[0-9]+$ ]] || all=0
  if [[ "$all" -gt "$x" ]]; then
    echo "$all"
  elif [[ "$x" -gt 0 ]]; then
    echo "$x"
  fi
}

# Inode guard for deploys. SiteGround counts inodes for the whole account and
# the deploy user cannot see mail, other sites or backups, so the total is
# this home directory plus RW_INODE_EXTERNAL, an allowance for usage outside it
# (repo variable, 0 when unset). At or above the warn level it removes
# regenerable files and counts again. At or above the abort level it fails
# before anything else is written. It is never run for rollback or backup.
inode_guard() {
  local cap=600000 external warn abort home_count total
  external="${RW_INODE_EXTERNAL:-0}"
  warn="${RW_INODE_WARN:-450000}"
  abort="${RW_INODE_ABORT:-540000}"
  if [[ ! "$external" =~ ^[0-9]{1,9}$ ]]; then
    echo "inode_guard_external=invalid, treated as 0" >&2
    external=0
  fi
  [[ "$warn" =~ ^[0-9]{1,9}$ ]] || warn=450000
  [[ "$abort" =~ ^[0-9]{1,9}$ ]] || abort=540000

  home_count="$(count_account_inodes)"
  if [[ ! "$home_count" =~ ^[0-9]+$ ]]; then
    echo "inode_guard=unmeasured, du --inodes did not report a count, guard skipped" >&2
    return 0
  fi
  total=$((home_count + external))
  if [[ "$total" -ge "$warn" ]]; then
    echo "inode_guard_cleanup=running at ${total}"
    inode_guard_cleanup
    home_count="$(count_account_inodes)"
    if [[ "$home_count" =~ ^[0-9]+$ ]]; then
      total=$((home_count + external))
    fi
  fi

  echo "inode_guard_home=${home_count}"
  echo "inode_guard_external=${external}"
  echo "inode_guard_total=${total}"
  echo "inode_guard_warn=${warn} abort=${abort} cap=${cap}"
  if [[ "$total" -ge "$abort" ]]; then
    echo "inode_guard=abort total ${total} is at or above ${abort} of the ${cap} cap. Nothing was extracted or changed. Free space outside this app (main site, mail, backups) or raise RW_INODE_EXTERNAL only if it is too high." >&2
    return 1
  fi
  if [[ "$total" -ge "$warn" ]]; then
    echo "inode_guard=warn total ${total} is at or above ${warn} of the ${cap} cap"
    return 0
  fi
  echo "inode_guard=ok"
  return 0
}

# Pin the daily log channel with a seven day limit in the shared .env without
# printing it. Only LOG_CHANNEL and LOG_DAILY_DAYS change; every other line is
# copied as it is, and the file is left alone when the copy looks short.
ensure_log_settings() {
  local env_file="${shared}/.env" tmp before after
  case "$env_file" in
    */www/app.reserveworx.com/laravel/shared/.env) ;;
    *)
      echo "log_env=rejected" >&2
      return 1
      ;;
  esac
  [[ -f "$env_file" && ! -L "$env_file" ]] || return 0
  tmp="$(mktemp "${shared}/.env-log.XXXXXX")"
  chmod 600 "$tmp"
  awk '
    /^LOG_CHANNEL=/ { print "LOG_CHANNEL=daily"; c = 1; next }
    /^LOG_DAILY_DAYS=/ { print "LOG_DAILY_DAYS=7"; d = 1; next }
    { print }
    END {
      if (!c) print "LOG_CHANNEL=daily"
      if (!d) print "LOG_DAILY_DAYS=7"
    }
  ' "$env_file" > "$tmp"
  before="$(wc -l < "$env_file")"
  after="$(wc -l < "$tmp")"
  if [[ "$after" -ge "$before" && -s "$tmp" ]] && cat "$tmp" > "$env_file"; then
    echo "log_env=daily 7 days"
  else
    echo "log_env=unchanged" >&2
  fi
  rm -f "$tmp"
}

# Entries counted by find, one per path, so symlinks and mounts show up.
count_entries() {
  find "$1" 2>/dev/null | wc -l | tr -d ' '
}

# Secret hand-off files are located by name only. Their contents are never read.
# A path under public_html is under a web root.
find_secret_files() {
  local root
  for root in "$domain" "${HOME}/www/harbor.reserveworx.com"; do
    [[ -d "$root" && ! -L "$root" ]] || continue
    find "$root" -xdev -maxdepth 6 \( -type f -o -type l \) \( -name demo-logins.txt -o -name platform-admin.txt \) 2>/dev/null
  done
}

# Read-only. Answers where the inodes are and whether the numbers in the deploy
# log are for the real path: it prints the resolved path and its mount, counts
# the tree with find (links not followed) and with find -L (links followed, how
# a symlink-following scanner would count it), and counts every release with
# and without vendor.
maintenance_report() {
  local label="$1" entry name target kind file webroot home_real secrets
  echo "maintenance_${label}_begin"
  echo "maintenance_home_real=$(cd "$HOME" && pwd -P)"
  echo "maintenance_laravel_real=$(readlink -f "$laravel")"
  echo "maintenance_laravel_mount=$(df -P "$laravel" 2>/dev/null | awk 'NR==2 {print $1 " " $6}')"
  echo "maintenance_find_laravel=$(count_entries "$laravel")"
  echo "maintenance_find_L_laravel=$(find -L "$laravel" 2>/dev/null | wc -l | tr -d ' ')"
  echo "maintenance_du_x_laravel=$(du --inodes -s -x "$laravel" 2>/dev/null | cut -f1 || true)"
  echo "maintenance_du_laravel=$(du --inodes -s "$laravel" 2>/dev/null | cut -f1 || true)"
  echo "maintenance_find_domain=$(count_entries "$domain")"
  home_real="$(cd "$HOME" 2>/dev/null && pwd -P)" || home_real="$HOME"
  echo "maintenance_home_is_symlink=$([[ -L "$HOME" ]] && echo yes || echo no)"
  echo "maintenance_du_x_home=$(du --inodes -s -x "${home_real}/" 2>/dev/null | cut -f1 || true)"
  echo "maintenance_du_home_without_x=$(du --inodes -s "${home_real}/" 2>/dev/null | cut -f1 || true)"
  echo "maintenance_find_xdev_home=$(cd "$HOME" 2>/dev/null && find . -xdev 2>/dev/null | wc -l | tr -d ' ')"
  echo "maintenance_find_home=$(cd "$HOME" 2>/dev/null && find . 2>/dev/null | wc -l | tr -d ' ')"
  echo "maintenance_home_mount=$(df -P "$home_real" 2>/dev/null | awk 'NR==2 {print $1 " " $6}')"
  for name in current previous; do
    entry="${laravel}/${name}"
    if [[ -L "$entry" ]]; then
      target="$(readlink -f "$entry")"
      echo "maintenance_${name}=symlink to ${target#"${laravel}/"} find_L=$(count_entries "${entry}/")"
    elif [[ -d "$entry" ]]; then
      echo "maintenance_${name}=REAL DIRECTORY find=$(count_entries "$entry") vendor=$(count_entries "${entry}/vendor")"
    else
      echo "maintenance_${name}=absent"
    fi
  done
  shopt -s nullglob
  for entry in "$releases"/*; do
    [[ -d "$entry" ]] || continue
    kind="directory"
    [[ -L "$entry" ]] && kind="symlink"
    echo "maintenance_release=$(basename "$entry") ${kind} find=$(count_entries "$entry") vendor=$(count_entries "${entry}/vendor")"
  done
  for entry in "$laravel"/*; do
    echo "maintenance_laravel_entry=$(basename "$entry") find=$(count_entries "$entry")"
  done
  shopt -u nullglob
  secrets="$(find_secret_files)"
  while IFS= read -r file; do
    [[ -n "$file" ]] || continue
    webroot="no"
    case "$file" in */public_html/*) webroot="YES" ;; esac
    echo "maintenance_secret_file=${file#"${HOME}/"} under_web_root=${webroot} mode=$(stat -c %a "$file" 2>/dev/null || echo ?)"
  done <<< "$secrets"
  echo "maintenance_${label}_end"
}

# Read-only. Where the account's inodes are, counted from the SSH home with
# find (one filesystem, links not followed): the 40 biggest three-level paths by
# file count, then a total per top-level directory. Nothing is changed, including
# under www/reserveworx.com.
maintenance_breakdown() {
  local dir rows
  echo "maintenance_breakdown_begin"
  echo "maintenance_breakdown_top_paths (files, first three path levels under home, find -xdev)"
  rows="$(cd "$HOME" && find . -xdev -type f 2>/dev/null | cut -d/ -f2-4 | sort | uniq -c | sort -rn | head -40)" || rows=""
  if [[ -n "$rows" ]]; then
    echo "$rows"
  else
    echo "maintenance_breakdown_xdev_empty=find -xdev found nothing, repeating without -xdev"
    (cd "$HOME" && find . -type f 2>/dev/null | cut -d/ -f2-4 | sort | uniq -c | sort -rn | head -40) || true
  fi
  echo "maintenance_breakdown_per_directory (inodes with du -x, inodes without -x, name)"
  for dir in "$HOME"/* "$HOME"/.[!.]*; do
    [[ -d "$dir" ]] || continue
    printf '%s\t%s\t%s\n' "$(du --inodes -s -x "${dir}/" 2>/dev/null | cut -f1)" "$(du --inodes -s "${dir}/" 2>/dev/null | cut -f1)" "$(basename "$dir")"
  done | sort -rn || true
  echo "maintenance_breakdown_end"
}

# Opt-in purge of the PHP opcache folder. It deletes only the contents of
# <real home>/.opcache and keeps the folder. It refuses unless the path is
# exactly <real home>/.opcache and is a real directory (not a symlink), and it
# never follows a symlink out of it. Nothing else is touched.
purge_opcache() {
  local home_real target resolved before after account_before account_after status=0
  home_real="$(cd "$HOME" 2>/dev/null && pwd -P)" || home_real=""
  if [[ -z "$home_real" ]]; then
    echo "opcache_purge=refused reason=home-not-resolvable" >&2
    return 1
  fi
  target="${home_real}/.opcache"
  if [[ -L "$HOME/.opcache" || -L "$target" || ! -d "$target" ]]; then
    echo "opcache_purge=refused reason=not-a-real-directory path=${target}" >&2
    return 1
  fi
  resolved="$(cd "$target" 2>/dev/null && pwd -P)" || resolved=""
  if [[ "$resolved" != "${home_real}/.opcache" ]]; then
    echo "opcache_purge=refused reason=path-mismatch resolved=${resolved}" >&2
    return 1
  fi
  account_before="$(count_account_inodes)"
  before="$(find "$HOME/.opcache" -mindepth 1 2>/dev/null | wc -l | tr -d ' ')"
  echo "opcache_path=${resolved}"
  echo "opcache_inodes_before=${before}"
  echo "opcache_account_inodes_before=${account_before}"
  find "$HOME/.opcache" -mindepth 1 -delete || status=$?
  after="$(find "$HOME/.opcache" -mindepth 1 2>/dev/null | wc -l | tr -d ' ')"
  account_after="$(count_account_inodes)"
  echo "opcache_inodes_after=${after}"
  echo "opcache_account_inodes_after=${account_after}"
  echo "opcache_purge_exit=${status}"
  return "$status"
}

# Clean without touching any release. It never deletes, creates, links or
# uploads a release. It clears regenerable files only: compiled views, file
# cache and sessions older than 2 days in shared storage and in each release's own storage, logs
# older than 7 days, stale archives in incoming, and dumps beyond two
# (shared/backups stays). A secret hand-off file found under
# app.reserveworx.com/public_html is moved into laravel/shared, never read.
maintenance_clean() {
  local dir file secrets moved=0
  shopt -s nullglob
  for dir in "$shared/storage" "$releases"/*/storage; do
    [[ -d "$dir" ]] || continue
    prune_old_files "${dir}/framework/cache/data" 0 "cache_$(basename "$(dirname "$dir")")"
    prune_old_files "${dir}/framework/views" 0 "views_$(basename "$(dirname "$dir")")"
    prune_old_files "${dir}/framework/sessions" "$session_retention_minutes" "sessions_$(basename "$(dirname "$dir")")"
    prune_old_files "${dir}/logs" "$log_retention_minutes" "logs_$(basename "$(dirname "$dir")")"
  done
  shopt -u nullglob
  inode_guard_cleanup

  secrets="$(find_secret_files)"
  while IFS= read -r file; do
    [[ -n "$file" && ! -L "$file" ]] || continue
    case "$file" in
      "${domain}"/public_html/*)
        if [[ ! -e "${shared}/$(basename "$file")" ]]; then
          mv -n -- "$file" "${shared}/$(basename "$file")"
          chmod 600 "${shared}/$(basename "$file")"
          echo "maintenance_secret_moved=$(basename "$file") to laravel/shared"
          moved=$((moved + 1))
        else
          echo "maintenance_secret_move=skipped $(basename "$file") already exists in laravel/shared" >&2
        fi
        ;;
    esac
  done <<< "$secrets"
  echo "maintenance_secret_files_moved=${moved}"
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
# Also removes app-only leftovers this deploy created (never another site).
report_df_inodes() {
  local out
  echo "df_inodes_begin"
  out="$(mktemp)"
  if df -iP "$domain" >"$out" 2>/dev/null; then
    while IFS= read -r line; do
      echo "df_inodes ${line}"
    done < "$out"
  else
    echo "df_inodes=unavailable"
  fi
  rm -f "$out"
  echo "df_inodes_end"
}

remove_pre_docroots() {
  local dir
  shopt -s nullglob
  for dir in "$domain"/public_html.pre-reserveworx-*; do
    [[ -d "$dir" && ! -L "$dir" ]] || continue
    case "$dir" in
      */www/app.reserveworx.com/public_html.pre-reserveworx-*) ;;
      *)
        echo "inode_cleanup=refused ${dir}" >&2
        continue
        ;;
    esac
    case "$dir" in
      *www/reserveworx.com*|*laravel/shared*|*laravel/releases*)
        echo "inode_cleanup=refused ${dir}" >&2
        continue
        ;;
    esac
    echo "inode_cleanup_pre_docroot_removed=${dir#"${domain}/"} files=$(count_files "$dir")"
    rm -rf -- "$dir"
  done
  shopt -u nullglob
}

# Early dump path was laravel/backups; live dumps are laravel/shared/backups.
# Move any leftover dumps into shared, keep two newest, then drop the orphan dir.
remove_orphan_laravel_backups() {
  local orphan="${laravel}/backups" file dest count list
  [[ -d "$orphan" && ! -L "$orphan" ]] || { echo "inode_cleanup_orphan_backups=absent"; return 0; }
  case "$orphan" in
    */www/app.reserveworx.com/laravel/backups) ;;
    *)
      echo "inode_cleanup=refused ${orphan}" >&2
      return 0
      ;;
  esac
  if [[ "$orphan" == "${shared}/backups" ]]; then
    echo "inode_cleanup_orphan_backups=skipped_shared"
    return 0
  fi
  mkdir -p "${shared}/backups"
  chmod 700 "${shared}/backups"
  shopt -s nullglob
  for file in "$orphan"/*.sql.gz; do
    [[ -f "$file" ]] || continue
    dest="${shared}/backups/$(basename "$file")"
    if [[ -e "$dest" ]]; then
      rm -f -- "$file"
      echo "inode_cleanup_orphan_backup_dup_removed=$(basename "$file")"
    else
      mv -- "$file" "$dest"
      chmod 600 "$dest"
      echo "inode_cleanup_orphan_backup_moved=$(basename "$file")"
    fi
  done
  shopt -u nullglob
  echo "inode_cleanup_orphan_backups_dir_removed=1 files=$(count_files "$orphan")"
  rm -rf -- "$orphan"
  prune_db_dumps
}

inode_cleanup() {
  local dir rel
  report_inodes before
  prune_releases
  shopt -s nullglob
  for rel in "$releases"/*; do
    [[ -d "$rel" && ! -L "$rel" ]] || continue
    for dir in "$rel/node_modules" "$rel/.composer" "$rel/.cache" "$rel/tests" "$rel/.github" "$rel/.cursor" "$rel/docs"; do
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
  prune_old_files "${shared}/storage/framework/sessions" "$session_retention_minutes" sessions
  prune_old_files "${shared}/storage/framework/cache/data" 10080 cache
  prune_old_files "${shared}/storage/framework/testing" 0 testing
  prune_old_files "${shared}/storage/pail" 1440 pail
  prune_old_files "${shared}/storage/logs" "$log_retention_minutes" logs
  remove_pre_docroots
  remove_orphan_laravel_backups
  prune_db_dumps
  if [[ -d "${shared}/vendor" || -d "${laravel}/vendor" ]]; then
    echo "vendor_outside_releases=present (not removed, review)"
  else
    echo "vendor_outside_releases=none"
  fi
  report_inodes after
  report_df_inodes || true
  report_home_inodes || true
  report_site_inodes || true
}

# Spec Section 16. Say whether the user crontab runs schedule:run.
# Do not print the command, a path, an assignment, or the crontab text.
# Do not install a crontab. A missing job does not fail the deploy.
cron_check() {
  local text="" line="" found=0 schedule_run_pattern='(^|[^A-Za-z0-9_])schedule:run([^A-Za-z0-9_]|$)'

  if [[ "${RW_DEPLOY_LIBRARY:-}" == "1" && -n "${RW_CRON_CHECK_FILE:-}" ]]; then
    if [[ ! -f "$RW_CRON_CHECK_FILE" || ! -r "$RW_CRON_CHECK_FILE" ]]; then
      echo "cron_check=unavailable"
      return 0
    fi
    text=$(cat -- "$RW_CRON_CHECK_FILE" 2>/dev/null || true)
  else
    if ! command -v crontab >/dev/null 2>&1; then
      echo "cron_check=unavailable"
      return 0
    fi
    if ! text=$(crontab -l 2>&1); then
      case "$text" in
        *"no crontab"*|*"No crontab"*)
          echo "cron_check=empty"
          ;;
        *)
          echo "cron_check=unavailable"
          ;;
      esac
      return 0
    fi
  fi

  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line//$'\r'/}"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    if [[ -z "$line" || "$line" == \#* ]]; then
      continue
    fi
    if [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
      continue
    fi
    if [[ "$line" =~ $schedule_run_pattern ]]; then
      found=1
      break
    fi
  done <<< "$text"

  if [[ "$found" -eq 1 ]]; then
    echo "cron_check=present"
  elif [[ -z "${text//[[:space:]]/}" ]]; then
    echo "cron_check=empty"
  else
    echo "cron_check=missing"
  fi

  return 0
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

if [[ "$ACTION" == "preflight" ]]; then
  inode_guard
  exit $?
fi

if [[ "$ACTION" == "maintenance" ]]; then
  # Report always. Clean only when the workflow input asks for it. This never
  # runs the guard, never creates, prunes or uploads a release, and never
  # touches the database.
  echo "maintenance_inodes_before=$(count_account_inodes)"
  maintenance_report before
  maintenance_breakdown
  if [[ "${RW_MAINTENANCE_CLEAN:-false}" == "true" ]]; then
    maintenance_clean
    echo "maintenance_inodes_after=$(count_account_inodes)"
  else
    echo "maintenance_clean=not requested, report only"
  fi
  if [[ "${RW_MAINTENANCE_PURGE_OPCACHE:-false}" == "true" ]]; then
    purge_opcache || exit 1
  else
    echo "opcache_purge=not requested"
  fi
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
  inode_cleanup || echo "inode_cleanup=failed" >&2
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

# The archive is already uploaded. The guard runs before anything is extracted
# or changed, and the exit trap deletes the archive when it aborts.
if ! inode_guard; then
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

ensure_log_settings || echo "log_env=failed" >&2

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
if ! tar -xzf "$archive" -C "$target"; then
  echo "The release archive could not be extracted. Document root was not changed." >&2
  rm -rf "$target"
  exit 1
fi
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
sync_tenants || post_failed=1
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
cron_check
