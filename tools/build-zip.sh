#!/usr/bin/env bash
# Construit le zip installable (sans les fichiers de développement).
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$HERE/../oli-abandoned-cart-recovery.zip}"
TMP="$(mktemp -d)"
mkdir -p "$TMP/oli-abandoned-cart-recovery"
rsync -a --exclude '.git' --exclude '.gitignore' --exclude '.gitattributes' --exclude '.github' \
  --exclude '.wordpress-org' --exclude '.phpcs.xml.dist' --exclude 'phpcs.xml.dist' --exclude 'phpstan.neon.dist' --exclude 'README.md' --exclude 'tests' \
  --exclude 'tools' --exclude 'languages' --exclude 'composer.json' --exclude 'composer.lock' --exclude 'vendor' --exclude 'node_modules' \
  "$HERE/" "$TMP/oli-abandoned-cart-recovery/"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" oli-abandoned-cart-recovery)
rm -rf "$TMP"
echo "$OUT"
