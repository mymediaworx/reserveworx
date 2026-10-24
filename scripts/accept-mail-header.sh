#!/usr/bin/env bash
# Read one mail header from stdin, then run the rest of stdin as the deploy
# script. The password is never printed. This runs on the same SSH session
# as the release so deploy does not open a second connection.
set -euo pipefail

IFS= read -r mail_header || {
  echo "mail_header=missing" >&2
  exit 1
}

mail_dir="${HOME}/www/app.reserveworx.com/laravel/shared"
mail_file="${mail_dir}/.mail-password"

case "$mail_file" in
  */www/app.reserveworx.com/laravel/shared/.mail-password) ;;
  *)
    echo "mail_env=rejected" >&2
    exit 1
    ;;
esac
case "$mail_file" in
  *public_html*|*www/reserveworx.com/*)
    echo "mail_env=rejected" >&2
    exit 1
    ;;
esac

case "$mail_header" in
  "MAIL -")
    rm -f "$mail_file" "${mail_file}.tmp"
    ;;
  "MAIL skip")
    ;;
  MAIL\ *)
    payload="${mail_header#MAIL }"
    case "$payload" in
      *[!A-Za-z0-9+/=]*)
        echo "mail_env=rejected" >&2
        exit 1
        ;;
    esac
    umask 077
    mkdir -p "$mail_dir"
    printf '%s' "$payload" | base64 -d > "${mail_file}.tmp"
    mv -f "${mail_file}.tmp" "$mail_file"
    chmod 600 "$mail_file"
    ;;
  *)
    echo "mail_header=rejected" >&2
    exit 1
    ;;
esac

exec bash -s
