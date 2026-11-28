<?php
/**
 * Reads typed values out of a decoded JSON object without trusting its shape.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class Cast {

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function str( array $payload, string $key ): string {
		$value = $payload[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function int( array $payload, string $key ): int {
		$value = $payload[ $key ] ?? 0;
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function float( array $payload, string $key ): float {
		$value = $payload[ $key ] ?? 0;
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * A boolean flag, or `$fallback` when the payload has none or not a boolean.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function flag( array $payload, string $key, bool $fallback ): bool {
		$value = $payload[ $key ] ?? $fallback;
		return is_bool( $value ) ? $value : $fallback;
	}

	/**
	 * The rows of a list that are objects, as arrays.
	 *
	 * @param mixed $raw
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( is_array( $row ) ) {
				/** @var array<string, mixed> $row */
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * A list of non-negative integers; anything else in it is dropped.
	 *
	 * @param mixed $raw
	 *
	 * @return array<int, int>
	 */
	public static function ints( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $value ) {
			if ( is_numeric( $value ) ) {
				$out[] = max( 0, (int) $value );
			}
		}
		return $out;
	}

	/**
	 * A list of strings; anything else in it is dropped.
	 *
	 * @param mixed $raw
	 *
	 * @return array<int, string>
	 */
	public static function strings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( $raw, 'is_string' ) );
	}
}
