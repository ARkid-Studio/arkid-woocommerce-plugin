<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Links;

use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Links\EmbedLookup;
use Arkid\CatalogueLink\Links\LinkState;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

/**
 * What each answer of ARkid means for a link, in one place.
 */
final class EmbedLookupTest extends TestCase {

	private function lookup(): EmbedLookup {
		return new EmbedLookup( new ClientFactory( new Options() ) );
	}

	public function set_up(): void {
		parent::set_up();
		update_option( Options::OPTION_KEY, array( 'api_key' => 'test-key' ) );
	}

	public function tear_down(): void {
		delete_option( Options::OPTION_KEY );
		parent::tear_down();
	}

	public function test_a_viewer_arkid_has_is_found(): void {
		$this->stub_http( '/api/ecom/embed/', $this->embed_payload( 'abc-123' ) );

		$result = $this->lookup()->find( 'abc-123' );

		$this->assertTrue( $result->is_found() );
		$this->assertSame( 'abc-123', $result->embed?->embed_id );
	}

	public function test_a_404_is_the_only_answer_that_means_gone(): void {
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$result = $this->lookup()->find( 'gone-404' );

		$this->assertTrue( $result->is_not_found() );
		$this->assertNull( $result->embed );
	}

	/**
	 * @dataProvider answers_that_say_nothing_about_the_viewer
	 */
	public function test_every_other_failure_is_unavailable_never_gone( int $status ): void {
		$this->stub_http_status( '/api/ecom/embed/', $status );

		$result = $this->lookup()->find( 'abc-123' );

		$this->assertTrue( $result->is_unavailable() );
		$this->assertFalse( $result->is_not_found() );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function answers_that_say_nothing_about_the_viewer(): array {
		return array(
			'401' => array( 401 ),
			'403' => array( 403 ),
			'402' => array( 402 ),
			'422' => array( 422 ),
			'429' => array( 429 ),
			'500' => array( 500 ),
			'503' => array( 503 ),
			'302' => array( 302 ),
		);
	}

	public function test_no_connection_is_unavailable(): void {
		// No stub: the request fails the way a dead network does.
		$this->assertTrue( $this->lookup()->find( 'abc-123' )->is_unavailable() );
	}

	public function test_an_answer_that_is_not_json_is_unavailable(): void {
		$this->http_responses['/api/ecom/embed/'] = array(
			'headers'  => array(),
			'body'     => '<html>maintenance</html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$this->assertTrue( $this->lookup()->find( 'abc-123' )->is_unavailable() );
	}

	public function test_without_an_api_key_nothing_is_asked(): void {
		delete_option( Options::OPTION_KEY );

		$result = $this->lookup()->find( 'abc-123' );

		$this->assertTrue( $result->is_unavailable() );
		$this->assertSame( array(), $this->http_calls );
	}

	/**
	 * A viewer created a minute ago is not in any list the plugin read earlier.
	 * It is asked about by id, so it is found, and a product that was flagged
	 * missing before it existed is not.
	 */
	public function test_a_viewer_created_after_the_link_was_flagged_is_found_by_id(): void {
		$product_id = $this->make_product_with_embed( 'new-999' );
		PostMeta::mark_missing( $product_id );
		$this->stub_http( '/api/ecom/embed/new-999', $this->embed_payload( 'new-999' ) );

		$result = $this->lookup()->find( 'new-999' );

		$this->assertTrue( $result->is_found() );
		$this->assertSame( array( 'https://catalogue.arkid.app/api/ecom/embed/new-999?source=woocommerce' ), $this->http_calls );
		$this->assertSame( LinkState::Missing, LinkState::of_product( $product_id ), 'The stored state is the refresh job\'s to correct, not the lookup\'s.' );
	}
}
