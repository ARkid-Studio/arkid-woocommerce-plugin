#!/usr/bin/env bash
#
# Build a release zip of the plugin.
#
# Includes only the files needed at runtime on the merchant's WP install:
#   - the main plugin file + uninstall.php
#   - includes/ (PSR-4 PHP)
#   - build/   (compiled JS / CSS / asset manifests)
#   - vendor/  (production Composer deps only — no dev tools)
#   - composer.json (Plugin Check expects it beside a shipped vendor/)
#   - languages/ (.pot + .po, plus the .mo/.json compiled below)
#   - assets/, readme.txt (WP plugin manifest), license.txt
#
# Excludes everything else (src, tests, node_modules, dotfiles, dev configs,
# README.md, package.json, composer.lock, phpunit/phpcs/phpstan configs, …).
#
# Behaviour:
#   1. Snapshot the current Composer state (so we can restore it).
#   2. Re-install Composer with --no-dev --optimize-autoloader.
#   3. Build a fresh `npm run build` so build/ is current.
#   4. Stage only the whitelisted files into a temp dir and zip them.
#   5. Restore the dev Composer install (always — even on failure).

set -euo pipefail

NAME="arkid-catalogue-link"
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
ZIP_PATH="${ROOT}/${NAME}.zip"

cd "${ROOT}"

# Fail before doing any work rather than after staging everything.
for tool in npm composer wp zip; do
	command -v "$tool" >/dev/null 2>&1 || {
		echo "[plugin-zip] Required tool '$tool' is not installed." >&2
		exit 1
	}
done

# Always restore dev dependencies on exit (success OR failure).
restore_dev_deps() {
	echo "[plugin-zip] Restoring dev Composer dependencies…"
	composer install --quiet >/dev/null 2>&1 || true
}
trap restore_dev_deps EXIT

echo "[plugin-zip] Compiling production assets…"
npm run build --silent

echo "[plugin-zip] Compiling translations (.mo + .json)…"
# Generated artifacts are gitignored and rebuilt fresh on every release so
# they always match the committed .po sources and the current build/ output
# (the JSON filename hash is md5(scriptHandleSrc), so it must point at build/).
# Run inside the devcontainer (wp-cli is on PATH there); make-mo/make-json need no
# WordPress install. The script cd'd to ROOT, so `languages` is the plugin's dir.
wp i18n make-mo languages >/dev/null
wp i18n make-json languages --no-purge --pretty-print >/dev/null

echo "[plugin-zip] Re-installing Composer with --no-dev…"
composer install --no-dev --optimize-autoloader --quiet --no-interaction

# Stage into a temp dir so the zip has a clean ${NAME}/ root folder.
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGE_DIR}"; restore_dev_deps' EXIT
PAYLOAD="${STAGE_DIR}/${NAME}"
mkdir -p "${PAYLOAD}"

echo "[plugin-zip] Staging release payload…"
cp -p "${NAME}.php" "${PAYLOAD}/"
cp -p uninstall.php "${PAYLOAD}/"
cp -p readme.txt    "${PAYLOAD}/"
cp -p license.txt   "${PAYLOAD}/"
cp -p composer.json "${PAYLOAD}/"
cp -rp assets/      "${PAYLOAD}/assets"
cp -rp build/       "${PAYLOAD}/build"
cp -rp includes/    "${PAYLOAD}/includes"
cp -rp languages/   "${PAYLOAD}/languages"
cp -rp vendor/      "${PAYLOAD}/vendor"

# Belt-and-braces cleanup of files that should never end up in a release.
find "${PAYLOAD}" -name '.DS_Store' -delete
find "${PAYLOAD}" -name '.git' -type d -prune -exec rm -rf {} + 2>/dev/null || true
find "${PAYLOAD}" -name '.gitkeep' -delete
find "${PAYLOAD}" -name '.gitignore' -delete
find "${PAYLOAD}" -name '.gitattributes' -delete
find "${PAYLOAD}" -name 'phpunit.xml*' -delete
find "${PAYLOAD}" -path '*/tests' -type d -prune -exec rm -rf {} + 2>/dev/null || true

rm -f "${ZIP_PATH}"
echo "[plugin-zip] Creating ${ZIP_PATH}…"
( cd "${STAGE_DIR}" && zip -qr "${ZIP_PATH}" "${NAME}" )

echo
echo "[plugin-zip] Done. $(du -h "${ZIP_PATH}" | cut -f1) — $(unzip -Z -1 "${ZIP_PATH}" | wc -l) files"
