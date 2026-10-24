#!/usr/bin/env bash
# OpenSSH wrapper for SiteGround. Extra arguments are passed to ssh,
# which is what rsync -e expects. Never prints secrets.
#
# The first call in a job opens one SSH connection and leaves it up
# (ControlMaster / ControlPersist). Later calls, including later workflow
# steps, reuse that connection. This does not retry a dropped handshake:
# another handshake is the traffic SiteGround's connection limit resets.
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/prepare-siteground-ssh.sh"
exec "$SG_SSH_BIN" "$@"
