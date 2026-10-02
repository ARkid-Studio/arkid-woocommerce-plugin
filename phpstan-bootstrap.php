<?php
/**
 * PHPStan bootstrap: defines plugin constants that are normally defined at
 * runtime in arkid-catalogue-link.php so static analysis can resolve them.
 */

declare( strict_types = 1 );

defined( 'ARKID_CATALOGUE_LINK_VERSION' ) || define( 'ARKID_CATALOGUE_LINK_VERSION', '0.0.0' );
defined( 'ARKID_CATALOGUE_LINK_FILE' )    || define( 'ARKID_CATALOGUE_LINK_FILE', __DIR__ . '/arkid-catalogue-link.php' );
defined( 'ARKID_CATALOGUE_LINK_PATH' )    || define( 'ARKID_CATALOGUE_LINK_PATH', __DIR__ . '/' );
defined( 'ARKID_CATALOGUE_LINK_URL' )     || define( 'ARKID_CATALOGUE_LINK_URL', 'https://example.com/wp-content/plugins/arkid-catalogue-link/' );
