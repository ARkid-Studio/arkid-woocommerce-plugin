<?php
/**
 * Lightweight value object describing what to render for the current product.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

defined( 'ABSPATH' ) || exit;

final class EmbedContext {

	public function __construct(
		public readonly int $product_id,
		public readonly string $product_name,
		public readonly string $embed_id,
		public readonly string $embed_url,
		public readonly string $embed_image,
		public readonly string $position
	) {}
}
