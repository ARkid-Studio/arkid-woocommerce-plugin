<?php
/**
 * How alike two names are: the port of ARkid's Shopify matcher (`match.ts`).
 *
 * A name is read as its words: accents and case removed, split at everything that
 * is not a letter or a number, each word once. Two words are the same when they
 * are equal or when one is a prefix of the other and at least four characters
 * long ("wheel", "wheels"). Each word pairs with at most one word of the other
 * name, so "sofa sofabed" shares one word with "sofa", not two. The similarity is
 * the mean of the overlap (the shared words over the shorter name's words) and the
 * Dice coefficient. A product matches a candidate from 0.7 up, by its title alone
 * or with its brand, against the candidate's product name alone or with its brand.
 *
 * Every function here is the reference: a plain loop that is easy to hold against
 * the Shopify code. `CandidateIndex` finds the same answers without scoring every
 * candidate, and a test holds it to these.
 *
 * Differences from the JavaScript that cannot be avoided: without mbstring the
 * lower-casing covers ASCII only, and the length of a word is counted in UTF-16
 * code units, as JavaScript does, so that a word of two astral characters is as
 * long as there.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

use Arkid\CatalogueLink\Support\Text;

defined( 'ABSPATH' ) || exit;

final class NameMatcher {

	/** Below this similarity, a candidate is no suggestion. */
	public const THRESHOLD = 0.7;

	/** A word that is a prefix of another counts as the same word from this length. */
	private const MIN_PREFIX_LENGTH = 4;

	/**
	 * The words of a name, lower case and without accents, in reading order.
	 *
	 * @return array<int, string>
	 */
	public static function words( string $text ): array {
		$text = self::lower( Decomposition::strip_marks( $text ) );
		if ( '' === $text ) {
			return array();
		}

		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return false === $words ? array() : $words;
	}

	/**
	 * The words of a name, each once, at the place it first stands.
	 *
	 * @return array<int, string>
	 */
	public static function unique_words( string $text ): array {
		return array_values( array_unique( self::words( $text ) ) );
	}

	/**
	 * Equal words, or one a prefix of the other, such as "wheel" and "wheels".
	 */
	public static function same_word( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}

		$length_a = self::length( $a );
		$length_b = self::length( $b );

		// Equal lengths that are not equal words can be no prefix of each other.
		if ( $length_a < $length_b ) {
			return $length_a >= self::MIN_PREFIX_LENGTH && substr( $b, 0, strlen( $a ) ) === $a;
		}

		return $length_b >= self::MIN_PREFIX_LENGTH && substr( $a, 0, strlen( $b ) ) === $b;
	}

	/**
	 * How alike two names are, from 0 to 1.
	 */
	public static function similarity( string $a, string $b ): float {
		return self::similarity_of_words( self::unique_words( $a ), self::unique_words( $b ) );
	}

	/**
	 * `similarity()` for names that are already words.
	 *
	 * The order of the words matters for which words pair up, so it is kept.
	 *
	 * @param array<int, string> $words_a Distinct words of the first name.
	 * @param array<int, string> $words_b Distinct words of the second name.
	 */
	public static function similarity_of_words( array $words_a, array $words_b ): float {
		$count_a = count( $words_a );
		$count_b = count( $words_b );
		if ( 0 === $count_a || 0 === $count_b ) {
			return 0.0;
		}

		// Each word pairs with at most one word of the other name.
		$unpaired = $words_b;
		$shared   = 0;
		foreach ( $words_a as $word ) {
			foreach ( $unpaired as $index => $other ) {
				if ( self::same_word( $word, $other ) ) {
					array_splice( $unpaired, $index, 1 );
					++$shared;
					break;
				}
			}
		}

		$overlap = (float) $shared / min( $count_a, $count_b );
		$dice    = ( 2.0 * $shared ) / ( $count_a + $count_b );

		return ( $overlap + $dice ) / 2;
	}

	/**
	 * How well a candidate matches a product: by the product's title alone or with
	 * its vendor, against the candidate's product name alone or with its brand.
	 */
	public static function score( string $title, string $vendor, Candidate $candidate ): float {
		return self::score_of_words(
			self::unique_words( $title ),
			self::unique_words( $vendor . ' ' . $title ),
			self::unique_words( $candidate->product ),
			self::unique_words( $candidate->brand . ' ' . $candidate->product )
		);
	}

	/**
	 * `score()` for names that are already words.
	 *
	 * @param array<int, string> $title        Words of the product's title.
	 * @param array<int, string> $vendor_title Words of the vendor and the title.
	 * @param array<int, string> $product      Words of the candidate's product name.
	 * @param array<int, string> $full         Words of the candidate's brand and product name.
	 */
	public static function score_of_words( array $title, array $vendor_title, array $product, array $full ): float {
		return max(
			self::similarity_of_words( $title, $product ),
			self::similarity_of_words( $title, $full ),
			self::similarity_of_words( $vendor_title, $full )
		);
	}

	/**
	 * The candidate that matches the product closely, or null. Of equal scores
	 * the first candidate wins.
	 *
	 * @param array<int, Candidate> $candidates
	 */
	public static function best( string $title, string $vendor, array $candidates ): ?Candidate {
		$best       = null;
		$best_score = 0.0;
		foreach ( $candidates as $candidate ) {
			$score = self::score( $title, $vendor, $candidate );
			if ( $score >= self::THRESHOLD && $score > $best_score ) {
				$best       = $candidate;
				$best_score = $score;
			}
		}

		return $best;
	}

	/**
	 * The candidates that match the product closely, the closest first; of equal
	 * scores the earlier candidate first.
	 *
	 * @param array<int, Candidate> $candidates
	 *
	 * @return array<int, Candidate>
	 */
	public static function ranked( string $title, string $vendor, array $candidates ): array {
		$scored = array();
		foreach ( $candidates as $candidate ) {
			$score = self::score( $title, $vendor, $candidate );
			if ( $score >= self::THRESHOLD ) {
				$scored[] = array( $score, $candidate );
			}
		}
		// `usort()` is stable since PHP 8.0, as `Array.prototype.sort()` is.
		usort( $scored, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );

		return array_column( $scored, 1 );
	}

	/**
	 * The length of a word the way JavaScript counts it: in UTF-16 code units, so a
	 * character outside the Basic Multilingual Plane counts twice.
	 */
	public static function length( string $word ): int {
		if ( 1 !== preg_match( '/[^\x00-\x7F]/', $word ) ) {
			return strlen( $word );
		}

		$characters = preg_match_all( '/./su', $word );
		$astral     = preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $word );

		return ( false === $characters ? 0 : $characters ) + ( false === $astral ? 0 : $astral );
	}

	/**
	 * Lower-case like `String.prototype.toLowerCase()`: a capital sigma that ends a
	 * word becomes the final sigma, which PHP's `mb_strtolower()` does only from
	 * PHP 8.3, and which the same Greek word needs to be one word whichever case
	 * it is typed in.
	 *
	 * The rule is Unicode's Final_Sigma: a cased letter before, skipping the
	 * characters that are ignored when casing (apostrophes, full stops, colons and
	 * a few more, and the marks that are gone by now), and none after, skipping
	 * the same. "ΟΔΟΣ" ends in one, "ΟΔΟΣ.ΟΔΟΣ" does not.
	 */
	private static function lower( string $text ): string {
		$cased   = '[\p{Lu}\p{Ll}\p{Lt}]';
		$ignored = '[\p{Mn}\p{Me}\p{Cf}\p{Lm}\p{Sk}\x{27}\x{2E}\x{3A}\x{B7}\x{387}\x{55F}\x{5F4}\x{2018}\x{2019}\x{2024}\x{2027}\x{FE13}\x{FE52}\x{FE55}\x{FF07}\x{FF0E}\x{FF1A}]';

		$text = preg_replace( '/(' . $cased . $ignored . '*)Σ(?!' . $ignored . '*' . $cased . ')/u', '$1ς', $text ) ?? '';

		return Text::lower( $text );
	}
}
