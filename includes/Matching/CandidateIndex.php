<?php
/**
 * Finds the best match for many products among many candidates.
 *
 * Scoring every product against every candidate would be thousands of products
 * times thousands of catalogue names. The score of two names is above zero only
 * when a word of one is the same word as a word of the other (see
 * `NameMatcher::same_word()`), and a match needs a score of 0.7, so the only
 * candidates worth scoring are those that share a word with the product. This
 * class looks those up in a word index, scores them with `NameMatcher`'s own
 * functions in the order the candidates were given, and so gives the answer
 * `NameMatcher::best()` and `ranked()` give, for a fraction of the work. A test
 * holds the two together.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class CandidateIndex {

	/** The shortest prefix that makes two words the same word. */
	private const MIN_PREFIX_LENGTH = 4;

	/**
	 * @var array<int, Candidate>
	 */
	private array $candidates;

	/**
	 * Words of each candidate's product name, by position.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $product_words = array();

	/**
	 * Words of each candidate's brand and product name, by position.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $full_words = array();

	/**
	 * The positions of the candidates that have a word, by that word.
	 *
	 * @var array<int|string, array<int, int>>
	 */
	private array $by_word = array();

	/**
	 * The positions of the candidates that have a word that starts with a prefix
	 * (a proper one, of 4 or more characters), by that prefix.
	 *
	 * @var array<int|string, array<int, int>>
	 */
	private array $by_prefix = array();

	/**
	 * @param array<int, Candidate> $candidates In the order a tie is decided by.
	 */
	public function __construct( array $candidates ) {
		$this->candidates = array_values( $candidates );

		foreach ( $this->candidates as $position => $candidate ) {
			$this->product_words[ $position ] = NameMatcher::unique_words( $candidate->product );
			$this->full_words[ $position ]    = NameMatcher::unique_words( $candidate->brand . ' ' . $candidate->product );

			foreach ( $this->full_words[ $position ] as $word ) {
				$this->by_word[ $word ][] = $position;
				foreach ( self::proper_prefixes( $word ) as $prefix ) {
					$this->by_prefix[ $prefix ][] = $position;
				}
			}
		}
	}

	public function count(): int {
		return count( $this->candidates );
	}

	/**
	 * The candidate that matches the product closely, or null. Of equal scores
	 * the first candidate wins, as in `NameMatcher::best()`.
	 */
	public function best( string $title, string $vendor ): ?Candidate {
		$best       = null;
		$best_score = 0.0;
		foreach ( $this->scored( $title, $vendor ) as $position => $score ) {
			if ( $score > $best_score ) {
				$best       = $this->candidates[ $position ];
				$best_score = $score;
			}
		}

		return $best;
	}

	/**
	 * The candidates that match the product closely, the closest first, as
	 * `NameMatcher::ranked()` gives them.
	 *
	 * @return array<int, Candidate>
	 */
	public function ranked( string $title, string $vendor ): array {
		$scored = array();
		foreach ( $this->scored( $title, $vendor ) as $position => $score ) {
			$scored[] = array( $score, $this->candidates[ $position ] );
		}
		usort( $scored, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );

		return array_column( $scored, 1 );
	}

	/**
	 * The score of every candidate that reaches the threshold, by position, in
	 * the order of the candidates.
	 *
	 * @return array<int, float>
	 */
	private function scored( string $title, string $vendor ): array {
		$title_words        = NameMatcher::unique_words( $title );
		$vendor_title_words = NameMatcher::unique_words( $vendor . ' ' . $title );

		$out = array();
		foreach ( $this->possible( $vendor_title_words ) as $position ) {
			$score = NameMatcher::score_of_words(
				$title_words,
				$vendor_title_words,
				$this->product_words[ $position ],
				$this->full_words[ $position ]
			);
			if ( $score >= NameMatcher::THRESHOLD ) {
				$out[ $position ] = $score;
			}
		}

		return $out;
	}

	/**
	 * The positions of the candidates that have a word that is the same word as
	 * one of `$words`, in ascending order.
	 *
	 * @param array<int, string> $words The words of the vendor and the title, which holds the words every one of the three comparisons uses.
	 *
	 * @return array<int, int>
	 */
	private function possible( array $words ): array {
		$found = array();
		foreach ( $words as $word ) {
			// The same word.
			foreach ( $this->by_word[ $word ] ?? array() as $position ) {
				$found[ $position ] = true;
			}
			// A candidate's word that is a prefix of this one.
			foreach ( self::proper_prefixes( $word ) as $prefix ) {
				foreach ( $this->by_word[ $prefix ] ?? array() as $position ) {
					$found[ $position ] = true;
				}
			}
			// This word is a prefix of a candidate's word.
			if ( NameMatcher::length( $word ) >= self::MIN_PREFIX_LENGTH ) {
				foreach ( $this->by_prefix[ $word ] ?? array() as $position ) {
					$found[ $position ] = true;
				}
			}
		}

		$positions = array_keys( $found );
		sort( $positions );

		return $positions;
	}

	/**
	 * The prefixes of a word that are shorter than it and at least four
	 * characters long, in the unit `NameMatcher::length()` counts in.
	 *
	 * @return array<int, string>
	 */
	private static function proper_prefixes( string $word ): array {
		if ( 1 !== preg_match( '/[^\x00-\x7F]/', $word ) ) {
			$length = strlen( $word );
			$out    = array();
			for ( $size = self::MIN_PREFIX_LENGTH; $size < $length; $size++ ) {
				$out[] = substr( $word, 0, $size );
			}

			return $out;
		}

		$characters = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $characters ) {
			return array();
		}

		$out    = array();
		$prefix = '';
		$units  = 0;
		$last   = count( $characters ) - 1;
		foreach ( $characters as $index => $character ) {
			if ( $index === $last ) {
				break;
			}
			$prefix .= $character;
			$units  += NameMatcher::length( $character );
			if ( $units >= self::MIN_PREFIX_LENGTH ) {
				$out[] = $prefix;
			}
		}

		return $out;
	}
}
