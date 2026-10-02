<?php
/**
 * Storefront renderer dispatcher.
 *
 * Wires the four "non-gallery" positions (above_product, below_product,
 * button_only) plus delegates to the gallery injectors for first_image /
 * last_image. Per-product position override beats the site default.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;


class Renderer {

	/**
	 * Product whose "below product" viewer has already been emitted.
	 *
	 * On a block theme both the block filter below AND the legacy action fire for
	 * the same product, so one of them has to stand down. Keying on the product ID
	 * rather than a bare flag keeps that correct when a single request renders
	 * several products (related products, shortcode loops).
	 *
	 * @var int|null
	 */
	private ?int $below_rendered_for = null;

	/**
	 * True while the `woocommerce/product-details` block is being rendered.
	 *
	 * Set before WooCommerce's callbacks run and cleared once we've prepended, so
	 * the legacy action can tell whether a block is about to place the viewer for
	 * it. On a classic theme the block never renders and this stays false.
	 *
	 * @var bool
	 */
	private bool $in_product_details_block = false;

	public function __construct(
		private readonly IframeFactory $iframe,
		private readonly Consent $consent,
		private readonly Modal $modal,
		private readonly EmbedResolver $resolver
	) {}

	public function register(): void {
		// "Above product": fires before the gallery / summary columns.
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'maybe_render_above' ), 5 );

		// "Below product": fires AFTER the gallery + summary columns but
		// BEFORE the data tabs (Description / Additional info / Reviews,
		// hooked at priority 10), upsells (15), and related products (20).
		// This places the viewer below the product info & gallery without
		// pushing it past the reviews.
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'maybe_render_below' ), 5 );

		// "Button only": at the end of the summary stack, after add-to-cart.
		add_action( 'woocommerce_single_product_summary', array( $this, 'maybe_render_button_only' ), 35 );

		// Block themes never hook the data tabs onto the action above: the
		// `woocommerce/product-details` block renders them, and WooCommerce's
		// SingleProductTemplateCompatibility maps that action to `position => after`
		// on the very same block. Our priority-5 callback is therefore buffered and
		// injected *below* the tabs and reviews. Prepending to the block instead
		// reproduces the classic placement.
		//
		// It takes two hooks, because WooCommerce puts two callbacks on
		// `render_block` at priority 10: BlockTypesController::add_data_attributes,
		// which stamps `data-block-name` onto the FIRST tag in the content, and then
		// SingleProductTemplateCompatibility::inject_hooks, which fires the legacy
		// action. Prepending ahead of the stamper would move `data-block-name` onto
		// our wrapper and off WooCommerce's, so instead we only *claim* the block at
		// priority 5 and do the prepending on the block-specific filter, which core
		// applies after every generic `render_block` callback has run.
		add_filter( 'render_block', array( $this, 'note_product_details_block' ), 5, 2 );
		add_filter( 'render_block_woocommerce/product-details', array( $this, 'prepend_below_to_product_details' ), 20, 1 );
	}

	public function maybe_render_above(): void {
		$this->maybe_render_inline_position( Options::POSITION_ABOVE_PRODUCT, 'arkid-catalogue-link__embed--above' );
	}

	public function maybe_render_below(): void {
		// On a block theme WooCommerce fires this action from inside the
		// product-details block's own render, and prepend_below_to_product_details()
		// is about to place the viewer above the tabs instead. Stand down so it
		// isn't rendered twice.
		if ( $this->in_product_details_block ) {
			return;
		}

		$context = $this->context_for_position( Options::POSITION_BELOW_PRODUCT );
		if ( null === $context || $this->below_rendered_for === $context->product_id ) {
			return;
		}

		$html = $this->build_embed_html( $context, 'arkid-catalogue-link__embed--below' );
		if ( '' === $html ) {
			return;
		}

		$this->below_rendered_for = $context->product_id;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IframeFactory escapes internally.
	}

	/**
	 * Note that the data-tabs block is rendering, so the legacy action stands down.
	 *
	 * Runs for every block on the page, so the name check comes first and costs a
	 * single string comparison. The content is never modified here.
	 *
	 * @param string               $html  The rendered block content.
	 * @param array<string, mixed> $block The parsed block.
	 */
	public function note_product_details_block( string $html, array $block ): string {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( 'woocommerce/product-details' === $name ) {
			$this->in_product_details_block = true;
		}

		return $html;
	}

	/**
	 * Prepend the "below product" viewer to a block theme's data-tabs block.
	 *
	 * This is the block-theme equivalent of the priority-5 classic hook: it lands
	 * below the gallery and summary, above the tabs and reviews.
	 *
	 * @param string $html The rendered block content.
	 */
	public function prepend_below_to_product_details( string $html ): string {
		$this->in_product_details_block = false;

		$context = $this->context_for_position( Options::POSITION_BELOW_PRODUCT );
		if ( null === $context || $this->below_rendered_for === $context->product_id ) {
			return $html;
		}

		$embed = $this->build_embed_html( $context, 'arkid-catalogue-link__embed--below' );
		if ( '' === $embed ) {
			return $html;
		}

		$this->below_rendered_for = $context->product_id;

		return $embed . $html;
	}

	public function maybe_render_button_only(): void {
		$context = $this->context_for_position( Options::POSITION_BUTTON_ONLY );
		if ( null === $context ) {
			return;
		}

		$this->modal->ensure_rendered();

		$trigger  = $this->iframe->build_text_trigger( $context->embed_url, $context->product_name );
		$noscript = $this->iframe->build_noscript_link( $context->embed_url, $context->product_name );

		echo '<div class="arkid-catalogue-link__embed arkid-catalogue-link__embed--button-only">'
			. $trigger // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IframeFactory escapes internally.
			. $noscript // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IframeFactory escapes internally.
			. '</div>';
	}

	/**
	 * Return the active embed context if its position matches `$expected`.
	 */
	public function context_for_position( string $expected ): ?EmbedContext {
		if ( ! $this->consent->may_render() ) {
			return null;
		}

		$product = $this->current_product();
		if ( null === $product ) {
			return null;
		}

		$context = $this->resolver->resolve( $product );
		if ( null === $context ) {
			return null;
		}

		return $context->position === $expected ? $context : null;
	}

	private function maybe_render_inline_position( string $position, string $modifier ): void {
		$context = $this->context_for_position( $position );
		if ( null === $context ) {
			return;
		}

		echo $this->build_embed_html( $context, $modifier ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IframeFactory escapes internally.
	}

	/**
	 * Viewer markup for a context, independent of any hook.
	 *
	 * Used as the gallery injectors' fallback: when the gallery markup isn't the
	 * shape they expect they leave it alone and emit this instead, so the viewer
	 * still appears rather than silently vanishing.
	 */
	public function build_standalone_embed( EmbedContext $context ): string {
		return $this->build_embed_html( $context, 'arkid-catalogue-link__embed--fallback' );
	}

	private function build_embed_html( EmbedContext $context, string $modifier ): string {
		$iframe = $this->iframe->build_iframe( $context->embed_url, $context->product_name );
		if ( '' === $iframe ) {
			return '';
		}

		return '<div class="arkid-catalogue-link__embed ' . esc_attr( $modifier ) . '">'
			. $iframe
			. $this->iframe->build_noscript_link( $context->embed_url, $context->product_name )
			. '</div>';
	}

	private function current_product(): ?\WC_Product {
		global $product;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$resolved = wc_get_product();
			return $resolved instanceof \WC_Product ? $resolved : null;
		}
		return null;
	}
}
