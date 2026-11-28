<?php
/**
 * The ARkid views, dropdowns and filters of the Products list.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\ProductListFilter;
use Arkid\CatalogueLink\Admin\ProductListViews;
use Arkid\CatalogueLink\Links\AccountData;
use Arkid\CatalogueLink\Links\ProductMatches;
use Arkid\CatalogueLink\Links\ProductScan;
use Arkid\CatalogueLink\Links\ProductStates;
use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;

final class ProductListViewsTest extends ProductListTestCase {

	private function views( int $scan_limit = ProductScan::LIMIT, ?AccountData $account = null ): ProductListViews {
		$account ??= $this->account();

		return new ProductListViews( $account, new ProductStates(), new ProductMatches( new ProductScan( $scan_limit ), $account ) );
	}

	/**
	 * The views as the list prints them: the core ones first, ours after.
	 *
	 * @return array<string, string>
	 */
	private function view_links( ProductListViews $views ): array {
		$all = $views->add_views( array( 'all' => '<a href="edit.php?post_type=product">All <span class="count">(9)</span></a>' ) );

		return is_array( $all ) ? $all : array();
	}

	private function ask( string $state, string $embed_id = '' ): void {
		$_GET = array( ProductListViews::QUERY_VAR => $state );
		if ( '' !== $embed_id ) {
			$_GET[ ProductLinks::QUERY_VAR ] = $embed_id;
		}
		$_REQUEST = $_GET;
	}

	/**
	 * Run the list's query the way WordPress does.
	 *
	 * @return array<int, string> The titles listed, in title order.
	 */
	private function listed( ProductListViews $views ): array {
		( new ProductListFilter( new ProductLinks() ) )->register();
		add_action( 'pre_get_posts', array( $views, 'narrow' ) );

		$query                   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['wp_query']     = $query;
		$posts                   = $query->query(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		return array_map( static fn( \WP_Post $post ): string => $post->post_title, $posts );
	}

	public function tear_down(): void {
		remove_all_actions( 'pre_get_posts' );
		parent::tear_down();
	}

	private function shop(): void {
		$this->stub_account(
			array(
				$this->viewer( 'viewer-fame', 'Maxi-Cosi', 'Fame - New Generation', 3 ),
				$this->viewer( 'viewer-fox', 'Bugaboo', 'Fox 5', 2 ),
			),
			array( $this->catalog_product( 'p-priam', 'Cybex', 'Priam' ) )
		);
		$this->linked( 'Fox stroller', 'viewer-fox' );                    // In order.
		$this->linked( 'Fame stroller', 'viewer-fame' );                  // In order.
		$this->linked( 'Deleted upstream', 'viewer-gone' );               // Unknown to the account.
		$this->product( 'Not synced yet', 'viewer-fame' );                // No snapshot.
		$this->product( 'Maxi-Cosi Fame New Generation' );                // A viewer matches.
		$this->product( 'Priam Kinderwagen' );                            // The catalogue matches.
		$this->product( 'Garden hose' );                                  // Nothing matches.
	}

	public function test_the_views_come_after_the_core_ones_with_their_counts(): void {
		$this->shop();

		$views = $this->view_links( $this->views() );

		$this->assertSame( array( 'all', 'arkid_with', 'arkid_without', 'arkid_match', 'arkid_attention' ), array_keys( $views ) );
		$this->assertStringContainsString( '>With viewer <span class="count">(4)</span>', $views['arkid_with'] );
		$this->assertStringContainsString( '>Without viewer <span class="count">(3)</span>', $views['arkid_without'] );
		$this->assertStringContainsString( '>Match available <span class="count">(2)</span>', $views['arkid_match'] );
		$this->assertStringContainsString( '>Needs attention <span class="count">(2)</span>', $views['arkid_attention'], 'the deleted viewer and the one not synced' );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'edit.php?post_type=product&arkid=attention' ) ) . '"', $views['arkid_attention'] );
		$this->assertStringNotContainsString( 'current', $views['arkid_with'] );
	}

	public function test_the_view_that_is_asked_for_is_the_current_one(): void {
		$this->shop();
		$this->ask( 'match' );

		$views = $this->view_links( $this->views() );

		$this->assertStringContainsString( 'class="current" aria-current="page"', $views['arkid_match'] );
		$this->assertStringNotContainsString( 'aria-current', $views['arkid_with'] );
	}

	public function test_the_match_count_has_a_plus_when_the_scan_stopped_early(): void {
		$this->shop();

		// Two of the three products without a viewer are read, the first by id.
		$views = $this->view_links( $this->views( 2 ) );

		$this->assertStringContainsString( '(2+)', $views['arkid_match'] );
		$this->assertStringContainsString( '(3)', $views['arkid_without'], 'counted by SQL, so it is exact' );
	}

	public function test_the_account_is_read_once_for_the_views_the_dropdowns_and_the_notice(): void {
		$this->shop();
		$views = $this->views();

		$this->view_links( $views );
		$this->render( fn() => $views->print_dropdowns( 'product', 'top' ) );
		$this->render( array( $views, 'notice' ) );

		$this->assertCount( 1, $this->calls_to( '/api/ecom/embed' ) );
		$this->assertCount( 1, $this->calls_to( '/api/ecom/product' ) );
	}

	public function test_when_arkid_cannot_answer_the_list_still_has_the_views_it_can_count_and_says_why(): void {
		$this->stub_http_status( '/api/ecom/embed', 500 );
		$this->linked( 'In order', 'viewer-a' );
		$this->product( 'Waiting', 'viewer-b' );
		$this->product( 'Plain' );
		$views = $this->views();

		$links = $this->view_links( $views );

		$this->assertSame( array( 'all', 'arkid_with', 'arkid_without', 'arkid_attention' ), array_keys( $links ), 'no match view without the account' );
		$this->assertStringContainsString( '(2)', $links['arkid_with'] );
		$this->assertStringContainsString( '(1)', $links['arkid_without'] );
		$this->assertStringContainsString( '(1)', $links['arkid_attention'], 'what is stored says so' );

		$notice = $this->render( array( $views, 'notice' ) );
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( 'Could not reach the ARkid API', $notice );
		$this->assertStringContainsString( 'the "Match available" view and the suggestions are left out', html_entity_decode( $notice ) );
	}

	public function test_a_rejected_key_is_named(): void {
		$this->stub_http_status( '/api/ecom/embed', 403 );
		$views = $this->views();

		$this->assertStringContainsString( 'The API key was rejected', $this->render( array( $views, 'notice' ) ) );
	}

	public function test_when_only_the_catalogue_fails_the_viewers_still_count_and_the_match_view_is_left_out(): void {
		$this->stub_http( '/api/ecom/embed', array( 'data' => array( $this->viewer( 'viewer-a', 'Maxi-Cosi', 'Fame' ) ) ) );
		$this->stub_http_status( '/api/ecom/product', 500 );
		$this->linked( 'In order', 'viewer-a' );
		$this->product( 'Maxi-Cosi Fame' );
		$views = $this->views();

		$links = $this->view_links( $views );

		$this->assertArrayNotHasKey( 'arkid_match', $links );
		$this->assertStringContainsString( '(0)', $links['arkid_attention'], 'the account is known, and lists the viewer' );
		$notice = html_entity_decode( $this->render( array( $views, 'notice' ) ) );
		$this->assertStringContainsString( 'The "Match available" view is left out, and suggestions only use your viewers.', $notice );
	}

	public function test_without_an_api_key_nothing_is_asked_and_no_notice_is_added(): void {
		delete_option( Options::OPTION_KEY );
		$this->product( 'Plain' );
		$this->product( 'Linked', 'viewer-a' );
		$views = $this->views();

		$links = $this->view_links( $views );

		$this->assertSame( array( 'all', 'arkid_with', 'arkid_without', 'arkid_attention' ), array_keys( $links ) );
		$this->assertSame( '', $this->render( array( $views, 'notice' ) ), 'the missing key has a notice of its own' );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_nothing_is_added_for_someone_who_cannot_manage_the_shop(): void {
		$this->shop();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$views = $this->views();

		$this->assertSame( array( 'all' ), array_keys( $this->view_links( $views ) ) );
		$this->assertSame( '', $this->render( fn() => $views->print_dropdowns( 'product', 'top' ) ) );
		$this->assertSame( '', $this->render( array( $views, 'notice' ) ) );
		$this->assertSame( array(), $this->http_calls );

		$this->ask( 'with' );
		$this->assertCount( 7, $this->listed( $views ), 'a filter the person cannot see does not narrow the list' );
	}

	public function test_nothing_is_added_to_other_screens(): void {
		$this->shop();
		set_current_screen( 'edit-post' );
		$views = $this->views();

		$this->assertSame( array( 'all' ), array_keys( $this->view_links( $views ) ) );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_each_view_narrows_the_list_to_its_products(): void {
		$this->shop();
		$views = $this->views();

		$this->ask( 'with' );
		$this->assertSame( array( 'Deleted upstream', 'Fame stroller', 'Fox stroller', 'Not synced yet' ), $this->listed( $views ) );

		$this->ask( 'without' );
		$this->assertSame( array( 'Garden hose', 'Maxi-Cosi Fame New Generation', 'Priam Kinderwagen' ), $this->listed( $views ) );

		$this->ask( 'match' );
		$this->assertSame( array( 'Maxi-Cosi Fame New Generation', 'Priam Kinderwagen' ), $this->listed( $views ) );

		$this->ask( 'attention' );
		$this->assertSame( array( 'Deleted upstream', 'Not synced yet' ), $this->listed( $views ) );
	}

	public function test_a_value_that_is_no_view_is_ignored(): void {
		$this->shop();
		$this->ask( 'everything' );
		$views = $this->views();

		$this->assertCount( 7, $this->listed( $views ) );
		$this->assertSame( array(), $this->http_calls, 'and nothing is read for it' );
	}

	public function test_a_view_and_a_viewer_narrow_the_list_together(): void {
		$this->shop();
		$views = $this->views();

		$this->ask( 'attention', 'viewer-fame' );
		$this->assertSame( array( 'Not synced yet' ), $this->listed( $views ) );

		$this->ask( 'without', 'viewer-fame' );
		$this->assertSame( array(), $this->listed( $views ), 'no product has a viewer and none' );
	}

	public function test_the_match_view_lists_nothing_when_the_account_cannot_be_read(): void {
		$this->stub_http_status( '/api/ecom/embed', 500 );
		$this->product( 'Maxi-Cosi Fame New Generation' );
		$this->ask( 'match' );
		$views = $this->views();

		$this->assertSame( array(), $this->listed( $views ), 'it cannot say which products match, and does not pretend that all do' );
		$this->assertStringContainsString( 'Could not reach the ARkid API', $this->render( array( $views, 'notice' ) ) );
	}

	public function test_the_match_view_says_when_it_compared_only_the_first_products(): void {
		$this->shop();
		$this->ask( 'match' );
		$views = $this->views( 2 );

		$notice = $this->render( array( $views, 'notice' ) );

		$this->assertStringContainsString( 'notice-info', $notice );
		$this->assertStringContainsString( 'Only the first 2 products without a viewer', $notice );
		$this->assertSame( '', $this->render( array( $this->views(), 'notice' ) ), 'nothing to say when the scan was whole' );
	}

	public function test_the_views_narrow_next_to_the_filters_other_plugins_set(): void {
		$this->shop();
		$views = $this->views();
		add_action(
			'pre_get_posts',
			static function ( \WP_Query $query ): void {
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => '_sku',
							'value'   => 'SKU-1',
							'compare' => '=',
						),
					)
				);
			},
			1
		);
		$sku = $this->product( 'With SKU' );
		update_post_meta( $sku, '_sku', 'SKU-1' );
		$other = $this->product( 'Other with SKU', 'viewer-fox' );
		update_post_meta( $other, '_sku', 'SKU-1' );

		$this->ask( 'without' );

		$this->assertSame( array( 'With SKU' ), $this->listed( $views ) );
	}

	public function test_the_dropdowns_offer_the_views_and_the_viewers_in_use(): void {
		$this->shop();
		$this->ask( 'attention', 'viewer-fame' );
		$views = $this->views();

		$html = $this->render( fn() => $views->print_dropdowns( 'product', 'top' ) );

		$this->assertStringContainsString( '<select name="arkid" id="arkid-catalogue-link-state">', $html );
		$this->assertStringContainsString( '>ARkid: All products</option>', $html );
		$this->assertStringContainsString( '<option value="with">ARkid: With viewer</option>', $html );
		$this->assertStringContainsString( '<option value="match">ARkid: Match available</option>', $html );
		$this->assertStringContainsString( '<option value="attention" selected=\'selected\'>ARkid: Needs attention</option>', $html );
		$this->assertStringContainsString( '<select name="arkid_embed" id="arkid-catalogue-link-embed">', $html );
		$this->assertStringContainsString( '>ARkid: Any viewer</option>', $html );
		$this->assertStringContainsString( '<option value="viewer-fame" selected=\'selected\'>Maxi-Cosi Fame - New Generation (2 products)</option>', $html );
		$this->assertStringContainsString( '>Bugaboo Fox 5 (1 product)</option>', $html );
		$this->assertStringContainsString( '>viewer-gone (1 product)</option>', $html, 'a viewer the account does not list is named by its id' );
		$this->assertStringContainsString( 'class="screen-reader-text" for="arkid-catalogue-link-state"', $html );
	}

	public function test_the_dropdowns_are_printed_on_top_of_the_products_list_only(): void {
		$this->shop();
		$views = $this->views();

		$this->assertSame( '', $this->render( fn() => $views->print_dropdowns( 'product', 'bottom' ) ) );
		$this->assertSame( '', $this->render( fn() => $views->print_dropdowns( 'post', 'top' ) ) );
		$this->assertNotSame( '', $this->render( fn() => $views->print_dropdowns( 'product', 'top' ) ) );
	}

	public function test_the_dropdowns_leave_out_a_view_that_cannot_be_counted(): void {
		$this->stub_http_status( '/api/ecom/embed', 500 );
		$this->product( 'Plain' );
		$views = $this->views();

		$html = $this->render( fn() => $views->print_dropdowns( 'product', 'top' ) );

		$this->assertStringContainsString( 'value="with"', $html );
		$this->assertStringContainsString( 'value="attention"', $html );
		$this->assertStringNotContainsString( 'value="match"', $html );
		$this->assertStringNotContainsString( 'arkid-catalogue-link-embed', $html, 'no product shows a viewer' );
	}

	public function test_a_viewer_name_from_arkid_is_escaped_in_the_dropdown(): void {
		$this->stub_account( array( $this->viewer( 'viewer-a', '<script>alert(1)</script>', 'Fame "x"' ) ) );
		$this->linked( 'Fame', 'viewer-a' );
		$views = $this->views();

		$html = $this->render( fn() => $views->print_dropdowns( 'product', 'top' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; Fame &quot;x&quot; (1 product)', $html );
	}

	public function test_the_hooks_are_those_of_the_products_list(): void {
		$views = $this->views();
		$views->register();

		$this->assertNotFalse( has_filter( 'views_edit-product', array( $views, 'add_views' ) ) );
		$this->assertNotFalse( has_action( 'restrict_manage_posts', array( $views, 'print_dropdowns' ) ) );
		$this->assertNotFalse( has_action( 'pre_get_posts', array( $views, 'narrow' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( $views, 'notice' ) ) );
		$this->assertSame( PostMeta::POST_TYPE, 'product' );
	}
}
