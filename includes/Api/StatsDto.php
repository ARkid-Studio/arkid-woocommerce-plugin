<?php
/**
 * The traffic of the account over the last 7, 30 or 90 days, from
 * `GET /api/ecom/stats`, with the brands it does not embed yet and its newest
 * catalogue products.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class StatsDto {

	/** The periods the API answers for. Any other `days` gives the default. */
	public const PERIODS = array( 7, 30, 90 );

	public const DEFAULT_PERIOD = 30;

	/**
	 * @param int                $days                    The period the answer covers.
	 * @param array<int, string> $dates                   `YYYY-MM-DD`, oldest first, ending today.
	 * @param float              $avg_interaction_seconds How long a shopper interacts with the viewer, on average.
	 * @param float|null         $avg_ar_seconds          The average AR session; null from an older API that does not send it.
	 * @param array<int, array{embed_id: string, impressions: int, interactions: int}> $embeds Viewers with traffic in the period, busiest first.
	 * @param array<int, array{name: string, logo: string, products: int}>             $unused_brands Brands the account can embed and has no viewer of.
	 * @param array<int, array{product_id: string, name: string, brand_name: string, type: string, image: string, created: string, embedded: bool}> $latest_products Newest catalogue products.
	 */
	public function __construct(
		public readonly int $days,
		public readonly array $dates,
		public readonly StatsSeries $impressions,
		public readonly StatsSeries $interactions,
		public readonly StatsSeries $ar,
		public readonly float $avg_interaction_seconds,
		public readonly ?float $avg_ar_seconds,
		public readonly array $embeds,
		public readonly array $unused_brands,
		public readonly array $latest_products
	) {}

	/**
	 * The period the API will answer for when asked for `$days`.
	 */
	public static function normalize_period( int $days ): int {
		return in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_PERIOD;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_array( array $payload ): self {
		$embeds = array();
		foreach ( Cast::rows( $payload['embeds'] ?? array() ) as $row ) {
			$embeds[] = array(
				'embed_id'     => Cast::str( $row, 'embed_id' ),
				'impressions'  => Cast::int( $row, 'impressions' ),
				'interactions' => Cast::int( $row, 'interactions' ),
			);
		}

		$brands = array();
		foreach ( Cast::rows( $payload['unused_brands'] ?? array() ) as $row ) {
			$brands[] = array(
				'name'     => Cast::str( $row, 'name' ),
				'logo'     => Cast::str( $row, 'logo' ),
				'products' => Cast::int( $row, 'products' ),
			);
		}

		$products = array();
		foreach ( Cast::rows( $payload['latest_products'] ?? array() ) as $row ) {
			$products[] = array(
				'product_id' => Cast::str( $row, 'product_id' ),
				'name'       => Cast::str( $row, 'name' ),
				'brand_name' => Cast::str( $row, 'brand_name' ),
				'type'       => Cast::str( $row, 'type' ),
				'image'      => Cast::str( $row, 'image' ),
				'created'    => Cast::str( $row, 'created' ),
				'embedded'   => Cast::flag( $row, 'embedded', false ),
			);
		}

		return new self(
			days:                    Cast::int( $payload, 'days' ),
			dates:                   Cast::strings( $payload['dates'] ?? array() ),
			impressions:             StatsSeries::from_array( self::object( $payload, 'impressions' ) ),
			interactions:            StatsSeries::from_array( self::object( $payload, 'interactions' ) ),
			ar:                      StatsSeries::from_array( self::object( $payload, 'ar' ) ),
			avg_interaction_seconds: Cast::float( $payload, 'avg_interaction_seconds' ),
			avg_ar_seconds:          isset( $payload['avg_ar_seconds'] ) && is_numeric( $payload['avg_ar_seconds'] )
				? (float) $payload['avg_ar_seconds']
				: null,
			embeds:                  $embeds,
			unused_brands:           $brands,
			latest_products:         $products,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	private static function object( array $payload, string $key ): array {
		$value = $payload[ $key ] ?? null;
		if ( ! is_array( $value ) ) {
			return array();
		}
		/** @var array<string, mixed> $value */
		return $value;
	}
}
