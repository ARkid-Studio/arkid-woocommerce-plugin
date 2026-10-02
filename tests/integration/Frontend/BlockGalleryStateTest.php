<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Frontend;

use Arkid\CatalogueLink\Frontend\BlockGalleryInjector;
use Arkid\CatalogueLink\Frontend\Consent;
use Arkid\CatalogueLink\Frontend\EmbedResolver;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Frontend\Modal;
use Arkid\CatalogueLink\Frontend\Renderer;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

/**
 * The `woocommerce/product-gallery` Interactivity API contract.
 *
 * WooCommerce's `callbacks.toggleImageVisibility` reads `data-image-id` off the
 * element it is bound to, looks that value up in the `imageData` array, and sets
 * the slide's `hidden` and `style.order` from the index it finds. Navigation
 * state comes from the same index: `isDisabledPrevious: index === 0` and
 * `isDisabledNext: index === length - 1`.
 *
 * A slide that doesn't take part in that contract is never ordered, so it sorts
 * to the front of the flex container regardless of where imageData puts it.
 */
final class BlockGalleryStateTest extends TestCase {

	private function injector(): BlockGalleryInjector {
		$options = new Options();

		return new BlockGalleryInjector(
			new IframeFactory( $options ),
			new Modal(),
			new Renderer(
				new IframeFactory( $options ),
				new Consent( $options ),
				new Modal(),
				new EmbedResolver( $options )
			)
		);
	}

	private function with_current_product( int $product_id ): void {
		$GLOBALS['product'] = wc_get_product( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function wrapper( array $context ): string {
		return '<div data-wp-context=\'' . esc_attr( (string) wp_json_encode( $context ) ) . '\'>gallery</div>';
	}

	/**
	 * @param array<int, int> $image_ids
	 *
	 * @return array<string, mixed>
	 */
	private function wc_context( array $image_ids ): array {
		// The shape ProductGallery.php actually emits.
		return array(
			'imageData'               => $image_ids,
			'isDialogOpen'            => false,
			'productId'               => '1',
			'selectedImageId'         => array() === $image_ids ? -1 : $image_ids[0],
			'hideNextPreviousButtons' => count( $image_ids ) <= 1,
			'isDisabledPrevious'      => true,
			'isDisabledNext'          => count( $image_ids ) <= 1,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_gallery( string $position, string $image_container_html, array $image_ids ): array {
		$product_id = $this->make_product_with_embed( 'abc-123', $position );
		$this->with_current_product( $product_id );

		$injector = $this->injector();
		$injector->observe_block( null, array( 'blockName' => 'woocommerce/product-gallery' ) );

		$slides = $injector->append_large_image_slide( $image_container_html );
		$out    = $injector->mutate_wrapper_state( $this->wrapper( $this->wc_context( $image_ids ) ) );

		$processor = new \WP_HTML_Tag_Processor( $out );
		$processor->next_tag( array( 'tag_name' => 'div' ) );
		$decoded = json_decode( (string) $processor->get_attribute( 'data-wp-context' ), true );

		return array(
			'slides'  => $slides,
			'context' => is_array( $decoded ) ? $decoded : array(),
		);
	}

	private function container( int $slide_count ): string {
		$slides = str_repeat( '<li class="wc-block-product-gallery-large-image__wrapper">img</li>', $slide_count );

		return '<ul class="wc-block-product-gallery-large-image__container">' . $slides . '</ul>';
	}

	/**
	 * Without `data-image-id` the visibility callback never runs for our slide,
	 * so it is never assigned a `style.order` and sorts ahead of every real
	 * image — the "last image" position rendering first.
	 */
	public function test_the_slide_carries_the_attributes_the_visibility_callback_needs(): void {
		$result = $this->run_gallery( Options::POSITION_LAST_IMAGE, $this->container( 2 ), array( 11, 12 ) );

		$this->assertStringContainsString(
			'data-image-id="' . BlockGalleryInjector::SENTINEL_ID . '"',
			$result['slides']
		);
		$this->assertStringContainsString(
			'data-wp-watch="callbacks.toggleImageVisibility"',
			$result['slides']
		);
	}

	public function test_first_image_puts_the_viewer_at_index_zero_and_selects_it(): void {
		$result  = $this->run_gallery( Options::POSITION_FIRST_IMAGE, $this->container( 2 ), array( 11, 12 ) );
		$context = $result['context'];

		$this->assertSame(
			array( BlockGalleryInjector::SENTINEL_ID, 11, 12 ),
			$context['imageData']
		);
		$this->assertSame(
			BlockGalleryInjector::SENTINEL_ID,
			$context['selectedImageId'],
			'"First image" means the gallery opens on the viewer.'
		);
		$this->assertTrue( $context['isDisabledPrevious'] );
		$this->assertFalse( $context['isDisabledNext'] );
	}

	public function test_last_image_appends_the_viewer_and_leaves_the_selection_alone(): void {
		$result  = $this->run_gallery( Options::POSITION_LAST_IMAGE, $this->container( 2 ), array( 11, 12 ) );
		$context = $result['context'];

		$this->assertSame(
			array( 11, 12, BlockGalleryInjector::SENTINEL_ID ),
			$context['imageData']
		);
		$this->assertSame( 11, $context['selectedImageId'], 'The gallery still opens on the real first image.' );
		$this->assertTrue( $context['isDisabledPrevious'] );
		$this->assertFalse( $context['isDisabledNext'] );
	}

	/**
	 * The concrete regression: WooCommerce disables "next" when the selected
	 * image is the last one. On a single-image product it shipped
	 * `isDisabledNext => true`, and adding our slide without recomputing left
	 * the viewer unreachable through the gallery navigation.
	 */
	public function test_next_is_re_enabled_once_our_slide_makes_a_second_one(): void {
		$before = $this->wc_context( array( 11 ) );
		$this->assertTrue( $before['isDisabledNext'], 'Precondition: WC disables next for one image.' );

		$context = $this->run_gallery( Options::POSITION_LAST_IMAGE, $this->container( 1 ), array( 11 ) )['context'];

		$this->assertSame( array( 11, BlockGalleryInjector::SENTINEL_ID ), $context['imageData'] );
		$this->assertFalse( $context['isDisabledNext'], 'The viewer must be reachable with "next".' );
		$this->assertFalse( $context['hideNextPreviousButtons'] );
	}

	/**
	 * A product with no gallery images still renders the slide container, so our
	 * slide can be the only one. It has to be in imageData and selected —
	 * otherwise the visibility callback finds index -1 and hides it.
	 */
	public function test_a_product_with_no_images_still_shows_the_viewer(): void {
		$context = $this->run_gallery( Options::POSITION_FIRST_IMAGE, $this->container( 0 ), array() )['context'];

		$this->assertSame( array( BlockGalleryInjector::SENTINEL_ID ), $context['imageData'] );
		$this->assertSame(
			BlockGalleryInjector::SENTINEL_ID,
			$context['selectedImageId'],
			'An unselected lone slide would be hidden by toggleImageVisibility.'
		);
		$this->assertTrue( $context['isDisabledNext'] );
		$this->assertTrue( $context['hideNextPreviousButtons'] );
	}

	/**
	 * selectedImageId must always name a member of imageData, or the store's
	 * `imageIndex` getter returns -1 and prev/next stop moving entirely.
	 */
	public function test_a_selection_that_is_not_in_image_data_is_repaired(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_LAST_IMAGE );
		$this->with_current_product( $product_id );

		$injector = $this->injector();
		$injector->observe_block( null, array( 'blockName' => 'woocommerce/product-gallery' ) );
		$injector->append_large_image_slide( $this->container( 2 ) );

		$wc                    = $this->wc_context( array( 11, 12 ) );
		$wc['selectedImageId'] = 999;

		$out       = $injector->mutate_wrapper_state( $this->wrapper( $wc ) );
		$processor = new \WP_HTML_Tag_Processor( $out );
		$processor->next_tag( array( 'tag_name' => 'div' ) );
		$context = json_decode( (string) $processor->get_attribute( 'data-wp-context' ), true );

		$this->assertContains( $context['selectedImageId'], $context['imageData'] );
	}
}
