<?php
/**
 * The ARkid column of the Products list.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\ProductListColumns;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;

final class ProductListColumnsTest extends ProductListTestCase {

	private function column(): ProductListColumns {
		return new ProductListColumns( $this->account(), new IframeFactory( new Options() ) );
	}

	public function test_the_column_comes_before_the_date_and_only_for_a_store_manager(): void {
		$columns = array(
			'cb'    => '',
			'name'  => 'Name',
			'price' => 'Price',
			'date'  => 'Date',
		);

		$this->assertSame( array( 'cb', 'name', 'price', 'arkid', 'date' ), array_keys( (array) $this->column()->add_column( $columns ) ) );
		$this->assertSame( 'ARkid', ( (array) $this->column()->add_column( $columns ) )['arkid'] );
		$this->assertSame( array( 'name', 'arkid' ), array_keys( (array) $this->column()->add_column( array( 'name' => 'Name' ) ) ), 'at the end when there is no date' );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( $columns, $this->column()->add_column( $columns ) );
	}

	public function test_the_column_is_hooked_to_the_products_list(): void {
		$this->column()->register();

		$this->assertNotFalse( has_filter( 'manage_edit-product_columns' ) );
		$this->assertNotFalse( has_action( 'manage_product_posts_custom_column' ) );
	}

	public function test_a_product_with_a_viewer_shows_the_viewer_its_colours_and_its_state(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame', 12 ) ) );
		$id = $this->linked( 'Fame stroller', 'viewer-a' );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( '#12 Maxi-Cosi Fame', $html );
		$this->assertStringContainsString( 'src="https://catalogue.arkid.app/img/viewer-a.jpg"', $html, 'the thumbnail of the viewer, as ARkid says it now' );
		$this->assertStringContainsString( 'arkid-catalogue-link__badge--ok">Linked</span>', $html );
		$this->assertStringContainsString( 'Oak: shown', $html, 'colour dots say what they are in words' );
		$this->assertStringContainsString( 'Walnut: hidden', $html );
		$this->assertStringContainsString( 'data-embed-url="https://catalogue.arkid.app/embed/viewer-a?source=woocommerce"', $html, 'a preview button' );
		$this->assertStringContainsString( 'aria-label="Preview #12 Maxi-Cosi Fame"', $html );
		$this->assertStringContainsString( 'class="button-link arkid-catalogue-link__preview"', $html );
	}

	public function test_the_account_is_asked_once_for_the_whole_page(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ), $this->viewer( 'viewer-b', 'Bugaboo', 'Fox 5', 8 ) ), array( $this->catalog_product( 'p1', 'Cybex', 'Priam' ) ) );
		$ids   = array(
			$this->linked( 'One', 'viewer-a' ),
			$this->linked( 'Two', 'viewer-b' ),
			$this->linked( 'Three', 'viewer-a' ),
			$this->product( 'Priam' ),
			$this->product( 'Plain' ),
		);
		$column = $this->column();

		foreach ( $ids as $id ) {
			$column->cell( $id );
		}

		$this->assertCount( 1, $this->calls_to( '/api/ecom/embed' ) );
		$this->assertCount( 1, $this->calls_to( '/api/ecom/product' ) );
	}

	public function test_a_viewer_the_account_does_not_list_is_called_unknown(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ) ) );
		$id = $this->linked( 'Deleted viewer', 'viewer-gone' );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( 'arkid-catalogue-link__badge--error">Unknown viewer</span>', $html );
		$this->assertStringContainsString( 'viewer-gone', $html );
		$this->assertStringContainsString( 'Linked to a viewer your ARkid account does not list.', $html );
		$this->assertStringNotContainsString( 'data-embed-url', $html, 'there is nothing to preview' );
		$this->assertStringContainsString( 'src="https://cloud.arkid.app/covers/viewer-gone.jpg"', $html, 'the stored thumbnail still shows' );
	}

	public function test_a_viewer_ark_id_has_is_not_missing_whatever_a_refresh_left_behind(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ) ) );
		$id = $this->linked( 'Back again', 'viewer-a' );
		update_post_meta( $id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( '>Linked</span>', $html );
		$this->assertStringNotContainsString( 'Viewer missing', $html );
	}

	public function test_a_viewer_that_was_not_synced_says_so(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ) ) );
		$id = $this->product( 'Waiting', 'viewer-a' );

		$this->assertStringContainsString( 'arkid-catalogue-link__badge--warning">Not synced yet</span>', $this->column()->cell( $id ) );
	}

	public function test_when_arkid_cannot_answer_a_linked_product_shows_what_is_stored(): void {
		$this->stub_http_status( '/api/ecom/embed', 500 );
		$synced  = $this->linked( 'Synced', 'viewer-a' );
		$waiting = $this->product( 'Waiting', 'viewer-b' );
		$missing = $this->product( 'Deleted', 'viewer-c', array( PostMeta::KEY_STATUS => PostMeta::STATUS_MISSING ) );
		$column  = $this->column();

		$html = $column->cell( $synced );
		$this->assertStringContainsString( '>viewer-a</button>', $html, 'the raw id stands in for the name' );
		$this->assertStringContainsString( '>Linked</span>', $html );
		$this->assertStringContainsString( 'src="https://cloud.arkid.app/covers/viewer-a.jpg"', $html );
		$this->assertStringContainsString( 'data-embed-url="https://catalogue.arkid.app/embed/viewer-a?source=woocommerce"', $html, 'the stored address can still be previewed' );
		$not_synced = $column->cell( $waiting );
		$this->assertStringContainsString( 'Not synced yet', $not_synced );
		$this->assertStringContainsString( '<code class="arkid-catalogue-link__viewer-id">viewer-b</code>', $not_synced, 'without an address there is nothing to preview, and the id is not a button' );
		$this->assertStringContainsString( 'Viewer missing', $column->cell( $missing ) );
		$this->assertStringContainsString( 'screen-reader-text">No viewer<', $column->cell( $this->product( 'Plain' ) ) );
		$this->assertCount( 1, $this->calls_to( '/api/ecom/embed' ), 'a failure is not asked again' );
		$this->assertCount( 0, $this->calls_to( '/api/ecom/product' ), 'nor is the catalogue, when the viewers could not be read' );
	}

	public function test_without_an_api_key_nothing_is_asked(): void {
		delete_option( Options::OPTION_KEY );
		$id = $this->linked( 'Synced', 'viewer-a' );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( 'viewer-a', $html );
		$this->assertStringContainsString( '>Linked</span>', $html );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_a_product_without_a_viewer_shows_the_viewer_its_name_matches(): void {
		$this->stub_account(
			array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame - New Generation', 3 ) ),
			array( $this->catalog_product( 'p1', 'Maxi-Cosi', 'Fame - New Generation' ) )
		);
		$id = $this->product( 'Maxi-Cosi Fame New Generation' );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( 'arkid-catalogue-link__badge--info">Match available</span>', $html );
		$this->assertStringContainsString( 'Suggested viewer: Maxi-Cosi Fame - New Generation', $html );
		$this->assertStringContainsString( 'href="' . esc_url( (string) get_edit_post_link( $id, 'raw' ) ) . '#arkid-catalogue-link-metabox"', $html, 'a way to the product, where the viewer is chosen' );
		$this->assertStringNotContainsString( 'data-embed-url', $html );
	}

	public function test_it_suggests_a_catalogue_product_when_no_viewer_matches(): void {
		$this->stub_account( array(), array( $this->catalog_product( 'p1', 'Cybex', 'Priam' ) ) );
		$id = $this->product( 'Priam Kinderwagen' );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( 'Suggested from the ARkid catalogue: Cybex Priam', $html );
		$this->assertStringNotContainsString( 'Suggested viewer', $html );
	}

	public function test_the_brand_of_the_product_counts_towards_the_match(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame New Generation Special Edition Two' ) ) );
		$without_brand = $this->product( 'Fame' );
		$with_brand    = $this->product( 'Fame' );
		wp_set_object_terms( $with_brand, array( 'Maxi-Cosi' ), 'product_brand' );
		$column = $this->column();

		// "Fame" is a sixth of the viewer's name; with its brand it is three words of eight.
		$this->assertStringContainsString( 'screen-reader-text">No viewer<', $column->cell( $without_brand ) );
		$this->assertStringContainsString( 'Suggested viewer: Maxi-Cosi Fame New Generation Special Edition Two', $column->cell( $with_brand ) );
	}

	public function test_a_private_product_is_matched_by_its_own_title(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Bugaboo', 'Fox 5' ) ) );
		$id = $this->product( 'Fox 5', '', array(), 'private' );

		$this->assertStringContainsString( 'Suggested viewer: Bugaboo Fox 5', $this->column()->cell( $id ), 'not "Private: Fox 5"' );
	}

	public function test_a_product_nothing_matches_shows_a_dash_a_screen_reader_can_name(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ) ), array( $this->catalog_product( 'p1', 'Cybex', 'Priam' ) ) );
		$id = $this->product( 'Garden hose' );

		$html = $this->column()->cell( $id );

		$this->assertStringContainsString( 'aria-hidden="true">&ndash;</span>', $html );
		$this->assertStringContainsString( 'screen-reader-text">No viewer</span>', $html );
		$this->assertStringNotContainsString( 'Suggested', $html );
	}

	public function test_a_thumbnail_that_is_not_https_is_not_shown(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame', 1, array( 'image' => 'http://cloud.arkid.app/covers/insecure.jpg' ) ) ) );
		$id = $this->product( 'Fame', 'viewer-a' );

		$html = $this->column()->cell( $id );

		$this->assertStringNotContainsString( 'insecure.jpg', $html, 'esc_url() alone would let http through' );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_names_from_arkid_are_escaped_and_addresses_must_be_https(): void {
		$this->stub_account(
			array(
				$this->viewer(
					'viewer-a',
					'<img src=x onerror=alert(1)>',
					'"><script>alert(2)</script>',
					9,
					array( 'image' => 'javascript:alert(3)' )
				),
			)
		);
		$id = $this->linked( 'Hostile', 'viewer-a' );

		$html = $this->column()->cell( $id );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'alert(3)', $html, 'a thumbnail that is not https is not shown' );
	}

	public function test_a_preview_address_that_is_not_an_arkid_host_is_not_offered(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame', 1, array( 'embed_url' => 'https://evil.example.com/embed/x' ) ) ) );
		$id = $this->linked( 'Fame', 'viewer-a' );

		$html = $this->column()->cell( $id );

		$this->assertStringNotContainsString( 'evil.example.com', $html );
		$this->assertStringNotContainsString( 'data-embed-url', $html );
		$this->assertStringContainsString( '#1 Maxi-Cosi Fame', $html, 'the name still shows' );
	}

	public function test_only_a_store_manager_gets_the_cell(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ) ) );
		$id     = $this->linked( 'Fame', 'viewer-a' );
		$column = $this->column();

		$shown = $this->render( fn() => $column->render_column( 'arkid', $id ) );
		$this->assertStringContainsString( 'Maxi-Cosi Fame', $shown );
		$this->assertSame( '', $this->render( fn() => $column->render_column( 'price', $id ) ), 'another column' );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->render( fn() => $column->render_column( 'arkid', $id ) ) );
	}
}
