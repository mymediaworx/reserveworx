#!/usr/bin/env bash
# Prepare an SSH key for SiteGround. Source this file; do not execute it.
# Reads SG_HOST, SG_USER, SG_PORT, SG_SSH_KEY, and optional SG_SSH_PASSPHRASE.
# Never prints the key, the passphrase, or the host key material.

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo "Source scripts/prepare-siteground-ssh.sh; do not execute it." >&2
  exit 1
fi

: "${SG_HOST:?SG_HOST is required}"
: "${SG_USER:?SG_USER is required}"
: "${SG_PORT:?SG_PORT is required}"
: "${SG_SSH_KEY:?SG_SSH_KEY is required}"

if [[ ! "$SG_PORT" =~ ^[0-9]+$ ]] || [[ "$SG_PORT" -lt 1 ]] || [[ "$SG_PORT" -gt 65535 ]]; then
  echo "SG_PORT must be a TCP port." >&2
  return 1
fi

if [[ ! "$SG_HOST" =~ ^[A-Za-z0-9.:-]+$ ]]; then
  echo "SG_HOST is not a hostname." >&2
  return 1
fi

if [[ ! "$SG_USER" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "SG_USER is not a username." >&2
  return 1
fi

if [[ -n "${SG_SSH_READY:-}" && -n "${SG_SSH_BIN:-}" && -x "$SG_SSH_BIN" ]]; then
  return 0
fi

umask 077
ssh_dir="${HOME}/.ssh"
mkdir -p "$ssh_dir"

key_file="${ssh_dir}/sg_key"
known_hosts="${ssh_dir}/sg_known_hosts"
askpass="${ssh_dir}/sg_askpass.sh"
wrapper="${ssh_dir}/sg-ssh"

# GitHub secret values sometimes store newlines as the two characters \n.
printf '%s\n' "$SG_SSH_KEY" | sed 's/\\n/\n/g' > "$key_file"
chmod 600 "$key_file"

encrypted=0
if ! ssh-keygen -y -f "$key_file" -P "" >/dev/null 2>&1; then
  encrypted=1
fi

identity_opt=""
if [[ "$encrypted" -eq 1 ]]; then
  if [[ -z "${SG_SSH_PASSPHRASE:-}" ]]; then
    echo "The SiteGround SSH key is encrypted. Set the SG_SSH_PASSPHRASE secret. The key was not printed." >&2
    rm -f "$key_file"
    return 1
  fi

  cat > "$askpass" << 'EOF'
#!/bin/sh
printf '%s\n' "$SG_SSH_PASSPHRASE"
EOF
  chmod 700 "$askpass"

  eval "$(ssh-agent -s)" >/dev/null
  export SSH_ASKPASS="$askpass"
  export SSH_ASKPASS_REQUIRE=force
  export DISPLAY="${DISPLAY:-:0}"
  if ! setsid ssh-add "$key_file" </dev/null >/dev/null 2>&1; then
    echo "Could not unlock the SiteGround SSH key. The key and passphrase were not printed." >&2
    rm -f "$key_file" "$askpass"
    return 1
  fi
  rm -f "$askpass"
  unset SSH_ASKPASS SSH_ASKPASS_REQUIRE
  # IdentitiesOnly ignores keys that are only loaded in the agent. Name the
  # public key so ssh offers this identity and the agent supplies the private half.
  pub_file="${ssh_dir}/sg_key.pub"
  if ! ssh-keygen -y -f "$key_file" -P "$SG_SSH_PASSPHRASE" > "$pub_file" 2>/dev/null; then
    echo "Could not read the SiteGround public key. The key and passphrase were not printed." >&2
    rm -f "$key_file" "$pub_file"
    return 1
  fi
  chmod 600 "$pub_file"
  identity_opt="-i ${pub_file}"
else
  identity_opt="-i ${key_file}"
fi

unset SG_SSH_KEY SG_SSH_PASSPHRASE

# Do not open a second connection just to read the host key. SiteGround counts
# that probe toward the same limit that resets a later handshake. accept-new
# stores the host key on the one real connection.
if [[ ! -s "$known_hosts" ]]; then
  : > "$known_hosts"
fi
chmod 600 "$known_hosts"

# One socket for the whole job. Later ssh processes attach to it and do not
# repeat key exchange. The path stays short so it fits the unix socket limit.
control_path="${ssh_dir}/sg-cm.sock"
handshake_log="${ssh_dir}/sg-handshakes.log"

# identity_opt is -i and a path under ~/.ssh. It is expanded into the wrapper
# once, as two words, with no remote command mixed in.
cat > "$wrapper" << EOF
#!/bin/sh
# Reuse one SiteGround TCP connection. A new handshake is what the host
# rate-limits; this script does not open a second one to retry the first.
set -eu
control="\${SG_CONTROL_PATH}"
log="\${SG_HANDSHAKE_LOG}"
port="\${SG_PORT}"
known="\${SG_KNOWN_HOSTS}"

if [ "\${1:-}" = "-O" ]; then
  if [ ! -S "\${control}" ]; then
    exit 0
  fi
  # Control the existing master only. ControlMaster is intentionally absent
  # so a missing socket cannot turn -O exit into a new handshake.
  export RUNNER_TRACKING_ID=""
  exec ssh -p "\${port}" -o BatchMode=yes -o ControlPath="\${control}" "\$@"
fi

if [ -S "\${control}" ]; then
  if ! ssh -p "\${port}" -o BatchMode=yes -o ControlPath="\${control}" -O check -- "\$1" >/dev/null 2>&1; then
    rm -f "\${control}"
  fi
fi

fresh=0
if [ -S "\${control}" ]; then
  echo "ssh_handshake=0" >&2
  printf '%s\n' "ssh_handshake=0" >> "\${log}"
else
  fresh=1
  echo "ssh_handshake=1" >&2
  printf '%s\n' "ssh_handshake=1" >> "\${log}"
fi

# The persisted master inherits this. An empty id is what keeps Actions from
# killing that master when the step that opened it has finished.
export RUNNER_TRACKING_ID=""
set +e
ssh \\
  -p "\${port}" \\
  -o IdentitiesOnly=yes \\
  -o StrictHostKeyChecking=accept-new \\
  -o UserKnownHostsFile="\${known}" \\
  -o ConnectTimeout=20 \\
  -o BatchMode=yes \\
  -o ControlMaster=auto \\
  -o ControlPersist=600 \\
  -o ControlPath="\${control}" \\
  ${identity_opt} \\
  "\$@"
status=\$?
set -e

if [ "\${fresh}" -eq 1 ] && [ -s "\${known}" ]; then
  fingerprint="\$(ssh-keygen -lf "\${known}" 2>/dev/null | awk 'NR==1 { print \$2 }' || true)"
  if [ -n "\${fingerprint}" ]; then
    echo "Host key fingerprint: \${fingerprint}" >&2
  fi
fi
exit "\${status}"
EOF
chmod 700 "$wrapper"

export SG_PORT
export SG_KNOWN_HOSTS="$known_hosts"
export SG_CONTROL_PATH="$control_path"
export SG_HANDSHAKE_LOG="$handshake_log"
export SG_SSH_BIN="$wrapper"
export SG_SSH_READY=1
