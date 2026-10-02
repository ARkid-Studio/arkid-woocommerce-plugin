#!/usr/bin/env bash
#
# Put a WooCommerce release on disk, without a running WordPress.
#
# `wp plugin install` cannot do this job: it boots WordPress before it does
# anything, so it needs a wp-config.php and a reachable database — neither of
# which exists yet while the environment is still being provisioned. Pointing it
# at a freshly downloaded core fails with:
#
#     Error: 'wp-config.php' not found.
#
# Unpacking the zip ourselves sidesteps the bootstrap entirely, which is the
# right shape anyway: this is a file-copy step, not a WordPress operation.
#
# The CI matrix names MINORS ("11.0"), because that is the unit the Woo
# Marketplace's "latest two majors" policy is written in, but WordPress.org only
# ever publishes PATCH releases ("11.0.2"), so a minor has to be resolved to its
# newest patch before anything can be downloaded.
#
# Usage: install-woocommerce.sh <version|latest> <plugins-dir>
set -euo pipefail

VERSION_SPEC="${1:-latest}"
PLUGINS_DIR="${2:?usage: install-woocommerce.sh <version|latest> <plugins-dir>}"

INFO_URL='https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce&request[fields][versions]=1'

fetch() {
	# --globoff: the info endpoint's query string contains request[slug] and
	# request[fields][versions], and curl reads an unescaped [...] as a glob
	# range — it rejects the URL before it ever opens a connection:
	#   curl: (3) bad range in URL position 79
	curl --globoff --fail --silent --show-error --location --retry 3 --retry-delay 2 "$@"
}

resolve_version() {
	local info match

	# Checked explicitly rather than left to `set -e`: bash does not abort on a
	# failing command substitution assigned from a shell FUNCTION, so a dead
	# endpoint would otherwise surface as the misleading "no release matches".
	if ! info=$(fetch "${INFO_URL}") || [ -z "${info}" ]; then
		echo "Could not read the plugin index at ${INFO_URL}." >&2
		exit 1
	fi

	if [ "${VERSION_SPEC}" = 'latest' ]; then
		printf '%s' "${info}" | jq -er '.version'
		return
	fi

	# An exact hit wins ("11.0.2"); otherwise take the newest patch under the
	# requested minor. `sort -V` is what orders 11.0.10 after 11.0.9 — a lexical
	# sort puts it before, and would silently pin an older release.
	match=$(
		printf '%s' "${info}" |
			jq -r --arg v "${VERSION_SPEC}" \
				'.versions | keys_unsorted[] | select( . == $v or startswith( $v + "." ) )' |
			sort -V | tail -n1
	)

	if [ -z "${match}" ]; then
		echo "No WordPress.org release of WooCommerce matches '${VERSION_SPEC}'." >&2
		exit 1
	fi

	printf '%s' "${match}"
}

version=$(resolve_version)
echo "==> WooCommerce ${VERSION_SPEC} resolves to ${version}"

workdir=$(mktemp -d)
trap 'rm -rf "${workdir}"' EXIT

fetch -o "${workdir}/woocommerce.zip" \
	"https://downloads.wordpress.org/plugin/woocommerce.${version}.zip"

mkdir -p "${PLUGINS_DIR}"
# A stale copy would win over the one we just downloaded, because unzip skips
# nothing but also removes nothing.
rm -rf "${PLUGINS_DIR:?}/woocommerce"
unzip -q "${workdir}/woocommerce.zip" -d "${PLUGINS_DIR}"

echo "==> Unpacked WooCommerce ${version} into ${PLUGINS_DIR}/woocommerce"
