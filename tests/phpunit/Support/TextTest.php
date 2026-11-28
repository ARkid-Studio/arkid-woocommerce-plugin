<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Support;

use Arkid\CatalogueLink\Support\Text;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * `remove_accents()` is WordPress core and is stubbed here with the few letters
 * the cases use. The integration suite runs the same helpers on the real one.
 */
final class TextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_user_locale' )->justReturn( 'en_US' );
		Functions\when( 'remove_accents' )->alias(
			static function ( string $text, string $locale = '' ): string {
				if ( 'en_US' !== $locale ) {
					return '[locale-dependent fold]' . $text;
				}

				return strtr(
					$text,
					array(
						'ä' => 'a',
						'Ä' => 'A',
						'ö' => 'o',
						'ü' => 'u',
						'Ü' => 'U',
						'ß' => 's', // As core does outside German sites; Text::fold() handles it first.
						'é' => 'e',
					)
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider lower_cases
	 */
	public function test_lower_handles_non_ascii_letters( string $input, string $expected ): void {
		$this->assertSame( $expected, Text::lower( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function lower_cases(): array {
		return array(
			'ascii'          => array( 'Felix Learning', 'felix learning' ),
			'german umlauts' => array( 'ÄPFEL ÖL ÜBER', 'äpfel öl über' ),
			'polish'         => array( 'ŁÓDŹ', 'łódź' ),
			'danish'         => array( 'ÆBLE ØL ÅR', 'æble øl år' ),
			'cyrillic'       => array( 'МОСКВА', 'москва' ),
			'cjk unchanged'  => array( '日本語ブランド', '日本語ブランド' ),
			'empty'          => array( '', '' ),
		);
	}

	public function test_fold_removes_accents_with_a_locale_independent_table(): void {
		$this->assertSame( 'muller strasse cafe', Text::fold( 'Müller Straße Café' ) );
	}

	public function test_fold_is_case_insensitive(): void {
		$this->assertSame( Text::fold( 'ÜBER' ), Text::fold( 'über' ) );
	}

	/**
	 * @dataProvider word_cases
	 *
	 * @param array<int, string> $expected
	 */
	public function test_words( string $query, array $expected ): void {
		$this->assertSame( $expected, Text::words( $query ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function word_cases(): array {
		return array(
			'one word'                 => array( 'Felix', array( 'felix' ) ),
			'two words'                => array( 'felix learning', array( 'felix', 'learning' ) ),
			'extra white space'        => array( "  felix \t  learning \n", array( 'felix', 'learning' ) ),
			'folded'                   => array( 'Müller STRAßE', array( 'muller', 'strasse' ) ),
			'duplicates collapse'      => array( 'tower Tower TOWER', array( 'tower' ) ),
			'no-break space separates' => array( "felix\u{00A0}tower", array( 'felix', 'tower' ) ),
			'ideographic space'        => array( "商品\u{3000}ブランド", array( '商品', 'ブランド' ) ),
			'empty'                    => array( '', array() ),
			'only white space'         => array( " \t\n ", array() ),
		);
	}

	public function test_words_survives_a_query_that_is_not_valid_utf8(): void {
		$words = Text::words( "felix \xB1\x31 tower" );

		$this->assertCount( 3, $words );
		$this->assertSame( 'felix', $words[0] );
		$this->assertSame( 'tower', $words[2] );
	}

	/**
	 * `strcmp()` ordered "Zebra" before "apple" and "Äpfel" after "Zebra".
	 */
	public function test_compare_sorts_case_insensitively_and_by_letter(): void {
		$names = array( 'Zebra', 'apple', 'Äpfel', 'Banana', 'apfel' );

		usort( $names, array( Text::class, 'compare' ) );

		$this->assertSame( array( 'apfel', 'Äpfel', 'apple', 'Banana', 'Zebra' ), $names );
	}

	public function test_compare_orders_numbers_naturally(): void {
		$names = array( 'Chair 10', 'Chair 2', 'Chair 1' );

		usort( $names, array( Text::class, 'compare' ) );

		$this->assertSame( array( 'Chair 1', 'Chair 2', 'Chair 10' ), $names );
	}

	public function test_compare_returns_zero_for_equal_names(): void {
		$this->assertSame( 0, Text::compare( 'Felix', 'Felix' ) );
	}

	public function test_compare_follows_the_collation_of_the_user_locale(): void {
		if ( ! class_exists( '\Collator' ) ) {
			$this->markTestSkipped( 'ext-intl is not available.' );
		}

		// Swedish sorts "Å" after "Z"; German sorts it with "A".
		Functions\when( 'get_user_locale' )->justReturn( 'sv_SE' );
		$this->assertGreaterThan( 0, Text::compare( 'Åland', 'Zebra' ) );

		Functions\when( 'get_user_locale' )->justReturn( 'de_DE' );
		$this->assertLessThan( 0, Text::compare( 'Åland', 'Zebra' ) );
	}

	/**
	 * The path hosts without ext-intl take.
	 *
	 * @dataProvider folded_comparison_cases
	 */
	public function test_compare_folded( string $a, string $b, int $expected_sign ): void {
		$this->assertSame( $expected_sign, Text::compare_folded( $a, $b ) <=> 0 );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function folded_comparison_cases(): array {
		return array(
			'case is ignored'      => array( 'apple', 'Zebra', -1 ),
			'case, reversed'       => array( 'Zebra', 'apple', 1 ),
			'accent is ignored'    => array( 'Äpfel', 'Zebra', -1 ),
			'accent sorts as base' => array( 'Äpfel', 'Apfel', 0 ),
			'natural numbers'      => array( 'Chair 2', 'Chair 10', -1 ),
		);
	}
}
