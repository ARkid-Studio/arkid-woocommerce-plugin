<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Api;

use Arkid\CatalogueLink\Api\Client;
use WP_UnitTestCase;

/**
 * Calls the REAL ARkid API and checks it still matches the recorded fixtures.
 *
 * This is the only thing in the suite that touches the network, so it is opt-in
 * twice over: it carries `@group live-api`, which phpunit-integration.xml.dist
 * excludes by default, AND it skips itself unless ARKID_API_KEY is set. CI and
 * QIT therefore never run it, and no key is ever committed.
 *
 *     ARKID_API_KEY=… composer test:contract
 *
 * EmbedSchemaTest checks that OUR parser still reads a real payload. This checks
 * that ARkid still SENDS one — the two fail for different reasons and both
 * matter. It deliberately asserts shape, never values: the account's viewers
 * change, the contract shouldn't.
 *
 * @group live-api
 */
final class LiveApiContractTest extends WP_UnitTestCase {

	private string $api_key = '';

	public function set_up(): void {
		parent::set_up();

		$key = is_string( getenv( 'ARKID_API_KEY' ) ) ? trim( (string) getenv( 'ARKID_API_KEY' ) ) : '';

		if ( '' === $key ) {
			$this->markTestSkipped( 'Set ARKID_API_KEY to run the live API contract check.' );
		}

		// `.env` is committed public-key encrypted; `.env.keys` is not. Without
		// the private key dotenvx passes the ciphertext through VERBATIM rather
		// than failing, so the variable is set but useless. Left unchecked that
		// turns "you don't have the key" into a 403 and a red suite.
		if ( str_starts_with( $key, 'encrypted:' ) ) {
			$this->markTestSkipped(
				'ARKID_API_KEY is still encrypted — .env.keys is missing, so dotenvx could not decrypt it.'
			);
		}

		$this->api_key = $key;
	}

	/**
	 * @return array<string, string> field => expected PHP type
	 */
	private function contract(): array {
		$recorded = EmbedSchemaTest::fixture( 'embed-list' )['data'][0];

		$types = array();
		foreach ( $recorded as $field => $value ) {
			$types[ $field ] = get_debug_type( $value );
		}

		return $types;
	}

	public function test_the_live_list_endpoint_still_returns_the_recorded_shape(): void {
		$client = new Client( $this->api_key );
		$embeds = $client->list_embeds();

		$this->assertNotEmpty(
			$embeds,
			'The live account returned no embeds — the fixtures cannot be re-verified.'
		);

		// Round-tripping through the DTO proves nothing about unknown fields, so
		// go back to the wire format for the shape comparison.
		$response = wp_remote_get(
			'https://catalogue.arkid.app/api/ecom/embed',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( 'woocommerce:' . $this->api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			)
		);

		$this->assertNotWPError( $response );
		$this->assertSame( 200, (int) wp_remote_retrieve_response_code( $response ) );

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'data', $payload, 'The list response lost its `data` wrapper.' );
		$this->assertNotEmpty( $payload['data'] );

		$contract = $this->contract();
		$live     = $payload['data'][0];

		foreach ( $contract as $field => $expected_type ) {
			$this->assertArrayHasKey( $field, $live, "The API stopped sending `{$field}`." );
			$this->assertSame(
				$expected_type,
				get_debug_type( $live[ $field ] ),
				"`{$field}` changed type: fixtures record {$expected_type}."
			);
		}

		$added = array_diff( array_keys( $live ), array_keys( $contract ) );
		$this->assertSame(
			array(),
			$added,
			'The API grew field(s): ' . implode( ', ', $added ) . '. Re-record the fixtures.'
		);
	}

	/**
	 * The assumption IframeFactory's allow-list is built on.
	 */
	public function test_every_live_embed_url_is_still_on_the_allow_listed_host(): void {
		$embeds = ( new Client( $this->api_key ) )->list_embeds();

		foreach ( $embeds as $embed ) {
			$host = (string) wp_parse_url( $embed->embed_url, PHP_URL_HOST );

			$this->assertTrue(
				'catalogue.arkid.app' === $host || str_ends_with( $host, '.arkid.app' ),
				"embed_url moved to an un-allow-listed host: {$embed->embed_url}"
			);
		}
	}

	public function test_a_bad_key_is_still_rejected_as_auth_not_transport(): void {
		$this->expectException( \Arkid\CatalogueLink\Api\AuthException::class );

		( new Client( 'definitely-not-a-valid-key' ) )->probe();
	}
}
