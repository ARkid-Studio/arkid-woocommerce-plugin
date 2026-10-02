<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Frontend;

use Arkid\CatalogueLink\Frontend\BlockGalleryInjector;
use Arkid\CatalogueLink\Frontend\ClassicGalleryInjector;
use Arkid\CatalogueLink\Frontend\Consent;
use Arkid\CatalogueLink\Frontend\EmbedResolver;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Frontend\Modal;
use Arkid\CatalogueLink\Frontend\Renderer;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class GalleryInjectorTest extends TestCase {

	private function renderer(): Renderer {
		$options = new Options();

		return new Renderer(
			new IframeFactory( $options ),
			new Consent( $options ),
			new Modal(),
			new EmbedResolver( $options )
		);
	}

	private function classic(): ClassicGalleryInjector {
		$options = new Options();

		return new ClassicGalleryInjector(
			new IframeFactory( $options ),
			new Modal(),
			$this->renderer()
		);
	}

	private function with_current_product( int $product_id ): void {
		$GLOBALS['product'] = wc_get_product( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * WooCommerce fires `woocommerce_single_product_image_thumbnail_html` once
	 * per gallery image. The old guard compared against the featured image id,
	 * which is 0 when a product has no featured image and therefore matches
	 * nothing — so the guard never short-circuited and EVERY slide in the
	 * gallery was replaced by a viewer.
	 */
	public function test_only_the_first_slide_is_replaced_when_there_is_no_featured_image(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_FIRST_IMAGE );
		$this->with_current_product( $product_id );

		$injector = $this->classic();

		$replaced = 0;
		foreach ( array( '<div>one</div>', '<div>two</div>', '<div>three</div>' ) as $slide_html ) {
			$out = $injector->maybe_replace_first_slide( $slide_html, 0 );
			if ( str_contains( $out, 'arkid-catalogue-link__slide--first' ) ) {
				++$replaced;
			}
		}

		$this->assertSame( 1, $replaced, 'Exactly one gallery slide may be replaced.' );
	}

	public function test_each_product_in_a_loop_gets_its_own_first_slide(): void {
		$injector = $this->classic();

		$first  = $this->make_product_with_embed( 'abc-123', Options::POSITION_FIRST_IMAGE );
		$second = $this->make_product_with_embed( 'def-456', Options::POSITION_FIRST_IMAGE );

		$this->with_current_product( $first );
		$a = $injector->maybe_replace_first_slide( '<div>a</div>', 0 );

		$this->with_current_product( $second );
		$b = $injector->maybe_replace_first_slide( '<div>b</div>', 0 );

		$this->assertStringContainsString( 'arkid-catalogue-link__slide--first', $a );
		$this->assertStringContainsString(
			'arkid-catalogue-link__slide--first',
			$b,
			'The latch is per product; a second product must still get its slide.'
		);
	}

	/**
	 * The injected markup carries the product name and thumbnail URL. Passing
	 * those as a preg_replace() REPLACEMENT made PCRE expand any `$1` inside
	 * them as a backreference to the matched <ul> tag.
	 */
	public function test_a_dollar_one_in_the_product_name_survives_injection_verbatim(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_FIRST_IMAGE );
		wp_update_post(
			array(
				'ID'         => $product_id,
				'post_title' => 'Chair $1 Deluxe',
			)
		);
		$this->with_current_product( $product_id );

		$options  = new Options();
		$injector = new BlockGalleryInjector(
			new IframeFactory( $options ),
			new Modal(),
			$this->renderer()
		);

		$injector->observe_block( null, array( 'blockName' => 'woocommerce/product-gallery' ) );

		$html = '<ul class="wc-block-product-gallery-large-image__container"><li>real</li></ul>';
		$out  = $injector->append_large_image_slide( $html );

		$this->assertStringNotContainsString(
			'<ul class="wc-block-product-gallery-large-image__container"><li>real</li></ul></ul>',
			$out,
			'A backreference expansion would duplicate the matched tag into the output.'
		);
		$this->assertSame(
			1,
			substr_count( $out, '<ul class="wc-block-product-gallery-large-image__container">' ),
			'The <ul> tag must appear exactly once; more means $1 was expanded.'
		);
		$this->assertStringContainsString( '<li>real</li>', $out );
	}

	public function test_unrecognised_gallery_markup_is_left_untouched(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_FIRST_IMAGE );
		$this->with_current_product( $product_id );

		$options  = new Options();
		$injector = new BlockGalleryInjector(
			new IframeFactory( $options ),
			new Modal(),
			$this->renderer()
		);
		$injector->observe_block( null, array( 'blockName' => 'woocommerce/product-gallery' ) );

		$foreign = '<section class="something-else"><p>not a gallery</p></section>';

		$this->assertSame(
			$foreign,
			$injector->append_large_image_slide( $foreign ),
			'Markup we do not recognise must be returned byte-identical.'
		);
	}

	/**
	 * Filtering imageData with is_int() and writing the result back would drop
	 * every real image — and with it the whole gallery — the moment WooCommerce
	 * changed that array's shape.
	 */
	public function test_a_foreign_image_data_shape_leaves_the_gallery_state_alone(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_FIRST_IMAGE );
		$this->with_current_product( $product_id );

		$options  = new Options();
		$injector = new BlockGalleryInjector(
			new IframeFactory( $options ),
			new Modal(),
			$this->renderer()
		);
		$injector->observe_block( null, array( 'blockName' => 'woocommerce/product-gallery' ) );

		// imageData as objects rather than ints — a plausible future WC shape.
		$context = wp_json_encode(
			array(
				'imageData'       => array( array( 'id' => 11 ), array( 'id' => 12 ) ),
				'selectedImageId' => 11,
			)
		);
		$html    = '<div data-wp-context=\'' . esc_attr( (string) $context ) . '\'>gallery</div>';

		$out = $injector->mutate_wrapper_state( $html );

		$this->assertStringStartsWith(
			$html,
			$out,
			"WooCommerce's own gallery state must be returned byte-identical."
		);
		$this->assertStringNotContainsString(
			(string) BlockGalleryInjector::SENTINEL_ID,
			$out,
			'The sentinel must not be written into a context we do not understand.'
		);
		// The viewer still reaches the page, just outside the gallery.
		$this->assertStringContainsString( 'arkid-catalogue-link__embed--fallback', $out );
	}

	/**
	 * WooCommerce uses -1 itself for "this product has no images".
	 */
	public function test_the_sentinel_does_not_collide_with_woocommerce(): void {
		$this->assertNotSame( -1, BlockGalleryInjector::SENTINEL_ID );
		$this->assertLessThan( 0, BlockGalleryInjector::SENTINEL_ID );
	}
}
