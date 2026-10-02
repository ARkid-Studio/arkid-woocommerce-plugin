<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api;

use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\Client;
use Arkid\CatalogueLink\Api\NotFoundException;
use Arkid\CatalogueLink\Api\TransportException;
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

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function ok_response( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	private function stub_remote_get( mixed $response ): void {
		Functions\when( 'wp_remote_get' )->justReturn( $response );
	}
}
