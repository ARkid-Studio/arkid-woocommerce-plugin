<?php
/**
 * Server-rendered "ARkid Catalogue Link" Gutenberg block.
 *
 * Renders purely from block attributes. The editor writes `embedUrl` and
 * `productName` alongside `embedId` when the viewer is picked, so a storefront
 * page containing this block makes no API call at all — the previous version
 * could block a front-end render for up to 5s on a cache miss.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Blocks;

use Arkid\CatalogueLink\Frontend\Consent;
use Arkid\CatalogueLink\Frontend\IframeFactory;

defined( 'ABSPATH' ) || exit;

class EmbedBlock {

	public const NAME = 'arkid-catalogue-link/embed';

	public function __construct(
		private readonly IframeFactory $iframe,
		private readonly Consent $consent
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		register_block_type(
			ARKID_CATALOGUE_LINK_PATH . 'build/blocks/embed',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes ): string {
		if ( ! $this->consent->may_render() ) {
			return '';
		}

		// Blocks saved before 1.0.0 carry only embedId. They render nothing
		// until re-saved; the editor detects that and repairs them in place.
		$embed_url = $this->string_attribute( $attributes, 'embedUrl' );
		if ( '' === $embed_url ) {
			return '';
		}

		$product_name = $this->string_attribute( $attributes, 'productName' );

		// Empty when the URL failed the host allow-list.
		$iframe = $this->iframe->build_iframe( $embed_url, $product_name );
		if ( '' === $iframe ) {
			return '';
		}

		$wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'arkid-catalogue-link__embed arkid-catalogue-link__embed--block' ) )
			: 'class="arkid-catalogue-link__embed arkid-catalogue-link__embed--block"';

		return sprintf(
			'<div %1$s>%2$s%3$s</div>',
			$wrapper_attrs,
			$iframe,
			$this->iframe->build_noscript_link( $embed_url, $product_name )
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function string_attribute( array $attributes, string $key ): string {
		$value = $attributes[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}
}
