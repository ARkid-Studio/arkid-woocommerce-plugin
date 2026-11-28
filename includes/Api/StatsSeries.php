<?php
/**
 * One traffic series of a period, with the period of equal length before it.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class StatsSeries {

	/**
	 * @param int        $total         The sum over the period.
	 * @param int        $before        The sum over the period before it.
	 * @param array<int, int> $series        One value per date of the period, oldest first.
	 * @param array<int, int> $before_series The same for the period before.
	 */
	public function __construct(
		public readonly int $total,
		public readonly int $before,
		public readonly array $series,
		public readonly array $before_series
	) {}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_array( array $payload ): self {
		return new self(
			total:         Cast::int( $payload, 'total' ),
			before:        Cast::int( $payload, 'before' ),
			series:        Cast::ints( $payload['series'] ?? array() ),
			before_series: Cast::ints( $payload['before_series'] ?? array() ),
		);
	}

	/**
	 * The change from the period before to this one, in percent, or null when
	 * there was nothing before to compare with. Rounds half up, as the Shopify
	 * app's `Math.round` does.
	 */
	public function percent_change(): ?int {
		if ( $this->before <= 0 ) {
			return null;
		}
		return (int) floor( ( ( $this->total - $this->before ) / $this->before ) * 100 + 0.5 );
	}

	/**
	 * @return array{total: int, before: int, series: array<int, int>, before_series: array<int, int>}
	 */
	public function to_array(): array {
		return array(
			'total'         => $this->total,
			'before'        => $this->before,
			'series'        => $this->series,
			'before_series' => $this->before_series,
		);
	}
}
