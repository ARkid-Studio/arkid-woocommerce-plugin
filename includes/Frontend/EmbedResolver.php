<?php
/**
 * Resolve the embed for a given product from post meta + site defaults.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class EmbedResolver {

	public function __construct( private readonly Options $options ) {}

	public function resolve( \WC_Product $product ): ?EmbedContext {
		$product_id = $product->get_id();
		$embed_id   = PostMeta::embed_id( $product_id );
		if ( '' === $embed_id ) {
			return null;
		}

		$embed_url = PostMeta::embed_url( $product_id );
		if ( '' === $embed_url ) {
			return null;
		}

		$position = PostMeta::position( $product_id );
		if ( '' === $position ) {
			$position = $this->options->default_position();
		}

		return new EmbedContext(
			product_id:   $product_id,
			product_name: $product->get_name(),
			embed_id:     $embed_id,
			embed_url:    $embed_url,
			embed_image:  PostMeta::embed_image( $product_id ),
			position:     $position
		);
	}
}
