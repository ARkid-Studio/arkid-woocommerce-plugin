<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Frontend;

use Arkid\CatalogueLink\Frontend\Consent;
use Arkid\CatalogueLink\Frontend\EmbedResolver;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Frontend\Modal;
use Arkid\CatalogueLink\Frontend\Renderer;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

/**
 * "Below product" has to land above the data tabs on BOTH theme families.
 *
 * On a classic theme that's the priority-5 hook on
 * `woocommerce_after_single_product_summary`, which beats the tabs at 10.
 *
 * On a block theme the tabs are the `woocommerce/product-details` block, and
 * WooCommerce's SingleProductTemplateCompatibility maps that same action to
 * `position => after` on it — so the classic hook renders BELOW the tabs and
 * reviews. The renderer therefore stands the action down while that block is
 * rendering and prepends to the block instead.
 */
final class BelowProductPlacementTest extends TestCase {

	private const DETAILS_BLOCK = array( 'blockName' => 'woocommerce/product-details' );

	private function renderer(): Renderer {
		$options = new Options();

		return new Renderer(
			new IframeFactory( $options ),
			new Consent( $options ),
			new Modal(),
			new EmbedResolver( $options )
		);
	}

	private function with_current_product( int $product_id ): void {
		$GLOBALS['product'] = wc_get_product( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	private function product(): int {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_BELOW_PRODUCT );
		$this->with_current_product( $product_id );

		return $product_id;
	}

	public function test_classic_themes_still_render_from_the_legacy_action(): void {
		$this->product();
		$renderer = $this->renderer();

		$html = $this->render(
			static function () use ( $renderer ): void {
				$renderer->maybe_render_below();
			}
		);

		$this->assertStringContainsString( 'arkid-catalogue-link__embed--below', $html );
		$this->assertStringContainsString( 'catalogue.arkid.app/e/abc-123', $html );
	}

	/**
	 * The regression itself: on the pre-fix code the action rendered here AND the
	 * viewer ended up after the reviews, because nothing told it a block was
	 * about to place it.
	 */
	public function test_the_legacy_action_stands_down_while_the_details_block_renders(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );

		$html = $this->render(
			static function () use ( $renderer ): void {
				$renderer->maybe_render_below();
			}
		);

		$this->assertSame( '', $html, 'The action must not render while the details block is rendering.' );
	}

	public function test_the_details_block_gets_the_viewer_prepended(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$result = $renderer->prepend_below_to_product_details( '<div class="wp-block-woocommerce-product-details"></div>' );

		$this->assertStringContainsString( 'arkid-catalogue-link__embed--below', $result );
		$this->assertLessThan(
			(int) strpos( $result, 'wp-block-woocommerce-product-details' ),
			(int) strpos( $result, 'arkid-catalogue-link__embed--below' ),
			'The viewer must come before the tabs block, not after it.'
		);
	}

	/**
	 * Both hooks fire for the same product on a block theme. Exactly one of them
	 * may produce output.
	 */
	public function test_the_viewer_is_rendered_exactly_once_across_both_hooks(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$block_html = $renderer->prepend_below_to_product_details( '<div class="tabs"></div>' );

		// WooCommerce fires the action from inside the block render, i.e. before
		// the block-specific filter; render it afterwards too and assert the
		// latch holds either way.
		$action_html = $this->render(
			static function () use ( $renderer ): void {
				$renderer->maybe_render_below();
			}
		);

		$this->assertSame( 1, substr_count( $block_html, 'arkid-catalogue-link__embed--below' ) );
		$this->assertSame( '', $action_html );
	}

	public function test_a_second_details_block_does_not_repeat_the_viewer(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$first = $renderer->prepend_below_to_product_details( '<div class="tabs"></div>' );

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$second = $renderer->prepend_below_to_product_details( '<div class="tabs"></div>' );

		$this->assertStringContainsString( 'arkid-catalogue-link__embed--below', $first );
		$this->assertStringNotContainsString( 'arkid-catalogue-link__embed--below', $second );
	}

	/**
	 * The claim hook runs for every block on the page; only the tabs block counts.
	 */
	public function test_an_unrelated_block_does_not_stand_the_action_down(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', array( 'blockName' => 'core/paragraph' ) );

		$html = $this->render(
			static function () use ( $renderer ): void {
				$renderer->maybe_render_below();
			}
		);

		$this->assertStringContainsString( 'arkid-catalogue-link__embed--below', $html );
	}

	public function test_a_block_with_no_name_is_ignored(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', array() );

		$html = $this->render(
			static function () use ( $renderer ): void {
				$renderer->maybe_render_below();
			}
		);

		$this->assertStringContainsString( 'arkid-catalogue-link__embed--below', $html );
	}

	/**
	 * A different product in the same request (related products, loops) must
	 * still get its own viewer — the latch is keyed on the product, not the
	 * request.
	 */
	public function test_a_second_product_in_the_same_request_still_renders(): void {
		$this->product();
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$first = $renderer->prepend_below_to_product_details( '<div class="tabs"></div>' );

		$other = $this->make_product_with_embed( 'zzz-999', Options::POSITION_BELOW_PRODUCT, 'https://catalogue.arkid.app/e/zzz-999' );
		$this->with_current_product( $other );

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$second = $renderer->prepend_below_to_product_details( '<div class="tabs"></div>' );

		$this->assertStringContainsString( 'abc-123', $first );
		$this->assertStringContainsString( 'zzz-999', $second );
	}

	public function test_nothing_renders_when_the_position_is_not_below_product(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', Options::POSITION_BUTTON_ONLY );
		$this->with_current_product( $product_id );
		$renderer = $this->renderer();

		$renderer->note_product_details_block( '', self::DETAILS_BLOCK );
		$result = $renderer->prepend_below_to_product_details( '<div class="tabs"></div>' );

		$this->assertSame( '<div class="tabs"></div>', $result );
	}
}
