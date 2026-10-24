#!/usr/bin/env bash
# OpenSSH wrapper for SiteGround. Extra arguments are passed to ssh,
# which is what rsync -e expects. Never prints secrets.
set -euo pipefail

# A drop during connect, before the remote command prints anything, is tried
# again. A command that already started is not run a second time.
ssh_with_retry() {
  local attempt out err status
  attempt=1
  while true; do
    out="$(mktemp)"
    err="$(mktemp)"
    set +e
    "$SG_SSH_BIN" "$@" >"$out" 2>"$err"
    status=$?
    set -e
    if [[ "$status" -eq 255 && "$attempt" -lt 3 && ! -s "$out" ]] && grep -qE 'kex_exchange_identification|Connection reset by peer|Connection closed by' "$err"; then
      cat "$err" >&2
      echo "ssh_connect_retry attempt=${attempt}" >&2
      rm -f "$out" "$err"
      if [[ -n "${RW_SSH_RETRY_SLEEP:-}" ]]; then
        sleep "$RW_SSH_RETRY_SLEEP"
      else
        sleep $((attempt * 3))
      fi
      attempt=$((attempt + 1))
      continue
    fi
    cat "$out"
    cat "$err" >&2
    rm -f "$out" "$err"
    return "$status"
  done
}

if [[ "${RW_SSH_LIBRARY:-}" == "1" ]]; then
  return 0
fi

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/prepare-siteground-ssh.sh"
ssh_with_retry "$@"
