<?php
/**
 * Bootstrap for the real-WordPress integration suite.
 *
 * Runs against the container's existing WP + WooCommerce install (so the tests,
 * the dev site and the E2E suite all exercise the same versions) but against a
 * SEPARATE database — the WP test bootstrap drops every table it finds.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

$arkid_db_name = getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test';

// Guard 2 of 3 (see bin/install-wp-tests.sh). Cheap, and it turns a
// catastrophic misconfiguration into a one-line failure.
if ( in_array( $arkid_db_name, array( 'wordpress', '' ), true ) ) {
	fwrite( STDERR, "Refusing to run integration tests against the dev database.\n" );
	exit( 1 );
}

$arkid_root = dirname( __DIR__, 2 );
require_once $arkid_root . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $arkid_root . '/vendor/yoast/phpunit-polyfills' );

// The installer runs in its own process and reads this file; defining the
// constants here instead would be invisible to it.
putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

$arkid_abspath = getenv( 'WP_ABSPATH' ) ?: '/var/www/html/';

$arkid_wp_phpunit = $arkid_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $arkid_wp_phpunit . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $arkid_abspath, $arkid_root ): void {
		require_once $arkid_abspath . 'wp-content/plugins/woocommerce/woocommerce.php';

		// Loaded here rather than via `active_plugins` so the plugin's own
		// plugins_loaded:10 callback still fires normally — which is exactly
		// what the Migrator hook-ordering test needs to observe.
		require_once $arkid_root . '/arkid-catalogue-link.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( '\WC_Install' ) ) {
			\WC_Install::install();
			// Re-init the roles the WooCommerce installer just created.
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
);

require $arkid_wp_phpunit . '/includes/bootstrap.php';
