#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$ROOT/public/wp-content/plugins/active-plugin-locator"

mkdir -p "$DEST"

rsync -av --delete \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.ddev/' \
  --exclude 'public/' \
  --exclude 'vendor/' \
  --exclude 'node_modules/' \
  "$ROOT"/ "$DEST"/
