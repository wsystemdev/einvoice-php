#!/usr/bin/env bash
# Copy the shared verdict corpus (wsystemdev/einvoice-validator fixtures/: valid/, invalid/, expected.json, README.md)
# into tests/fixtures/validator/shared/. The same files and expected verdicts are used by the W System validator
# (Docker), so both products report the same results. Usage: tools/validator/sync_shared_fixtures.sh [git-ref]
set -euo pipefail
ref="${1:-main}"
here="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
git clone -q https://github.com/wsystemdev/einvoice-validator.git "$tmp/src"
git -C "$tmp/src" checkout -q "$ref"
dest="$here/tests/fixtures/validator/shared"
rm -rf "$dest"
mkdir -p "$dest"
cp -r "$tmp/src/fixtures/valid" "$tmp/src/fixtures/invalid" "$tmp/src/fixtures/expected.json" "$tmp/src/fixtures/README.md" "$dest/"
printf 'wsystemdev/einvoice-validator %s (%s)\n' "$(git -C "$tmp/src" rev-parse --short HEAD)" "$(git -C "$tmp/src" log -1 --format=%cs)" > "$dest/SOURCE"
echo "synced $(find "$dest" -type f | wc -l) files from $(cat "$dest/SOURCE")"
