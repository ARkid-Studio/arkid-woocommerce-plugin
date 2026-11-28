<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Support;

use Arkid\CatalogueLink\Support\Text;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

/**
 * Text helpers on top of WordPress' own remove_accents().
 */
final class TextTest extends TestCase {

	/**
	 * @dataProvider fold_cases
	 */
	public function test_fold( string $input, string $expected ): void {
		$this->assertSame( $expected, Text::fold( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function fold_cases(): array {
		return array(
			'german'            => array( 'Müller Möbel Straße', 'muller mobel strasse' ),
			'german capitals'   => array( 'ÄRZTEHAUS ÖL ÜBER', 'arztehaus ol uber' ),
			'danish'            => array( 'Ørsted Æble Å', 'orsted aeble a' ),
			'polish'            => array( 'Łódź Krzesło Żółć', 'lodz krzeslo zolc' ),
			'dutch'             => array( 'Café Coöperatie', 'cafe cooperatie' ),
			'spanish'           => array( 'Niño Camión', 'nino camion' ),
			'decomposed accent' => array( "Mu\u{0308}ller", 'muller' ),
			'cjk is kept'       => array( '日本ブランド 椅子', '日本ブランド 椅子' ),
			'ascii'             => array( 'Felix Learning', 'felix learning' ),
		);
	}

	public function test_fold_ignores_the_site_language(): void {
		switch_to_locale( 'da_DK' );

		try {
			// Under da_DK core spells "Ø" as "Oe" and "Å" as "Aa".
			$this->assertSame( 'orsted', Text::fold( 'Ørsted' ) );
		} finally {
			restore_previous_locale();
		}
	}

	public function test_compare_orders_names_for_the_viewers_table(): void {
		$names = array( 'Zebra', 'apple', 'Äpfel', 'Øl', 'Oak', 'Banana', 'chair 10', 'Chair 2' );

		usort( $names, array( Text::class, 'compare' ) );

		$this->assertSame( array( 'Äpfel', 'apple', 'Banana', 'chair 10' ), array( $names[0], $names[1], $names[2], $names[4] ), 'Letters are compared without regard to case or accent, numbers by value.' );
		$this->assertSame( 'Chair 2', $names[3] );
		$this->assertSame( 'Zebra', $names[7] );
	}

	public function test_the_fallback_order_agrees_on_the_common_cases(): void {
		$names = array( 'Zebra', 'apple', 'Äpfel', 'Banana', 'Chair 10', 'Chair 2' );

		usort( $names, array( Text::class, 'compare_folded' ) );

		$this->assertSame( array( 'Äpfel', 'apple', 'Banana', 'Chair 2', 'Chair 10', 'Zebra' ), $names );
	}
}
