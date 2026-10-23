#!/usr/bin/env bash
# OpenSSH wrapper for SiteGround. Extra arguments are passed to ssh,
# which is what rsync -e expects. Never prints secrets.
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/prepare-siteground-ssh.sh"

exec "$SG_SSH_BIN" "$@"
