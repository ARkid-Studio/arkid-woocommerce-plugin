<?php
/**
 * Checks for URLs that arrive from outside the plugin.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Support;

defined( 'ABSPATH' ) || exit;

final class Url {

	/**
	 * The URL when it is an absolute https URL with a host, else ''.
	 *
	 * For the links and images ARkid's API sends, which are shown and followed in
	 * wp-admin: `esc_url()` alone also lets `http:`, `ftp:` and `mailto:` through.
	 * The result is not escaped; pass it to `esc_url()` or `esc_attr()` where it is
	 * output.
	 */
	public static function https( string $url ): string {
		$url = trim( $url );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $url : '';
	}
}
