<?php
/**
 * The viewers screen's list table: views, filters, search, paging, columns.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\ViewersListTable;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Links\LinkedProduct;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ViewersTableTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The table prints screen-reader headings through the current screen.
		$GLOBALS['hook_suffix'] = 'woocommerce_page_arkid-viewers';
		set_current_screen( 'woocommerce_page_arkid-viewers' );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_REQUEST = array();
		unset( $GLOBALS['hook_suffix'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function embed( int $row, array $extra = array() ): EmbedDto {
		return EmbedDto::from_array(
			array_merge(
				array(
					'embed_id'      => 'embed-' . $row,
					'row_number'    => $row,
					'brand_name'    => 'Brand ' . $row,
					'product_name'  => 'Product ' . $row,
					'type'          => 'pdp',
					'created'       => '2026-01-01 10:00:00',
					'modified'      => '2026-01-02 10:00:00',
					'embed_url'     => 'https://catalogue.arkid.app/embed/xyz' . $row . '?source=woocommerce',
					'edit_url'      => 'https://catalogue.arkid.app/configure/edit/xyz' . $row,
					'analytics_url' => 'https://catalogue.arkid.app/analytics/xyz' . $row,
					'image'         => 'https://cloud.arkid.app/covers/' . $row . '.webp',
				),
				$extra
			)
		);
	}

	/**
	 * @param array<int, EmbedDto>                     $rows
	 * @param array<string, array<int, LinkedProduct>> $linked
	 * @param array<string, int>|null                  $interactions
	 */
	private function table( array $rows, array $linked = array(), ?array $interactions = array(), bool $preview = true ): ViewersListTable {
		return new ViewersListTable(
			$rows,
			$linked,
			$interactions,
			$preview ? new IframeFactory( new Options() ) : null
		);
	}

	/**
	 * @param array<int, EmbedDto> $rows
	 *
	 * @return array<int, int> Row numbers in table order, for the current request.
	 */
	private function shown_rows( ViewersListTable $table ): array {
		$table->prepare_items();

		return array_map( static fn( EmbedDto $e ): int => $e->row_number, $table->items );
	}

	/**
	 * @param array<string, string> $query
	 */
	private function request( array $query ): void {
		$_GET     = $query;
		$_REQUEST = $query;
	}

	private function product( int $id, string $title = 'Chair', string $status = 'publish' ): LinkedProduct {
		return new LinkedProduct( $id, $title, $status );
	}

	/**
	 * @return array<int, string>
	 */
	private function view_texts( ViewersListTable $table ): array {
		$table->prepare_items();
		$method = new \ReflectionMethod( $table, 'get_views' );
		$method->setAccessible( true );

		return array_map(
			static fn( string $html ): string => trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) ),
			array_values( (array) $method->invoke( $table ) )
		);
	}

	private function html( ViewersListTable $table ): string {
		$table->prepare_items();

		return $this->render(
			static function () use ( $table ): void {
				$table->views();
				$table->search_box( 'Search viewers', 'arkid-viewers' );
				$table->display();
			}
		);
	}

	// ---- Views (the tiles) ------------------------------------------------

	public function test_the_views_count_all_live_unlinked_and_quiet_viewers(): void {
		$rows   = array( $this->embed( 1 ), $this->embed( 2 ), $this->embed( 3 ), $this->embed( 4 ) );
		$linked = array(
			'embed-1' => array( $this->product( 11 ), $this->product( 12 ) ),
			'embed-2' => array( $this->product( 13 ) ),
		);
		// Viewer 1 was interacted with. 2 was listed without interactions; 3 and 4 had no traffic at all.
		$interactions = array(
			'embed-1' => 5,
			'embed-2' => 0,
		);

		$this->assertSame(
			array( 'All (4)', 'Live on a product (2)', 'Not linked (2)', 'No interactions, 30 days (3)' ),
			$this->view_texts( $this->table( $rows, $linked, $interactions ) )
		);
	}

	public function test_a_view_selects_its_viewers(): void {
		$rows   = array( $this->embed( 1 ), $this->embed( 2 ), $this->embed( 3 ), $this->embed( 4 ) );
		$linked = array(
			'embed-1' => array( $this->product( 11 ) ),
			'embed-2' => array( $this->product( 12 ) ),
		);
		$stats  = array( 'embed-1' => 9 );

		$this->request( array( 'show' => 'live' ) );
		$this->assertSame( array( 1, 2 ), $this->shown_rows( $this->table( $rows, $linked, $stats ) ) );

		$this->request( array( 'show' => 'unlinked' ) );
		$this->assertSame( array( 3, 4 ), $this->shown_rows( $this->table( $rows, $linked, $stats ) ) );

		$this->request( array( 'show' => 'quiet' ) );
		$this->assertSame( array( 2, 3, 4 ), $this->shown_rows( $this->table( $rows, $linked, $stats ) ) );

		$this->request( array( 'show' => 'nonsense' ) );
		$this->assertSame( array( 1, 2, 3, 4 ), $this->shown_rows( $this->table( $rows, $linked, $stats ) ) );
	}

	/**
	 * ARkid lists a viewer in its stats only if it was viewed in the period. A
	 * viewer that is not listed has had no interactions, and must be counted and
	 * shown as quiet; one that is listed with interactions must not be.
	 */
	public function test_a_viewer_without_traffic_is_quiet_and_one_with_interactions_is_not(): void {
		$rows = array( $this->embed( 1 ), $this->embed( 2 ) );
		// The list spells the id in lower case; the stats may not.
		$table = $this->table( $rows, array(), array( 'embed-1' => 7 ) );

		$this->request( array( 'show' => 'quiet' ) );
		$this->assertSame( array( 2 ), $this->shown_rows( $table ) );

		$this->request( array() );
		$html = $this->html( $this->table( $rows, array(), array( 'embed-1' => 7 ) ) );
		$this->assertSame( 1, preg_match( "/column-interactions' data-colname=\"[^\"]*\">7</", $html ) );
		$this->assertSame( 1, preg_match( "/column-interactions' data-colname=\"[^\"]*\">0</", $html ) );
	}

	/**
	 * Without the stats a count of zero would claim that ARkid had been asked and
	 * had said "none": the column and the quiet view are left out instead.
	 */
	public function test_without_the_stats_the_interactions_column_and_quiet_view_are_left_out(): void {
		$table = $this->table( array( $this->embed( 1 ), $this->embed( 2 ) ), array(), null );

		$this->assertArrayNotHasKey( 'interactions', $table->get_columns() );
		$this->assertArrayNotHasKey( 'interactions', $table->get_sortable_columns() );
		$this->assertSame(
			array( 'All (2)', 'Live on a product (0)', 'Not linked (2)' ),
			$this->view_texts( $table )
		);

		// A bookmark of the quiet view shows everything rather than nothing.
		$this->request( array( 'show' => 'quiet' ) );
		$this->assertSame( array( 1, 2 ), $this->shown_rows( $this->table( array( $this->embed( 1 ), $this->embed( 2 ) ), array(), null ) ) );
	}

	public function test_the_selected_view_is_marked_for_assistive_technology(): void {
		$this->request( array( 'show' => 'live' ) );
		$table = $this->table( array( $this->embed( 1 ) ), array( 'embed-1' => array( $this->product( 11 ) ) ) );

		$html = $this->html( $table );

		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertMatchesRegularExpression( '/class="current" aria-current="page">Live on a product/', $html );
	}

	// ---- Search and filters -----------------------------------------------

	public function test_search_matches_every_word_across_product_brand_note_and_number(): void {
		$rows = array(
			$this->embed( 1, array( 'brand_name' => 'Felix', 'product_name' => 'Learning Tower' ) ),
			$this->embed( 2, array( 'brand_name' => 'Felix', 'product_name' => 'High Chair', 'internal_note' => 'Spring campaign' ) ),
			$this->embed( 3, array( 'brand_name' => 'Müller', 'product_name' => 'Stroller' ) ),
		);

		$this->request( array( 's' => 'felix learning' ) );
		$this->assertSame( array( 1 ), $this->shown_rows( $this->table( $rows ) ) );

		$this->request( array( 's' => 'campaign felix' ) );
		$this->assertSame( array( 2 ), $this->shown_rows( $this->table( $rows ) ) );

		$this->request( array( 's' => 'muller' ) );
		$this->assertSame( array( 3 ), $this->shown_rows( $this->table( $rows ) ) );

		$this->request( array( 's' => '#3' ) );
		$this->assertSame( array( 3 ), $this->shown_rows( $this->table( $rows ) ) );
	}

	public function test_search_and_a_view_narrow_each_other(): void {
		$rows   = array(
			$this->embed( 1, array( 'brand_name' => 'Felix' ) ),
			$this->embed( 2, array( 'brand_name' => 'Felix' ) ),
		);
		$linked = array( 'embed-1' => array( $this->product( 11 ) ) );

		$this->request( array( 's' => 'felix', 'show' => 'unlinked' ) );

		$this->assertSame( array( 2 ), $this->shown_rows( $this->table( $rows, $linked ) ) );
	}

	public function test_the_type_and_brand_filters_select_their_viewers(): void {
		$rows = array(
			$this->embed( 1, array( 'type' => 'pdp', 'brand_name' => 'Felix' ) ),
			$this->embed( 2, array( 'type' => 'exp', 'brand_name' => 'Felix' ) ),
			$this->embed( 3, array( 'type' => 'pdp', 'brand_name' => 'Acme' ) ),
		);

		$this->request( array( 'viewer_type' => 'pdp' ) );
		$this->assertSame( array( 1, 3 ), $this->shown_rows( $this->table( $rows ) ) );

		$this->request( array( 'viewer_brand' => 'Felix' ) );
		$this->assertSame( array( 1, 2 ), $this->shown_rows( $this->table( $rows ) ) );

		$this->request( array( 'viewer_type' => 'pdp', 'viewer_brand' => 'Felix' ) );
		$this->assertSame( array( 1 ), $this->shown_rows( $this->table( $rows ) ) );
	}

	/**
	 * A value the dropdown does not offer would otherwise empty the list with no
	 * way to see why.
	 */
	public function test_a_filter_value_that_is_not_offered_is_ignored(): void {
		$rows = array( $this->embed( 1 ), $this->embed( 2 ) );

		$this->request( array( 'viewer_type' => 'gone', 'viewer_brand' => 'Nobody' ) );

		$this->assertSame( array( 1, 2 ), $this->shown_rows( $this->table( $rows ) ) );
	}

	public function test_the_filter_dropdowns_offer_each_type_and_brand_once_and_keep_the_selection(): void {
		$rows = array(
			$this->embed( 1, array( 'type' => 'pdp', 'brand_name' => 'Zeta' ) ),
			$this->embed( 2, array( 'type' => 'exp', 'brand_name' => 'alpha' ) ),
			$this->embed( 3, array( 'type' => 'pdp', 'brand_name' => 'Zeta' ) ),
		);
		$this->request( array( 'viewer_brand' => 'Zeta' ) );

		$html = $this->html( $this->table( $rows ) );

		$this->assertSame( 1, substr_count( $html, '<option value="pdp"' ) );
		$this->assertSame( 1, substr_count( $html, '<option value="Zeta" selected=\'selected\'' ) + substr_count( $html, '<option value="Zeta" selected="selected"' ) );
		$this->assertLessThan( strpos( $html, '>Zeta</option>' ), strpos( $html, '>alpha</option>' ), 'Brands are listed without regard to case.' );
		$this->assertStringContainsString( 'All types', $html );
		$this->assertStringContainsString( 'All brands', $html );
		$this->assertStringContainsString( 'for="arkid-viewers-viewer_type"', $html, 'Each dropdown has a label.' );
	}

	// ---- Paging -----------------------------------------------------------

	/**
	 * @return array<int, EmbedDto>
	 */
	private function many( int $count ): array {
		$rows = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = $this->embed( $i );
		}

		return $rows;
	}

	public function test_a_page_holds_twenty_viewers_unless_the_user_chose_otherwise(): void {
		$table = $this->table( $this->many( 45 ) );

		$this->assertCount( 20, $this->shown_rows( $table ) );
		$this->assertSame( 45, $table->get_pagination_arg( 'total_items' ) );
		$this->assertSame( 3, $table->get_pagination_arg( 'total_pages' ) );
	}

	public function test_the_per_page_screen_option_sets_the_page_size_and_the_page_is_followed(): void {
		update_user_option( get_current_user_id(), 'arkid_viewers_per_page', 4 );

		$this->request( array( 'paged' => '2' ) );
		$rows = $this->shown_rows( $this->table( $this->many( 10 ) ) );
		$this->assertSame( array( 5, 6, 7, 8 ), $rows );

		$this->request( array( 'paged' => '3' ) );
		$this->assertSame( array( 9, 10 ), $this->shown_rows( $this->table( $this->many( 10 ) ) ) );
	}

	public function test_a_page_past_the_end_is_the_last_page(): void {
		update_user_option( get_current_user_id(), 'arkid_viewers_per_page', 4 );
		$this->request( array( 'paged' => '99' ) );

		$this->assertSame( array( 9, 10 ), $this->shown_rows( $this->table( $this->many( 10 ) ) ) );
	}

	public function test_paging_counts_the_filtered_viewers_not_all_of_them(): void {
		update_user_option( get_current_user_id(), 'arkid_viewers_per_page', 3 );
		$rows = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$rows[] = $this->embed( $i, array( 'type' => 0 === $i % 2 ? 'exp' : 'pdp' ) );
		}
		$this->request( array( 'viewer_type' => 'exp' ) );

		$table = $this->table( $rows );

		$this->assertSame( array( 2, 4, 6 ), $this->shown_rows( $table ) );
		$this->assertSame( 5, $table->get_pagination_arg( 'total_items' ) );
		$this->assertSame( 2, $table->get_pagination_arg( 'total_pages' ) );
	}

	// ---- Columns ----------------------------------------------------------

	/**
	 * Without a primary column WordPress takes the first one, the thumbnail, and
	 * the table cannot collapse on a phone.
	 */
	public function test_the_product_column_is_primary_so_the_table_collapses_on_mobile(): void {
		$table = $this->table( array( $this->embed( 1 ) ) );
		$html  = $this->html( $table );

		list( , , , $primary ) = $table->get_column_info();
		$this->assertSame( 'product_name', $primary );
		$this->assertMatchesRegularExpression( '/column-product_name column-primary/', $html );
		$this->assertStringContainsString( 'class="toggle-row"', $html );
		$this->assertStringContainsString( 'data-colname="Brand"', $html, 'The collapsed table labels each value.' );
	}

	public function test_created_starts_hidden_and_the_other_columns_show(): void {
		$this->assertSame( array( 'created' ), ViewersListTable::default_hidden_columns() );
		$this->assertArrayHasKey( 'created', ViewersListTable::all_columns() );
		$this->assertSame(
			array( 'image', 'row_number', 'product_name', 'brand_name', 'type', 'internal_note', 'shown_on', 'colors', 'interactions', 'views', 'modified', 'created', 'actions' ),
			array_keys( ViewersListTable::all_columns() )
		);
	}

	public function test_new_columns_sort(): void {
		$rows = array(
			$this->embed( 1, array( 'views' => 50, 'modified' => '2026-03-01 00:00:00', 'type' => 'pdp', 'internal_note' => 'b' ) ),
			$this->embed( 2, array( 'views' => 5, 'modified' => '2026-01-01 00:00:00', 'type' => 'asm', 'internal_note' => 'C' ) ),
			$this->embed( 3, array( 'views' => 500, 'modified' => '2026-02-01 00:00:00', 'type' => 'exp', 'internal_note' => 'a' ) ),
		);
		$linked       = array(
			'embed-1' => array( $this->product( 11 ) ),
			'embed-3' => array( $this->product( 12 ), $this->product( 13 ) ),
		);
		$interactions = array( 'embed-2' => 40, 'embed-1' => 3 );

		$order = function ( string $orderby, string $order ) use ( $rows, $linked, $interactions ): array {
			$this->request( array( 'orderby' => $orderby, 'order' => $order ) );

			return $this->shown_rows( $this->table( $rows, $linked, $interactions ) );
		};

		$this->assertSame( array( 2, 1, 3 ), $order( 'views', 'asc' ) );
		$this->assertSame( array( 3, 1, 2 ), $order( 'views', 'desc' ) );
		$this->assertSame( array( 2, 3, 1 ), $order( 'modified', 'asc' ) );
		$this->assertSame( array( 2, 3, 1 ), $order( 'type', 'asc' ), 'asm, exp, pdp' );
		$this->assertSame( array( 3, 1, 2 ), $order( 'internal_note', 'asc' ), 'a, b, C: without regard to case' );
		$this->assertSame( array( 2, 1, 3 ), $order( 'shown_on', 'asc' ), 'none, one, two products' );
		$this->assertSame( array( 3, 1, 2 ), $order( 'interactions', 'asc' ), '0, 3, 40' );
		$this->assertSame( array( 2, 1, 3 ), $order( 'interactions', 'desc' ), '40, 3, 0' );
	}

	public function test_interactions_cannot_be_sorted_when_there_are_none_to_sort(): void {
		$rows = array( $this->embed( 2 ), $this->embed( 1 ) );
		$this->request( array( 'orderby' => 'interactions', 'order' => 'desc' ) );

		$this->assertSame( array( 2, 1 ), $this->shown_rows( $this->table( $rows, array(), null ) ), 'Falls back to the row number, descending.' );
	}

	// ---- Cells ------------------------------------------------------------

	public function test_shown_on_lists_three_products_and_links_the_rest_to_the_products_list(): void {
		$ids      = array();
		$products = array();
		foreach ( array( 'Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo' ) as $title ) {
			$id         = (int) self::factory()->post->create( array( 'post_type' => 'product', 'post_title' => $title ) );
			$ids[]      = $id;
			$products[] = $this->product( $id, $title );
		}
		$table = $this->table( array( $this->embed( 1 ) ), array( 'embed-1' => $products ) );

		$html = $table->column_shown_on( $this->embed( 1 ) );

		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( 'Alpha', $html );
		$this->assertStringContainsString( 'Charlie', $html );
		$this->assertStringNotContainsString( 'Delta', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_edit_post_link( $ids[0], 'raw' ) ) . '"', $html );
		$this->assertStringContainsString( '+2 more', $html );
		$this->assertStringContainsString( 'edit.php?post_type=product&#038;arkid_embed=embed-1', $html );
	}

	public function test_shown_on_has_no_more_link_when_everything_is_listed(): void {
		$table = $this->table( array( $this->embed( 1 ) ), array( 'embed-1' => array( $this->product( 1, 'One' ), $this->product( 2, 'Two' ), $this->product( 3, 'Three' ) ) ) );

		$this->assertStringNotContainsString( 'more', $table->column_shown_on( $this->embed( 1 ) ) );
	}

	public function test_shown_on_says_when_a_viewer_is_not_linked(): void {
		$table = $this->table( array( $this->embed( 1 ) ) );

		$this->assertStringContainsString( 'Not linked', $table->column_shown_on( $this->embed( 1 ) ) );
	}

	public function test_shown_on_marks_a_product_that_is_not_published_and_names_one_without_a_title(): void {
		$draft = (int) self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Prototype' ) );
		$table = $this->table(
			array( $this->embed( 1 ) ),
			array( 'embed-1' => array( $this->product( $draft, 'Prototype', 'draft' ), $this->product( 0, '' ) ) )
		);

		$html = $table->column_shown_on( $this->embed( 1 ) );

		$this->assertStringContainsString( 'Prototype</a> <span class="arkid-catalogue-link__state">Draft</span>', $html );
		$this->assertStringContainsString( '(no title)', $html );
	}

	public function test_product_titles_are_escaped(): void {
		$table = $this->table( array( $this->embed( 1 ) ), array( 'embed-1' => array( $this->product( 0, '<script>alert(1)</script>' ) ) ) );

		$html = $table->column_shown_on( $this->embed( 1 ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_colour_dots_show_which_colours_the_viewer_shows(): void {
		$embed = $this->embed(
			1,
			array(
				'colors' => array(
					array( 'variant_id' => 'v1', 'name' => 'Copper', 'rgb' => '#b55f39', 'enabled' => true ),
					array( 'variant_id' => 'v2', 'name' => 'Graphite', 'rgb' => '#929498', 'enabled' => false ),
				),
			)
		);

		$html = $this->table( array( $embed ) )->column_colors( $embed );

		$this->assertStringContainsString( 'aria-label="Copper: shown"', $html );
		$this->assertStringContainsString( 'aria-label="Graphite: hidden"', $html );
		$this->assertSame( 1, substr_count( $html, 'is-hidden' ) );
	}

	public function test_views_are_the_lifetime_total(): void {
		$embed = $this->embed( 1, array( 'views' => 12345 ) );

		$this->assertSame( number_format_i18n( 12345 ), $this->table( array( $embed ) )->column_views( $embed ) );
		$this->assertSame( 'Total views', ViewersListTable::all_columns()['views'] );
	}

	/**
	 * ARkid writes UTC. An hour is an hour whatever the site's zone is.
	 */
	public function test_last_updated_is_relative_with_the_exact_time_and_the_author(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$modified = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
		$embed    = $this->embed( 1, array( 'modified' => $modified, 'author_name' => 'Jo Author' ) );

		$html = $this->table( array( $embed ) )->column_modified( $embed );

		$this->assertStringContainsString( '3 hours ago', $html );
		$this->assertStringContainsString( 'Jo Author', $html );
		$this->assertStringContainsString( 'datetime="' . gmdate( 'c', (int) strtotime( $modified . ' UTC' ) ) . '"', $html );
		// The tooltip is the time on this site: Tokyo is nine hours ahead.
		$exact = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $modified . ' UTC' ) );
		$this->assertStringContainsString( 'title="' . esc_attr( (string) $exact ) . '"', $html );

		delete_option( 'timezone_string' );
	}

	public function test_an_old_update_is_shown_as_a_date_and_a_date_alone_has_no_time(): void {
		$old   = $this->embed( 1, array( 'modified' => '2025-07-16 02:51:43' ) );
		$plain = $this->embed( 2, array( 'modified' => '2026-01-01' ) );
		$table = $this->table( array( $old, $plain ) );

		$this->assertMatchesRegularExpression( '/(year|years|month|months) ago/', $table->column_modified( $old ) );
		$this->assertStringNotContainsString( ' ago', $table->column_modified( $plain ), 'A date has no time of day to be relative to.' );
		$this->assertStringContainsString( '>' . wp_date( (string) get_option( 'date_format' ), (int) gmmktime( 0, 0, 0, 1, 1, 2026 ) ) . '<', $table->column_modified( $plain ) );
	}

	public function test_a_time_from_the_future_is_shown_as_a_date_not_as_negative_time(): void {
		$embed = $this->embed( 1, array( 'modified' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );

		$this->assertStringNotContainsString( ' ago', $this->table( array( $embed ) )->column_modified( $embed ) );
	}

	public function test_a_value_that_is_not_a_time_is_shown_as_it_came_and_escaped(): void {
		$embed = $this->embed( 1, array( 'modified' => '<b>soon</b>' ) );

		$html = $this->table( array( $embed ) )->column_modified( $embed );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;soon', $html );
	}

	// ---- Preview ----------------------------------------------------------

	public function test_the_name_and_the_thumbnail_open_the_preview_of_a_viewer_on_an_arkid_host(): void {
		$embed = $this->embed( 1, array( 'brand_name' => 'Felix', 'product_name' => 'Learning Tower' ) );
		$table = $this->table( array( $embed ) );

		$name  = $table->column_product_name( $embed );
		$thumb = $table->column_image( $embed );

		foreach ( array( $name, $thumb, $table->column_actions( $embed ) ) as $html ) {
			$this->assertStringContainsString( 'data-embed-url="https://catalogue.arkid.app/embed/xyz1?source=woocommerce"', $html );
			$this->assertStringContainsString( 'arkid-catalogue-link__preview', $html );
		}
		$this->assertStringContainsString( 'data-product-name="Felix Learning Tower"', $name );
		$this->assertStringContainsString( 'data-edit-url="https://catalogue.arkid.app/configure/edit/xyz1"', $name );
		$this->assertStringContainsString( 'data-analytics-url="https://catalogue.arkid.app/analytics/xyz1"', $name );
		$this->assertStringContainsString( 'aria-label="Preview Learning Tower"', $name );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $name );
	}

	/**
	 * The thumbnail is the mouse's way to the preview; the name beside it is the
	 * keyboard's and the screen reader's, so the thumbnail is not a second stop.
	 */
	public function test_the_thumbnail_button_is_not_a_second_tab_stop(): void {
		$embed = $this->embed( 1 );

		$thumb = $this->table( array( $embed ) )->column_image( $embed );

		$this->assertStringContainsString( 'tabindex="-1"', $thumb );
		$this->assertStringContainsString( 'aria-hidden="true"', $thumb );
		$this->assertStringContainsString( 'alt=""', $thumb );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function addresses_that_must_not_be_framed(): array {
		return array(
			'another host'       => array( 'https://evil.example/embed/x' ),
			'a lookalike host'   => array( 'https://catalogue.arkid.app.evil.example/embed/x' ),
			'plain http'         => array( 'http://catalogue.arkid.app/embed/x' ),
			'javascript'         => array( 'javascript:alert(1)' ),
			'data'               => array( 'data:text/html,<script>alert(1)</script>' ),
			'protocol-relative'  => array( '//catalogue.arkid.app/embed/x' ),
			'none'               => array( '' ),
		);
	}

	/**
	 * @dataProvider addresses_that_must_not_be_framed
	 */
	public function test_a_viewer_whose_address_is_not_an_arkid_host_cannot_be_previewed( string $url ): void {
		$embed = $this->embed( 1, array( 'embed_url' => $url ) );
		$table = $this->table( array( $embed ) );

		foreach ( array( $table->column_product_name( $embed ), $table->column_image( $embed ), $table->column_actions( $embed ) ) as $html ) {
			$this->assertStringNotContainsString( 'data-embed-url', $html );
			$this->assertStringNotContainsString( '<button', $html );
		}
		$this->assertStringContainsString( 'Product 1', $table->column_product_name( $embed ), 'The name is still shown.' );
		$this->assertStringContainsString( 'Edit', $table->column_actions( $embed ), 'The links into ARkid stay.' );
	}

	public function test_without_a_frame_factory_nothing_can_be_previewed(): void {
		$embed = $this->embed( 1 );

		$this->assertStringNotContainsString( 'data-embed-url', $this->table( array( $embed ), array(), array(), false )->column_product_name( $embed ) );
	}

	public function test_edit_and_analytics_stay_and_leave_in_a_new_tab_only_when_https(): void {
		$embed = $this->embed( 1 );
		$html  = $this->table( array( $embed ) )->column_actions( $embed );

		$this->assertStringContainsString( '<a href="https://catalogue.arkid.app/configure/edit/xyz1" target="_blank" rel="noopener">Edit</a>', $html );
		$this->assertStringContainsString( '<a href="https://catalogue.arkid.app/analytics/xyz1" target="_blank" rel="noopener">Analytics</a>', $html );

		$unsafe = $this->embed( 2, array( 'edit_url' => 'http://catalogue.arkid.app/x', 'analytics_url' => 'javascript:alert(1)' ) );
		$html   = $this->table( array( $unsafe ) )->column_actions( $unsafe );

		$this->assertStringNotContainsString( 'href=', $html );
	}

	public function test_a_thumbnail_must_be_an_https_image(): void {
		$http = $this->embed( 1, array( 'image' => 'http://cloud.arkid.app/x.webp' ) );

		$this->assertSame( '', $this->table( array( $http ) )->column_image( $http ) );
	}

	public function test_names_in_the_preview_attributes_are_escaped(): void {
		$embed = $this->embed( 1, array( 'brand_name' => '"><script>x</script>', 'product_name' => 'A & B' ) );

		$html = $this->table( array( $embed ) )->column_product_name( $embed );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'A &amp; B', $html );
	}

	// ---- Empty states -----------------------------------------------------

	public function test_an_account_without_viewers_and_a_filter_without_matches_say_different_things(): void {
		$empty = $this->html( $this->table( array() ) );
		$this->assertStringContainsString( 'No viewers found. Add a viewer in the ARkid Catalogue', $empty );
		$this->assertStringNotContainsString( 'Clear the filters', $empty );

		$this->request( array( 's' => 'zzz' ) );
		$none = $this->html( $this->table( array( $this->embed( 1 ) ) ) );
		$this->assertStringContainsString( 'No viewers match these filters.', $none );
		$this->assertStringContainsString( 'Clear the filters', $none );
		$this->assertStringContainsString( 'page=arkid-viewers"', $none );
	}

	public function test_the_filter_dropdowns_are_not_printed_for_an_account_without_viewers(): void {
		$this->assertStringNotContainsString( 'arkid-viewers-filter-submit', $this->html( $this->table( array() ) ) );
	}

	public function test_the_search_box_is_labelled_and_the_table_is_responsive(): void {
		$html = $this->html( $this->table( array( $this->embed( 1 ) ) ) );

		$this->assertStringContainsString( 'id="arkid-viewers-search-input"', $html );
		$this->assertStringContainsString( 'for="arkid-viewers-search-input"', $html );
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringNotContainsString( 'class="wp-list-table widefat fixed', $html, 'Fixed layout would squeeze the name columns.' );
	}
}
