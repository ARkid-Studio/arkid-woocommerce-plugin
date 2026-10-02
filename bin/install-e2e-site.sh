#!/usr/bin/env bash
#
# Provision the WordPress install the E2E suite drives, on the local filesystem.
#
# The suite talks to WordPress two ways at once: over HTTP at WP_BASE_URL, and
# through wp-cli against WP_PATH — it seeds products, flips options, reads
# permalinks and drops an mu-plugin in by hand (tests/e2e/global-setup.js).
# The second half only works if wp-cli and the files are on the SAME machine as
# the test runner, which is why this installs WordPress directly rather than
# behind a container boundary. It is the CI counterpart of
# .devcontainer/provision.sh, and deliberately produces the same site: same URL,
# same admin credentials, same permalink structure.
#
# Environment:
#   WP_PATH       where WordPress is installed (required)
#   WP_BASE_URL   the site URL, default http://localhost:8888
#   WP_VERSION    WordPress version to download, default latest
#   WC_VERSION    WooCommerce version or minor, default latest
set -euo pipefail

WP_PATH="${WP_PATH:?WP_PATH must name the directory to install WordPress into}"
WP_BASE_URL="${WP_BASE_URL:-http://localhost:8888}"
WP_VERSION="${WP_VERSION:-latest}"
WC_VERSION="${WC_VERSION:-latest}"

DB_HOST="${WP_E2E_DB_HOST:-127.0.0.1}"
DB_NAME="${WP_E2E_DB_NAME:-wordpress_e2e}"
DB_USER="${WP_E2E_DB_USER:-root}"
DB_PASS="${WP_E2E_DB_PASS:-${MARIADB_ROOT_PASSWORD:-wordpress}}"

ADMIN_USER="${E2E_WP_USER:-admin}"
ADMIN_PASS="${E2E_WP_PASS:-password}"

PLUGIN=arkid-catalogue-link
REPO_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

WP=( wp "--path=${WP_PATH}" )

echo "==> Downloading WordPress ${WP_VERSION} into ${WP_PATH}…"
mkdir -p "${WP_PATH}"
"${WP[@]}" core download --version="${WP_VERSION}" --force

echo "==> Configuring against ${DB_NAME} on ${DB_HOST}…"
"${WP[@]}" config create \
	--dbname="${DB_NAME}" \
	--dbuser="${DB_USER}" \
	--dbpass="${DB_PASS}" \
	--dbhost="${DB_HOST}" \
	--force

# WordPress spawns wp-cron.php as a LOOPBACK request back into the very server
# serving the page. Under `wp server` that competes for a fixed pool of workers
# PHP never respawns, so admin page loads spend workers on requests no test
# asked for and capacity only shrinks: the block editor died with
# net::ERR_EMPTY_RESPONSE, and once that pool was raised to 16 the starvation
# simply moved to the login POST, which hung for the full 30s navigation
# timeout. Both runs ended with 4 of 16 workers alive.
#
# Nothing here needs cron to run. No spec references it, the storefront renders
# from per-product meta, and the plugin schedules through Action Scheduler,
# whose queue the integration suite covers directly.
"${WP[@]}" config set DISABLE_WP_CRON true --raw

# A CI runner is always fresh, so `db create` is the path that runs there.
# `db reset` is for a re-run on a developer's machine: installing on top of an
# existing schema is how you get a half-migrated store that fails mysteriously.
"${WP[@]}" db create 2>/dev/null || "${WP[@]}" db reset --yes

echo "==> Installing WordPress…"
"${WP[@]}" core install \
	--url="${WP_BASE_URL}" \
	--title='ARkid CatalogueLink E2E' \
	--admin_user="${ADMIN_USER}" \
	--admin_password="${ADMIN_PASS}" \
	--admin_email=e2e@example.com \
	--skip-email

# The suite reaches the REST API at /wp-json/, which only resolves once rewrite
# rules exist. Soft flush: there is no Apache here to read an .htaccess, the
# wp-cli router maps every unmatched path to index.php itself.
"${WP[@]}" rewrite structure '/%postname%/'

echo "==> Installing WooCommerce ${WC_VERSION}…"
bash "${REPO_ROOT}/bin/install-woocommerce.sh" "${WC_VERSION}" "${WP_PATH}/wp-content/plugins"
"${WP[@]}" plugin activate woocommerce
# Activation schedules the installer rather than always running it inline;
# calling it directly makes the tables and pages exist before the first request
# instead of during it. Idempotent — this is what WooCommerce runs on upgrade.
"${WP[@]}" eval 'if ( class_exists( "WC_Install" ) ) { WC_Install::install(); }'
# Otherwise the first admin page load redirects to the onboarding wizard, and
# the settings specs land somewhere they did not ask for.
"${WP[@]}" transient delete _wc_activation_redirect || true
# WooCommerce 9+ hides the storefront behind "Coming soon".
"${WP[@]}" option update woocommerce_coming_soon no || true

echo "==> Installing the plugin under test…"
# Copied, not symlinked: PHP's built-in server resolves static assets against the
# document root, and a plugin directory that leaves it by symlink is a needless
# way to lose the block's own CSS. vendor/ and build/ are already built by the
# time this runs, and are copied with it.
rsync -a --delete \
	--exclude='.git/' \
	--exclude='node_modules/' \
	"${REPO_ROOT}/" "${WP_PATH}/wp-content/plugins/${PLUGIN}/"
"${WP[@]}" plugin activate "${PLUGIN}"

# Now that every post type is registered — `product` above all — rebuild the
# rules for real. WooCommerce defers its own flush to the next front-end
# request, which would make the first product permalink the suite visits a 404.
"${WP[@]}" rewrite flush

# Activation succeeding proves nothing: the plugin returns early — leaving every
# hook unregistered — when it cannot autoload or when WooCommerce is absent.
# Assert the main class actually resolved, so a broken build fails here rather
# than as twenty confusing spec failures later. (Same check as provision.sh.)
if "${WP[@]}" eval 'exit( class_exists( "\\Arkid\\CatalogueLink\\Plugin" ) ? 0 : 1 );' >/dev/null 2>&1; then
	echo "==> Plugin booted: Arkid\\CatalogueLink\\Plugin resolved."
else
	echo "==> ERROR: the plugin is active but Arkid\\CatalogueLink\\Plugin did not load." >&2
	echo "    Check that vendor/ and build/ were produced before this ran." >&2
	exit 1
fi

echo "==> Site ready at ${WP_BASE_URL} (${ADMIN_USER}/${ADMIN_PASS})"
