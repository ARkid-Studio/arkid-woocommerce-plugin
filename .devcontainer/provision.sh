#!/usr/bin/env bash
# First-run WordPress installer for the devcontainer. Idempotent: if WP is already
# installed it only re-asserts plugin state and exits. Runs via postCreateCommand
# inside the `wordpress` service (as www-data); calls wp-cli DIRECTLY against the
# local install + db — no compose/socket needed (that's why this is a sibling, not
# a nested, setup). Config constants come from WORDPRESS_CONFIG_EXTRA (compose), so
# this script never edits wp-config.php.
set -euo pipefail

WP="wp --path=/var/www/html"
PLUGIN=arkid-catalogue-link
PLUGIN_DIR="/var/www/html/wp-content/plugins/${PLUGIN}"
MU_DIR="/var/www/html/wp-content/mu-plugins"

# --- Composer autoloader reachability -----------------------------------------
# The repo is bind-mounted at TWO paths (the workspace and the plugins dir) and the
# `vendor` named volume must be mounted at both (compose.yaml). If the container
# predates that fix, vendor/ is missing at the plugins path, the plugin loads with
# no autoloader and silently degrades to its "missing Composer autoloader" notice —
# every hook unregistered, every test red, with no obvious cause.
#
# Detect it, say so loudly, and drop a mu-plugin that loads the workspace
# autoloader so an un-rebuilt container still works. Rebuilding (`npm run dev:reset
# && npm run dev:up`) removes the need for the shim; this function then cleans it up.
ensure_autoloader() {
	if [ -r "${PLUGIN_DIR}/vendor/autoload.php" ]; then
		rm -f "${MU_DIR}/zz-arkid-autoload-fallback.php"
		return 0
	fi

	echo "==> WARNING: ${PLUGIN_DIR}/vendor/autoload.php is missing."
	echo "    The 'vendor' volume is not mounted at the plugins path. Rebuild the"
	echo "    stack from the HOST to fix it properly: npm run dev:reset && npm run dev:up"
	echo "    Installing a temporary mu-plugin autoloader shim so this container works."

	mkdir -p "${MU_DIR}"
	cat > "${MU_DIR}/zz-arkid-autoload-fallback.php" <<'PHP'
<?php
/**
 * Plugin Name: ARkid autoloader fallback (dev only)
 *
 * Written by .devcontainer/provision.sh when the `vendor` named volume is not
 * mounted at the plugins path. Loads the workspace autoloader so the plugin can
 * boot. Rebuild the stack and provision.sh deletes this file automatically.
 */
$arkid_fallback_autoload = '/workspaces/arkid-woocommerce-plugin/vendor/autoload.php';
if ( is_readable( $arkid_fallback_autoload ) ) {
	require_once $arkid_fallback_autoload;
}
PHP
}

# Activation succeeding proves nothing: the plugin returns early (leaving every hook
# unregistered) when it can't autoload or when WooCommerce is absent. Assert the
# main class actually resolved, so a broken environment fails here rather than as a
# confusing test failure later.
verify_plugin_booted() {
	if $WP eval 'exit( class_exists( "\\Arkid\\CatalogueLink\\Plugin" ) ? 0 : 1 );' >/dev/null 2>&1; then
		echo "==> Plugin booted: Arkid\\CatalogueLink\\Plugin resolved."
	else
		echo "==> ERROR: the plugin is active but Arkid\\CatalogueLink\\Plugin did not load."
		echo "    Check that vendor/ exists at ${PLUGIN_DIR} and that WooCommerce is active."
		return 1
	fi
}

# The official image entrypoint writes wp-config.php and copies core on first boot;
# `depends_on` only waits for container start, not DB readiness. Poll for both.
echo "==> Waiting for wp-config.php + database…"
for _ in $(seq 1 60); do
	if [ -f /var/www/html/wp-config.php ] && $WP db query 'SELECT 1;' >/dev/null 2>&1; then
		break
	fi
	sleep 2
done

ensure_autoloader

# --- Idempotency guard ------------------------------------------------------
if $WP core is-installed 2>/dev/null; then
	echo "==> WordPress already installed — ensuring WooCommerce + plugin active."
	$WP plugin is-installed woocommerce >/dev/null 2>&1 || $WP plugin install woocommerce
	$WP plugin activate woocommerce || true
	$WP plugin activate "$PLUGIN" || true
	verify_plugin_booted
	exit 0
fi

# --- Fresh install ----------------------------------------------------------
echo "==> Installing WordPress (latest)…"
$WP core install \
	--url=http://localhost:8888 \
	--title="ARkid CatalogueLink Dev" \
	--admin_user=admin \
	--admin_password=password \
	--admin_email=dev@example.com \
	--skip-email

echo "==> Installing + activating WooCommerce…"
$WP plugin install woocommerce --activate

echo "==> Activating the ARkid plugin (bind-mounted at plugins/$PLUGIN)…"
$WP plugin activate "$PLUGIN"

echo "==> Permalinks + dev conveniences…"
$WP rewrite structure '/%postname%/' --hard
# WooCommerce 9+ hides the storefront behind "Coming soon" — disable for dev.
$WP option update woocommerce_coming_soon no || true

verify_plugin_booted

echo "==> Done. Storefront http://localhost:8888/  Admin http://localhost:8888/wp-admin (admin/password)"
