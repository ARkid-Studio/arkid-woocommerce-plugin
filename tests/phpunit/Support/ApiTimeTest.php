<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Support;

use Arkid\CatalogueLink\Support\ApiTime;
use PHPUnit\Framework\TestCase;

final class ApiTimeTest extends TestCase {

	/**
	 * ARkid stores UTC and writes it without a zone.
	 */
	public function test_a_time_without_a_zone_is_utc(): void {
		$this->assertSame( gmmktime( 2, 51, 43, 7, 16, 2025 ), ApiTime::parse( '2025-07-16 02:51:43' ) );
	}

	public function test_the_server_zone_does_not_move_it(): void {
		$zone = date_default_timezone_get();
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- The point of the test: a server in another zone must not move the time.
		date_default_timezone_set( 'America/Los_Angeles' );
		try {
			$this->assertSame( gmmktime( 2, 51, 43, 7, 16, 2025 ), ApiTime::parse( '2025-07-16 02:51:43' ) );
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Puts the zone back.
			date_default_timezone_set( $zone );
		}
	}

	public function test_a_date_alone_is_midnight_utc(): void {
		$this->assertSame( gmmktime( 0, 0, 0, 1, 1, 2026 ), ApiTime::parse( '2026-01-01' ) );
	}

	public function test_a_time_that_names_its_zone_keeps_it(): void {
		$this->assertSame( gmmktime( 10, 0, 0, 1, 1, 2026 ), ApiTime::parse( '2026-01-01T10:00:00Z' ) );
		$this->assertSame( gmmktime( 8, 0, 0, 1, 1, 2026 ), ApiTime::parse( '2026-01-01T10:00:00+02:00' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_times(): array {
		return array(
			'empty'             => array( '' ),
			'text'              => array( 'yesterday' ),
			'a relative time'   => array( '+1 week' ),
			'now'               => array( 'now' ),
			'a month 13'        => array( '2026-13-01' ),
			'a date and junk'   => array( '2026-01-01 and some more' ),
			'a unix time'       => array( '1767225600' ),
		);
	}

	/**
	 * PHP would read "tomorrow" as a time; text from the API must not become one.
	 *
	 * @dataProvider not_times
	 */
	public function test_anything_that_is_not_an_absolute_date_is_null( string $value ): void {
		$this->assertNull( ApiTime::parse( $value ) );
	}

	public function test_it_says_whether_a_value_has_a_time_of_day(): void {
		$this->assertTrue( ApiTime::has_time( '2025-07-16 02:51:43' ) );
		$this->assertTrue( ApiTime::has_time( '2026-01-01T10:00:00Z' ) );
		$this->assertFalse( ApiTime::has_time( '2026-01-01' ) );
		$this->assertFalse( ApiTime::has_time( '' ) );
	}
}
