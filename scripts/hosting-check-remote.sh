#!/usr/bin/env bash
# Read-only SiteGround probe for spec Section 2.2.
# Exit 0 when the shell starts. Missing tools are reported, not fatal.
# Do not print environment files, crontab commands, or phpinfo output.
set -u
set +e

section() {
  printf '\n## %s\n' "$1"
}

section "Session"
# An SSH login sets these. The values are not printed.
ssh_state=not_measured
case "${SSH_CONNECTION-}${SSH_CLIENT-}" in
  *[![:space:]]*) ssh_state=session ;;
esac
echo "ssh=${ssh_state}"
# SiteGround does not name StartUp, GrowBig, GoGeek, or Cloud in this session.
echo "plan_name=not_measured"
echo "home=${HOME}"
echo "pwd=$(pwd)"
date -u +"utc=%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || true
uname -srm 2>/dev/null || true

section "Process limits"
ulimit -a 2>/dev/null || echo "ulimit=unavailable"
echo "long_running_processes=not started by this check; assume none until proven otherwise"

section "PHP"
php_bins=""
for candidate in /usr/local/php*/bin/php /usr/bin/php /usr/bin/php8.* /usr/bin/php7.* "$HOME"/bin/php; do
  for path in $candidate; do
    if [[ -x "$path" ]]; then
      php_bins="${php_bins} ${path}"
    fi
  done
done

if command -v php >/dev/null 2>&1; then
  php_bins="${php_bins} $(command -v php)"
fi

seen=" "
if [[ -z "${php_bins// /}" ]]; then
  echo "php=not found"
else
  for bin in $php_bins; do
    case "$seen" in
      *" $bin "*) continue ;;
    esac
    seen="${seen}${bin} "
    echo "binary=${bin}"
    "$bin" -r 'echo "version=".PHP_VERSION, " memory_limit=", ini_get("memory_limit"), " max_execution_time=", ini_get("max_execution_time"), "\n";' 2>/dev/null || echo "probe_failed"
    echo "extensions=$("$bin" -m 2>/dev/null | tr '\n' ' ' | sed 's/  */ /g')"
  done
fi

section "Database"
if command -v mysql >/dev/null 2>&1; then
  echo "mysql_client=$(mysql --version 2>/dev/null | head -n 1)"
  if mysql --batch --skip-column-names -e "SELECT VERSION();" >/tmp/reserveworx-mysql-version 2>/tmp/reserveworx-mysql-err; then
    echo "mysql_version=$(head -n 1 /tmp/reserveworx-mysql-version)"
    echo "engines:"
    mysql --batch -e "SHOW ENGINES;" 2>/dev/null | awk 'NR==1 || /InnoDB|PERFORMANCE_SCHEMA/'
    if mysql --batch -e "CREATE TEMPORARY TABLE reserveworx_lock_probe (id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO reserveworx_lock_probe VALUES (1); START TRANSACTION; SELECT id FROM reserveworx_lock_probe WHERE id = 1 FOR UPDATE; ROLLBACK;" >/dev/null 2>/tmp/reserveworx-mysql-err; then
      echo "for_update=ok"
      echo "innodb_temporary_table=ok"
    else
      echo "for_update=failed"
    fi
  else
    echo "mysql_query=unavailable"
  fi
  rm -f /tmp/reserveworx-mysql-version /tmp/reserveworx-mysql-err
else
  echo "mysql_client=not found"
  echo "mysql_query=unavailable"
fi

if command -v mariadb >/dev/null 2>&1; then
  echo "mariadb_client=$(mariadb --version 2>/dev/null | head -n 1)"
fi

section "Composer and Git"
if command -v composer >/dev/null 2>&1; then
  composer --version 2>/dev/null | head -n 1
else
  echo "composer=not found"
fi

if command -v git >/dev/null 2>&1; then
  git --version 2>/dev/null | head -n 1
else
  echo "git=not found"
fi

section "Cron"
if crontab -l >/tmp/reserveworx-cron 2>/dev/null; then
  echo "crontab=present"
  awk '
    /^[[:space:]]*$/ { next }
    /^[[:space:]]*#/ { print; next }
    { print $1, $2, $3, $4, $5, "<command hidden>" }
  ' /tmp/reserveworx-cron
else
  echo "crontab=unavailable"
fi
rm -f /tmp/reserveworx-cron
echo "minimum_interval=not exposed by this SSH session"

section "Home layout"
echo "directories under www (max depth 3):"
if [[ -d "${HOME}/www" ]]; then
  find "${HOME}/www" -maxdepth 3 -type d 2>/dev/null | head -n 200
else
  echo "missing:${HOME}/www"
fi

report_path() {
  path="$1"
  echo
  echo "path=${path}"
  if [[ ! -e "$path" && ! -L "$path" ]]; then
    echo "state=missing"
    return
  fi
  if [[ -L "$path" ]]; then
    echo "state=symlink"
    echo "target=$(readlink "$path")"
  elif [[ -d "$path" ]]; then
    echo "state=directory"
  else
    echo "state=other"
  fi
  echo "entry_count=$(find "$path" -mindepth 1 -maxdepth 1 2>/dev/null | wc -l | tr -d ' ')"
  echo "subdirectories:"
  find "$path" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | head -n 50
}

report_path "${HOME}/www"
report_path "${HOME}/www/app.reserveworx.com"
report_path "${HOME}/www/app.reserveworx.com/public_html"
report_path "${HOME}/public_html/app.reserveworx.com"
report_path "${HOME}/reserveworx"

section "Outbound HTTPS"
for url in https://api.stripe.com https://services.leadconnectorhq.com; do
  curl -sS -o /dev/null --max-time 20 -w "${url} http_code=%{http_code} ssl_verify_result=%{ssl_verify_result}\n" "$url" || echo "${url} curl_failed"
done

section "Domains and SSL"
# This probe does not query DNS, read a certificate, or print APP_URL.
echo "custom_domains=not_measured"
echo "wildcard_subdomains=not_measured"
echo "wildcard_ssl=not_measured"

section "Inbound webhooks"
echo "waf_and_rate_rules=not observable from SSH"

section "Application URL"
echo "http_202_means=SiteGround bot check, not a healthy application"
code="$(curl -sS -o /dev/null --max-time 20 -w '%{http_code}' -H 'Cache-Control: no-cache' https://app.reserveworx.com/up || true)"
echo "app_reserveworx_up_http=${code:-none}"
if [[ "$code" == "202" ]]; then
  echo "app_reserveworx_up=not proven"
fi

echo
echo "hosting_check=complete"
exit 0
