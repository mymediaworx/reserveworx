#!/usr/bin/env bash
# Activate a release that the workflow already uploaded, or roll back.
# Migrations run only for action=deploy when RUN_MIGRATIONS=true,
# MIGRATION_CONFIRMATION is exactly "I have a backup", and shared .env exists.
# Rollback never runs migrate:rollback.
set -euo pipefail

: "${ACTION:?ACTION is required}"

if [[ "$ACTION" != "deploy" && "$ACTION" != "rollback" ]]; then
  echo "ACTION must be deploy or rollback." >&2
  exit 1
fi

base="${HOME}/reserveworx"
releases="${base}/releases"
shared="${base}/shared"
current="${base}/current"
previous="${base}/previous"

mkdir -p \
  "$releases" \
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
    /usr/local/php85/bin/php
    /usr/local/php84/bin/php
    /usr/local/php83/bin/php
    /usr/bin/php8.5
    /usr/bin/php8.4
    /usr/bin/php8.3
  )
  candidates+=(/usr/local/php*/bin/php)
  if command -v php >/dev/null 2>&1; then
    candidates+=("$(command -v php)")
  fi
  shopt -u nullglob

  for candidate in "${candidates[@]}"; do
    [[ -x "$candidate" ]] || continue
    version="$("$candidate" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
    major="${version%%.*}"
    minor="${version#*.}"
    if [[ "$major" -gt 8 ]] || [[ "$major" -eq 8 && "$minor" -ge 3 ]]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

link_shared() {
  local target="$1"
  if [[ -f "${shared}/.env" ]]; then
    ln -sfn "${shared}/.env" "${target}/.env"
  else
    echo "shared_env=missing"
  fi
  rm -rf "${target}/storage"
  ln -sfn "${shared}/storage" "${target}/storage"
  if [[ -e "${target}/public/storage" || -L "${target}/public/storage" ]]; then
    rm -rf "${target}/public/storage"
  fi
  ln -sfn "${shared}/storage/app/public" "${target}/public/storage"
}

switch_docroot() {
  local public_dir="$1"
  local candidates=(
    "${HOME}/www/app.reserveworx.com/public_html"
    "${HOME}/www/app.reserveworx.com"
    "${HOME}/public_html/app.reserveworx.com"
  )
  local docroot=""
  local candidate
  for candidate in "${candidates[@]}"; do
    if [[ -e "$candidate" || -L "$candidate" ]]; then
      docroot="$candidate"
      break
    fi
  done

  if [[ -z "$docroot" ]]; then
    echo "document_root=not found. Release is prepared and current is updated. Document root was not changed." >&2
    return 1
  fi

  if [[ -L "$docroot" ]]; then
    ln -sfn "$public_dir" "$docroot"
    echo "document_root=updated symlink ${docroot}"
    return 0
  fi

  local allowed=1
  local name
  while IFS= read -r name; do
    [[ -z "$name" ]] && continue
    case "$name" in
      index.html|index.php|cgi-bin|.well-known|default.html) ;;
      *) allowed=0 ;;
    esac
  done < <(find "$docroot" -mindepth 1 -maxdepth 1 -printf '%f\n')

  if [[ "$allowed" -ne 1 ]]; then
    echo "document_root=left unchanged because ${docroot} contains files outside the placeholder list." >&2
    return 1
  fi

  local stamp
  stamp="$(date -u +%Y%m%d%H%M%S)"
  mv "$docroot" "${docroot}.pre-reserveworx-${stamp}"
  ln -sfn "$public_dir" "$docroot"
  echo "document_root=moved placeholder aside and linked ${docroot}"
}

if [[ "$ACTION" == "rollback" ]]; then
  if [[ ! -L "$previous" ]]; then
    echo "No previous release is recorded. Nothing was changed." >&2
    exit 1
  fi
  prev_target="$(readlink -f "$previous")"
  if [[ ! -d "$prev_target" ]]; then
    echo "The previous release directory is missing. Nothing was changed." >&2
    exit 1
  fi
  if [[ -L "$current" ]]; then
    curr_target="$(readlink -f "$current")"
    ln -sfn "$prev_target" "$current"
    ln -sfn "$curr_target" "$previous"
  else
    ln -sfn "$prev_target" "$current"
  fi
  echo "rollback=complete"
  echo "migrate_rollback=not run"
  echo "current=$(readlink -f "$current")"
  exit 0
fi

if [[ ! "${RELEASE:-}" =~ ^[0-9a-f]{40}$ ]]; then
  echo "RELEASE must be a 40-character commit sha." >&2
  exit 1
fi

target="${releases}/${RELEASE}"
if [[ ! -d "$target" || ! -f "${target}/artisan" ]]; then
  echo "The uploaded release is missing artisan. Document root was not changed." >&2
  exit 1
fi

if ! php_bin="$(find_php)"; then
  echo "No PHP 8.3 or newer binary was found. Document root was not changed." >&2
  exit 1
fi

echo "php_binary=${php_bin}"
echo "php_version=$("$php_bin" -r 'echo PHP_VERSION;')"

if [[ "${RUN_MIGRATIONS:-false}" == "true" && "${MIGRATION_CONFIRMATION:-}" != "I have a backup" ]]; then
  echo "Migrations were requested but the confirmation phrase did not match. Nothing was changed." >&2
  exit 1
fi

if [[ ! -f "${shared}/.env" ]]; then
  echo "shared_env=missing"
  echo "document_root=not changed. Create ${shared}/.env from .env.example before the document root is switched." >&2
  exit 1
fi

link_shared "$target"

if [[ -L "$current" ]]; then
  ln -sfn "$(readlink -f "$current")" "$previous"
fi
ln -sfn "$target" "$current"
echo "current=$(readlink "$current")"

migrate="skipped"
if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  (cd "$current" && "$php_bin" artisan migrate --force --no-interaction)
  migrate="ran"
fi
echo "migrations=${migrate}"

(cd "$current" && "$php_bin" artisan config:cache --no-interaction)
(cd "$current" && "$php_bin" artisan route:cache --no-interaction)
(cd "$current" && "$php_bin" artisan view:cache --no-interaction)
echo "caches=written"

switch_docroot "${current}/public"
echo "deploy=complete"
echo "cron_hint=add a cron entry that runs: ${php_bin} ${current}/artisan schedule:run"
echo "cron_command_body=<not installed by this script>"
