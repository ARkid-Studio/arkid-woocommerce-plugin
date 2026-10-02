<?php
/**
 * Config for the WordPress test suite.
 *
 * A real file rather than constants defined in bootstrap.php, because
 * wp-phpunit shells out to `php install.php` in a SEPARATE process which reads
 * this file — in-process constants are invisible to it.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

$arkid_db_name = getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test';

// See bin/install-wp-tests.sh: the test suite drops every table in this database.
if ( in_array( $arkid_db_name, array( 'wordpress', '' ), true ) ) {
	fwrite( STDERR, "Refusing to run integration tests against the dev database.\n" );
	exit( 1 );
}

define( 'DB_NAME', $arkid_db_name );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'wptest' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) ?: 'wptest' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'dev@example.com' );
define( 'WP_TESTS_TITLE', 'ARkid Integration' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_TESTS_MULTISITE', (bool) getenv( 'WP_TESTS_MULTISITE' ) );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );

// Reuse the container's WordPress rather than downloading a second copy, so the
// integration suite, the dev site and the E2E suite all run the same core.
define( 'ABSPATH', getenv( 'WP_ABSPATH' ) ?: '/var/www/html/' );
