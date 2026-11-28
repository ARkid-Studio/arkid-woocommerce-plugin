<?php
/**
 * Removes the accents of a text the way Unicode normalisation does, and no more.
 *
 * The matcher is an exact port of ARkid's Shopify matcher, which reads a name as
 * `text.normalize("NFD").replace(/\p{M}/gu, "")`: split every character into its
 * base letter and combining marks (NFD), then drop the marks. "é" becomes "e" and
 * "Ünï" becomes "Uni", but "ß", "ø", "æ", "ł" and "đ" stay what they are because
 * Unicode has no base letter for them, so "Straße" and "Strasse" are different
 * words in both plugins. WordPress's `remove_accents()` and `iconv( '//TRANSLIT' )`
 * fold those letters as well and would give other scores, so neither is used.
 *
 * With ext-intl this is `Normalizer`. Without it, which many WordPress hosts are,
 * a table of every character that NFD splits (`nfd-table.php`, generated from ICU)
 * gives the same answer, and Hangul syllables, which NFD splits into letters that
 * are not marks, are split by their arithmetic. Both ways are public so that a
 * test can hold them to the same results on any host.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class Decomposition {

	/** Hangul syllables, and how their code points are built from conjoining letters (Unicode 3.12). */
	private const HANGUL_FIRST = 0xAC00;
	private const HANGUL_L     = 0x1100;
	private const HANGUL_V     = 0x1161;
	private const HANGUL_T     = 0x11A7;
	private const HANGUL_V_NUM = 21;
	private const HANGUL_T_NUM = 28;

	/**
	 * Whether the table is used even where ext-intl is available. Only a test
	 * sets it, to hold the fallback to the results of `Normalizer` on a host that
	 * has both.
	 *
	 * @var bool
	 */
	private static bool $table_only = false;

	/**
	 * The table as `strtr()` takes it, once it has been read.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $table = null;

	/**
	 * The text with every accent removed, by whichever way this host can.
	 *
	 * A text that is not valid UTF-8 has no words, so it gives ''.
	 */
	public static function strip_marks( string $text ): string {
		// Plain ASCII has neither a decomposition nor a mark: most names, and cheap.
		if ( 1 !== preg_match( '/[^\x00-\x7F]/', $text ) ) {
			return $text;
		}

		return self::has_intl()
			? self::strip_marks_with_intl( $text )
			: self::strip_marks_with_table( $text );
	}

	/**
	 * Whether `strip_marks()` normalises with ext-intl.
	 */
	public static function has_intl(): bool {
		return ! self::$table_only && class_exists( '\Normalizer' );
	}

	/**
	 * Test seam: use the table whatever this PHP has.
	 */
	public static function force_table( bool $only ): void {
		self::$table_only = $only;
	}

	/**
	 * NFD, then every combining mark removed.
	 */
	public static function strip_marks_with_intl( string $text ): string {
		$decomposed = \Normalizer::normalize( $text, \Normalizer::FORM_D );
		if ( false === $decomposed ) {
			return '';
		}

		return preg_replace( '/\p{M}+/u', '', $decomposed ) ?? '';
	}

	/**
	 * The same result without ext-intl.
	 */
	public static function strip_marks_with_table( string $text ): string {
		if ( 1 !== preg_match( '//u', $text ) ) {
			return '';
		}

		// Hangul first: `strtr()` below must not see the letters it makes.
		$text = preg_replace_callback(
			'/[\x{AC00}-\x{D7A3}]+/u',
			static fn( array $found ): string => self::decompose_hangul( $found[0] ),
			$text
		) ?? '';

		return preg_replace( '/\p{M}+/u', '', strtr( $text, self::table() ) ) ?? '';
	}

	/**
	 * Hangul syllables as the conjoining letters (leading consonant, vowel and, if
	 * there is one, trailing consonant) that NFD makes of them.
	 */
	private static function decompose_hangul( string $syllables ): string {
		$out = '';
		foreach ( self::characters( $syllables ) as $syllable ) {
			$code  = self::code_point( $syllable ) - self::HANGUL_FIRST;
			$vowel = intdiv( $code % ( self::HANGUL_V_NUM * self::HANGUL_T_NUM ), self::HANGUL_T_NUM );
			$tail  = $code % self::HANGUL_T_NUM;

			$out .= self::character( self::HANGUL_L + intdiv( $code, self::HANGUL_V_NUM * self::HANGUL_T_NUM ) );
			$out .= self::character( self::HANGUL_V + $vowel );
			if ( $tail > 0 ) {
				$out .= self::character( self::HANGUL_T + $tail );
			}
		}

		return $out;
	}

	/**
	 * The `strtr()` map of the generated table: each character that NFD splits,
	 * and what is left of it without its marks.
	 *
	 * @return array<string, string>
	 */
	private static function table(): array {
		if ( null !== self::$table ) {
			return self::$table;
		}

		/** @var array<int|string, string> $groups */
		$groups = require __DIR__ . '/nfd-table.php';

		$map = array();
		foreach ( $groups as $left => $sources ) {
			foreach ( self::characters( $sources ) as $source ) {
				$map[ $source ] = (string) $left;
			}
		}
		self::$table = $map;

		return $map;
	}

	/**
	 * The characters of a text.
	 *
	 * @return array<int, string>
	 */
	private static function characters( string $text ): array {
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return false === $characters ? array() : $characters;
	}

	/**
	 * The code point of one character of three bytes in UTF-8 (U+0800 to U+FFFF,
	 * which is where Hangul syllables are).
	 */
	private static function code_point( string $char ): int {
		return 3 === strlen( $char )
			? ( ( ord( $char[0] ) & 0x0F ) << 12 ) | ( ( ord( $char[1] ) & 0x3F ) << 6 ) | ( ord( $char[2] ) & 0x3F )
			: 0;
	}

	/**
	 * A character of U+0800 to U+FFFF in UTF-8, built without mbstring.
	 */
	private static function character( int $code_point ): string {
		return chr( 0xE0 | ( $code_point >> 12 ) ) . chr( 0x80 | ( ( $code_point >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code_point & 0x3F ) );
	}
}
