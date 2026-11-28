<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Matching;

use Arkid\CatalogueLink\Matching\Decomposition;
use PHPUnit\Framework\TestCase;

/**
 * Without ext-intl the table has to give what `Normalizer` gives. The comparison
 * tests need ext-intl to know the answer and skip themselves where it is missing;
 * CI runs the suite on both. The table tests need nothing.
 */
final class DecompositionTest extends TestCase {

	protected function tearDown(): void {
		Decomposition::force_table( false );
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function texts(): array {
		return array(
			'ascii is left alone'                  => array( 'Maxi-Cosi Fame 5', 'Maxi-Cosi Fame 5' ),
			'latin accents'                        => array( 'Café Crème Brûlée Ünïcödé Nåme', 'Cafe Creme Brulee Unicode Name' ),
			'accents on accents'                   => array( 'ǖ ǘ ȫ ṩ Ǻ ế ệ', 'u u o s A e e' ),
			'a decomposed accent'                  => array( "Cafe\u{301} u\u{308}", 'Cafe u' ),
			'letters without a base are not folded' => array( 'ß ø æ ł đ ð þ ı', 'ß ø æ ł đ ð þ ı' ),
			'greek tonos and polytonic'            => array( 'Άλφα ῷ ΐ', 'Αλφα ω ι' ),
			'cyrillic breve and diaeresis'         => array( 'Йё Ёлка', 'Ие Елка' ),
			'kana voicing'                         => array( 'ガギグ ぱぴぷ', 'カキク はひふ' ),
			'hangul is split into letters'         => array( '한국어', "\u{1112}\u{1161}\u{11AB}\u{1100}\u{116E}\u{11A8}\u{110B}\u{1165}" ),
			'cjk compatibility ideographs'         => array( "\u{F900}\u{2F800}", "\u{8C48}\u{4E3D}" ),
			'singletons'                           => array( "\u{212B} \u{212A} \u{2126}", "A K \u{3A9}" ),
			'astral'                               => array( "\u{1D15E}x \u{1109A}", "\u{1D157}x \u{11099}" ),
			'the empty text'                       => array( '', '' ),
		);
	}

	/**
	 * @dataProvider texts
	 */
	public function test_the_table_removes_the_accents_unicode_removes( string $text, string $expected ): void {
		$this->assertSame( $expected, Decomposition::strip_marks_with_table( $text ) );
	}

	/**
	 * @dataProvider texts
	 */
	public function test_normalizer_gives_the_same( string $text, string $expected ): void {
		if ( ! class_exists( '\Normalizer' ) ) {
			$this->markTestSkipped( 'ext-intl is not loaded.' );
		}

		$this->assertSame( $expected, Decomposition::strip_marks_with_intl( $text ) );
	}

	public function test_strip_marks_uses_the_table_when_asked_to_and_says_so(): void {
		Decomposition::force_table( true );

		$this->assertFalse( Decomposition::has_intl() );
		$this->assertSame( 'Cafe', Decomposition::strip_marks( 'Café' ) );

		Decomposition::force_table( false );
		$this->assertSame( class_exists( '\Normalizer' ), Decomposition::has_intl() );
		$this->assertSame( 'Cafe', Decomposition::strip_marks( 'Café' ) );
	}

	public function test_a_text_that_is_not_utf_8_has_nothing_left_on_either_way(): void {
		$this->assertSame( '', Decomposition::strip_marks_with_table( "caf\xE9" ) );
		if ( class_exists( '\Normalizer' ) ) {
			$this->assertSame( '', Decomposition::strip_marks_with_intl( "caf\xE9" ) );
		}
	}

	public function test_every_hangul_syllable_is_split_as_normalizer_splits_it(): void {
		if ( ! class_exists( '\Normalizer' ) ) {
			$this->markTestSkipped( 'ext-intl is not loaded.' );
		}

		$syllables = '';
		for ( $code_point = 0xAC00; $code_point <= 0xD7A3; $code_point++ ) {
			$syllables .= self::utf8( $code_point ) . ' ';
		}

		$this->assertSame( Decomposition::strip_marks_with_intl( $syllables ), Decomposition::strip_marks_with_table( $syllables ) );
	}

	/**
	 * Unicode never changes a canonical decomposition, so a table made with one
	 * ICU is right with every later one; a character a later Unicode adds is the
	 * only thing it can miss, and those are left out of this check.
	 */
	public function test_the_table_agrees_with_normalizer_on_every_character_of_unicode_15(): void {
		if ( ! class_exists( '\Normalizer' ) || ! class_exists( '\IntlChar' ) ) {
			$this->markTestSkipped( 'ext-intl is not loaded.' );
		}

		$wrong = array();
		foreach ( array( array( 0x80, 0x2FFF ), array( 0x3000, 0xFFFF ), array( 0x10000, 0x1FFFF ), array( 0x2F800, 0x2FA1F ) ) as [ $first, $last ] ) {
			for ( $code_point = $first; $code_point <= $last; $code_point++ ) {
				if ( ( $code_point >= 0xD800 && $code_point <= 0xDFFF ) || ( $code_point >= 0xAC00 && $code_point <= 0xD7A3 ) ) {
					continue;
				}
				$age = \IntlChar::charAge( $code_point );
				if ( array( 15, 1, 0, 0 ) < $age ) {
					continue;
				}

				$char = self::utf8( $code_point );
				if ( Decomposition::strip_marks_with_intl( $char ) !== Decomposition::strip_marks_with_table( $char ) ) {
					$wrong[] = sprintf( 'U+%04X', $code_point );
				}
			}
		}

		$this->assertSame( array(), $wrong, 'characters the table leaves different from Normalizer' );
	}

	private static function utf8( int $code_point ): string {
		return html_entity_decode( '&#' . $code_point . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
