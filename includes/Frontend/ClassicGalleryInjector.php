<?php
/**
 * Classic gallery (FlexSlider + PhotoSwipe) — first_image / last_image positions.
 *
 *  - first_image: replace the first slide via the
 *    `woocommerce_single_product_image_thumbnail_html` filter, latched so only
 *    the first call for a given product is replaced.
 *  - last_image: append a synthetic slide via the `woocommerce_product_thumbnails`
 *    action at priority 30 (after WC's own loop at 20). The action fires INSIDE
 *    `<div class="woocommerce-product-gallery__wrapper">` (verified in
 *    templates/single-product/product-image.php line 60).
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class ClassicGalleryInjector {

	/**
	 * Product whose first slide has already been replaced this request. Keyed by
	 * id rather than a bool because a single request can render several products
	 * (shortcode loops, related products).
	 *
	 * @var int
	 */
	private int $first_slide_rendered_for = 0;

	public function __construct(
		private readonly IframeFactory $iframe,
		private readonly Modal $modal,
		private readonly Renderer $renderer
	) {}

	public function register(): void {
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'maybe_replace_first_slide' ), 10, 2 );
		add_action( 'woocommerce_product_thumbnails', array( $this, 'maybe_append_last_slide' ), 30 );
	}

	/**
	 * @param string     $html          Slide HTML built by WC.
	 * @param int|string $attachment_id Attachment id. Unused — see below.
	 */
	public function maybe_replace_first_slide( string $html, int|string $attachment_id = 0 ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Part of the WooCommerce filter signature.
		$context = $this->renderer->context_for_position( Options::POSITION_FIRST_IMAGE );
		if ( null === $context ) {
			return $html;
		}

		// "First" is a property of call ORDER, not of which attachment happens to
		// be featured. Comparing against the featured id looks equivalent but
		// isn't: a product with no featured image has $featured_id === 0, which
		// matches nothing, so the guard never short-circuits and every slide in
		// the gallery gets replaced by a viewer. WooCommerce's own template
		// currently hides that by skipping the gallery loop when there's no
		// featured image — but that template is routinely overridden by themes,
		// so the invariant isn't ours to rely on.
		if ( $this->first_slide_rendered_for === $context->product_id ) {
			return $html;
		}
		$this->first_slide_rendered_for = $context->product_id;

		return $this->build_first_slide_html( $context );
	}

	public function maybe_append_last_slide(): void {
		$context = $this->renderer->context_for_position( Options::POSITION_LAST_IMAGE );
		if ( null === $context ) {
			return;
		}

		$this->modal->ensure_rendered();

		echo $this->build_last_slide_html( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builders escape internally.
	}

	private function build_first_slide_html( EmbedContext $context ): string {
		$thumb = '' !== $context->embed_image ? $context->embed_image : '';

		return sprintf(
			'<div class="woocommerce-product-gallery__image arkid-catalogue-link__slide arkid-catalogue-link__slide--first" data-thumb="%1$s" data-thumb-alt="%2$s"><span class="screen-reader-text">%2$s</span>%3$s%4$s</div>',
			esc_attr( $thumb ),
			esc_attr(
				sprintf(
					/* translators: %s: product name */
					__( '%s 3D viewer', 'arkid-catalogue-link' ),
					$context->product_name
				)
			),
			$this->iframe->build_iframe( $context->embed_url, $context->product_name ),
			$this->iframe->build_noscript_link( $context->embed_url, $context->product_name )
		);
	}

	private function build_last_slide_html( EmbedContext $context ): string {
		$thumb = '' !== $context->embed_image ? $context->embed_image : '';
		return sprintf(
			'<div class="woocommerce-product-gallery__image arkid-catalogue-link__slide arkid-catalogue-link__slide--last" data-thumb="%1$s" data-thumb-alt="%2$s">%3$s%4$s</div>',
			esc_attr( $thumb ),
			esc_attr(
				sprintf(
					/* translators: %s: product name */
					__( '%s 3D viewer', 'arkid-catalogue-link' ),
					$context->product_name
				)
			),
			$this->iframe->build_image_trigger(
				$context->embed_url,
				$context->product_name,
				$context->embed_image,
				'arkid-catalogue-link__trigger--gallery'
			),
			$this->iframe->build_noscript_link( $context->embed_url, $context->product_name )
		);
	}
}
