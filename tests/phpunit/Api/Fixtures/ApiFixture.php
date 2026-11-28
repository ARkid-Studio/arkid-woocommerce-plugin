<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api\Fixtures;

/**
 * Reads a JSON answer of the ARkid API from `tests/fixtures/api/`.
 */
final class ApiFixture {

	/**
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the file is missing or not JSON.
	 */
	public static function load( string $name ): array {
		$path = dirname( __DIR__, 3 ) . '/fixtures/api/' . $name . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local test fixture, not a remote URL.
		$json = file_get_contents( $path );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Unreadable fixture: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		/** @var array<string, mixed> $data */
		return $data;
	}
}
