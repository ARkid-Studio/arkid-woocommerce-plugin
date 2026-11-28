<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api;

use Arkid\CatalogueLink\Api\StatsDto;
use Arkid\CatalogueLink\Api\StatsSeries;
use Arkid\CatalogueLink\Tests\Api\Fixtures\ApiFixture;
use PHPUnit\Framework\TestCase;

final class StatsDtoTest extends TestCase {

	public function test_the_recorded_answer_parses_completely(): void {
		$stats = StatsDto::from_array( ApiFixture::load( 'stats' ) );

		$this->assertSame( 7, $stats->days );
		$this->assertCount( 7, $stats->dates );
		$this->assertSame( '2026-11-28', $stats->dates[6] );
		$this->assertSame( 1497, $stats->impressions->total );
		$this->assertSame( 1600, $stats->impressions->before );
		$this->assertCount( 7, $stats->impressions->series );
		$this->assertCount( 7, $stats->impressions->before_series );
		$this->assertSame( 596, $stats->interactions->total );
		$this->assertSame( 122, $stats->ar->total );
		$this->assertSame( 14.5, $stats->avg_interaction_seconds );
		$this->assertSame( 31.0, $stats->avg_ar_seconds );
	}

	public function test_the_traffic_of_each_viewer_is_listed_busiest_first(): void {
		$stats = StatsDto::from_array( ApiFixture::load( 'stats' ) );

		$this->assertCount( 6, $stats->embeds );
		$this->assertSame( 'd637eb1e-8d7d-11ef-0daa-6f5654bdddee', $stats->embeds[0]['embed_id'] );
		$this->assertGreaterThanOrEqual( $stats->embeds[1]['impressions'], $stats->embeds[0]['impressions'] );
		$this->assertSame( array( 'embed_id', 'impressions', 'interactions' ), array_keys( $stats->embeds[0] ) );
	}

	public function test_interactions_are_joined_by_lower_case_viewer_id(): void {
		$payload           = ApiFixture::load( 'stats' );
		$payload['embeds'] = array(
			array( 'embed_id' => 'AAAA-1', 'impressions' => 10, 'interactions' => 4 ),
			array( 'embed_id' => 'bbbb-2', 'impressions' => 5, 'interactions' => 0 ),
			array( 'embed_id' => '', 'impressions' => 1, 'interactions' => 1 ),
		);

		$this->assertSame(
			array( 'aaaa-1' => 4, 'bbbb-2' => 0 ),
			StatsDto::from_array( $payload )->interactions_by_embed()
		);
	}

	/**
	 * ARkid lists every live viewer that was viewed in the period, not a top few:
	 * the recorded answer lists them all, and so does a larger one. A viewer
	 * that is missing from the map has had no interactions.
	 */
	public function test_the_map_holds_every_listed_viewer_however_many(): void {
		$payload           = ApiFixture::load( 'stats' );
		$payload['embeds'] = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$payload['embeds'][] = array( 'embed_id' => 'embed-' . $i, 'impressions' => 100 - $i, 'interactions' => $i );
		}

		$map = StatsDto::from_array( $payload )->interactions_by_embed();

		$this->assertCount( 12, $map );
		$this->assertSame( 12, $map['embed-12'] );
		$this->assertArrayNotHasKey( 'embed-13', $map );
	}

	public function test_a_payload_without_traffic_gives_an_empty_map(): void {
		$payload           = ApiFixture::load( 'stats' );
		$payload['embeds'] = array();

		$this->assertSame( array(), StatsDto::from_array( $payload )->interactions_by_embed() );
	}

	public function test_the_newest_products_say_whether_they_are_embedded(): void {
		$stats = StatsDto::from_array( ApiFixture::load( 'stats' ) );

		$this->assertSame( 'Fame - New Generation', $stats->latest_products[0]['name'] );
		$this->assertTrue( $stats->latest_products[0]['embedded'] );
		$this->assertSame( 'pdp', $stats->latest_products[0]['type'] );
		$this->assertStringStartsWith( 'https://cloud.arkid.app/', $stats->latest_products[0]['image'] );
	}

	public function test_unused_brands_are_read_from_a_payload_that_has_some(): void {
		$payload                  = ApiFixture::load( 'stats' );
		$payload['unused_brands'] = array(
			array( 'name' => 'Acme', 'logo' => 'https://cloud.arkid.app/brand/acme.webp', 'products' => 4 ),
			array( 'name' => 'NoLogo', 'logo' => '', 'products' => '2' ),
			'junk',
		);

		$stats = StatsDto::from_array( $payload );

		$this->assertSame(
			array(
				array( 'name' => 'Acme', 'logo' => 'https://cloud.arkid.app/brand/acme.webp', 'products' => 4 ),
				array( 'name' => 'NoLogo', 'logo' => '', 'products' => 2 ),
			),
			$stats->unused_brands
		);
	}

	public function test_an_older_api_without_the_ar_time_gives_null_not_zero(): void {
		$payload = ApiFixture::load( 'stats' );
		unset( $payload['avg_ar_seconds'] );

		$this->assertNull( StatsDto::from_array( $payload )->avg_ar_seconds );
	}

	public function test_a_hollow_answer_parses_into_empty_series(): void {
		$stats = StatsDto::from_array( array() );

		$this->assertSame( 0, $stats->days );
		$this->assertSame( array(), $stats->dates );
		$this->assertSame( 0, $stats->impressions->total );
		$this->assertSame( array(), $stats->impressions->series );
		$this->assertSame( array(), $stats->embeds );
		$this->assertSame( array(), $stats->latest_products );
		$this->assertSame( 0.0, $stats->avg_interaction_seconds );
	}

	public function test_series_values_that_are_not_numbers_are_dropped_and_negatives_clamped(): void {
		$series = StatsSeries::from_array(
			array(
				'total'         => '12',
				'before'        => 'x',
				'series'        => array( 1, '2', 'x', null, -5, 3.9 ),
				'before_series' => 'nope',
			)
		);

		$this->assertSame( 12, $series->total );
		$this->assertSame( 0, $series->before );
		$this->assertSame( array( 1, 2, 0, 3 ), $series->series );
		$this->assertSame( array(), $series->before_series );
	}

	/**
	 * @dataProvider period_changes
	 */
	public function test_the_change_against_the_period_before_is_a_rounded_percentage( int $before, int $total, ?int $expected ): void {
		$series = new StatsSeries( $total, $before, array(), array() );

		$this->assertSame( $expected, $series->percent_change() );
	}

	/**
	 * Matches `percentChange()` of the Shopify app: no base, no percentage, and
	 * halves round up (`Math.round`), also below zero.
	 *
	 * @return array<string, array{int, int, int|null}>
	 */
	public static function period_changes(): array {
		return array(
			'no base'           => array( 0, 50, null ),
			'unchanged'         => array( 100, 100, 0 ),
			'doubled'           => array( 100, 200, 100 ),
			'halved'            => array( 100, 50, -50 ),
			'gone'              => array( 100, 0, -100 ),
			'rounds down'       => array( 3, 4, 33 ),
			'rounds up'         => array( 3, 5, 67 ),
			'half goes up'      => array( 200, 201, 1 ),
			'negative half'     => array( 200, 195, -2 ),
			'negative half two' => array( 8, 7, -12 ),
		);
	}

	public function test_the_periods_are_those_of_the_api(): void {
		$this->assertSame( 7, StatsDto::normalize_period( 7 ) );
		$this->assertSame( 30, StatsDto::normalize_period( 30 ) );
		$this->assertSame( 90, StatsDto::normalize_period( 90 ) );
		$this->assertSame( 30, StatsDto::normalize_period( 14 ) );
		$this->assertSame( 30, StatsDto::normalize_period( 0 ) );
		$this->assertSame( 30, StatsDto::normalize_period( -90 ) );
	}
}
