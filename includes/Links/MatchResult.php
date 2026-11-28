<?php
/**
 * The products without a viewer that a viewer or a catalogue product matches.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

defined( 'ABSPATH' ) || exit;

final class MatchResult {

	/**
	 * @param array<int, int> $ids       The matching products, by id.
	 * @param bool            $truncated Whether the shop has more products without a viewer than the scan compared, so that there may be more matches.
	 * @param int             $compared  How many products were compared.
	 */
	public function __construct(
		public readonly array $ids,
		public readonly bool $truncated,
		public readonly int $compared
	) {}
}
