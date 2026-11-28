<?php
/**
 * Reads the timestamps ARkid's API sends.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Support;

defined( 'ABSPATH' ) || exit;

final class ApiTime {

	/**
	 * What ARkid writes: a date, or a date and a time, as stored in its
	 * database (`2025-07-16 02:51:43`), or the ISO 8601 form of the same.
	 */
	private const PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?$/D';

	/**
	 * The Unix time of an API timestamp, or null when it is not one.
	 *
	 * ARkid stores its times in UTC and writes them without a zone
	 * (`2025-07-16 02:51:43`), so a value without a zone is read as UTC. One that
	 * names its zone keeps it. Only an absolute date is accepted: PHP would also
	 * read "tomorrow" or "+1 week", which would turn text into a time.
	 */
	public static function parse( string $value ): ?int {
		$value = trim( $value );
		if ( 1 !== preg_match( self::PATTERN, $value ) ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			// A shape that matches but is not a date, such as month 13.
			return null;
		}
	}

	/**
	 * Whether the value says what time of day it is, and not only the date.
	 */
	public static function has_time( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', trim( $value ) );
	}
}
