<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api;

use Arkid\CatalogueLink\Api\EmbedInput;
use PHPUnit\Framework\TestCase;

/**
 * The local copy of ARkid's write rules (`EcomEmbedInput`).
 */
final class EmbedInputTest extends TestCase {

	private const PRODUCT = '761023ba-4d2e-11f1-fc62-e0c83dd5547a';
	private const VARIANT = '887054d4-4d59-11f1-45ee-903d874f4ba7';
	private const OTHER   = '8dae23b3-4d59-11f1-88ee-7e3b4ee7b7d2';

	public function test_an_empty_update_is_valid_and_sends_nothing(): void {
		$input = new EmbedInput();

		$this->assertSame( array(), $input->errors( false ) );
		$this->assertSame( array(), $input->to_body( false ) );
	}

	public function test_a_create_needs_a_product(): void {
		$this->assertSame( array( 'product_id' ), array_keys( ( new EmbedInput() )->errors( true ) ) );
		$this->assertSame( array(), ( new EmbedInput( product_id: self::PRODUCT ) )->errors( true ) );
	}

	public function test_an_update_refuses_a_product(): void {
		$errors = ( new EmbedInput( product_id: self::PRODUCT ) )->errors( false );

		$this->assertSame( array( 'product_id' => 'Read-only field.' ), $errors );
	}

	public function test_the_body_carries_only_what_is_set_in_the_order_ARkid_documents(): void {
		$input = new EmbedInput(
			product_id: self::PRODUCT,
			name: 'Hero',
			color_a: '#FFAA00',
			color_b: '#00AAFF',
			environment: 'env9',
			variants: array( strtoupper( self::OTHER ), self::VARIANT ),
			embedder_url: ' https://shop.example/p '
		);

		$this->assertSame(
			array(
				'product_id'   => self::PRODUCT,
				'name'         => 'Hero',
				'color_a'      => '#ffaa00',
				'color_b'      => '#00aaff',
				'environment'  => 'env9',
				'variants'     => array( self::OTHER, self::VARIANT ),
				'embedder_url' => 'https://shop.example/p',
			),
			$input->to_body( true )
		);
		$this->assertArrayNotHasKey( 'product_id', $input->to_body( false ) );
	}

	public function test_an_empty_name_is_a_value_not_an_omission(): void {
		$this->assertSame( array( 'name' => '' ), ( new EmbedInput( name: '' ) )->to_body( false ) );
	}

	/**
	 * @dataProvider bad_colors
	 */
	public function test_a_colour_must_be_six_hex_digits_with_a_hash( string $color ): void {
		$this->assertSame( array( 'color_a' ), array_keys( ( new EmbedInput( color_a: $color ) )->errors( false ) ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_colors(): array {
		return array(
			'no hash'   => array( 'ffffff' ),
			'short'     => array( '#fff' ),
			'long'      => array( '#fffffff' ),
			'not hex'   => array( '#gggggg' ),
			'name'      => array( 'red' ),
			'trailing'  => array( "#ffffff\n" ),
			'empty'     => array( '' ),
			'rgb()'     => array( 'rgb(0,0,0)' ),
		);
	}

	public function test_a_name_may_be_255_characters_but_not_256(): void {
		$this->assertSame( array(), ( new EmbedInput( name: str_repeat( 'ä', 255 ) ) )->errors( false ) );
		$this->assertSame( array( 'name' ), array_keys( ( new EmbedInput( name: str_repeat( 'ä', 256 ) ) )->errors( false ) ) );
	}

	/**
	 * @dataProvider bad_variants
	 *
	 * @param array<int|string, mixed> $variants
	 */
	public function test_the_variants_must_be_a_non_empty_list_of_distinct_uuids( array $variants ): void {
		$input = new EmbedInput( variants: $variants );

		$this->assertSame( array( 'variants' ), array_keys( $input->errors( false ) ) );
	}

	/**
	 * @return array<string, array{array<int|string, mixed>}>
	 */
	public static function bad_variants(): array {
		return array(
			'none'         => array( array() ),
			'keyed'        => array( array( 'a' => self::VARIANT ) ),
			'not a uuid'   => array( array( 'blue' ) ),
			'a number'     => array( array( 5 ) ),
			'twice'        => array( array( self::VARIANT, self::VARIANT ) ),
			'twice, cased' => array( array( self::VARIANT, strtoupper( self::VARIANT ) ) ),
		);
	}

	/**
	 * @dataProvider bad_urls
	 */
	public function test_the_page_url_must_be_an_http_or_https_url( string $url ): void {
		$this->assertSame( array( 'embedder_url' ), array_keys( ( new EmbedInput( embedder_url: $url ) )->errors( false ) ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_urls(): array {
		return array(
			'empty'       => array( '' ),
			'no scheme'   => array( 'shop.example/p' ),
			'javascript'  => array( 'javascript:alert(1)' ),
			'ftp'         => array( 'ftp://shop.example/' ),
			'with space'  => array( 'https://shop.example/a b' ),
			'too long'    => array( 'https://shop.example/' . str_repeat( 'a', 500 ) ),
		);
	}

	public function test_an_environment_is_a_short_token(): void {
		$this->assertSame( array(), ( new EmbedInput( environment: 'env13' ) )->errors( false ) );
		$this->assertSame( array( 'environment' ), array_keys( ( new EmbedInput( environment: 'env 13' ) )->errors( false ) ) );
		$this->assertSame( array( 'environment' ), array_keys( ( new EmbedInput( environment: '' ) )->errors( false ) ) );
	}
}
