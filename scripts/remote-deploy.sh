#!/usr/bin/env bash
# Activate a release for app.reserveworx.com only, or roll back to the one
# previous release. Migrations run only for action=deploy when
# RUN_MIGRATIONS=true, MIGRATION_CONFIRMATION is exactly "I have a backup",
# and shared .env exists. Rollback never runs migrate:rollback.
#
# Inode budget: at most the current release tree and one previous release tree.
# The upload archive is deleted after extract. Vendor lives only inside those
# release trees. Composer is not run on the server.
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

switch_docroot() {
  local relative="../laravel/current/public"

  if [[ -L "$docroot" ]]; then
    ln -sfn "$relative" "$docroot"
    echo "document_root=symlink ${docroot} -> ${relative}"
    return 0
  fi

  if [[ -d "$docroot" ]]; then
    local allowed=1 name
    while IFS= read -r name; do
      [[ -z "$name" ]] && continue
      case "$name" in
        index.html|index.php|cgi-bin|.well-known|default.html) ;;
        *) allowed=0 ;;
      esac
    done < <(find "$docroot" -mindepth 1 -maxdepth 1 -printf '%f\n')

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

  ln -sfn "$relative" "$docroot"
  echo "document_root=symlink ${docroot} -> ${relative}"
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
  purge_dynamic_cache
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
purge_dynamic_cache
health_check
echo "deploy=complete"
echo "cron_hint=Site Tools cron, every minute, only this command: ${php_bin} ${current}/artisan schedule:run"
echo "cron_command_body=<not installed by this script>"
