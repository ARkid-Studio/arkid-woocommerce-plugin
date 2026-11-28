<?php
/**
 * The viewers screen: Screen Options, the page's form, and what it does when
 * ARkid answers or does not.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\ViewersListTable;
use Arkid\CatalogueLink\Admin\ViewersPage;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ViewersPageTest extends TestCase {

	private const HOOK = 'woocommerce_page_arkid-viewers';

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['hook_suffix'] = self::HOOK;
		set_current_screen( self::HOOK );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Options::OPTION_KEY, array( 'api_key' => 'test-key' ) );
	}

	public function tear_down(): void {
		delete_option( Options::OPTION_KEY );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		remove_all_filters( 'wp_redirect' );
		unset( $GLOBALS['hook_suffix'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function page(): ViewersPage {
		$options = new Options();

		return new ViewersPage( $options, new ClientFactory( $options ) );
	}

	/**
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function viewer( int $row, array $extra = array() ): array {
		return array_merge(
			$this->embed_payload( 'viewer-' . $row ),
			array(
				'row_number'    => $row,
				'product_name'  => 'Product ' . $row,
				'edit_url'      => 'https://catalogue.arkid.app/configure/edit/v' . $row,
				'analytics_url' => 'https://catalogue.arkid.app/analytics/v' . $row,
				'modified'      => '2026-01-02 10:00:00',
				'author_name'   => 'Jo Author',
				'views'         => 100 + $row,
			),
			$extra
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $viewers
	 * @param array<int, array<string, mixed>> $traffic
	 */
	private function stub_account( array $viewers, array $traffic = array() ): void {
		$this->stub_http( '/api/ecom/embed', array( 'data' => $viewers ) );
		$this->stub_http(
			'/api/ecom/stats',
			array(
				'days'                    => 30,
				'dates'                   => array(),
				'impressions'             => array( 'total' => 0, 'before' => 0, 'series' => array(), 'before_series' => array() ),
				'interactions'            => array( 'total' => 0, 'before' => 0, 'series' => array(), 'before_series' => array() ),
				'ar'                      => array( 'total' => 0, 'before' => 0, 'series' => array(), 'before_series' => array() ),
				'avg_interaction_seconds' => 0,
				'embeds'                  => $traffic,
				'unused_brands'           => array(),
				'latest_products'         => array(),
			)
		);
	}

	private function page_html(): string {
		$page = $this->page();

		return $this->render( array( $page, 'render' ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function calls_to( string $path ): array {
		return array_values( array_filter( $this->http_calls, static fn( string $url ): bool => str_contains( $url, $path ) ) );
	}

	private function load_screen_options_handler(): void {
		if ( ! function_exists( 'set_screen_options' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}

	/**
	 * Submit Screen Options the way the browser does, and let WordPress's own
	 * handler decide. It runs in wp-admin/admin.php before the admin menu is
	 * built and before any `load-*` hook, so nothing but a filter in place at
	 * plugin load can see it.
	 */
	private function submit_screen_options( string $option, string $value ): void {
		$this->load_screen_options_handler();

		$_POST    = array(
			'wp_screen_options' => array(
				'option' => $option,
				'value'  => $value,
			),
		);
		$_REQUEST = array( 'screenoptionnonce' => wp_create_nonce( 'screen-options-nonce' ) );

		// The handler redirects and exits when it saved something.
		add_filter(
			'wp_redirect',
			static function (): never {
				throw new \RuntimeException( 'redirected' );
			}
		);
		try {
			set_screen_options();
		} catch ( \RuntimeException $e ) {
			// Saved.
		}
	}

	// ---- Screen Options ---------------------------------------------------

	/**
	 * Registered when the page registers, not when its screen loads: the form is
	 * handled before the screen loads, and a per-page value saved after that
	 * would be thrown away.
	 */
	public function test_the_per_page_option_is_accepted_by_the_time_the_screen_options_form_is_handled(): void {
		$this->page()->register();

		$this->assertNotFalse( has_filter( 'set_screen_option_arkid_viewers_per_page' ) );
		$this->assertFalse( has_action( 'load-' . self::HOOK ), 'Nothing needs the load hook yet.' );
	}

	public function test_wordpress_saves_the_viewers_per_page_a_user_chooses(): void {
		$this->page()->register();

		$this->submit_screen_options( 'arkid_viewers_per_page', '7' );

		$this->assertSame( 7, (int) get_user_option( 'arkid_viewers_per_page' ) );
	}

	/**
	 * Without the filter WordPress ignores an option it does not know, which is
	 * what made the setting silently not stick.
	 */
	public function test_the_choice_is_not_saved_without_the_filter(): void {
		$this->submit_screen_options( 'arkid_viewers_per_page', '7' );

		$this->assertFalse( get_user_option( 'arkid_viewers_per_page' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function bad_page_sizes(): array {
		return array(
			'zero'     => array( '0' ),
			'negative' => array( '-5' ),
			'huge'     => array( '1000' ),
			'text'     => array( 'many' ),
			'empty'    => array( '' ),
		);
	}

	/**
	 * @dataProvider bad_page_sizes
	 */
	public function test_a_page_size_that_makes_no_sense_is_not_saved( string $value ): void {
		$this->page()->register();

		$this->submit_screen_options( 'arkid_viewers_per_page', $value );

		$this->assertFalse( get_user_option( 'arkid_viewers_per_page' ) );
	}

	public function test_the_filter_keeps_what_wordpress_decided_when_the_value_is_not_valid(): void {
		$page = $this->page();

		$this->assertSame( 5, $page->save_screen_option( false, 'arkid_viewers_per_page', '5' ) );
		$this->assertSame( 999, $page->save_screen_option( false, 'arkid_viewers_per_page', 999 ) );
		$this->assertFalse( $page->save_screen_option( false, 'arkid_viewers_per_page', '0' ) );
		$this->assertSame( 'keep', $page->save_screen_option( 'keep', 'arkid_viewers_per_page', 'x' ) );
	}

	public function test_an_option_that_is_not_ours_is_not_saved_through_it(): void {
		$this->page()->register();

		$this->submit_screen_options( 'some_other_page', '5' );

		$this->assertFalse( get_user_option( 'some_other_page' ) );
	}

	public function test_the_load_hook_adds_the_option_to_screen_options(): void {
		$this->page()->on_load();

		$this->assertSame( 20, get_current_screen()?->get_option( 'per_page', 'default' ) );
		$this->assertSame( 'arkid_viewers_per_page', get_current_screen()?->get_option( 'per_page', 'option' ) );
	}

	public function test_the_created_column_starts_hidden_on_this_screen_only(): void {
		$page = $this->page();
		// The menu is built by `register_menu()`; it needs the parent menu to be known.
		global $admin_page_hooks;
		$admin_page_hooks['woocommerce'] = 'woocommerce';
		$page->register_menu();

		$screen = \WP_Screen::get( 'woocommerce_page_arkid-viewers' );
		$hidden = $page->default_hidden_columns( array( 'existing' ), $screen );

		$this->assertSame( array( 'existing', 'created' ), $hidden );
		$this->assertSame( array( 'x' ), $page->default_hidden_columns( array( 'x' ), \WP_Screen::get( 'edit-product' ) ) );
	}

	// ---- The page ---------------------------------------------------------

	public function test_the_page_is_a_get_form_that_keeps_its_own_page(): void {
		$this->stub_account( array( $this->viewer( 1 ) ) );

		$html = $this->page_html();

		$this->assertMatchesRegularExpression( '/<form method="get"[^>]*id="arkid-viewers-filter"/', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="arkid-viewers" />', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'name="viewer_type"', $html );
		$this->assertStringContainsString( 'subsubsub', $html );
	}

	public function test_the_selected_view_survives_a_search(): void {
		$this->stub_account( array( $this->viewer( 1 ) ) );
		$_GET = array( 'show' => 'unlinked' );

		$html = $this->page_html();

		$this->assertStringContainsString( '<input type="hidden" name="show" value="unlinked" />', $html );
	}

	public function test_the_page_lists_the_viewers_with_their_interactions_and_where_they_are_shown(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product', 'post_title' => 'Linked Chair' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'viewer-1' );
		$this->stub_account(
			array( $this->viewer( 1 ), $this->viewer( 2 ) ),
			array( array( 'embed_id' => 'viewer-1', 'impressions' => 90, 'interactions' => 42 ) )
		);

		$html = $this->page_html();

		$this->assertStringContainsString( 'Interactions (30 days)', $html );
		$this->assertSame( 1, preg_match( "/column-interactions' data-colname=\"[^\"]*\">42</", $html ) );
		$this->assertStringContainsString( 'Linked Chair', $html );
		$this->assertStringContainsString( 'Live on a product <span class="count">(1)</span>', $html );
		$this->assertStringContainsString( 'No interactions, 30 days <span class="count">(1)</span>', $html );
	}

	/**
	 * Admin pages may call ARkid directly, but a request calls it once for the
	 * same thing: one list and one statistics call, however many viewers.
	 */
	public function test_a_page_asks_arkid_once_for_the_list_and_once_for_the_stats(): void {
		$this->stub_account( array( $this->viewer( 1 ), $this->viewer( 2 ), $this->viewer( 3 ) ) );

		$this->page_html();

		$this->assertCount( 1, $this->calls_to( '/api/ecom/embed' ) );
		$calls = $this->calls_to( '/api/ecom/stats' );
		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( 'days=30', $calls[0] );
	}

	/**
	 * The list is the point of the screen. A statistics call that fails must not
	 * take it down, and must not be reported as zero interactions.
	 */
	public function test_the_list_still_renders_when_the_stats_call_fails(): void {
		$this->stub_http( '/api/ecom/embed', array( 'data' => array( $this->viewer( 1 ), $this->viewer( 2 ) ) ) );
		$this->stub_http_status( '/api/ecom/stats', 503 );

		$html = $this->page_html();

		$this->assertStringContainsString( 'Product 1', $html );
		$this->assertStringContainsString( 'Product 2', $html );
		$this->assertStringNotContainsString( 'Interactions (30 days)', $html );
		$this->assertStringNotContainsString( 'No interactions, 30 days', $html );
		$this->assertStringContainsString( 'could not be loaded from ARkid', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Total views', $html, 'The lifetime count stays.' );
	}

	public function test_a_rejected_key_on_the_stats_call_only_costs_the_stats(): void {
		$this->stub_http( '/api/ecom/embed', array( 'data' => array( $this->viewer( 1 ) ) ) );
		$this->stub_http_status( '/api/ecom/stats', 403 );

		$html = $this->page_html();

		$this->assertStringContainsString( 'Product 1', $html );
		$this->assertStringContainsString( 'could not be loaded from ARkid', $html );
	}

	public function test_an_answer_the_client_cannot_read_does_not_take_the_list_down(): void {
		$this->stub_http( '/api/ecom/embed', array( 'data' => array( $this->viewer( 1 ) ) ) );
		$this->http_responses['/api/ecom/stats'] = array(
			'headers'  => array(),
			'body'     => '<html>maintenance</html>',
			'response' => array( 'code' => 200, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$html = $this->page_html();

		$this->assertStringContainsString( 'Product 1', $html );
		$this->assertStringNotContainsString( 'Interactions (30 days)', $html );
	}

	public function test_an_account_without_viewers_does_not_ask_for_stats(): void {
		$this->stub_account( array() );

		$html = $this->page_html();

		$this->assertCount( 0, $this->calls_to( '/api/ecom/stats' ) );
		$this->assertStringContainsString( 'No viewers found. Add a viewer', $html );
		$this->assertStringNotContainsString( 'subsubsub', $html );
		$this->assertStringNotContainsString( 'could not be loaded from ARkid', $html );
	}

	public function test_a_list_that_cannot_be_loaded_is_an_error_and_not_an_empty_table(): void {
		$this->stub_http_status( '/api/ecom/embed', 503 );

		$html = $this->page_html();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Could not reach the ARkid API', $html );
		$this->assertStringNotContainsString( 'wp-list-table', $html );
		$this->assertCount( 0, $this->calls_to( '/api/ecom/stats' ), 'No point asking for statistics of a list that did not load.' );
	}

	public function test_a_rejected_key_says_so(): void {
		$this->stub_http_status( '/api/ecom/embed', 403 );

		$this->assertStringContainsString( 'The API key was rejected', $this->page_html() );
	}

	public function test_without_a_key_nothing_is_asked(): void {
		update_option( Options::OPTION_KEY, array( 'api_key' => '' ) );

		$html = $this->page_html();

		$this->assertSame( array(), $this->http_calls );
		$this->assertStringContainsString( 'No API key configured', $html );
	}

	public function test_a_user_who_may_not_manage_the_shop_gets_nothing(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->page_html() );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_page_two_of_a_long_list_is_reached_with_the_search_and_filters_kept(): void {
		update_user_option( get_current_user_id(), 'arkid_viewers_per_page', 2 );
		$viewers = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$viewers[] = $this->viewer( $i );
		}
		$this->stub_account( $viewers );
		$_GET     = array( 's' => 'product', 'paged' => '2' );
		$_REQUEST = $_GET;

		$html = $this->page_html();

		$this->assertStringContainsString( 'Product 3', $html );
		$this->assertStringContainsString( 'Product 4', $html );
		$this->assertStringNotContainsString( 'Product 1<', $html );
		$this->assertStringContainsString( '5 items', $html );
		$this->assertStringContainsString( 'value="product"', $html, 'The search term stays in the box.' );
	}
}
