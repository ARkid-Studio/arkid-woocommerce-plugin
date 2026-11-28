<?php
/**
 * What a scan of the shop's products found, and whether it stopped early.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\Matching\ShopProduct;

defined( 'ABSPATH' ) || exit;

final class ScanResult {

	/**
	 * @param array<int, ShopProduct> $products  In the order of their ids.
	 * @param bool                    $truncated Whether the shop has more of them than the scan read.
	 */
	public function __construct(
		public readonly array $products,
		public readonly bool $truncated
	) {}
}
