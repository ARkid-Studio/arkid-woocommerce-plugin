<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Matching;

use Arkid\CatalogueLink\Matching\Candidate;
use Arkid\CatalogueLink\Matching\CandidateIndex;
use Arkid\CatalogueLink\Matching\Decomposition;
use Arkid\CatalogueLink\Matching\NameMatcher;
use PHPUnit\Framework\TestCase;

/**
 * The matcher is an exact port of the Shopify plugin's, so the shared vectors are
 * the specification. Each is checked on the way this host normalises text and on
 * the table that stands in for ext-intl, whichever the host has.
 */
final class NameMatcherTest extends TestCase {

	protected function tearDown(): void {
		Decomposition::force_table( false );
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function ways(): array {
		return array(
			'as this host normalises' => array( false ),
			'with the table'          => array( true ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: float, 3: bool}>
	 */
	public static function similarity_vectors(): array {
		$cases = array();
		foreach ( Vectors::section( 'similarity' ) as $index => $vector ) {
			foreach ( array( false, true ) as $table ) {
				$cases[ sprintf( '#%d %s ~ %s%s', $index, $vector['a'], $vector['b'], $table ? ' (table)' : '' ) ] = array(
					(string) $vector['a'],
					(string) $vector['b'],
					(float) $vector['score'],
					$table,
				);
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider similarity_vectors
	 */
	public function test_similarity_gives_the_score_of_the_shopify_matcher( string $a, string $b, float $score, bool $table ): void {
		Decomposition::force_table( $table );

		$this->assertEqualsWithDelta( $score, NameMatcher::similarity( $a, $b ), 1e-9 );
		// Symmetric, as the pairing of words is.
		$this->assertEqualsWithDelta( $score, NameMatcher::similarity( $b, $a ), 1e-9 );
	}

	public function test_the_threshold_is_the_one_of_the_vectors(): void {
		$this->assertSame( (float) Vectors::all()['threshold'], NameMatcher::THRESHOLD );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function match_vectors(): array {
		$cases = array();
		foreach ( Vectors::section( 'bestMatch' ) as $index => $vector ) {
			foreach ( array( false, true ) as $table ) {
				/** @var array<string, string> $target */
				$target = $vector['target'];
				$cases[ sprintf( '#%d "%s" by "%s"%s', $index, $target['title'], $target['vendor'], $table ? ' (table)' : '' ) ] = array( $vector, $table );
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider match_vectors
	 *
	 * @param array<string, mixed> $vector
	 */
	public function test_best_and_ranked_pick_the_candidates_of_the_shopify_matcher( array $vector, bool $table ): void {
		Decomposition::force_table( $table );
		/** @var array<string, string> $target */
		$target   = $vector['target'];
		$embeds   = Vectors::embeds();
		$expected = $vector['bestId'];
		/** @var array<int, string> $ranked */
		$ranked = $vector['rankedIds'];

		$best = NameMatcher::best( $target['title'], $target['vendor'], $embeds );
		$this->assertSame( $expected, $best?->id );
		$this->assertSame( $ranked, array_map( static fn( Candidate $c ): string => $c->id, NameMatcher::ranked( $target['title'], $target['vendor'], $embeds ) ) );

		// The indexed search gives the same answers.
		$index = new CandidateIndex( $embeds );
		$this->assertSame( $expected, $index->best( $target['title'], $target['vendor'] )?->id );
		$this->assertSame( $ranked, array_map( static fn( Candidate $c ): string => $c->id, $index->ranked( $target['title'], $target['vendor'] ) ) );
	}

	/**
	 * @dataProvider words_cases
	 *
	 * @param array<int, string> $expected
	 */
	public function test_words( string $text, array $expected ): void {
		$this->assertSame( $expected, NameMatcher::words( $text ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function words_cases(): array {
		return array(
			'case and punctuation'      => array( 'Maxi-Cosi Fame - New Generation', array( 'maxi', 'cosi', 'fame', 'new', 'generation' ) ),
			'accents go'                => array( 'Café Crème Brûlée', array( 'cafe', 'creme', 'brulee' ) ),
			'a decomposed accent goes'  => array( "Cafe\u{301}", array( 'cafe' ) ),
			'letters without a base stay' => array( 'Große Ørsted Łódź Đorđe Æble', array( 'große', 'ørsted', 'łodz', 'đorđe', 'æble' ) ),
			'numbers are words'         => array( 'Fox 5 / 2025', array( 'fox', '5', '2025' ) ),
			'repeated words stay'       => array( 'a a b', array( 'a', 'a', 'b' ) ),
			'cyrillic'                  => array( 'Ёлка Йогурт', array( 'елка', 'иогурт' ) ),
			'japanese voicing goes'     => array( 'ガギグ', array( 'カキク' ) ),
			'nothing but punctuation'   => array( ' - / ', array() ),
			'empty'                     => array( '', array() ),
			'invalid utf-8 has no words' => array( "caf\xE9", array() ),
		);
	}

	/**
	 * @dataProvider ways
	 */
	public function test_words_are_the_same_on_both_ways_of_normalising( bool $table ): void {
		Decomposition::force_table( $table );

		$this->assertSame( array( 'cafe', 'creme' ), NameMatcher::words( 'CAFÉ Crème' ) );
		$this->assertSame( array( 'στρατος' ), NameMatcher::words( 'ΣΤΡΆΤΟΣ' ), 'Greek: the tonos goes, a capital sigma that ends the word is the final sigma' );
		$this->assertSame( array( 'στρατος' ), NameMatcher::words( 'στρατος' ) );
		$this->assertSame( array( 'σα', 'σ' ), NameMatcher::words( 'ΣΑ Σ' ), 'A capital sigma at the start of a word, or alone, is not final' );
	}

	public function test_a_word_is_the_same_as_itself_or_as_a_prefix_of_four_or_more(): void {
		$this->assertTrue( NameMatcher::same_word( 'wheel', 'wheel' ) );
		$this->assertTrue( NameMatcher::same_word( 'wheel', 'wheels' ) );
		$this->assertTrue( NameMatcher::same_word( 'wheels', 'wheel' ) );
		$this->assertTrue( NameMatcher::same_word( 'abcd', 'abcde' ) );
		$this->assertFalse( NameMatcher::same_word( 'abc', 'abcd' ), 'three characters are too short a prefix' );
		$this->assertFalse( NameMatcher::same_word( 'abcd', 'abdc' ) );
		$this->assertFalse( NameMatcher::same_word( 'abcd', 'zabcd' ), 'a suffix is not a prefix' );
		$this->assertTrue( NameMatcher::same_word( '', '' ) );
	}

	public function test_a_word_is_as_long_as_javascript_counts_it(): void {
		$this->assertSame( 4, NameMatcher::length( 'abcd' ) );
		$this->assertSame( 3, NameMatcher::length( 'äöü' ) );
		// U+20BB7 is outside the Basic Multilingual Plane: two UTF-16 code units.
		$this->assertSame( 4, NameMatcher::length( "\u{20BB7}\u{20BB7}" ) );
		$this->assertTrue( NameMatcher::same_word( "\u{20BB7}\u{20BB7}", "\u{20BB7}\u{20BB7}x" ), 'two such characters are four code units, a prefix long enough' );
		$this->assertFalse( NameMatcher::same_word( "\u{20BB7}", "\u{20BB7}x" ), 'one is only two' );
	}

	public function test_the_pairing_of_words_is_greedy_and_in_reading_order(): void {
		// "ab" pairs with the first word of the other name that is its prefix, and
		// that word is then spent: two words of A cannot share one word of B.
		$this->assertEqualsWithDelta( 0.8333333333333333, NameMatcher::similarity( 'sofa sofabed', 'sofa' ), 1e-9 );
		$this->assertEqualsWithDelta( 0.8333333333333333, NameMatcher::similarity( 'wheel', 'wheels wheel' ), 1e-9, '"wheel" takes the first word it is the same as, "wheels"' );
	}

	public function test_score_is_the_best_of_the_three_comparisons(): void {
		$candidate = new Candidate( 'x', 'Maxi-Cosi', 'Fame - New Generation' );

		// The title against the product name alone, 0.75; against brand and product, less.
		$this->assertEqualsWithDelta( 0.75, NameMatcher::score( 'Fame', '', $candidate ), 1e-9 );
		// A title that holds the brand is the candidate's brand and product.
		$this->assertEqualsWithDelta( 1.0, NameMatcher::score( 'Maxi-Cosi Fame - New Generation', '', $candidate ), 1e-9 );
		// The vendor stands in for the brand that the title leaves out ...
		$this->assertEqualsWithDelta( 0.9444444444444444, NameMatcher::score( 'Fame New', 'Maxi-Cosi', $candidate ), 1e-9 );
		// ... and without a vendor the title alone is what is compared with the product name.
		$this->assertEqualsWithDelta( 0.9, NameMatcher::score( 'Fame New', '', $candidate ), 1e-9 );
	}

	public function test_of_equal_scores_the_first_candidate_wins_and_ranking_is_stable(): void {
		$first  = new Candidate( 'first', 'A', 'Chair Oak' );
		$second = new Candidate( 'second', 'B', 'Chair Oak' );
		$third  = new Candidate( 'third', 'C', 'Chair Oak Large' );
		$all    = array( $first, $second, $third );

		$this->assertSame( 'first', NameMatcher::best( 'Chair Oak', '', $all )?->id );
		$this->assertSame( array( 'first', 'second', 'third' ), array_map( static fn( Candidate $c ): string => $c->id, NameMatcher::ranked( 'Chair Oak', '', $all ) ) );

		$index = new CandidateIndex( array( $third, $second, $first ) );
		$this->assertSame( 'second', $index->best( 'Chair Oak', '' )?->id, 'the order the candidates were given in decides' );
	}

	public function test_the_best_matches_nothing_below_the_threshold(): void {
		$this->assertNull( NameMatcher::best( 'Garden Hose', 'Acme', Vectors::embeds() ) );
		$this->assertSame( array(), NameMatcher::ranked( 'Garden Hose', 'Acme', Vectors::embeds() ) );
		$this->assertNull( NameMatcher::best( 'Fame', '', array() ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: float, 3: bool}>
	 */
	public static function extra_similarity_vectors(): array {
		$cases = array();
		foreach ( Vectors::section( 'similarity', 'extra-vectors' ) as $index => $vector ) {
			foreach ( array( false, true ) as $table ) {
				$cases[ sprintf( '#%d %s ~ %s%s', $index, $vector['a'], $vector['b'], $table ? ' (table)' : '' ) ] = array(
					(string) $vector['a'],
					(string) $vector['b'],
					(float) $vector['score'],
					$table,
				);
			}
		}

		return $cases;
	}

	/**
	 * Random names in many scripts, scored by the Shopify matcher.
	 *
	 * @dataProvider extra_similarity_vectors
	 */
	public function test_similarity_of_random_names_in_many_scripts( string $a, string $b, float $score, bool $table ): void {
		Decomposition::force_table( $table );

		$this->assertEqualsWithDelta( $score, NameMatcher::similarity( $a, $b ), 1e-9 );
	}

	/**
	 * @dataProvider ways
	 */
	public function test_best_and_ranked_of_random_names_in_many_scripts( bool $table ): void {
		Decomposition::force_table( $table );
		$embeds = Vectors::embeds( 'extra-vectors' );
		$index  = new CandidateIndex( $embeds );
		$ids    = static fn( array $candidates ): array => array_map( static fn( Candidate $c ): string => $c->id, $candidates );

		$vectors = Vectors::section( 'bestMatch', 'extra-vectors' );
		$this->assertNotEmpty( $vectors );
		foreach ( $vectors as $vector ) {
			/** @var array<string, string> $target */
			$target = $vector['target'];
			$label  = $target['title'] . ' / ' . $target['vendor'];

			$this->assertSame( $vector['bestId'], NameMatcher::best( $target['title'], $target['vendor'], $embeds )?->id, $label );
			$this->assertSame( $vector['rankedIds'], $ids( NameMatcher::ranked( $target['title'], $target['vendor'], $embeds ) ), $label );
			$this->assertSame( $vector['bestId'], $index->best( $target['title'], $target['vendor'] )?->id, $label );
			$this->assertSame( $vector['rankedIds'], $ids( $index->ranked( $target['title'], $target['vendor'] ) ), $label );
		}
	}

	/**
	 * The index only skips candidates that cannot reach the threshold: on names
	 * built from a few words, with prefixes, repeats and accents, it must give what
	 * scoring every candidate gives.
	 */
	public function test_the_index_gives_the_answers_of_scoring_every_candidate(): void {
		$bank = array( 'fame', 'fames', 'fam', 'wheel', 'wheels', 'whee', 'sofa', 'sofabed', 'chair', 'chairs', 'ørsted', 'orsted', 'café', 'cafe', 'ñandú', 'nandu', '5', '50', 'x', 'xl', 'xlarge', "\u{20BB7}\u{20BB7}", "\u{20BB7}\u{20BB7}x", 'ΟΔΟΣ', 'οδος' );
		// A fixed linear congruential generator: the same names on every PHP.
		$state = 4711;
		$next  = static function ( int $below ) use ( &$state ): int {
			$state = ( $state * 1103515245 + 12345 ) & 0x7FFFFFFF;

			return ( $state >> 8 ) % $below;
		};
		$name  = static function () use ( $bank, $next ): string {
			$words = array();
			for ( $i = 1 + $next( 4 ); $i > 0; --$i ) {
				$word    = $bank[ $next( count( $bank ) ) ];
				$words[] = 0 === $next( 6 ) ? mb_strtoupper( $word ) : $word;
			}

			return implode( ' ', $words );
		};

		$candidates = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$candidates[] = new Candidate( 'c' . $i, $name(), $name() );
		}
		$index = new CandidateIndex( $candidates );
		$ids   = static fn( array $found ): array => array_map( static fn( Candidate $c ): string => $c->id, $found );

		$answered = 0;
		for ( $i = 0; $i < 300; $i++ ) {
			$title  = $name();
			$vendor = 0 === $next( 2 ) ? $name() : '';

			$this->assertSame( NameMatcher::best( $title, $vendor, $candidates )?->id, $index->best( $title, $vendor )?->id, "$vendor / $title" );
			$this->assertSame( $ids( NameMatcher::ranked( $title, $vendor, $candidates ) ), $ids( $index->ranked( $title, $vendor ) ), "$vendor / $title" );
			$answered += null === $index->best( $title, $vendor ) ? 0 : 1;
		}
		$this->assertGreaterThan( 20, $answered, 'the names are alike enough for the comparison to mean something' );
	}
}
