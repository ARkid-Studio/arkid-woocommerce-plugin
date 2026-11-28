<?php
/**
 * The bounded scan of the products without a viewer.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Links;

use Arkid\CatalogueLink\Links\ProductScan;
use Arkid\CatalogueLink\Matching\ShopProduct;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ProductScanTest extends TestCase {

	private function product( string $title, string $embed_id = '', string $status = 'publish' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
		if ( '' !== $embed_id ) {
			update_post_meta( $id, PostMeta::KEY_EMBED_ID, $embed_id );
		}

		return $id;
	}

	/**
	 * @param array<int, ShopProduct> $products
	 *
	 * @return array<int, string>
	 */
	private static function titles( array $products ): array {
		return array_map( static fn( ShopProduct $product ): string => $product->title, $products );
	}

	public function test_it_reads_the_products_without_a_viewer_in_the_order_of_their_ids(): void {
		$second = $this->product( 'Zebra stool' );
		$this->product( 'Linked chair', 'viewer-a' );
		$first_id = $this->product( 'Apple table' );

		$result = ( new ProductScan() )->unlinked();

		$this->assertSame( array( 'Zebra stool', 'Apple table' ), self::titles( $result->products ), 'by id, not by title' );
		$this->assertSame( array( $second, $first_id ), array_map( static fn( ShopProduct $p ): int => $p->id, $result->products ) );
		$this->assertFalse( $result->truncated );
		$this->assertSame( '', $result->products[0]->embed_id );
	}

	public function test_a_product_with_an_empty_viewer_id_has_no_viewer(): void {
		$id = $this->product( 'Lamp' );
		update_post_meta( $id, PostMeta::KEY_EMBED_ID, '' );

		$this->assertSame( array( 'Lamp' ), self::titles( ( new ProductScan() )->unlinked()->products ) );
	}

	public function test_every_status_but_the_trash_and_auto_drafts_is_read(): void {
		$this->product( 'Published' );
		$this->product( 'Draft', '', 'draft' );
		$this->product( 'Pending', '', 'pending' );
		$this->product( 'Private', '', 'private' );
		$this->product( 'Scheduled', '', 'future' );
		$this->product( 'Trashed', '', 'trash' );
		$this->product( 'Auto', '', 'auto-draft' );

		$this->assertSame(
			array( 'Published', 'Draft', 'Pending', 'Private', 'Scheduled' ),
			self::titles( ( new ProductScan() )->unlinked()->products )
		);
	}

	public function test_it_stops_at_the_limit_and_says_so(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->product( 'Product ' . $i );
		}

		$cut = ( new ProductScan( 3 ) )->unlinked();
		$this->assertSame( array( 'Product 1', 'Product 2', 'Product 3' ), self::titles( $cut->products ), 'the first by id, the same every time' );
		$this->assertTrue( $cut->truncated );

		$exact = ( new ProductScan( 5 ) )->unlinked();
		$this->assertCount( 5, $exact->products );
		$this->assertFalse( $exact->truncated, 'a shop of exactly the limit is not cut off' );

		$this->assertSame( 2000, ProductScan::LIMIT );
	}

	public function test_a_title_is_read_as_a_person_writes_it(): void {
		$id = $this->product( 'placeholder' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Tom &amp; Jerry <b>Chair</b> &quot;XL&quot;' ) );

		$this->assertSame( 'Tom & Jerry Chair "XL"', ( new ProductScan() )->unlinked()->products[0]->title );
		$this->assertSame( 'Tom & Jerry', ProductScan::title( '  Tom &amp; Jerry ' ) );
	}

	public function test_the_brand_is_the_first_brand_by_name(): void {
		if ( ! taxonomy_exists( ProductScan::BRAND_TAXONOMY ) ) {
			register_taxonomy( ProductScan::BRAND_TAXONOMY, 'product' );
		}
		$with_two = $this->product( 'Fame' );
		$without  = $this->product( 'Plain' );
		wp_set_object_terms( $with_two, array( 'Zeta', 'Maxi &amp; Cosi' ), ProductScan::BRAND_TAXONOMY );

		$products = ( new ProductScan() )->unlinked()->products;

		$this->assertSame( 'Maxi & Cosi', $products[0]->vendor );
		$this->assertSame( '', $products[1]->vendor );
		$this->assertSame( 'Maxi & Cosi', ProductScan::brand_of( $with_two ) );
		$this->assertSame( '', ProductScan::brand_of( $without ) );
	}

	public function test_a_shop_without_brands_has_no_vendor(): void {
		$id = $this->product( 'Fame' );
		$this->assertSame( '', ProductScan::brand_of( $id + 1000 ) );

		$brands = $GLOBALS['wp_taxonomies'][ ProductScan::BRAND_TAXONOMY ] ?? null;
		unregister_taxonomy( ProductScan::BRAND_TAXONOMY );
		try {
			$this->assertSame( '', ( new ProductScan() )->unlinked()->products[0]->vendor );
			$this->assertSame( '', ProductScan::brand_of( $id ) );
		} finally {
			if ( null !== $brands ) {
				// Put WooCommerce's taxonomy back for the tests that follow.
				$GLOBALS['wp_taxonomies'][ ProductScan::BRAND_TAXONOMY ] = $brands;
				register_taxonomy_for_object_type( ProductScan::BRAND_TAXONOMY, 'product' );
			}
		}
	}

	public function test_it_reads_only_the_shops_own_database(): void {
		$this->product( 'Chair' );

		( new ProductScan() )->unlinked();

		$this->assertSame( array(), $this->http_calls );
	}
}
