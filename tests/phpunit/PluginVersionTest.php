<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests;

use PHPUnit\Framework\TestCase;

final class PluginVersionTest extends TestCase {

	public function test_plugin_header_version_matches_constant_definition(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin file in a unit test; wp_remote_get is for remote URLs.
		$plugin_file = file_get_contents( dirname( __DIR__, 2 ) . '/arkid-catalogue-link.php' );

		$this->assertNotFalse( $plugin_file );

		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $plugin_file, $header_match );
		preg_match(
			"/(?:const ARKID_CATALOGUE_LINK_VERSION\s*=\s*'([^']+)')|(?:define\(\s*'ARKID_CATALOGUE_LINK_VERSION',\s*'([^']+)')/",
			$plugin_file,
			$constant_match
		);
		$constant_value = $constant_match[1] ?? ( $constant_match[2] ?? null );

		$this->assertNotEmpty( $header_match[1] ?? null, 'Plugin header is missing a Version field.' );
		$this->assertNotEmpty( $constant_value, 'ARKID_CATALOGUE_LINK_VERSION constant is missing.' );
		$this->assertSame( $header_match[1], $constant_value );
	}
}
