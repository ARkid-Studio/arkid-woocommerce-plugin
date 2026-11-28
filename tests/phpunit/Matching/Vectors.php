<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Matching;

use Arkid\CatalogueLink\Matching\Candidate;

/**
 * The vectors the Shopify plugin's matcher and id decoder produced
 * (`tests/fixtures/matching/shopify-vectors.json`, and `extra-vectors.json`, which
 * the same matcher gave for random names in many scripts). The PHP port has to
 * give every one of them.
 */
final class Vectors {

	/**
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the file is missing or not JSON.
	 */
	public static function all( string $file = 'shopify-vectors' ): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/matching/' . $file . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local test fixture, not a remote URL.
		$json = file_get_contents( $path );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Unreadable fixture: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		/** @var array<string, mixed> $data */
		return $data;
	}

	/**
	 * One section of the file, as the list it is.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function section( string $name, string $file = 'shopify-vectors' ): array {
		$section = self::all( $file )[ $name ] ?? array();
		/** @var array<int, array<string, mixed>> $section */
		return is_array( $section ) ? $section : array();
	}

	/**
	 * The viewers of the matching vectors.
	 *
	 * @return array<int, Candidate>
	 */
	public static function embeds( string $file = 'shopify-vectors' ): array {
		$out = array();
		foreach ( self::section( 'embeds', $file ) as $embed ) {
			$out[] = new Candidate( (string) $embed['id'], (string) $embed['brand'], (string) $embed['product'] );
		}

		return $out;
	}
}
