<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Support;

use Arkid\CatalogueLink\Support\Url;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Frontend/wp-parse-url-stub.php';

final class UrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => wp_parse_url_stub( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_https_url_is_kept(): void {
		$this->assertSame(
			'https://catalogue.arkid.app/configure/edit/abc?x=1',
			Url::https( 'https://catalogue.arkid.app/configure/edit/abc?x=1' )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_https(): array {
		return array(
			'http'            => array( 'http://catalogue.arkid.app/x' ),
			'javascript'      => array( 'javascript:alert(1)' ),
			'data'            => array( 'data:text/html;base64,PHNjcmlwdD4=' ),
			'protocol-relative' => array( '//catalogue.arkid.app/x' ),
			'relative'        => array( '/wp-admin/' ),
			'no host'         => array( 'https:///path' ),
			'ftp'             => array( 'ftp://catalogue.arkid.app/x' ),
			'empty'           => array( '' ),
		);
	}

	/**
	 * `esc_url()` lets all of these through except `javascript:`; links and images
	 * from ARkid's API must be https.
	 *
	 * @dataProvider not_https
	 */
	public function test_anything_else_is_dropped( string $url ): void {
		$this->assertSame( '', Url::https( $url ) );
	}
}
