<?php
/**
 * The Products list narrowed to the products of one viewer: the address the
 * viewers list's "+N more" opens.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\ProductListFilter;
use Arkid\CatalogueLink\Admin\Setup;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ProductListFilterTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		( new ProductListFilter( new ProductLinks() ) )->register();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->open_screen( 'edit-product' );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_REQUEST = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function open_screen( string $screen ): void {
		set_current_screen( $screen );
		$GLOBALS['hook_suffix'] = $screen;
	}

	private function product( string $title, string $embed_id, string $status = 'publish' ): int {
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
	 * Run the query of the Products list the way WordPress does: it is the main
	 * query of the request, and "All" holds every status but the trash.
	 *
	 * @param array<string, mixed> $query_vars
	 *
	 * @return array<int, string> The titles listed, in title order.
	 */
	private function listed( array $query_vars = array() ): array {
		$query                   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['wp_query']     = $query;

		$posts = $query->query(
			array_merge(
				array(
					'post_type'   => 'product',
					'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'orderby'     => 'title',
					'order'       => 'ASC',
				),
				$query_vars
			)
		);

		return array_map( static fn( \WP_Post $post ): string => $post->post_title, $posts );
	}

	private function ask_for( string $embed_id ): void {
		$_GET     = array( ProductLinks::QUERY_VAR => $embed_id );
		$_REQUEST = $_GET;
	}

	public function test_the_list_shows_only_the_products_of_the_viewer(): void {
		$this->product( 'Chair', 'viewer-a' );
		$this->product( 'Table', 'viewer-b' );
		$this->product( 'Lamp', 'viewer-a' );
		$this->product( 'Stool', '' );

		$this->ask_for( 'viewer-a' );

		$this->assertSame( array( 'Chair', 'Lamp' ), $this->listed() );
	}

	/**
	 * What the viewers list counts under "Shown on": a draft and a private product
	 * count, and so are listed with their status.
	 */
	public function test_the_list_holds_the_unpublished_products_the_viewers_list_counts(): void {
		$this->product( 'Public', 'viewer-a' );
		$this->product( 'Work in progress', 'viewer-a', 'draft' );
		$this->product( 'Members only', 'viewer-a', 'private' );
		$this->ask_for( 'viewer-a' );

		$this->assertSame( array( 'Members only', 'Public', 'Work in progress' ), $this->listed() );
	}

	public function test_the_viewer_id_is_matched_without_regard_to_case(): void {
		$this->product( 'Chair', 'ABCD-1234' );
		$this->ask_for( 'abcd-1234' );

		$this->assertSame( array( 'Chair' ), $this->listed() );
	}

	/**
	 * A viewer without products has none to show. Showing every product would
	 * say the opposite.
	 */
	public function test_a_viewer_nothing_shows_lists_nothing(): void {
		$this->product( 'Chair', 'viewer-a' );
		$this->ask_for( 'viewer-b' );

		$this->assertSame( array(), $this->listed() );
	}

	public function test_without_the_variable_the_list_is_untouched(): void {
		$this->product( 'Chair', 'viewer-a' );
		$this->product( 'Table', '' );

		$this->assertSame( array( 'Chair', 'Table' ), $this->listed() );
	}

	/**
	 * @dataProvider values_that_are_not_a_viewer_id
	 */
	public function test_a_value_that_cannot_be_a_viewer_id_is_ignored( string $value ): void {
		$this->product( 'Chair', 'viewer-a' );
		$this->product( 'Table', '' );
		$this->ask_for( $value );

		$this->assertSame( array( 'Chair', 'Table' ), $this->listed() );
		$this->assertSame( '', ProductListFilter::requested_embed_id() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function values_that_are_not_a_viewer_id(): array {
		return array(
			'empty'        => array( '' ),
			'a space'      => array( 'viewer a' ),
			'a quote'      => array( "viewer-a' OR '1'='1" ),
			'a wildcard'   => array( '%' ),
			'a path'       => array( '../../etc' ),
			'too long'     => array( str_repeat( 'a', 65 ) ),
		);
	}

	public function test_an_array_in_the_query_string_is_ignored(): void {
		$this->product( 'Chair', 'viewer-a' );
		$_GET = array( ProductLinks::QUERY_VAR => array( 'viewer-a' ) );

		$this->assertSame( '', ProductListFilter::requested_embed_id() );
		$this->assertSame( array( 'Chair' ), $this->listed() );
	}

	/**
	 * A search by SKU, for one, already holds the list to some products. The
	 * ones both ask for are shown, and no product the search left out is added.
	 */
	public function test_it_keeps_the_restriction_another_filter_has_set(): void {
		$chair = $this->product( 'Chair', 'viewer-a' );
		$this->product( 'Lamp', 'viewer-a' );
		$table = $this->product( 'Table', 'viewer-b' );
		$this->ask_for( 'viewer-a' );

		$this->assertSame( array( 'Chair' ), $this->listed( array( 'post__in' => array( $chair, $table ) ) ) );
		$this->assertSame( array(), $this->listed( array( 'post__in' => array( $table ) ) ) );
	}

	public function test_other_queries_are_not_narrowed(): void {
		$this->product( 'Chair', 'viewer-a' );
		$this->product( 'Table', 'viewer-b' );
		$this->ask_for( 'viewer-a' );

		$main                    = new \WP_Query();
		$GLOBALS['wp_the_query'] = $main;
		$GLOBALS['wp_query']     = $main;
		$other                   = new \WP_Query(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		$this->assertSame(
			array( 'Chair', 'Table' ),
			array_map( static fn( \WP_Post $post ): string => $post->post_title, $other->posts ),
			'A secondary query, a widget or a block for example, is not the list.'
		);
	}

	public function test_other_screens_are_not_narrowed(): void {
		$this->product( 'Chair', 'viewer-a' );
		$this->product( 'Table', 'viewer-b' );
		$this->ask_for( 'viewer-a' );

		foreach ( array( 'edit-post', 'woocommerce_page_arkid-viewers', 'front' ) as $screen ) {
			$this->open_screen( $screen );
			$this->assertSame( array( 'Chair', 'Table' ), $this->listed(), $screen );
		}
	}

	public function test_the_list_says_it_is_narrowed_and_how_to_undo_it(): void {
		$this->ask_for( 'viewer-a' );

		$html = $this->render( array( new ProductListFilter( new ProductLinks() ), 'notice' ) );

		$this->assertStringContainsString( 'Showing only the products that show one ARkid viewer.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '"', $html );
		$this->assertStringContainsString( 'Show all products', $html );
	}

	public function test_there_is_no_notice_when_the_list_is_not_narrowed(): void {
		$filter = new ProductListFilter( new ProductLinks() );

		$this->assertSame( '', $this->render( array( $filter, 'notice' ) ), 'No variable.' );

		$this->ask_for( 'viewer a' );
		$this->assertSame( '', $this->render( array( $filter, 'notice' ) ), 'A value that is not a viewer id.' );

		$this->ask_for( 'viewer-a' );
		$this->open_screen( 'edit-post' );
		$this->assertSame( '', $this->render( array( $filter, 'notice' ) ), 'Another screen.' );
	}

	public function test_the_admin_registers_it(): void {
		remove_all_actions( 'pre_get_posts' );
		remove_all_actions( 'admin_notices' );
		$options = new Options();
		( new Setup( $options, new ClientFactory( $options ) ) )->register();
		$this->product( 'Chair', 'viewer-a' );
		$this->product( 'Table', 'viewer-b' );
		$this->ask_for( 'viewer-a' );

		$this->assertSame( array( 'Chair' ), $this->listed() );
		$this->assertStringContainsString(
			'Show all products',
			$this->render( static fn() => do_action( 'admin_notices' ) )
		);
	}
}
