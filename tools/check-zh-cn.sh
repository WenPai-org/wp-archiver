#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for tool in wp msgfmt msgcmp; do
  command -v "$tool" >/dev/null || { echo "BLOCKED: missing $tool" >&2; exit 2; }
done
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
wp i18n make-pot . "$work/archiver.pot" \
  --domain=archiver \
  --exclude=.git,node_modules,vendor,lib/plugin-update-checker \
  --skip-js >/dev/null
msgfmt --check --check-format --output-file=/dev/null languages/archiver-zh_CN.po
msgcmp --use-fuzzy languages/archiver-zh_CN.po "$work/archiver.pot"
wp i18n make-pot . "$work/updatepulse-updater.pot" \
  --domain=updatepulse-updater \
  --exclude=.git,node_modules,vendor,lib/plugin-update-checker \
  --skip-js >/dev/null
msgfmt --check --check-format --output-file=/dev/null lib/updatepulse-updater/languages/updatepulse-updater-zh_CN.po
msgcmp --use-fuzzy lib/updatepulse-updater/languages/updatepulse-updater-zh_CN.po "$work/updatepulse-updater.pot"
printf 'PASS: wp-archiver zh_CN catalog covers the current archiver source catalog\n'
