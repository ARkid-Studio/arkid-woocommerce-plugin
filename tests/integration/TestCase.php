<?php
/**
 * Shared base for integration tests.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration;

use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Outbound HTTP requests observed during the test.
	 *
	 * @var array<int, string>
	 */
	protected array $http_calls = array();

	/**
	 * Canned responses keyed by a substring of the URL.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $http_responses = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_calls     = array();
		$this->http_responses = array();

		// Nothing in this suite may reach the network. An un-stubbed request
		// fails the test rather than silently depending on catalogue.arkid.app
		// being up — which would also break under QIT's network isolation.
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * @param mixed                $preempt
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function intercept_http( $preempt, $args, string $url ) {
		$this->http_calls[] = $url;

		foreach ( $this->http_responses as $needle => $response ) {
			if ( str_contains( $url, $needle ) ) {
				return $response;
			}
		}

		return new \WP_Error(
			'arkid_test_unstubbed_http',
			sprintf( 'Un-stubbed outbound request to %s. Stub it with stub_http().', $url )
		);
	}

	/**
	 * @param array<string, mixed>|list<array<string, mixed>> $body
	 */
	protected function stub_http( string $url_contains, array $body, int $status = 200 ): void {
		$this->http_responses[ $url_contains ] = array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	protected function stub_http_status( string $url_contains, int $status ): void {
		$this->http_responses[ $url_contains ] = array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Capture output from the many render methods that echo rather than return.
	 *
	 * phpunit.xml sets beStrictAboutOutputDuringTests, so any test that calls one
	 * of those without capturing is marked risky and fails the suite.
	 */
	protected function render( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	/**
	 * A published product with the four ARkid meta keys populated.
	 *
	 * @return int Product ID.
	 */
	protected function make_product_with_embed(
		string $embed_id = 'abc-123',
		string $position = '',
		string $embed_url = 'https://catalogue.arkid.app/e/abc-123'
	): int {
		$product_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Test Chair',
			)
		);

		update_post_meta( $product_id, '_arkid_embed_id', $embed_id );
		update_post_meta( $product_id, '_arkid_embed_url', $embed_url );
		update_post_meta( $product_id, '_arkid_embed_image', 'https://catalogue.arkid.app/img/abc-123.jpg' );
		if ( '' !== $position ) {
			update_post_meta( $product_id, '_arkid_embed_position', $position );
		}

		return $product_id;
	}

	/**
	 * A well-formed embed payload as the ARkid API would return it.
	 *
	 * @return array<string, mixed>
	 */
	protected function embed_payload( string $embed_id = 'abc-123', string $url = 'https://catalogue.arkid.app/e/abc-123' ): array {
		return array(
			'embed_id'     => $embed_id,
			'row_number'   => 7,
			'product_name' => 'Test Chair',
			'brand_name'   => 'ARkid',
			'image'        => 'https://catalogue.arkid.app/img/' . $embed_id . '.jpg',
			'embed_url'    => $url,
			'created'      => '2026-01-01',
			'type'         => 'model',
		);
	}
}
