<?php
/**
 * `wp_parse_url()` stand-in for unit tests. WordPress's implementation is
 * parse_url() with a normalised signature; that is all the allow-list needs.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

/**
 * @return mixed
 */
function wp_parse_url_stub( string $url, int $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}
