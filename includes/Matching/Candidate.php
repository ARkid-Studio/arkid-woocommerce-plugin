<?php
/**
 * A name a shop product can be matched to: a viewer of the account, or a
 * product of ARkid's catalogue.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

use Arkid\CatalogueLink\Api\CatalogProductDto;
use Arkid\CatalogueLink\Api\EmbedDto;

defined( 'ABSPATH' ) || exit;

final class Candidate {

	/**
	 * @param string $id      The viewer id or the catalogue product id.
	 * @param string $brand   The brand's name.
	 * @param string $product The product's name, without the brand.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $brand,
		public readonly string $product
	) {}

	public static function of_embed( EmbedDto $embed ): self {
		return new self( $embed->embed_id, $embed->brand_name, $embed->product_name );
	}

	public static function of_product( CatalogProductDto $product ): self {
		return new self( $product->product_id, $product->brand_name, $product->name );
	}

	/**
	 * The name as a person reads it: brand and product.
	 */
	public function label(): string {
		return trim( $this->brand . ' ' . $this->product );
	}
}
