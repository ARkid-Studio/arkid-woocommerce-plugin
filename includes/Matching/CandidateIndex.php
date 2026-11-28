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
 * Of the candidates that share a word, most share only a word like "chair" and
 * are scored for nothing. How many of the product's words are the same as a word
 * of a candidate is known from the lookup, and no score can be higher than if
 * all of those pair (see `ceiling()`), so a candidate whose best possible score
 * is below the threshold is not scored.
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
	 * How many words each candidate's product name and its brand and product name
	 * have, by position.
	 *
	 * @var array<int, int>
	 */
	private array $product_count = array();

	/**
	 * @var array<int, int>
	 */
	private array $full_count = array();

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
			$this->product_count[ $position ] = count( $this->product_words[ $position ] );
			$this->full_count[ $position ]    = count( $this->full_words[ $position ] );

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
	 * Whether any candidate matches the product closely: what `best()` finds
	 * something of, without looking for the closest.
	 */
	public function any( string $title, string $vendor ): bool {
		foreach ( $this->scored( $title, $vendor, true ) as $ignored ) {
			return true;
		}

		return false;
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
	 * @param bool $first_only Stop at the first one.
	 *
	 * @return array<int, float>
	 */
	private function scored( string $title, string $vendor, bool $first_only = false ): array {
		$title_words        = NameMatcher::unique_words( $title );
		$vendor_title_words = NameMatcher::unique_words( $vendor . ' ' . $title );
		$title_count        = count( $title_words );
		$all_count          = count( $vendor_title_words );

		[ $hits_all, $hits_title ] = $this->possible( $title_words, $vendor_title_words );
		if ( ! $first_only ) {
			// Of equal scores the first candidate wins, so they are scored in order.
			ksort( $hits_all );
		}

		$out = array();
		foreach ( $hits_all as $position => $all ) {
			if ( ! $this->may_reach_threshold( $position, $title_count, $all_count, $hits_title[ $position ] ?? 0, $all ) ) {
				continue;
			}

			$score = NameMatcher::score_of_words(
				$title_words,
				$vendor_title_words,
				$this->product_words[ $position ],
				$this->full_words[ $position ]
			);
			if ( $score >= NameMatcher::THRESHOLD ) {
				$out[ $position ] = $score;
				if ( $first_only ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a candidate can score the threshold at all, given how many of the
	 * product's words are the same as one of its words.
	 *
	 * @param int $title_count Words in the title.
	 * @param int $all_count   Words in the vendor and the title.
	 * @param int $hits_title  Words of the title that are the same as a word of the candidate.
	 * @param int $hits_all    Words of the vendor and the title that are.
	 */
	private function may_reach_threshold( int $position, int $title_count, int $all_count, int $hits_title, int $hits_all ): bool {
		$product = $this->product_count[ $position ];
		$full    = $this->full_count[ $position ];

		// A rounding error must never rule a candidate out: leave a margin.
		$needed = NameMatcher::THRESHOLD - 1e-9;

		return self::ceiling( $hits_title, $title_count, $product ) >= $needed
			|| self::ceiling( $hits_title, $title_count, $full ) >= $needed
			|| self::ceiling( $hits_all, $all_count, $full ) >= $needed;
	}

	/**
	 * The highest `NameMatcher::similarity_of_words()` of two names of `$count_a`
	 * and `$count_b` words can be when at most `$hits` words of the first have a
	 * word of the second to pair with: each pairing takes one word of each.
	 */
	private static function ceiling( int $hits, int $count_a, int $count_b ): float {
		$shared = min( $hits, $count_a, $count_b );
		if ( $shared < 1 ) {
			return 0.0;
		}

		return ( (float) $shared / min( $count_a, $count_b ) + ( 2.0 * $shared ) / ( $count_a + $count_b ) ) / 2;
	}

	/**
	 * The candidates that have a word that is the same word as one of the
	 * product's, with how many of the vendor's and the title's words, and how many
	 * of the title's words, they have a word for. In no particular order.
	 *
	 * @param array<int, string> $title        Words of the title.
	 * @param array<int, string> $vendor_title Words of the vendor and the title, which holds the words every one of the three comparisons uses.
	 *
	 * @return array{0: array<int, int>, 1: array<int, int>} Each by position: the words of the vendor and the title, and the words of the title.
	 */
	private function possible( array $title, array $vendor_title ): array {
		$in_title   = array_flip( $title );
		$hits_all   = array();
		$hits_title = array();
		$counted    = array();

		foreach ( array_values( $vendor_title ) as $index => $word ) {
			$is_title = isset( $in_title[ $word ] );

			// Every list that holds candidates with a word that is the same word:
			// the same word, a prefix of this word, and a word that this one is a prefix of.
			$lists = array( $this->by_word[ $word ] ?? array() );
			foreach ( self::proper_prefixes( $word ) as $prefix ) {
				if ( isset( $this->by_word[ $prefix ] ) ) {
					$lists[] = $this->by_word[ $prefix ];
				}
			}
			if ( NameMatcher::length( $word ) >= self::MIN_PREFIX_LENGTH && isset( $this->by_prefix[ $word ] ) ) {
				$lists[] = $this->by_prefix[ $word ];
			}

			foreach ( $lists as $positions ) {
				foreach ( $positions as $position ) {
					// A candidate that has several words that are the same as this one counts once.
					if ( ( $counted[ $position ] ?? -1 ) === $index ) {
						continue;
					}
					$counted[ $position ]   = $index;
					$hits_all[ $position ]  = ( $hits_all[ $position ] ?? 0 ) + 1;
					if ( $is_title ) {
						$hits_title[ $position ] = ( $hits_title[ $position ] ?? 0 ) + 1;
					}
				}
			}
		}

		return array( $hits_all, $hits_title );
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
