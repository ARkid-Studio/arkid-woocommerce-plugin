<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Rest;

use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

/**
 * The picker searches the embed list through the plugin's REST route.
 */
final class ControllerSearchTest extends TestCase {

	private const ROUTE = '/arkid-catalogue-link/v1/embeds';

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		update_option( Options::OPTION_KEY, array( 'api_key' => 'test-key' ) );

		$this->stub_list(
			array(
				array( 'Felix', 'Learning Tower', 'living room' ),
				array( 'Felix', 'Highchair', '' ),
				array( 'Müller Möbel', 'Straßenlampe', '' ),
				array( 'Ørsted Design', 'Sofa Æble', '' ),
				array( 'Łódź Meble', 'Krzesło', '' ),
				array( 'Ärztehaus', 'Öl-Lampe', '' ),
				array( '日本ブランド', '椅子', '' ),
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( Options::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * @param array<int, array{0: string, 1: string, 2: string}> $rows brand, product, note.
	 */
	private function stub_list( array $rows ): void {
		$data = array();
		foreach ( $rows as $index => $row ) {
			$payload             = $this->embed_payload( 'embed-' . ( $index + 1 ) );
			$payload['row_number']    = $index + 1;
			$payload['brand_name']    = $row[0];
			$payload['product_name']  = $row[1];
			$payload['internal_note'] = $row[2];
			$data[]                   = $payload;
		}

		$this->stub_http( '/api/ecom/embed', array( 'data' => $data ) );
	}

	/**
	 * @return array<int, int> Row numbers of the embeds a search finds.
	 */
	private function search( string $term ): array {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'search', $term );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$rows = array();
		foreach ( (array) $response->get_data() as $item ) {
			$this->assertIsArray( $item );
			$embed  = $item['embed'] ?? array();
			$rows[] = is_array( $embed ) ? (int) ( $embed['row_number'] ?? 0 ) : 0;
		}

		return $rows;
	}

	public function test_no_search_term_lists_every_embed(): void {
		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7 ), $this->search( '' ) );
	}

	/**
	 * The label reads '#1 - Felix - Learning Tower - ...', so this found nothing
	 * while the query was tested as one contiguous string.
	 */
	public function test_a_query_can_span_brand_and_product(): void {
		$this->assertSame( array( 1 ), $this->search( 'felix learning' ) );
		$this->assertSame( array( 1 ), $this->search( 'Learning   FELIX' ) );
		$this->assertSame( array( 1, 2 ), $this->search( 'felix' ) );
		$this->assertSame( array(), $this->search( 'felix lampe' ) );
	}

	public function test_a_query_can_combine_the_brand_with_the_note(): void {
		$this->assertSame( array( 1 ), $this->search( 'felix living' ) );
	}

	/**
	 * strtolower() only touches ASCII, so a capital "Ä" in the label never met the
	 * lower-case "ä" a merchant typed.
	 *
	 * @dataProvider multibyte_queries
	 *
	 * @param array<int, int> $expected
	 */
	public function test_non_ascii_names_are_found_whatever_the_case( string $term, array $expected ): void {
		$this->assertSame( $expected, $this->search( $term ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, int>}>
	 */
	public static function multibyte_queries(): array {
		return array(
			'german, lower case for upper case' => array( 'ärztehaus', array( 6 ) ),
			'german, upper case for lower case' => array( 'MÜLLER', array( 3 ) ),
			'german, without the umlaut'        => array( 'muller mobel', array( 3 ) ),
			'german, eszett'                    => array( 'strasse', array( 3 ) ),
			'german, eszett typed'              => array( 'Straßenlampe', array( 3 ) ),
			'german, hyphenated'                => array( 'öl-lampe', array( 6 ) ),
			'danish, o with stroke'             => array( 'ørsted', array( 4 ) ),
			'danish, without the stroke'        => array( 'orsted', array( 4 ) ),
			'danish, ae ligature'               => array( 'sofa æble', array( 4 ) ),
			'polish, barred l'                  => array( 'łódź krzesło', array( 5 ) ),
			'polish, plain letters'             => array( 'lodz krzeslo', array( 5 ) ),
			'japanese'                          => array( '椅子', array( 7 ) ),
			'japanese, brand and product'       => array( '日本ブランド 椅子', array( 7 ) ),
			'japanese, ideographic space'       => array( "日本ブランド\u{3000}椅子", array( 7 ) ),
		);
	}

	/**
	 * WordPress folds "ü" to "ue" on a German site; the search must still find
	 * "Müller" when the merchant types "muller".
	 */
	public function test_folding_does_not_depend_on_the_site_language(): void {
		switch_to_locale( 'de_DE' );

		try {
			$this->assertSame( array( 3 ), $this->search( 'muller' ) );
			$this->assertSame( array( 3 ), $this->search( 'müller' ) );
		} finally {
			restore_previous_locale();
		}
	}

	public function test_a_visitor_without_the_capability_cannot_search(): void {
		wp_set_current_user( 0 );

		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'search', 'felix' );

		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
	}
}
