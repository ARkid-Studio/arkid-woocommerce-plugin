<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Support;

use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Support\EmbedSearch;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class EmbedSearchTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Core's remove_accents() is covered by the integration suite.
		Functions\when( 'remove_accents' )->alias(
			static fn( string $text ): string => strtr(
				$text,
				array(
					'ü' => 'u',
					'Ü' => 'U',
					'ß' => 'ss',
				)
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function embed( int $row, string $brand, string $product, string $note = '' ): EmbedDto {
		return EmbedDto::from_array(
			array(
				'embed_id'      => 'embed-' . $row,
				'row_number'    => $row,
				'brand_name'    => $brand,
				'product_name'  => $product,
				'created'       => '2026-01-0' . $row . ' 10:00:00',
				'internal_note' => $note,
			)
		);
	}

	/**
	 * @param array<int, EmbedDto> $embeds
	 *
	 * @return array<int, int>
	 */
	private function rows( array $embeds ): array {
		return array_map( static fn( EmbedDto $e ): int => $e->row_number, $embeds );
	}

	/**
	 * @return array<int, EmbedDto>
	 */
	private function catalogue(): array {
		return array(
			$this->embed( 1, 'Felix', 'Learning Tower', 'living room' ),
			$this->embed( 2, 'Felix', 'Highchair' ),
			$this->embed( 3, 'Müller Möbel', 'Straßenlampe' ),
			$this->embed( 4, '日本ブランド', '椅子' ),
		);
	}

	public function test_an_empty_query_keeps_everything(): void {
		$this->assertSame( array( 1, 2, 3, 4 ), $this->rows( EmbedSearch::filter( $this->catalogue(), '' ) ) );
		$this->assertSame( array( 1, 2, 3, 4 ), $this->rows( EmbedSearch::filter( $this->catalogue(), "  \t " ) ) );
	}

	/**
	 * The label is '#row - brand - product - ...', so a query that crossed the
	 * separators matched nothing when it was tested as one contiguous string.
	 */
	public function test_words_match_across_fields(): void {
		$found = EmbedSearch::filter( $this->catalogue(), 'felix learning' );

		$this->assertSame( array( 1 ), $this->rows( $found ) );
	}

	public function test_word_order_does_not_matter(): void {
		$this->assertSame( array( 1 ), $this->rows( EmbedSearch::filter( $this->catalogue(), 'learning felix' ) ) );
	}

	public function test_every_word_must_match(): void {
		$this->assertSame( array(), $this->rows( EmbedSearch::filter( $this->catalogue(), 'felix lamp' ) ) );
	}

	public function test_a_word_can_match_the_note_the_row_number_or_the_date(): void {
		$this->assertSame( array( 1 ), $this->rows( EmbedSearch::filter( $this->catalogue(), 'living' ) ) );
		$this->assertSame( array( 2 ), $this->rows( EmbedSearch::filter( $this->catalogue(), '#2' ) ) );
		$this->assertSame( array( 3 ), $this->rows( EmbedSearch::filter( $this->catalogue(), '2026-01-03' ) ) );
	}

	public function test_it_is_multibyte_safe(): void {
		// strtolower() leaves "Ü" alone, so "ÜBER" never met "über".
		$embeds = array( $this->embed( 5, 'ÜBER Design', 'Sessel' ) );

		$this->assertSame( array( 5 ), $this->rows( EmbedSearch::filter( $embeds, 'über' ) ) );
		$this->assertSame( array( 5 ), $this->rows( EmbedSearch::filter( $embeds, 'ÜBER sessel' ) ) );
	}

	public function test_accents_do_not_matter_in_either_direction(): void {
		$this->assertSame( array( 3 ), $this->rows( EmbedSearch::filter( $this->catalogue(), 'muller' ) ) );
		$this->assertSame( array( 3 ), $this->rows( EmbedSearch::filter( $this->catalogue(), 'Müller strasse' ) ) );
		$this->assertSame( array( 3 ), $this->rows( EmbedSearch::filter( $this->catalogue(), 'straßenlampe' ) ) );
	}

	public function test_cjk_names_match_with_and_without_a_separator(): void {
		$this->assertSame( array( 4 ), $this->rows( EmbedSearch::filter( $this->catalogue(), '椅子' ) ) );
		$this->assertSame( array( 4 ), $this->rows( EmbedSearch::filter( $this->catalogue(), "日本ブランド\u{3000}椅子" ) ) );
	}

	public function test_an_embed_without_a_label_never_matches(): void {
		$blank = EmbedDto::from_array( array( 'embed_id' => 'x' ) );

		$this->assertSame( array(), EmbedSearch::filter( array( $blank ), 'felix' ) );
	}

	public function test_the_result_is_a_list_in_the_original_order(): void {
		$found = EmbedSearch::filter( $this->catalogue(), 'felix' );

		$this->assertSame( array( 0, 1 ), array_keys( $found ) );
		$this->assertSame( array( 1, 2 ), $this->rows( $found ) );
	}
}
