<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api;

use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\Client;
use Arkid\CatalogueLink\Api\EmbedInput;
use Arkid\CatalogueLink\Api\NotFoundException;
use Arkid\CatalogueLink\Api\RefusedException;
use Arkid\CatalogueLink\Api\TransportException;
use Arkid\CatalogueLink\Api\ValidationException;
use Arkid\CatalogueLink\Tests\Api\Fixtures\ApiFixture;
use Arkid\CatalogueLink\Tests\Api\Fixtures\FakeWpError;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/FakeWpError.php';
require_once __DIR__ . '/Fixtures/wp-json-encode-stub.php';

final class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'wp_remote_retrieve_response_code' => static fn( $r ): int => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0,
				'wp_remote_retrieve_body'          => static fn( $r ): string => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '',
				'wp_remote_retrieve_header'        => static fn( $r, string $name ) => is_array( $r ) ? ( $r['headers'][ $name ] ?? '' ) : '',
				'is_wp_error'                      => static fn( $thing ): bool => $thing instanceof FakeWpError,
				'esc_html'                         => static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_throws_auth_when_api_key_missing(): void {
		$this->expectException( AuthException::class );
		( new Client( '' ) )->probe();
	}

	public function test_get_embed_returns_dto_on_200(): void {
		$response = $this->ok_response(
			array(
				'embed_id'   => 'abc',
				'row_number' => 1,
				'embed_url'  => 'https://catalogue.arkid.app/embed/x',
			)
		);
		$this->stub_remote_get( $response );

		$dto = ( new Client( 'k' ) )->get_embed( 'abc' );

		$this->assertSame( 'abc', $dto->embed_id );
		$this->assertSame( 'https://catalogue.arkid.app/embed/x', $dto->embed_url );
	}

	public function test_requests_name_the_shop_system_and_send_the_key(): void {
		$seen = null;
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args ) use ( &$seen ): array {
				$seen = array( $url, $args );
				return $this->ok_response( array( 'data' => array() ) );
			}
		);

		( new Client( 'k' ) )->list_embeds();

		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/embed?source=woocommerce', $seen[0] );
		$this->assertSame( 'Basic ' . base64_encode( 'woocommerce:k' ), $seen[1]['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_get_embed_throws_not_found_on_404(): void {
		$this->stub_remote_get(
			array(
				'response' => array( 'code' => 404 ),
				'body'     => '<html>not found</html>',
			)
		);

		$this->expectException( NotFoundException::class );
		( new Client( 'k' ) )->get_embed( 'abc' );
	}

	public function test_throws_auth_on_403(): void {
		$this->stub_remote_get(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => '<html>denied</html>',
			)
		);

		$this->expectException( AuthException::class );
		( new Client( 'k' ) )->probe();
	}

	public function test_throws_transport_on_5xx(): void {
		$this->stub_remote_get(
			array(
				'response' => array( 'code' => 502 ),
				'body'     => '<html>bad gateway</html>',
			)
		);

		$this->expectException( TransportException::class );
		( new Client( 'k' ) )->probe();
	}

	public function test_throws_transport_on_wp_error(): void {
		$this->stub_remote_get( new FakeWpError( 'connection refused' ) );

		$this->expectException( TransportException::class );
		( new Client( 'k' ) )->probe();
	}

	public function test_throws_transport_on_non_json_2xx_body(): void {
		$this->stub_remote_get(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html>ok</html>',
			)
		);

		$this->expectException( TransportException::class );
		( new Client( 'k' ) )->probe();
	}

	public function test_list_embeds_returns_empty_on_missing_data_key(): void {
		$this->stub_remote_get( $this->ok_response( array( 'foo' => 'bar' ) ) );

		$this->assertSame( array(), ( new Client( 'k' ) )->list_embeds() );
	}

	public function test_list_embeds_filters_malformed_rows(): void {
		$this->stub_remote_get(
			$this->ok_response(
				array(
					'data' => array(
						array(
							'embed_id'   => 'a',
							'row_number' => 1,
						),
						'not-an-array',
						array(
							'embed_id'   => 'b',
							'row_number' => 2,
						),
					),
				)
			)
		);

		$rows = ( new Client( 'k' ) )->list_embeds();

		$this->assertCount( 2, $rows );
		$this->assertSame( 'a', $rows[0]->embed_id );
		$this->assertSame( 'b', $rows[1]->embed_id );
	}

	public function test_the_period_of_the_statistics_is_sent_as_days_after_the_source(): void {
		$seen = $this->capture( $this->ok_response( array( 'days' => 7 ) ) );

		( new Client( 'k' ) )->stats( 7 );

		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/stats?source=woocommerce&days=7', $seen->requests[0]['url'] );
	}

	/**
	 * @dataProvider unknown_periods
	 */
	public function test_a_period_ARkid_does_not_know_is_sent_as_the_default( int $days ): void {
		$seen = $this->capture( $this->ok_response( array() ) );

		( new Client( 'k' ) )->stats( $days );

		$this->assertStringEndsWith( '&days=30', $seen->requests[0]['url'] );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function unknown_periods(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -7 ),
			'fourteen' => array( 14 ),
			'huge'     => array( 100000 ),
		);
	}

	public function test_the_language_of_a_wordpress_locale_is_sent_as_lang(): void {
		$seen = $this->capture( $this->ok_response( array( 'data' => array() ) ) );

		( new Client( 'k' ) )->list_environments( 'de_DE' );

		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/environment?source=woocommerce&lang=de', $seen->requests[0]['url'] );
	}

	/**
	 * `add_query_arg()` would have spliced these into the URL as they are.
	 *
	 * @dataProvider hostile_locales
	 */
	public function test_a_locale_can_never_add_a_parameter_or_break_the_query( string $locale ): void {
		$seen = $this->capture( $this->ok_response( array( 'data' => array() ) ) );

		( new Client( 'k' ) )->list_environments( $locale );

		$this->assertSame(
			'https://catalogue.arkid.app/api/ecom/environment?source=woocommerce',
			$seen->requests[0]['url'],
			'An invalid language must leave `lang` out, not carry the text into the query.'
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function hostile_locales(): array {
		return array(
			'ampersand'      => array( 'de&source=magento' ),
			'hash'           => array( 'de#x' ),
			'space'          => array( 'd e' ),
			'path'           => array( '../../x' ),
			'too short'      => array( 'd' ),
			'too long'       => array( 'german' ),
			'digits'         => array( '12' ),
			'empty'          => array( '' ),
			'leading dash'   => array( '-de' ),
			'percent escape' => array( '%26source%3Dx' ),
		);
	}

	public function test_a_language_is_normalised_the_way_the_api_takes_it(): void {
		$this->assertSame( 'de', Client::api_language( 'de_DE' ) );
		$this->assertSame( 'de', Client::api_language( 'de-AT' ) );
		$this->assertSame( 'pt', Client::api_language( 'PT_br' ) );
		$this->assertSame( 'ja', Client::api_language( 'ja' ) );
		$this->assertSame( 'haw', Client::api_language( 'haw' ) );
		$this->assertSame( '', Client::api_language( 'de&x=1' ) );
	}

	/**
	 * @dataProvider unsafe_ids
	 */
	public function test_an_id_that_cannot_be_a_path_segment_never_reaches_the_network( string $id ): void {
		$seen = $this->capture( $this->ok_response( array() ) );

		try {
			( new Client( 'k' ) )->get_embed( $id );
			$this->fail( 'A NotFoundException was expected.' );
		} catch ( NotFoundException $e ) {
			$this->assertSame( array(), $seen->requests, 'No request may be made for an id that is not an id.' );
		}
	}

	/**
	 * @dataProvider unsafe_ids
	 */
	public function test_updating_an_id_that_cannot_be_a_path_segment_is_refused_before_the_request( string $id ): void {
		$seen = $this->capture( $this->ok_response( array() ) );

		try {
			( new Client( 'k' ) )->update_embed( $id, new EmbedInput( color_a: '#ffffff' ) );
			$this->fail( 'A NotFoundException was expected.' );
		} catch ( NotFoundException $e ) {
			$this->assertSame( array(), $seen->requests );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafe_ids(): array {
		return array(
			'empty'          => array( '' ),
			'slash'          => array( 'a/b' ),
			'parent'         => array( '../embed' ),
			'dot'            => array( '.' ),
			'query'          => array( 'abc?source=x' ),
			'fragment'       => array( 'abc#x' ),
			'space'          => array( 'a b' ),
			'percent'        => array( '%2e%2e' ),
			'newline'        => array( "abc\n" ),
			'too long'       => array( str_repeat( 'a', 65 ) ),
			'multibyte'      => array( "caf\u{e9}" ),
		);
	}

	public function test_the_two_id_forms_of_the_api_pass_through_unchanged(): void {
		$seen = $this->capture( $this->ok_response( array( 'embed_id' => 'x' ) ) );
		$uuid = 'ad21f15e-7164-11ef-fd5b-87131cfe84d0';
		$b64  = 'rSHxXnFkEe_9W4cTHP6E0A';

		$client = new Client( 'k' );
		$client->get_embed( $uuid );
		$client->get_embed( $b64 );

		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/embed/' . $uuid . '?source=woocommerce', $seen->requests[0]['url'] );
		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/embed/' . $b64 . '?source=woocommerce', $seen->requests[1]['url'] );
	}

	public function test_a_401_is_an_auth_error_whatever_the_body_says(): void {
		$this->stub_remote_get( $this->error_response( 401, array( 'errors' => array( 'auth' => 'Nope.' ) ) ) );

		$this->expectException( AuthException::class );
		( new Client( 'k' ) )->probe();
	}

	/**
	 * Verified in ARkid's source: a missing, unknown or revoked key is a 403 with
	 * a structured body, not a 401. It must keep reading as a bad key.
	 */
	public function test_arkids_own_answer_to_a_bad_key_is_an_auth_error(): void {
		$this->stub_remote_get(
			$this->error_response( 403, array( 'errors' => array( 'auth' => 'Missing or invalid API key.' ) ) )
		);

		$this->expectException( AuthException::class );
		( new Client( 'k' ) )->list_embeds();
	}

	public function test_a_403_on_a_read_is_a_bad_key_even_with_other_words(): void {
		// Reads need nothing but a valid key, so a refused read is a refused key.
		$this->stub_remote_get(
			$this->error_response( 403, array( 'errors' => array( 'auth' => 'The user of this API key may not do this.' ) ) )
		);

		$this->expectException( AuthException::class );
		( new Client( 'k' ) )->get_embed( 'abc' );
	}

	/**
	 * The reason this mapping exists: a key that may only read, creating a
	 * viewer, is not "check your API key".
	 */
	public function test_a_key_without_write_permission_is_refused_not_rejected(): void {
		$this->capture(
			$this->error_response( 403, array( 'errors' => array( 'auth' => 'The user of this API key may not do this.' ) ) )
		);

		try {
			( new Client( 'k' ) )->create_embed( new EmbedInput( product_id: self::PRODUCT ) );
			$this->fail( 'A RefusedException was expected.' );
		} catch ( RefusedException $e ) {
			$this->assertSame( 403, $e->status() );
			$this->assertSame( 'The user of this API key may not do this.', $e->error_for( 'auth' ) );
		}
	}

	public function test_a_bad_key_on_a_write_is_still_an_auth_error(): void {
		$this->capture(
			$this->error_response( 403, array( 'errors' => array( 'auth' => 'Missing or invalid API key.' ) ) )
		);

		$this->expectException( AuthException::class );
		( new Client( 'k' ) )->create_embed( new EmbedInput( product_id: self::PRODUCT ) );
	}

	public function test_a_403_that_explains_itself_with_another_field_is_a_refusal(): void {
		$this->capture( $this->error_response( 403, array( 'errors' => array( 'plan' => 'Your plan allows 10 products.' ) ) ) );

		try {
			( new Client( 'k' ) )->create_embed( new EmbedInput( product_id: self::PRODUCT ) );
			$this->fail( 'A RefusedException was expected.' );
		} catch ( RefusedException $e ) {
			$this->assertSame( 'Your plan allows 10 products.', $e->error_for( 'plan' ) );
			$this->assertSame( 'Your plan allows 10 products.', $e->summary() );
			$this->assertFalse( $e->is_rate_limited() );
		}
	}

	public function test_a_payment_required_answer_is_a_refusal(): void {
		$this->stub_remote_get( $this->error_response( 402, array( 'errors' => array( 'plan' => 'Upgrade to add more viewers.' ) ) ) );

		$this->expectException( RefusedException::class );
		( new Client( 'k' ) )->list_products();
	}

	public function test_too_many_requests_is_a_refusal_that_says_how_long_to_wait(): void {
		$this->stub_remote_get(
			array(
				'response' => array( 'code' => 429 ),
				'headers'  => array( 'retry-after' => '30' ),
				'body'     => '{"errors":{"rate":"Slow down."}}',
			)
		);

		try {
			( new Client( 'k' ) )->stats();
			$this->fail( 'A RefusedException was expected.' );
		} catch ( RefusedException $e ) {
			$this->assertTrue( $e->is_rate_limited() );
			$this->assertSame( 30, $e->retry_after() );
		}
	}

	public function test_a_retry_after_that_is_a_date_or_huge_is_bounded(): void {
		$client = new Client( 'k' );

		foreach (
			array(
				'Wed, 21 Oct 2026 07:28:00 GMT' => 0,
				'999999'                        => 3600,
				''                              => 0,
			) as $header => $expected
		) {
			$this->stub_remote_get(
				array(
					'response' => array( 'code' => 429 ),
					'headers'  => array( 'retry-after' => (string) $header ),
					'body'     => '',
				)
			);
			try {
				$client->probe();
				$this->fail( 'A RefusedException was expected.' );
			} catch ( RefusedException $e ) {
				$this->assertSame( $expected, $e->retry_after(), "Retry-After: {$header}" );
			}
		}
	}

	/**
	 * @dataProvider validation_statuses
	 */
	public function test_a_rejected_body_is_a_validation_error_with_its_fields( int $status ): void {
		$this->capture(
			$this->error_response(
				$status,
				array(
					'errors' => array(
						'color_a'  => 'A color as #RRGGBB.',
						'variants' => 'Not a variant of the product: x.',
					),
				)
			)
		);

		try {
			( new Client( 'k' ) )->update_embed( 'abc', new EmbedInput( color_a: '#ffffff' ) );
			$this->fail( 'A ValidationException was expected.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( $status, $e->status() );
			$this->assertSame(
				array(
					'color_a'  => 'A color as #RRGGBB.',
					'variants' => 'Not a variant of the product: x.',
				),
				$e->errors()
			);
			$this->assertSame( 'A color as #RRGGBB.', $e->error_for( 'color_a' ) );
			$this->assertSame( '', $e->error_for( 'name' ) );
		}
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function validation_statuses(): array {
		return array(
			'unprocessable' => array( 422 ),
			'bad request'   => array( 400 ),
		);
	}

	public function test_error_messages_that_are_not_text_are_dropped_and_long_ones_cut(): void {
		$this->capture(
			$this->error_response(
				422,
				array(
					'errors' => array(
						'name'     => str_repeat( 'x', 900 ),
						'variants' => array( 'nested' ),
						'color_a'  => '',
						'color_b'  => 'Fine.',
					),
				)
			)
		);

		try {
			( new Client( 'k' ) )->update_embed( 'abc', new EmbedInput( name: 'n' ) );
			$this->fail( 'A ValidationException was expected.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( array( 'name', 'color_b' ), array_keys( $e->errors() ) );
			$this->assertSame( 500, strlen( $e->error_for( 'name' ) ) );
		}
	}

	/**
	 * The two answers that mean the plugin itself sent something wrong are
	 * neither a bad key nor a refusal.
	 *
	 * @dataProvider client_bug_statuses
	 */
	public function test_a_method_or_media_type_complaint_is_a_transport_error( int $status ): void {
		$this->capture( $this->error_response( $status, array( 'errors' => array( 'body' => 'Send JSON.' ) ) ) );

		$this->expectException( TransportException::class );
		( new Client( 'k' ) )->update_embed( 'abc', new EmbedInput( name: 'n' ) );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function client_bug_statuses(): array {
		return array(
			'method not allowed' => array( 405 ),
			'unsupported media'  => array( 415 ),
			'redirect'           => array( 302 ),
		);
	}

	public function test_a_404_names_what_was_not_found(): void {
		$client = new Client( 'k' );

		$this->stub_remote_get( $this->error_response( 404, array( 'errors' => array( 'embed_id' => 'Not found.' ) ) ) );
		try {
			$client->get_embed( 'abc' );
			$this->fail( 'A NotFoundException was expected.' );
		} catch ( NotFoundException $e ) {
			$this->assertSame( 'Viewer not found (HTTP 404).', $e->getMessage() );
			$this->assertSame( 404, $e->status() );
		}

		try {
			$client->stats();
			$this->fail( 'A NotFoundException was expected.' );
		} catch ( NotFoundException $e ) {
			$this->assertSame( 'Statistics not found (HTTP 404).', $e->getMessage() );
		}
	}

	public function test_a_create_posts_json_with_the_long_timeout_and_no_redirects(): void {
		$seen = $this->capture( $this->ok_response( array( 'embed_id' => 'new-1', 'product_id' => self::PRODUCT ), 201 ) );

		$embed = ( new Client( 'k' ) )->create_embed(
			new EmbedInput(
				product_id: strtoupper( self::PRODUCT ),
				color_a: '#AABBCC',
				environment: 'env9',
				variants: array( self::VARIANT )
			)
		);

		$request = $seen->requests[0];
		$this->assertSame( 'new-1', $embed->embed_id );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/embed?source=woocommerce', $request['url'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Accept'] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'], 'A redirect would replay a write as a GET.' );
		$this->assertSame(
			array(
				'product_id'  => self::PRODUCT,
				'color_a'     => '#aabbcc',
				'environment' => 'env9',
				'variants'    => array( self::VARIANT ),
			),
			json_decode( $request['args']['body'], true ),
			'Ids and colours are sent lower-case, and only the fields that were set.'
		);
		$this->assertSame( 'Basic ' . base64_encode( 'woocommerce:k' ), $request['args']['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_an_update_puts_to_the_viewer_with_only_the_changed_fields(): void {
		$seen = $this->capture( $this->ok_response( array( 'embed_id' => 'abc-123' ) ) );

		( new Client( 'k' ) )->update_embed( 'abc-123', new EmbedInput( color_b: '#000000', embedder_url: ' https://shop.example/p ' ) );

		$request = $seen->requests[0];
		$this->assertSame( 'PUT', $request['method'] );
		$this->assertSame( 'https://catalogue.arkid.app/api/ecom/embed/abc-123?source=woocommerce', $request['url'] );
		$this->assertSame(
			array(
				'color_b'      => '#000000',
				'embedder_url' => 'https://shop.example/p',
			),
			json_decode( $request['args']['body'], true )
		);
	}

	public function test_an_update_that_changes_nothing_sends_an_empty_object_not_a_list(): void {
		$seen = $this->capture( $this->ok_response( array( 'embed_id' => 'abc-123' ) ) );

		( new Client( 'k' ) )->update_embed( 'abc-123', new EmbedInput() );

		$this->assertSame( '{}', $seen->requests[0]['args']['body'], 'ARkid reads [] as a list and refuses it.' );
	}

	public function test_reads_use_get_with_the_short_timeout_and_no_body(): void {
		$seen = $this->capture( $this->ok_response( array( 'data' => array() ) ) );

		( new Client( 'k' ) )->list_embeds();

		$request = $seen->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 5, $request['args']['timeout'] );
		$this->assertArrayNotHasKey( 'body', $request['args'] );
		$this->assertArrayNotHasKey( 'Content-Type', $request['args']['headers'] );
	}

	public function test_the_heavier_reads_get_the_long_timeout(): void {
		$seen = $this->capture( $this->ok_response( array( 'data' => array() ) ) );

		$client = new Client( 'k' );
		$client->list_products();
		$client->stats( 30 );

		$this->assertSame( 10, $seen->requests[0]['args']['timeout'] );
		$this->assertSame( 10, $seen->requests[1]['args']['timeout'] );
	}

	/**
	 * @dataProvider invalid_creates
	 *
	 * @param array<string, string> $expected_fields
	 */
	public function test_an_invalid_write_is_refused_before_any_request( EmbedInput $input, array $expected_fields ): void {
		$seen = $this->capture( $this->ok_response( array() ) );

		try {
			( new Client( 'k' ) )->create_embed( $input );
			$this->fail( 'A ValidationException was expected.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( array(), $seen->requests, 'Nothing may be sent for an input that is known to fail.' );
			$this->assertSame( $expected_fields, array_keys( $e->errors() ) );
			$this->assertSame( 422, $e->status() );
		}
	}

	/**
	 * @return array<string, array{EmbedInput, array<int, string>}>
	 */
	public static function invalid_creates(): array {
		return array(
			'no product'      => array( new EmbedInput(), array( 'product_id' ) ),
			'bad product id'  => array( new EmbedInput( product_id: 'not-a-uuid' ), array( 'product_id' ) ),
			'bad colour'      => array( new EmbedInput( product_id: self::PRODUCT, color_a: 'red' ), array( 'color_a' ) ),
			'short colour'    => array( new EmbedInput( product_id: self::PRODUCT, color_b: '#fff' ), array( 'color_b' ) ),
			'empty variants'  => array( new EmbedInput( product_id: self::PRODUCT, variants: array() ), array( 'variants' ) ),
			'twice'           => array( new EmbedInput( product_id: self::PRODUCT, variants: array( self::VARIANT, strtoupper( self::VARIANT ) ) ), array( 'variants' ) ),
			'not a uuid'      => array( new EmbedInput( product_id: self::PRODUCT, variants: array( 'red' ) ), array( 'variants' ) ),
			'bad environment' => array( new EmbedInput( product_id: self::PRODUCT, environment: 'a b' ), array( 'environment' ) ),
			'ftp url'         => array( new EmbedInput( product_id: self::PRODUCT, embedder_url: 'ftp://x.example/' ), array( 'embedder_url' ) ),
			'long name'       => array( new EmbedInput( product_id: self::PRODUCT, name: str_repeat( 'n', 256 ) ), array( 'name' ) ),
			'several'         => array( new EmbedInput( color_a: 'x', color_b: 'y' ), array( 'product_id', 'color_a', 'color_b' ) ),
		);
	}

	public function test_an_update_may_not_name_a_product(): void {
		$this->capture( $this->ok_response( array() ) );

		try {
			( new Client( 'k' ) )->update_embed( 'abc', new EmbedInput( product_id: self::PRODUCT ) );
			$this->fail( 'A ValidationException was expected.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( 'Read-only field.', $e->error_for( 'product_id' ) );
		}
	}

	public function test_writes_need_a_key_too(): void {
		$seen = $this->capture( $this->ok_response( array() ) );

		try {
			( new Client( '' ) )->create_embed( new EmbedInput( product_id: self::PRODUCT ) );
			$this->fail( 'An AuthException was expected.' );
		} catch ( AuthException $e ) {
			$this->assertSame( array(), $seen->requests );
		}
	}

	public function test_the_catalogue_products_are_listed_with_their_variants(): void {
		$this->capture( $this->ok_response( ApiFixture::load( 'product-list' ) ) );

		$products = ( new Client( 'k' ) )->list_products();

		$this->assertCount( 6, $products );
		$this->assertSame( 'Product Demo', $products[0]->name );
		$this->assertCount( 11, $products[4]->variants );
	}

	public function test_the_environments_are_listed_in_the_order_of_the_api(): void {
		$this->capture( $this->ok_response( ApiFixture::load( 'environment-list' ) ) );

		$environments = ( new Client( 'k' ) )->list_environments( 'en_US' );

		$this->assertCount( 15, $environments );
		$this->assertSame( 'env1', $environments[0]->environment );
		$this->assertSame( '2D Environments', $environments[0]->group );
	}

	public function test_the_statistics_parse_into_a_dto(): void {
		$this->capture( $this->ok_response( ApiFixture::load( 'stats' ) ) );

		$stats = ( new Client( 'k' ) )->stats( 7 );

		$this->assertSame( 7, $stats->days );
		$this->assertSame( 1497, $stats->impressions->total );
	}

	public function test_a_list_of_rows_skips_what_is_not_a_row(): void {
		$this->capture( $this->ok_response( array( 'data' => array( 'x', 5, null, array( 'environment' => 'env1', 'name' => 'Floor', 'group' => 'g' ) ) ) ) );

		$this->assertCount( 1, ( new Client( 'k' ) )->list_environments() );
	}

	private const PRODUCT = '761023ba-4d2e-11f1-fc62-e0c83dd5547a';
	private const VARIANT = '887054d4-4d59-11f1-45ee-903d874f4ba7';


	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function ok_response( array $body, int $status = 200 ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function error_response( int $status, array $body ): array {
		return $this->ok_response( $body, $status );
	}

	private function stub_remote_get( mixed $response ): void {
		Functions\when( 'wp_remote_get' )->justReturn( $response );
		Functions\when( 'wp_remote_request' )->justReturn( $response );
	}

	/**
	 * Answer every request with `$response` and record what was asked.
	 *
	 * @param array<string, mixed> $response
	 */
	private function capture( array $response ): object {
		$seen           = new \stdClass();
		$seen->requests = array();

		$record = static function ( string $method ) use ( $seen, $response ) {
			return static function ( string $url, array $args ) use ( $seen, $response, $method ): array {
				$seen->requests[] = array(
					'method' => $args['method'] ?? $method,
					'url'    => $url,
					'args'   => $args,
				);
				return $response;
			};
		};

		Functions\when( 'wp_remote_get' )->alias( $record( 'GET' ) );
		Functions\when( 'wp_remote_request' )->alias( $record( 'GET' ) );

		return $seen;
	}
}
