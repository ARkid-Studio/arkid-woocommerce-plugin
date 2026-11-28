<?php
/**
 * The counts and lists behind the views of the Products list.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Links;

use Arkid\CatalogueLink\Links\ProductStates;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ProductStatesTest extends TestCase {

	/**
	 * @param array<string, string> $meta
	 */
	private function product( string $title, string $embed_id = '', array $meta = array(), string $status = 'publish' ): int {
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
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	private function in_order( string $title, string $embed_id ): int {
		return $this->product( $title, $embed_id, array( PostMeta::KEY_EMBED_URL => 'https://catalogue.arkid.app/embed/' . $embed_id ) );
	}

	public function test_it_counts_the_products_and_the_ones_with_a_viewer(): void {
		$this->in_order( 'A', 'viewer-a' );
		$this->in_order( 'B', 'viewer-a' );
		$this->product( 'C' );
		$this->product( 'D', '', array( PostMeta::KEY_EMBED_ID => '' ) );
		$this->in_order( 'Draft', 'viewer-b' );
		wp_update_post( array( 'ID' => $this->product( 'Gone', 'viewer-c' ), 'post_status' => 'trash' ) );
		$this->product( 'Auto', 'viewer-d', array(), 'auto-draft' );

		$states = new ProductStates();

		$this->assertSame( 5, $states->total(), 'the trash and auto-drafts are not products of the shop' );
		$this->assertSame( 3, $states->linked() );
	}

	public function test_it_counts_the_products_of_each_viewer_without_regard_to_case(): void {
		$this->in_order( 'A', 'Viewer-A' );
		$this->in_order( 'B', 'viewer-a' );
		$this->in_order( 'C', 'viewer-b' );
		$this->product( 'None' );

		$this->assertSame( array( 'viewer-a' => 2, 'viewer-b' => 1 ), ( new ProductStates() )->by_viewer() );
	}

	public function test_without_the_account_attention_is_what_the_product_page_shows_nothing_for(): void {
		$this->in_order( 'Fine', 'viewer-a' );
		$not_synced = $this->product( 'Not synced', 'viewer-b' );
		$missing    = $this->product( 'Missing', 'viewer-c', array( PostMeta::KEY_STATUS => PostMeta::STATUS_MISSING ) );
		$this->product( 'No viewer' );

		$states = new ProductStates();

		$this->assertSame( array( $not_synced, $missing ), $states->attention_ids( null ) );
		$this->assertSame( 2, $states->attention_count( null ) );
	}

	public function test_with_the_account_a_viewer_it_does_not_list_needs_attention_too(): void {
		$this->in_order( 'Fine', 'viewer-a' );
		$deleted    = $this->in_order( 'Deleted upstream', 'viewer-gone' );
		$not_synced = $this->product( 'Not synced', 'viewer-a' );

		$states = new ProductStates();

		$this->assertSame( array( $deleted, $not_synced ), $states->attention_ids( array( 'viewer-a', 'viewer-b' ) ) );
		$this->assertSame( 2, $states->attention_count( array( 'viewer-a', 'viewer-b' ) ) );
		// Without the list nothing says that the deleted one is, since its snapshot is still stored.
		$this->assertSame( array( $not_synced ), $states->attention_ids( null ) );
	}

	public function test_a_viewer_is_compared_with_the_list_without_regard_to_case(): void {
		$this->in_order( 'Upper', 'VIEWER-A' );

		$this->assertSame( array(), ( new ProductStates() )->attention_ids( array( 'viewer-a' ) ) );
		$this->assertSame( array(), ( new ProductStates() )->attention_ids( array( 'Viewer-A' ) ) );
	}

	/**
	 * What the product edit screen says too: a viewer that ARkid has is not missing,
	 * whatever an earlier refresh left behind. A stale `missing` that still has no
	 * snapshot stays for the refresh to clear, but one with a snapshot does not count.
	 */
	public function test_a_viewer_the_account_lists_is_not_missing_whatever_a_refresh_left(): void {
		$stale = $this->in_order( 'Back again', 'viewer-a' );
		update_post_meta( $stale, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );

		$states = new ProductStates();

		$this->assertSame( array(), $states->attention_ids( array( 'viewer-a' ) ) );
		$this->assertSame( array( $stale ), $states->attention_ids( null ), 'without the list the stored word stands' );
	}

	public function test_an_account_without_a_viewer_lists_none_of_the_links(): void {
		$a = $this->in_order( 'A', 'viewer-a' );
		$b = $this->in_order( 'B', 'viewer-b' );
		$this->product( 'No viewer' );

		$this->assertSame( array( $a, $b ), ( new ProductStates() )->attention_ids( array() ) );
	}

	public function test_ids_with_quotes_are_data_not_sql(): void {
		$this->in_order( 'A', 'viewer-a' );

		$ids = ( new ProductStates() )->attention_ids( array( "x') OR 1=1 -- ", 'viewer-a' ) );

		$this->assertSame( array(), $ids );
	}

	public function test_it_reads_only_the_shops_own_database(): void {
		$this->in_order( 'A', 'viewer-a' );

		$states = new ProductStates();
		$states->total();
		$states->linked();
		$states->by_viewer();
		$states->attention_count( array( 'viewer-a' ) );

		$this->assertSame( array(), $this->http_calls );
	}
}
