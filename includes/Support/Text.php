<?php
/**
 * Text helpers for searching and ordering names that may not be ASCII.
 *
 * Brand and product names arrive in many languages, and the plugin ships in
 * eight locales, so byte-wise `strtolower()` / `strcmp()` give wrong answers
 * for them. `mbstring` and `intl` are optional on WordPress hosts: every
 * function here uses them when present and degrades to a plainer result when
 * not, never to a fatal error.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Support;

defined( 'ABSPATH' ) || exit;

final class Text {

	/**
	 * Locale handed to `remove_accents()`. WordPress otherwise follows the site
	 * language and spells some letters out (German "ü" becomes "ue"), which would
	 * make "muller" stop finding "Müller" on a German site.
	 */
	private const FOLD_LOCALE = 'en_US';

	/**
	 * Collators already built, keyed by locale. `false` records a failed attempt.
	 *
	 * @var array<string, \Collator|false>
	 */
	private static array $collators = array();

	/**
	 * Lower-case a UTF-8 string.
	 *
	 * Falls back to ASCII-only `strtolower()` when mbstring is missing.
	 */
	public static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $value, 'UTF-8' )
			: strtolower( $value );
	}

	/**
	 * The case- and accent-insensitive form two strings are compared in.
	 *
	 * Letters without a base letter in Unicode are folded the way WordPress folds
	 * them: "ø" to "o", "æ" to "ae", "ł" to "l". The one exception is "ß", which
	 * WordPress turns into a single "s" unless the site is German; it becomes
	 * "ss" here so that "strasse" finds "Straße" on every site.
	 *
	 * Text typed as a base letter plus a combining accent ("u" and U+0308, as
	 * macOS and many spreadsheet exports write it) is composed by WordPress only
	 * when ext-intl is available. The combining accents are therefore removed here
	 * as well, so the result does not depend on the host.
	 */
	public static function fold( string $value ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( str_replace( array( 'ß', 'ẞ' ), 'ss', $value ), self::FOLD_LOCALE );
		}

		// U+0300 to U+036F: the combining diacritical marks of the Latin, Greek and Cyrillic scripts.
		// Kana voicing marks (U+3099, U+309A) sit elsewhere and are left alone.
		$value = preg_replace( '/[\x{0300}-\x{036F}]+/u', '', $value ) ?? $value;

		return self::lower( $value );
	}

	/**
	 * Split a search query into folded, distinct words.
	 *
	 * Any Unicode white space separates words, including the ideographic space
	 * that CJK keyboards type. A query without a word gives an empty list.
	 *
	 * @return array<int, non-empty-string>
	 */
	public static function words( string $query ): array {
		$folded = trim( self::fold( $query ) );
		if ( '' === $folded ) {
			return array();
		}

		$words = preg_split( '/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words ) {
			// Not valid UTF-8: split on ASCII white space instead of giving up.
			$words = preg_split( '/\s+/', $folded, -1, PREG_SPLIT_NO_EMPTY );
		}

		return false === $words ? array() : array_values( array_unique( $words ) );
	}

	/**
	 * Three-way comparison for sorting names the way a person would.
	 *
	 * Uses the ICU collation of the user's locale when ext-intl is available
	 * ("apple" before "Zebra", "Äpfel" next to "Apfel", "item 2" before
	 * "item 10"), and a folded natural-order comparison when it is not.
	 *
	 * @return int Negative, zero or positive, like `strcmp()`.
	 */
	public static function compare( string $a, string $b ): int {
		$collator = self::collator();
		if ( null !== $collator ) {
			$result = $collator->compare( $a, $b );
			if ( false !== $result ) {
				return $result;
			}
		}

		return self::compare_folded( $a, $b );
	}

	/**
	 * The comparison used when ext-intl is not available.
	 */
	public static function compare_folded( string $a, string $b ): int {
		return strnatcmp( self::fold( $a ), self::fold( $b ) );
	}

	private static function collator(): ?\Collator {
		if ( ! class_exists( '\Collator' ) ) {
			return null;
		}

		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : 'en_US';

		if ( ! isset( self::$collators[ $locale ] ) ) {
			self::$collators[ $locale ] = self::build_collator( $locale );
		}

		$collator = self::$collators[ $locale ];

		return false === $collator ? null : $collator;
	}

	private static function build_collator( string $locale ): \Collator|false {
		try {
			$collator = new \Collator( $locale );
			$collator->setAttribute( \Collator::NUMERIC_COLLATION, \Collator::ON );
		} catch ( \Throwable $e ) {
			return false;
		}

		return $collator;
	}
}
