#!/usr/bin/env bash
# Activate a release for app.reserveworx.com only, or roll back to the one
# previous release. Migrations run only for action=deploy when
# RUN_MIGRATIONS=true, MIGRATION_CONFIRMATION is exactly "I have a backup",
# shared .env exists, and mysqldump has written a private gzip under
# laravel/backups. The dump runs before the previous release is deleted and
# before the document root changes. Rollback never runs migrate:rollback.
#
# Inode budget: at most the current release tree and one previous release tree.
# The upload archive is deleted after extract. Vendor lives only inside those
# release trees. Composer is not run on the server. Database dumps are at most
# two gzip files under laravel/backups, outside public_html.
set -euo pipefail

: "${ACTION:?ACTION is required}"

if [[ "$ACTION" != "deploy" && "$ACTION" != "rollback" ]]; then
  echo "ACTION must be deploy or rollback." >&2
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

health_check() {
  local body code
  body="$(mktemp)"
  code="$(curl -sS -o "$body" --max-time 20 -w '%{http_code}' \
    -H "Cache-Control: no-cache" \
    -H "Cookie: wpSGCacheBypass=1" \
    "https://app.reserveworx.com/up" || true)"
  echo "health_http=${code:-none}"
  if [[ "$code" == "202" ]]; then
    echo "health=not proven. HTTP 202 is the SiteGround bot check, not a healthy application." >&2
    rm -f "$body"
    return 1
  fi
  if [[ "$code" == "200" ]] && grep -q "Application up" "$body"; then
    echo "health=ok"
    rm -f "$body"
    return 0
  fi
  echo "health=failed. A 200 that contains Application up is required. HTTPS must be enforced for app.reserveworx.com in Site Tools." >&2
  rm -f "$body"
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

# Read DB_* from shared .env inside PHP so the password never enters this
# script's arguments or its log. The client defaults file is mode 600 and is
# removed before the function returns. The gzip stays under laravel/backups,
# which is not public_html and is not another site.
backup_database() {
  local backups outfile cnf namefile dump_bin size marker db_name
  local -a dump_args=()
  backups="${laravel}/backups"
  mkdir -p "$backups"
  chmod 700 "$backups"
  outfile="${backups}/reserveworx-$(date -u +%Y%m%dT%H%M%SZ)-$$.sql.gz"
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

  case "$outfile" in
    */www/app.reserveworx.com/laravel/backups/reserveworx-*.sql.gz) ;;
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

  if ! gzip -t "$outfile"; then
    rm -f "$outfile"
    echo "backup=failed archive" >&2
    return 1
  fi

  size="$(wc -c < "$outfile" | tr -d ' ')"
  if [[ ! "$size" =~ ^[0-9]+$ ]] || [[ "$size" -lt 100 ]]; then
    rm -f "$outfile"
    echo "backup=failed archive" >&2
    return 1
  fi

  marker="$(gzip -dc "$outfile" | head -n 40 || true)"
  if ! grep -q -E 'MySQL dump|MariaDB dump|CREATE TABLE|Dump completed' <<<"$marker"; then
    rm -f "$outfile"
    echo "backup=failed unrecognized" >&2
    unset marker db_name
    return 1
  fi
  unset marker db_name

  chmod 600 "$outfile"
  rm_backup_secrets
  echo "backup_path=${outfile}"
  echo "backup_bytes=${size}"
  echo "dump_client=${dump_bin}"

  # This host has no /dev/fd, so the file list cannot use process substitution.
  local count=0 file list
  list="$(mktemp)"
  find "$backups" -maxdepth 1 -type f -name 'reserveworx-*.sql.gz' -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2- > "$list"
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

if [[ "${RW_DEPLOY_LIBRARY:-}" == "1" ]]; then
  return 0
fi

echo "https_enforcement=required for app.reserveworx.com only, in Site Tools, Security, HTTPS Enforce"

if [[ "$ACTION" == "rollback" ]]; then
  if [[ ! -L "$previous" ]]; then
    echo "No previous release is recorded. Nothing was changed." >&2
    exit 1
  fi
  prev_target="$(release_path "$previous")"
  if [[ ! -d "$prev_target" ]]; then
    echo "The previous release directory is missing. Nothing was changed." >&2
    exit 1
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
  echo "current=$(readlink -f "$current")"
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

if [[ "${RUN_MIGRATIONS:-false}" == "true" && "${MIGRATION_CONFIRMATION:-}" != "I have a backup" ]]; then
  echo "Migrations were requested but the confirmation phrase did not match. Nothing was changed." >&2
  rm -f "$archive"
  exit 1
fi

if [[ ! -f "${shared}/.env" ]]; then
  echo "shared_env=missing"
  echo "document_root=not changed. Create ${shared}/.env from .env.example before the document root is switched." >&2
  rm -f "$archive"
  exit 1
fi

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  if ! backup_database; then
    rm -f "$archive"
    echo "Migrations were not run. Document root was not changed." >&2
    exit 1
  fi
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

if [[ -L "$current" ]]; then
  ln -sfn "$(release_path "$current")" "$previous"
fi
ln -sfn "$target" "$current"
echo "current=$(readlink "$current")"
prune_releases

migrate="skipped"
if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  (cd "$current" && "$php_bin" artisan migrate --force --no-interaction)
  migrate="ran"
fi
echo "migrations=${migrate}"
echo "composer_on_server=not run"

(cd "$current" && "$php_bin" artisan config:cache --no-interaction)
(cd "$current" && "$php_bin" artisan route:cache --no-interaction)
(cd "$current" && "$php_bin" artisan view:cache --no-interaction)
echo "caches=written"

switch_docroot
purge_dynamic_cache || echo "dynamic_cache=flush app.reserveworx.com manually in Site Tools"
health_check
echo "deploy=complete"
echo "cron_hint=Site Tools cron, every minute, only this command: ${php_bin} ${current}/artisan schedule:run"
echo "cron_command_body=<not installed by this script>"
