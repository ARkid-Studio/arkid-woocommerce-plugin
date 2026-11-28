<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api;

use Arkid\CatalogueLink\Api\CatalogProductDto;
use Arkid\CatalogueLink\Api\EnvironmentDto;
use Arkid\CatalogueLink\Tests\Api\Fixtures\ApiFixture;
use PHPUnit\Framework\TestCase;

final class CatalogDtosTest extends TestCase {

	public function test_a_product_keeps_its_variants_in_display_order(): void {
		$rows    = ApiFixture::load( 'product-list' )['data'];
		$product = CatalogProductDto::from_array( $rows[1] );

		$this->assertSame( '761023ba-4d2e-11f1-fc62-e0c83dd5547a', $product->product_id );
		$this->assertSame( 'Fame - New Generation', $product->name );
		$this->assertSame( 'Maxi-Cosi', $product->brand_name );
		$this->assertSame( 'pdp', $product->type );
		$this->assertSame( 'https://cloud.arkid.app/covers/FAME2026_Cot_CopperTerra_c.webp', $product->image );
		$this->assertCount( 6, $product->variants );
		$this->assertSame(
			array( 'variant_id' => '887054d4-4d59-11f1-45ee-903d874f4ba7', 'name' => 'Copper Terra', 'rgb' => '#b55f39' ),
			$product->variants[0]
		);
		$this->assertSame( 'Copper Terra', $product->variants[0]['name'] );
		$this->assertSame( $product->variant_ids()[5], 'decca133-d9f2-11f1-696d-d4e619f9fa26' );
	}

	public function test_a_product_says_what_a_new_viewer_of_it_starts_with(): void {
		$product = CatalogProductDto::from_array( ApiFixture::load( 'product-list' )['data'][0] );

		$this->assertSame( '#ffffff', $product->color_a );
		$this->assertSame( '#ededed', $product->color_b );
		$this->assertSame( 'env9', $product->environment );
		$this->assertTrue( $product->colors_editable );
		$this->assertTrue( $product->environment_editable );
	}

	public function test_a_skin_that_fixes_the_look_is_visible(): void {
		$row                         = ApiFixture::load( 'product-list' )['data'][0];
		$row['colors_editable']      = false;
		$row['environment_editable'] = false;

		$product = CatalogProductDto::from_array( $row );

		$this->assertFalse( $product->colors_editable );
		$this->assertFalse( $product->environment_editable );
	}

	public function test_a_product_round_trips_through_to_array(): void {
		$product = CatalogProductDto::from_array( ApiFixture::load( 'product-list' )['data'][4] );

		$this->assertEquals( $product, CatalogProductDto::from_array( $product->to_array() ) );
	}

	public function test_a_hollow_product_is_empty_not_an_error(): void {
		$product = CatalogProductDto::from_array( array() );

		$this->assertSame( '', $product->product_id );
		$this->assertSame( array(), $product->variants );
		$this->assertSame( array(), $product->variant_ids() );
	}

	public function test_variants_that_are_not_objects_are_skipped(): void {
		$product = CatalogProductDto::from_array(
			array(
				'product_id' => 'p',
				'variants'   => array( 'x', array( 'variant_id' => 'v', 'name' => 'Red', 'rgb' => '#ff0000' ), null ),
			)
		);

		$this->assertSame( array( 'v' ), $product->variant_ids() );
	}

	public function test_an_environment_has_an_id_a_name_and_a_group(): void {
		$rows = ApiFixture::load( 'environment-list' )['data'];

		$room = EnvironmentDto::from_array( $rows[6] );

		$this->assertSame( 'env12', $room->environment );
		$this->assertSame( 'Room', $room->name );
		$this->assertSame( '3D Environments', $room->group );
		$this->assertSame(
			array( 'environment' => 'env12', 'name' => 'Room', 'group' => '3D Environments' ),
			$room->to_array()
		);
	}
}
