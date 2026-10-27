#!/usr/bin/env bash
# Fail when the Unreleased section of docs/CHANGELOG.md differs.
# Usage: check-changelog-unreleased.sh BASE_CHANGELOG HEAD_CHANGELOG
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: check-changelog-unreleased.sh BASE_CHANGELOG HEAD_CHANGELOG" >&2
  exit 2
fi

base_file="$1"
head_file="$2"

if [[ ! -f "$base_file" || ! -f "$head_file" ]]; then
  echo "Both changelog files must exist." >&2
  exit 2
fi

heading='^## Unreleased[[:space:]]*$'

extract_unreleased() {
  awk '
    /^## Unreleased[[:space:]]*$/ { capture = 1; next }
    /^## / { if (capture) exit }
    capture { lines[++n] = $0 }
    END {
      while (n > 0 && lines[n] ~ /^[[:space:]]*$/) n--
      for (i = 1; i <= n; i++) print lines[i]
    }
  ' "$1"
}

if ! grep -Eq "$heading" "$base_file"; then
  echo "The base changelog is missing the ## Unreleased heading." >&2
  exit 1
fi

if ! grep -Eq "$heading" "$head_file"; then
  echo "docs/CHANGELOG.md is missing the ## Unreleased heading." >&2
  echo "Add docs/changelog.d/<branch-or-slug>.md instead of editing the Unreleased section." >&2
  exit 1
fi

base_body="$(mktemp)"
head_body="$(mktemp)"
trap 'rm -f "$base_body" "$head_body"' EXIT

extract_unreleased "$base_file" > "$base_body"
extract_unreleased "$head_file" > "$head_body"

if cmp -s "$base_body" "$head_body"; then
  exit 0
fi

echo "The Unreleased section of docs/CHANGELOG.md changed." >&2
echo "Add docs/changelog.d/<branch-or-slug>.md instead of editing that section." >&2
diff -u "$base_body" "$head_body" >&2 || true
exit 1
