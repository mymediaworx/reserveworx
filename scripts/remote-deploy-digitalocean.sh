#!/usr/bin/env bash
# Activate a release on reserveworx-prod-1 (DigitalOcean), or roll back to the
# one previous release. Runs as the `deploy` user. Never prints .env values.
# Keeps current plus one rollback and never deletes what current points to.
# Rollback never runs migrate:rollback.
set -euo pipefail
umask 002

: "${ACTION:?ACTION is required}"
if [[ "$ACTION" != "deploy" && "$ACTION" != "rollback" ]]; then
  echo "ACTION must be deploy or rollback." >&2
  exit 1
fi

base=/var/www/reserveworx
releases="${base}/releases"
incoming="${base}/incoming"
shared="${base}/shared"
current="${base}/current"
previous="${base}/previous"
php_bin=php8.3

if [[ ! -f "${shared}/.env" ]]; then
  echo "shared/.env is missing. Provision the server first." >&2
  exit 1
fi

point() { # atomically point symlink $2 at directory $1
  ln -sfn "$1" "${2}.tmp"
  mv -T "${2}.tmp" "$2"
}

reload_services() {
  sudo -n /usr/bin/systemctl reload php8.3-fpm
  sudo -n /usr/bin/supervisorctl reread >/dev/null
  sudo -n /usr/bin/supervisorctl update >/dev/null
  sudo -n /usr/bin/supervisorctl restart 'reserveworx-worker:*' >/dev/null || true
}

health() {
  # EnsureKnownHost returns 404 for hosts that are not platform domains, so the
  # probe sends the platform host. Laravel's /up page contains "Application up".
  local code body
  body="$(mktemp)"
  for _ in 1 2 3 4 5; do
    code="$(curl -s -o "$body" -m 10 -w '%{http_code}' -H 'Host: app.reserveworx.com' http://127.0.0.1/up || true)"
    if [[ "$code" == "200" ]] && grep -q "Application up" "$body"; then
      rm -f "$body"; echo "health=ok"; return 0
    fi
    sleep 2
  done
  rm -f "$body"
  echo "health=failed (http ${code})"
  return 1
}

prune() {
  # Keep exactly what current and previous point to. Nothing else survives.
  local keep_cur keep_prev d
  keep_cur="$(readlink -f "$current")"
  keep_prev="$(readlink -f "$previous" 2>/dev/null || true)"
  for d in "${releases}"/*/; do
    d="$(readlink -f "$d")"
    [[ "$d" == "$keep_cur" || "$d" == "$keep_prev" ]] && continue
    case "$d" in "${releases}"/*) rm -rf -- "$d" ;; esac
  done
  echo "releases_kept=$(ls -1 "$releases" | wc -l)"
}

if [[ "$ACTION" == "rollback" ]]; then
  [[ -L "$previous" && -d "$(readlink -f "$previous")" ]] || { echo "No previous release to roll back to." >&2; exit 1; }
  cur_target="$(readlink -f "$current")"
  prev_target="$(readlink -f "$previous")"
  point "$prev_target" "$current"
  point "$cur_target" "$previous"
  reload_services
  echo "rolled_back_to=$(basename "$prev_target")"
  echo "migrate_rollback=not run"
  health || exit 1
  exit 0
fi

: "${RELEASE:?RELEASE is required}"
[[ "$RELEASE" =~ ^[0-9a-f]{40}$ ]] || { echo "RELEASE must be a commit sha." >&2; exit 1; }
archive="${incoming}/${RELEASE}.tar.gz"
target="${releases}/${RELEASE}"
[[ -s "$archive" ]] || { echo "Release archive is missing." >&2; exit 1; }

rm -rf "$target"
mkdir -p "$target"
tar -xzf "$archive" -C "$target"
rm -f "$archive"

# Shared state
rm -rf "${target}/storage"
ln -s "${shared}/storage" "${target}/storage"
ln -s "${shared}/.env" "${target}/.env"
mkdir -p "${target}/bootstrap/cache"
chgrp -R www-data "${target}/bootstrap/cache"
chmod -R g+rwX "${target}/bootstrap/cache"

# APP_KEY is generated once, on the server, and never printed.
if ! grep -qE '^APP_KEY=.+' "${shared}/.env"; then
  (cd "$target" && "$php_bin" artisan key:generate --force --no-interaction >/dev/null)
  echo "app_key=generated"
fi

migrate="skipped"
if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  mkdir -p "${shared}/backups"
  stamp="$(date +%Y%m%d-%H%M%S)"
  dump="${shared}/backups/${stamp}-${RELEASE:0:8}.sql.gz"
  cnf="$(mktemp)"
  trap 'rm -f "$cnf"' EXIT
  chmod 600 "$cnf"
  {
    echo '[client]'
    echo "user=$(grep -E '^DB_USERNAME=' "${shared}/.env" | cut -d= -f2-)"
    echo "password=$(grep -E '^DB_PASSWORD=' "${shared}/.env" | cut -d= -f2-)"
    echo "host=127.0.0.1"
  } > "$cnf"
  db="$(grep -E '^DB_DATABASE=' "${shared}/.env" | cut -d= -f2-)"
  mysqldump --defaults-extra-file="$cnf" --single-transaction --no-tablespaces "$db" | gzip > "$dump"
  if [[ ! -s "$dump" ]] || ! gunzip -t "$dump"; then
    echo "Database dump was not verified. Migrations will not run." >&2
    rm -rf "$target"
    exit 1
  fi
  echo "backup_bytes=$(stat -c %s "$dump")"
  ls -1t "${shared}"/backups/*.sql.gz | tail -n +6 | xargs -r rm -f --
  (cd "$target" && "$php_bin" artisan migrate --force --no-interaction) || { rm -rf "$target"; echo "migrate=failed. Release abandoned." >&2; exit 1; }
  migrate="ran"
fi
echo "migrations=${migrate}"

(cd "$target" && "$php_bin" artisan storage:link --force >/dev/null 2>&1 || true)
(cd "$target" && "$php_bin" artisan optimize --no-interaction >/dev/null)

old_target=""
[[ -L "$current" ]] && old_target="$(readlink -f "$current")"
point "$target" "$current"
if [[ -n "$old_target" && "$old_target" != "$target" ]]; then
  point "$old_target" "$previous"
fi
reload_services

if ! health; then
  if [[ -n "$old_target" ]]; then
    point "$old_target" "$current"
    reload_services
    echo "Health check failed. Code rolled back. migrate:rollback is not run." >&2
  fi
  exit 1
fi

prune
echo "current=${RELEASE:0:8}"
echo "previous=$(basename "$(readlink -f "$previous" 2>/dev/null || echo none)")"
echo "cron_hint=/etc/cron.d/reserveworx-schedule runs schedule:run every minute"
