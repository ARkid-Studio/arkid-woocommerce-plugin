<?php
/**
 * A product of the shop, as far as matching it to a viewer needs to know it.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class ShopProduct {

	/**
	 * @param int    $id       The WordPress post id.
	 * @param string $title    The product's title.
	 * @param string $vendor   Its brand, which is what Shopify calls the vendor; '' for none.
	 * @param string $embed_id The viewer it has selected, '' for none.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $vendor,
		public readonly string $embed_id = ''
	) {}

	public function is_linked(): bool {
		return '' !== $this->embed_id;
	}
}
