<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\ViewersListTable;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class ViewersListTableTest extends TestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function embed( int $row, string $brand, string $product, string $created = '2026-01-01 10:00:00' ): EmbedDto {
		return EmbedDto::from_array(
			array(
				'embed_id'     => 'embed-' . $row,
				'row_number'   => $row,
				'brand_name'   => $brand,
				'product_name' => $product,
				'created'      => $created,
			)
		);
	}

	/**
	 * @param array<int, EmbedDto> $rows
	 *
	 * @return array<int, string> Product names in table order.
	 */
	private function products_sorted_by( array $rows, string $orderby, string $order = 'asc' ): array {
		$_GET = array(
			'orderby' => $orderby,
			'order'   => $order,
		);

		$table = new ViewersListTable( $rows );
		$table->prepare_items();

		return array_map(
			static fn( EmbedDto $e ): string => $e->product_name,
			$table->items
		);
	}

	/**
	 * strcmp() put every capital before every lower-case letter, so "apple"
	 * landed after "Zebra".
	 */
	public function test_products_sort_without_regard_to_case(): void {
		$rows = array(
			$this->embed( 1, 'A', 'Zebra' ),
			$this->embed( 2, 'A', 'apple' ),
			$this->embed( 3, 'A', 'Mango' ),
		);

		$this->assertSame( array( 'apple', 'Mango', 'Zebra' ), $this->products_sorted_by( $rows, 'product_name' ) );
		$this->assertSame( array( 'Zebra', 'Mango', 'apple' ), $this->products_sorted_by( $rows, 'product_name', 'desc' ) );
	}

	/**
	 * strcmp() compared UTF-8 bytes, which sorts "Äpfel" and "Östlich" after "Zebra".
	 */
	public function test_accented_names_sort_next_to_their_base_letter(): void {
		$rows = array(
			$this->embed( 1, 'A', 'Zebra' ),
			$this->embed( 2, 'A', 'Östlich' ),
			$this->embed( 3, 'A', 'Äpfel' ),
			$this->embed( 4, 'A', 'Ofen' ),
			$this->embed( 5, 'A', 'Apple' ),
		);

		$sorted = $this->products_sorted_by( $rows, 'product_name' );

		$this->assertSame( 'Zebra', end( $sorted ) );
		$this->assertLessThan( array_search( 'Ofen', $sorted, true ), array_search( 'Apple', $sorted, true ) );
		$this->assertLessThan( array_search( 'Zebra', $sorted, true ), array_search( 'Östlich', $sorted, true ) );
		$this->assertLessThan( array_search( 'Zebra', $sorted, true ), array_search( 'Äpfel', $sorted, true ) );
	}

	public function test_numbers_in_names_sort_by_value(): void {
		$rows = array(
			$this->embed( 1, 'A', 'Chair 10' ),
			$this->embed( 2, 'A', 'Chair 2' ),
			$this->embed( 3, 'A', 'Chair 1' ),
		);

		$this->assertSame( array( 'Chair 1', 'Chair 2', 'Chair 10' ), $this->products_sorted_by( $rows, 'product_name' ) );
	}

	public function test_brands_sort_without_regard_to_case(): void {
		$rows = array(
			$this->embed( 1, 'Zeta', 'p1' ),
			$this->embed( 2, 'alpha', 'p2' ),
			$this->embed( 3, 'Beta', 'p3' ),
		);

		$this->assertSame( array( 'p2', 'p3', 'p1' ), $this->products_sorted_by( $rows, 'brand_name' ) );
	}

	public function test_equal_names_keep_the_order_of_their_row_numbers_in_both_directions(): void {
		$rows = array(
			$this->embed( 3, 'ARkid', 'c' ),
			$this->embed( 1, 'ARkid', 'a' ),
			$this->embed( 2, 'ARkid', 'b' ),
		);

		$this->assertSame( array( 'a', 'b', 'c' ), $this->products_sorted_by( $rows, 'brand_name' ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $this->products_sorted_by( $rows, 'brand_name', 'desc' ) );
	}

	public function test_created_and_row_number_still_sort(): void {
		$rows = array(
			$this->embed( 2, 'A', 'second', '2026-02-01 00:00:00' ),
			$this->embed( 1, 'A', 'first', '2026-03-01 00:00:00' ),
			$this->embed( 3, 'A', 'third', '2026-01-01 00:00:00' ),
		);

		$this->assertSame( array( 'third', 'second', 'first' ), $this->products_sorted_by( $rows, 'created' ) );
		$this->assertSame( array( 'first', 'second', 'third' ), $this->products_sorted_by( $rows, 'created', 'desc' ) );
		$this->assertSame( array( 'first', 'second', 'third' ), $this->products_sorted_by( $rows, 'row_number' ) );
		$this->assertSame( array( 'third', 'second', 'first' ), $this->products_sorted_by( $rows, 'row_number', 'desc' ) );
	}

	public function test_an_unknown_column_falls_back_to_the_row_number(): void {
		$rows = array(
			$this->embed( 2, 'A', 'second' ),
			$this->embed( 1, 'A', 'first' ),
		);

		$this->assertSame( array( 'first', 'second' ), $this->products_sorted_by( $rows, 'nonsense' ) );
	}
}
