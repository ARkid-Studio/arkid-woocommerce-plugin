<?php
/**
 * Inject the ARkid embed slide into the new `woocommerce/product-gallery`
 * block (Interactivity API).
 *
 * Approach (see plan: "Slider verdict"):
 *  1. `pre_render_block` for `woocommerce/product-gallery` — observe but
 *     don't mutate (the block builds `imageData` by itself; we don't have a
 *     hook to inject before that). We use this only to remember "this block
 *     was rendered for product X and has position first/last".
 *  2. `render_block_woocommerce/product-gallery` — after the block renders,
 *     mutate the wrapper's `data-wp-context` JSON (parse the imageData
 *     array, inject our sentinel `-1`, write back) so the carousel JS
 *     extends prev/next nav across our slide.
 *  3. `render_block_woocommerce/product-gallery-large-image` — append our
 *     `<li>` to the slide `<ul>`, carrying the same `data-image-id` /
 *     `data-wp-watch` pair WooCommerce puts on its own slides.
 *  4. `render_block_woocommerce/product-gallery-thumbnails` — intentionally a
 *     no-op; see append_thumbnail().
 *
 * The `data-image-id` in (3) is what makes the slide behave. WooCommerce's
 * `callbacks.toggleImageVisibility` reads that attribute, looks the value up in
 * `imageData`, and then sets `hidden` and — crucially — `style.order` from the
 * index it finds. A slide without it is never ordered, so it sorts to the front
 * of the flex container whatever `imageData` says: that was the "last image"
 * position rendering first.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class BlockGalleryInjector {

	/**
	 * Stands in for our synthetic slide inside WooCommerce's imageData list.
	 *
	 * NOT -1: WooCommerce uses -1 itself as the selectedImageId for a product
	 * with no images (ProductGallery.php), so -1 would make an image-less
	 * product think our slide was its initial selection.
	 */
	public const SENTINEL_ID = -2147483647;

	/** The <ul> WooCommerce 11 wraps its large-image slides in. */
	private const LARGE_IMAGE_CONTAINER_CLASS = 'wc-block-product-gallery-large-image__container';

	/**
	 * Our slide actually made it into the markup this render.
	 *
	 * @var bool
	 */
	private bool $slide_injected = false;

	/**
	 * The gallery markup wasn't the shape we expect; don't touch WC's state.
	 *
	 * @var bool
	 */
	private bool $injection_failed = false;

	private ?EmbedContext $active_context = null;
	private string $active_position       = '';

	public function __construct(
		private readonly IframeFactory $iframe,
		private readonly Modal $modal,
		private readonly Renderer $renderer
	) {}

	public function register(): void {
		add_filter( 'pre_render_block', array( $this, 'observe_block' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-gallery', array( $this, 'mutate_wrapper_state' ), 20, 1 );
		add_filter( 'render_block_woocommerce/product-gallery-large-image', array( $this, 'append_large_image_slide' ), 20, 1 );
		add_filter( 'render_block_woocommerce/product-gallery-thumbnails', array( $this, 'append_thumbnail' ), 20, 1 );
	}

	/**
	 * @param array<string, mixed>|string|null $pre_render
	 * @param array<string, mixed>             $parsed_block
	 *
	 * @return array<string, mixed>|string|null
	 */
	public function observe_block( $pre_render, $parsed_block ) {
		$name = isset( $parsed_block['blockName'] ) && is_string( $parsed_block['blockName'] ) ? $parsed_block['blockName'] : '';
		if ( 'woocommerce/product-gallery' !== $name ) {
			return $pre_render;
		}

		$first  = $this->renderer->context_for_position( Options::POSITION_FIRST_IMAGE );
		$last   = $this->renderer->context_for_position( Options::POSITION_LAST_IMAGE );
		$active = $first ?? $last;
		if ( null === $active ) {
			$this->active_context  = null;
			$this->active_position = '';
			return $pre_render;
		}

		$this->active_context  = $active;
		$this->active_position = null !== $first ? Options::POSITION_FIRST_IMAGE : Options::POSITION_LAST_IMAGE;

		return $pre_render;
	}

	/**
	 * Runs last for a given gallery (inner blocks render before the parent's
	 * render_block filter), so this is where we decide whether the injection
	 * worked and reset per-gallery state.
	 */
	public function mutate_wrapper_state( string $html ): string {
		$context = $this->active_context;
		if ( null === $context ) {
			return $html;
		}

		try {
			return $this->apply_sentinel( $html, $context );
		} finally {
			// Several galleries can render in one request (product loops,
			// related products). Without this reset the next gallery inherits
			// the previous product's context and renders the wrong viewer.
			$this->active_context   = null;
			$this->active_position  = '';
			$this->slide_injected   = false;
			$this->injection_failed = false;
		}
	}

	private function apply_sentinel( string $html, EmbedContext $context ): string {
		if ( $this->injection_failed || ! $this->slide_injected ) {
			// The gallery isn't the shape we expect, so WC's state must not be
			// touched. Render the viewer after the gallery instead of dropping
			// it — on a block theme there is no other hook that would catch it.
			return $html . $this->renderer->build_standalone_embed( $context );
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'tag_name' => 'div' ) ) ) {
			return $html;
		}

		$context_attr = $processor->get_attribute( 'data-wp-context' );
		if ( ! is_string( $context_attr ) || '' === $context_attr ) {
			return $html;
		}

		$context = json_decode( $context_attr, true );
		if ( ! is_array( $context ) || ! isset( $context['imageData'] ) || ! is_array( $context['imageData'] ) ) {
			return $html;
		}

		// Validate rather than filter. array_filter() here was a lossy coercion
		// of someone else's data structure: if WooCommerce ever emits image ids
		// as strings or objects, every real image would be silently dropped and
		// the gallery replaced by our single slide. Leaving WC's own output
		// untouched is the only safe response to an unrecognised shape.
		$image_data = $context['imageData'];
		foreach ( $image_data as $value ) {
			if ( ! is_int( $value ) ) {
				$this->injection_failed = true;
				return $html;
			}
		}
		$image_data = array_values( $image_data );

		if ( in_array( self::SENTINEL_ID, $image_data, true ) ) {
			return $html;
		}

		if ( Options::POSITION_FIRST_IMAGE === $this->active_position ) {
			array_unshift( $image_data, self::SENTINEL_ID );
		} else {
			$image_data[] = self::SENTINEL_ID;
		}

		$context['imageData'] = $image_data;

		// WooCommerce derives the whole navigation state from where the SELECTED
		// image sits in imageData — `isDisabledPrevious: index === 0`,
		// `isDisabledNext: index === length - 1`. Adding a slide changes both the
		// length and (for "first image") the index, so leaving these alone left
		// "next" disabled on a one-image product that now has two slides.
		//
		// selectedImageId must also always name something in imageData, or the
		// store's `imageIndex` getter returns -1 and prev/next stop moving.
		$selected = $context['selectedImageId'] ?? null;
		if ( Options::POSITION_FIRST_IMAGE === $this->active_position
			|| ! is_int( $selected )
			|| ! in_array( $selected, $image_data, true )
		) {
			$selected = $image_data[0];
		}

		$index = (int) array_search( $selected, $image_data, true );

		$context['selectedImageId']         = $selected;
		$context['isDisabledPrevious']      = 0 === $index;
		$context['isDisabledNext']          = count( $image_data ) - 1 === $index;
		$context['hideNextPreviousButtons'] = count( $image_data ) <= 1;

		$processor->set_attribute( 'data-wp-context', (string) wp_json_encode( $context ) );

		return $processor->get_updated_html();
	}

	public function append_large_image_slide( string $html ): string {
		$context = $this->active_context;
		if ( null === $context ) {
			return $html;
		}

		$slide = $this->build_large_image_slide( $context, $this->active_position );
		if ( '' === $slide ) {
			return $html;
		}

		if ( Options::POSITION_LAST_IMAGE === $this->active_position ) {
			$this->modal->ensure_rendered();
		}

		$spliced = $this->splice_into_container( $html, $slide, Options::POSITION_FIRST_IMAGE === $this->active_position );
		if ( $spliced !== $html ) {
			$this->slide_injected = true;
		}

		return $spliced;
	}

	/**
	 * Thumbnail-strip injection is intentionally not implemented.
	 *
	 * WooCommerce 11 renders thumbnails as
	 * `<div class="wc-block-product-gallery-thumbnails__scrollable">` containing
	 * `<div>`/`<img data-image-id>` pairs wired to `actions.selectCurrentImage`
	 * — there is no <ul>, and the previous implementation spliced into one, so
	 * it had been a silent no-op. Emitting a thumbnail that doesn't match that
	 * contract is worse than emitting none: it would render an inert control.
	 * The large-image slide is still injected, so the viewer remains reachable
	 * via the gallery's own next/previous navigation.
	 */
	public function append_thumbnail( string $html ): string {
		return $html;
	}

	private function build_large_image_slide( EmbedContext $context, string $position ): string {
		$inner = Options::POSITION_LAST_IMAGE === $position
			? $this->iframe->build_image_trigger(
				$context->embed_url,
				$context->product_name,
				$context->embed_image,
				'arkid-catalogue-link__trigger--block-gallery'
			)
			: $this->iframe->build_iframe( $context->embed_url, $context->product_name );

		$wrapper_class = 'wc-block-product-gallery-large-image__wrapper arkid-catalogue-link__slide arkid-catalogue-link__slide--block';

		// `data-image-id` + `data-wp-watch` is the contract WooCommerce's own
		// slides use: the callback resolves the id against imageData and sets this
		// element's `hidden` and `style.order`. The class above is what its
		// `closest()` looks for, so the attributes belong on the <li> itself.
		return sprintf(
			'<li class="%1$s" data-image-id="%2$s" data-wp-watch="callbacks.toggleImageVisibility">%3$s%4$s</li>',
			esc_attr( $wrapper_class ),
			esc_attr( (string) self::SENTINEL_ID ),
			$inner,
			$this->iframe->build_noscript_link( $context->embed_url, $context->product_name )
		);
	}

	private function splice_into_container( string $html, string $injection, bool $prepend ): string {
		$pattern = '#<ul\b[^>]*\bclass="[^"]*\b'
			. preg_quote( self::LARGE_IMAGE_CONTAINER_CLASS, '#' )
			. '\b[^"]*"[^>]*>#i';

		if ( 1 !== preg_match( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			$this->injection_failed = true;
			return $html;
		}

		$open_end = $matches[0][1] + strlen( $matches[0][0] );
		$close    = strpos( $html, '</ul>', $open_end );
		if ( false === $close ) {
			$this->injection_failed = true;
			return $html;
		}

		// If anything nests inside, the </ul> we found may not close the list we
		// opened. Bail rather than guess.
		if ( false !== strpos( substr( $html, $open_end, $close - $open_end ), '<ul' ) ) {
			$this->injection_failed = true;
			return $html;
		}

		$at = $prepend ? $open_end : $close;

		return substr( $html, 0, $at ) . $injection . substr( $html, $at );
	}
}
