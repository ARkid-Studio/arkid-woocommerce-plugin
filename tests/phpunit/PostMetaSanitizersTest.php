<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests;

use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use PHPUnit\Framework\TestCase;

final class PostMetaSanitizersTest extends TestCase {

	/**
	 * @dataProvider embed_id_cases
	 */
	public function test_sanitize_embed_id( mixed $input, string $expected ): void {
		$this->assertSame( $expected, PostMeta::sanitize_embed_id( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function embed_id_cases(): array {
		return array(
			'uuid'          => array( 'ad21f15e-7164-11ef-fd5b-87131cfe84d0', 'ad21f15e-7164-11ef-fd5b-87131cfe84d0' ),
			'short id'      => array( 'abc123', 'abc123' ),
			'empty string'  => array( '', '' ),
			'spaces'        => array( ' abc ', '' ),
			'special chars' => array( 'abc!def', '' ),
			'too long'      => array( str_repeat( 'a', 65 ), '' ),
			'non-string'    => array( 42, '' ),
			'null'          => array( null, '' ),
		);
	}

	/**
	 * @dataProvider position_cases
	 */
	public function test_sanitize_position( mixed $input, string $expected ): void {
		$this->assertSame( $expected, PostMeta::sanitize_position( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function position_cases(): array {
		return array(
			'first_image'   => array( Options::POSITION_FIRST_IMAGE, Options::POSITION_FIRST_IMAGE ),
			'last_image'    => array( Options::POSITION_LAST_IMAGE, Options::POSITION_LAST_IMAGE ),
			'above_product' => array( Options::POSITION_ABOVE_PRODUCT, Options::POSITION_ABOVE_PRODUCT ),
			'below_product' => array( Options::POSITION_BELOW_PRODUCT, Options::POSITION_BELOW_PRODUCT ),
			'button_only'   => array( Options::POSITION_BUTTON_ONLY, Options::POSITION_BUTTON_ONLY ),
			'unknown value' => array( 'sidebar', '' ),
			'empty'         => array( '', '' ),
			'non-string'    => array( 42, '' ),
		);
	}
}
