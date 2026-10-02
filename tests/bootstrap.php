<?php
/**
 * PHPUnit bootstrap for ARkid Catalogue Link.
 *
 * Pure PHP-level tests with Brain\Monkey for WP function mocking.
 * (We do not load the full WP test suite — that lives in Playwright e2e.)
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ARKID_CATALOGUE_LINK_VERSION' ) ) {
	define( 'ARKID_CATALOGUE_LINK_VERSION', '0.0.0-test' );
}
if ( ! defined( 'ARKID_CATALOGUE_LINK_FILE' ) ) {
	define( 'ARKID_CATALOGUE_LINK_FILE', dirname( __DIR__ ) . '/arkid-catalogue-link.php' );
}
if ( ! defined( 'ARKID_CATALOGUE_LINK_PATH' ) ) {
	define( 'ARKID_CATALOGUE_LINK_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARKID_CATALOGUE_LINK_URL' ) ) {
	define( 'ARKID_CATALOGUE_LINK_URL', 'https://example.com/wp-content/plugins/arkid-catalogue-link/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
