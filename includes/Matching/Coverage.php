<?php
/**
 * How the shop's products stand with ARkid: linked to a viewer, matched by a
 * viewer or a catalogue product of ARkid, or neither. The port of `coverage.ts`.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class Coverage {

	/**
	 * @param array<int, ShopProduct>                $linked           Products with a viewer selected, even one the account does not list.
	 * @param array<int, ShopProduct>                $unknown          Linked products whose viewer the account does not list.
	 * @param array<int, ShopProduct>                $matches          Products without a viewer that a viewer or a catalogue product matches.
	 * @param array<int, ShopProduct>                $unmatched        Products without a viewer and without a match.
	 * @param array<string, array<int, ShopProduct>> $products_by_embed The products that show each viewer, by the viewer's id as the account spells it.
	 */
	private function __construct(
		public readonly array $linked,
		public readonly array $unknown,
		public readonly array $matches,
		public readonly array $unmatched,
		public readonly array $products_by_embed
	) {}

	/**
	 * Classify products.
	 *
	 * @param array<int, ShopProduct> $products
	 * @param array<int, Candidate>   $embeds    The viewers of the account.
	 * @param array<int, Candidate>   $catalog   The products of ARkid's catalogue.
	 * @param Suggester|null          $suggester Built from `$embeds` and `$catalog` already, to save building it again.
	 */
	public static function classify( array $products, array $embeds, array $catalog, ?Suggester $suggester = null ): self {
		$suggester ??= new Suggester( $embeds, $catalog );

		$linked    = array();
		$unknown   = array();
		$matches   = array();
		$unmatched = array();
		$by_embed  = array();

		foreach ( $products as $product ) {
			if ( $product->is_linked() ) {
				$linked[] = $product;
				$embed    = ViewerId::linked( $embeds, $product->embed_id );
				if ( null === $embed ) {
					$unknown[] = $product;
				} else {
					$by_embed[ $embed->id ][] = $product;
				}
				continue;
			}

			if ( $suggester->has_match( $product->title, $product->vendor ) ) {
				$matches[] = $product;
			} else {
				$unmatched[] = $product;
			}
		}

		return new self( $linked, $unknown, $matches, $unmatched, $by_embed );
	}
}
