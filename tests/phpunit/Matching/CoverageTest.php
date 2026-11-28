<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Matching;

use Arkid\CatalogueLink\Matching\Candidate;
use Arkid\CatalogueLink\Matching\Coverage;
use Arkid\CatalogueLink\Matching\ShopProduct;
use Arkid\CatalogueLink\Matching\Suggester;
use PHPUnit\Framework\TestCase;

/**
 * How products are classified: linked, linked to a viewer the account does not
 * list, matched by a viewer or by a catalogue product, or neither.
 */
final class CoverageTest extends TestCase {

	/**
	 * @param array<int, ShopProduct> $products
	 *
	 * @return array<int, int>
	 */
	private static function ids( array $products ): array {
		return array_map( static fn( ShopProduct $product ): int => $product->id, $products );
	}

	public function test_every_product_is_in_exactly_one_class(): void {
		$embeds  = array(
			new Candidate( 'viewer-fame', 'Maxi-Cosi', 'Fame - New Generation' ),
			new Candidate( 'VIEWER-FOX', 'Bugaboo', 'Fox 5' ),
		);
		$catalog = array( new Candidate( 'cat-priam', 'Cybex', 'Priam' ) );

		$products = array(
			new ShopProduct( 1, 'Anything', '', 'viewer-fox' ),        // Linked: the id is compared without regard to case.
			new ShopProduct( 2, 'Anything', '', 'viewer-deleted' ),    // Linked to a viewer the account does not list.
			new ShopProduct( 3, 'Maxi-Cosi Fame New Generation', '' ), // A viewer matches.
			new ShopProduct( 4, 'Priam Kinderwagen', 'Cybex' ),        // Only a catalogue product matches.
			new ShopProduct( 5, 'Garden Hose', 'Acme' ),               // Nothing matches.
			new ShopProduct( 6, 'Fox 5', 'Bugaboo', 'viewer-fame' ),   // Linked: a viewer that matches by name is no reason to unlink.
		);

		$coverage = Coverage::classify( $products, $embeds, $catalog );

		$this->assertSame( array( 1, 2, 6 ), self::ids( $coverage->linked ) );
		$this->assertSame( array( 2 ), self::ids( $coverage->unknown ) );
		$this->assertSame( array( 3, 4 ), self::ids( $coverage->matches ) );
		$this->assertSame( array( 5 ), self::ids( $coverage->unmatched ) );
		$this->assertSame( array( 1 ), self::ids( $coverage->products_by_embed['VIEWER-FOX'] ), 'by the id as the account spells it' );
		$this->assertSame( array( 6 ), self::ids( $coverage->products_by_embed['viewer-fame'] ) );
		$this->assertCount( 2, $coverage->products_by_embed );
	}

	public function test_without_any_viewer_or_catalogue_nothing_matches_and_every_link_is_unknown(): void {
		$coverage = Coverage::classify(
			array( new ShopProduct( 1, 'Fame', 'Maxi-Cosi' ), new ShopProduct( 2, 'Fame', '', 'x' ) ),
			array(),
			array()
		);

		$this->assertSame( array( 1 ), self::ids( $coverage->unmatched ) );
		$this->assertSame( array( 2 ), self::ids( $coverage->unknown ) );
		$this->assertSame( array(), $coverage->matches );
	}

	public function test_the_suggester_suggests_a_viewer_and_a_catalogue_product_side_by_side(): void {
		$suggester = new Suggester(
			array( new Candidate( 'v1', 'Maxi-Cosi', 'Fame' ) ),
			array( new Candidate( 'p1', 'Maxi-Cosi', 'Fame' ), new Candidate( 'p2', 'Cybex', 'Priam' ) )
		);

		$both = $suggester->suggest( 'Fame', 'Maxi-Cosi' );
		$this->assertSame( 'v1', $both?->embed?->id );
		$this->assertSame( 'p1', $both?->product?->id );

		$catalog_only = $suggester->suggest( 'Priam', 'Cybex' );
		$this->assertNull( $catalog_only?->embed );
		$this->assertSame( 'p2', $catalog_only?->product?->id );

		$this->assertNull( $suggester->suggest( 'Garden Hose', 'Acme' ) );
		$this->assertTrue( $suggester->has_match( 'Priam', 'Cybex' ) );
		$this->assertFalse( $suggester->has_match( 'Garden Hose', 'Acme' ) );
	}

	public function test_a_product_is_linked_when_it_has_a_viewer_id(): void {
		$this->assertFalse( ( new ShopProduct( 1, 'A', '' ) )->is_linked() );
		$this->assertTrue( ( new ShopProduct( 1, 'A', '', 'x' ) )->is_linked() );
	}
}
