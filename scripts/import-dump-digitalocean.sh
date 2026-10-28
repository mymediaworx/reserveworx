#!/usr/bin/env bash
# Import a SiteGround database dump and public storage into the DigitalOcean
# rehearsal server. Runs as the deploy user on reserveworx-prod-1 only. Never
# prints .env values or row data: only table names and counts. Transfer files
# are deleted before it exits.
set -euo pipefail
umask 007

base=/var/www/reserveworx
shared="${base}/shared"
envfile="${shared}/.env"
dump="${base}/incoming/import.sql.gz"
storage="${base}/incoming/storage.tgz"
: "${EXPECT_BYTES:?EXPECT_BYTES is required}"

cleanup() {
  rm -f "$dump" "$storage" "${cnf:-}"
}
cnf="$(mktemp)"
trap cleanup EXIT

[[ -f "$envfile" ]] || { echo "shared/.env is missing." >&2; exit 1; }
[[ -s "$dump" ]] || { echo "Dump is missing or empty." >&2; exit 1; }
size="$(stat -c %s "$dump")"
if [[ "$size" != "$EXPECT_BYTES" ]]; then
  echo "Dump size ${size} does not match the expected ${EXPECT_BYTES}. Nothing imported." >&2
  exit 1
fi
gunzip -t "$dump"
echo "dump_bytes=${size}"
echo "gunzip_test=ok"

envval() { grep -E "^$1=" "$envfile" | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
db="$(envval DB_DATABASE)"
chmod 600 "$cnf"
{
  echo '[client]'
  echo "user=$(envval DB_USERNAME)"
  echo "password=$(envval DB_PASSWORD)"
  echo 'host=127.0.0.1'
} > "$cnf"

# Nothing may run jobs against the imported data while it loads.
sudo -n /usr/bin/supervisorctl stop 'reserveworx-worker:*' >/dev/null 2>&1 || true

mkdir -p "${shared}/backups"
pre="${shared}/backups/pre-import-$(date +%Y%m%d-%H%M%S).sql.gz"
mysqldump --defaults-extra-file="$cnf" --single-transaction --no-tablespaces "$db" | gzip > "$pre"
echo "pre_import_backup=written"
ls -1t "${shared}"/backups/*.sql.gz | tail -n +6 | xargs -r rm -f --

# Empty the rehearsal database, then load the dump.
{
  echo 'SET FOREIGN_KEY_CHECKS=0;'
  mysql --defaults-extra-file="$cnf" -N -B -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema='${db}' AND table_type='BASE TABLE'"
  echo 'SET FOREIGN_KEY_CHECKS=1;'
} | mysql --defaults-extra-file="$cnf" "$db"
gunzip -c "$dump" | mysql --defaults-extra-file="$cnf" "$db"
echo "import=ok"

# The imported queue belongs to production. Drop it so the rehearsal never runs it.
for t in jobs failed_jobs job_batches; do
  mysql --defaults-extra-file="$cnf" "$db" -e "TRUNCATE TABLE \`${t}\`" 2>/dev/null || true
done
echo "queue_tables=truncated"

if [[ -s "$storage" ]]; then
  mkdir -p "${shared}/storage/app/public"
  find "${shared}/storage/app/public" -mindepth 1 -delete
  tar -xzf "$storage" -C "${shared}/storage/app"
  chgrp -R www-data "${shared}/storage/app/public"
  chmod -R g+rwX "${shared}/storage/app/public"
  echo "storage_files=$(find "${shared}/storage/app/public" -type f | wc -l)"
else
  echo "storage_files=0 (no public storage exported)"
fi

if [[ -d "${base}/current" ]]; then
  (cd "${base}/current" && php8.3 artisan migrate --force --no-interaction | tail -n 3)
  (cd "${base}/current" && php8.3 artisan config:cache >/dev/null && php8.3 artisan cache:clear >/dev/null 2>&1 || true)
fi

sudo -n /usr/bin/supervisorctl start 'reserveworx-worker:*' >/dev/null 2>&1 || true

echo
echo "row_counts:"
mysql --defaults-extra-file="$cnf" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${db}' AND table_type='BASE TABLE' ORDER BY table_name" | while read -r t; do
  n="$(mysql --defaults-extra-file="$cnf" -N -B "$db" -e "SELECT COUNT(*) FROM \`${t}\`")"
  printf '  %s %s\n' "$t" "$n"
done

echo
echo "APP_URL=$(envval APP_URL)"
echo "MAIL_MAILER=$(envval MAIL_MAILER)"
for k in STRIPE_KEY STRIPE_SECRET STRIPE_WEBHOOK_SECRET; do
  if [[ -n "$(envval "$k")" ]]; then echo "${k}=set"; else echo "${k}=not set"; fi
done
if [[ -e /etc/cron.d/reserveworx-schedule ]]; then echo "scheduler=ON"; else echo "scheduler=off"; fi
