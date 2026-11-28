<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Links;

use Arkid\CatalogueLink\Links\LinkedProduct;
use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ProductLinksTest extends TestCase {

	private function product( string $title, string $embed_id, string $status = 'publish', string $type = 'product' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => $type,
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
	 * @param array<int, LinkedProduct> $products
	 *
	 * @return array<int, string>
	 */
	private function titles( array $products ): array {
		return array_map( static fn( LinkedProduct $p ): string => $p->title, $products );
	}

	public function test_products_are_grouped_by_viewer_in_title_order(): void {
		$this->product( 'Zebra chair', 'viewer-a' );
		$this->product( 'apple chair', 'viewer-a' );
		$this->product( 'Mango table', 'viewer-b' );
		$this->product( 'Unlinked', '' );

		$by_embed = ( new ProductLinks() )->by_embed();

		$this->assertSame( array( 'viewer-a', 'viewer-b' ), array_keys( $by_embed ) );
		$this->assertSame( array( 'apple chair', 'Zebra chair' ), $this->titles( $by_embed['viewer-a'] ) );
		$this->assertSame( array( 'Mango table' ), $this->titles( $by_embed['viewer-b'] ) );
	}

	/**
	 * The same products the refresh job walks: not the trash, not an
	 * auto-draft, and only products.
	 */
	public function test_trashed_auto_draft_and_other_post_types_do_not_count(): void {
		$this->product( 'Live', 'viewer-a' );
		$this->product( 'Trashed', 'viewer-a', 'trash' );
		$this->product( 'Auto draft', 'viewer-a', 'auto-draft' );
		$this->product( 'A page', 'viewer-a', 'publish', 'page' );

		$by_embed = ( new ProductLinks() )->by_embed();

		$this->assertSame( array( 'Live' ), $this->titles( $by_embed['viewer-a'] ) );
	}

	/**
	 * The viewer is selected on a draft, so it is listed; the product says it is
	 * not published, so a viewer is not called live on the strength of it.
	 */
	public function test_unpublished_products_are_listed_with_their_status(): void {
		$this->product( 'Public', 'viewer-a' );
		$this->product( 'Work in progress', 'viewer-a', 'draft' );
		$this->product( 'Members only', 'viewer-a', 'private' );

		$products = ( new ProductLinks() )->by_embed()['viewer-a'];
		$status   = array();
		foreach ( $products as $product ) {
			$status[ $product->title ] = $product->status;
		}

		$this->assertSame(
			array(
				'Members only'     => 'private',
				'Public'           => 'publish',
				'Work in progress' => 'draft',
			),
			$status
		);
		$this->assertTrue( $products[1]->is_published() );
		$this->assertFalse( $products[0]->is_published() );
	}

	/**
	 * ARkid and the stored link may spell an id in different cases.
	 */
	public function test_ids_are_keyed_in_lower_case(): void {
		$this->product( 'Chair', 'ABCD-1234' );

		$by_embed = ( new ProductLinks() )->by_embed();

		$this->assertArrayHasKey( 'abcd-1234', $by_embed );
		$this->assertSame( 'abcd-1234', ProductLinks::key( 'ABCD-1234' ) );
	}

	public function test_a_store_without_links_gives_an_empty_map(): void {
		$this->product( 'Chair', '' );

		$this->assertSame( array(), ( new ProductLinks() )->by_embed() );
	}

	public function test_the_products_of_one_viewer_are_those_the_list_counts_for_it(): void {
		$this->product( 'Zebra chair', 'viewer-a' );
		$this->product( 'apple chair', 'viewer-a' );
		$this->product( 'Work in progress', 'viewer-a', 'draft' );
		$this->product( 'Mango table', 'viewer-b' );
		$this->product( 'Trashed', 'viewer-a', 'trash' );
		$this->product( 'Auto draft', 'viewer-a', 'auto-draft' );
		$this->product( 'A page', 'viewer-a', 'publish', 'page' );
		$this->product( 'Unlinked', '' );
		$links = new ProductLinks();

		$ids = $links->product_ids( 'viewer-a' );

		$this->assertCount( 3, $ids );
		// What the viewers list shows under "Shown on", in the same order, so that
		// "+N more" opens as many products as it promised.
		$this->assertSame(
			array_map( static fn( LinkedProduct $p ): int => $p->id, $links->by_embed()['viewer-a'] ),
			$ids
		);
		$this->assertSame( array(), $links->product_ids( 'viewer-c' ) );
	}

	public function test_a_viewer_is_found_whatever_case_it_is_spelt_in(): void {
		$first  = $this->product( 'Chair', 'ABCD-1234' );
		$second = $this->product( 'Table', 'abcd-1234' );

		$this->assertSame( array( $first, $second ), ( new ProductLinks() )->product_ids( 'AbCd-1234' ) );
	}

	public function test_an_id_that_cannot_be_a_viewers_finds_no_products(): void {
		$this->product( 'Chair', 'viewer-a' );
		$links = new ProductLinks();

		foreach ( array( '', 'viewer a', "viewer-a' OR '1'='1", '%', 'viewer-a%' ) as $id ) {
			$this->assertSame( array(), $links->product_ids( $id ), $id );
		}
	}

	/**
	 * `Admin\ProductListFilter` answers this address; the viewers list builds it.
	 */
	public function test_the_products_list_link_names_the_viewer(): void {
		$url = ProductLinks::products_url( 'abc-123' );

		$this->assertSame( admin_url( 'edit.php?post_type=product&arkid_embed=abc-123' ), $url );
		$this->assertSame( 'arkid_embed', ProductLinks::QUERY_VAR );
	}

	public function test_an_id_that_cannot_be_a_viewer_gets_no_link(): void {
		$this->assertSame( '', ProductLinks::products_url( '' ) );
		$this->assertSame( '', ProductLinks::products_url( 'a b' ) );
		$this->assertSame( '', ProductLinks::products_url( '../../etc' ) );
		$this->assertSame( '', ProductLinks::products_url( 'x&post_type=page' ) );
	}
}
