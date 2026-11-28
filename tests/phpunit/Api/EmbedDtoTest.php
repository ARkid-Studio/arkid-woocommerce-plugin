<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Api;

use Arkid\CatalogueLink\Api\EmbedDto;
use PHPUnit\Framework\TestCase;

final class EmbedDtoTest extends TestCase {

	public function test_from_array_maps_every_field(): void {
		$payload = $this->sample_payload();
		$dto     = EmbedDto::from_array( $payload );

		$this->assertSame( 'ad21f15e-7164-11ef-fd5b-87131cfe84d0', $dto->embed_id );
		$this->assertSame( 1, $dto->row_number );
		$this->assertSame( 'Felix Learning Tower', $dto->product_name );
		$this->assertSame( 'tiSsi', $dto->brand_name );
		$this->assertSame( 'https://catalogue.arkid.app/embed/rSHxXnFkEe_9W4cTHP6E0A', $dto->embed_url );
		$this->assertSame( 'pdp', $dto->type );
		$this->assertSame( 225, $dto->views );
	}

	public function test_picker_label_matches_spec_format(): void {
		$dto = EmbedDto::from_array( $this->sample_payload() );

		$this->assertSame(
			'#1 - tiSsi - Felix Learning Tower - 2024-09-13 02:11:08 - A really long internal note. A really long note',
			$dto->picker_label()
		);
	}

	public function test_picker_label_drops_empty_internal_note(): void {
		$payload                  = $this->sample_payload();
		$payload['internal_note'] = '';
		$dto                      = EmbedDto::from_array( $payload );

		$this->assertSame(
			'#1 - tiSsi - Felix Learning Tower - 2024-09-13 02:11:08',
			$dto->picker_label()
		);
	}

	public function test_to_array_round_trip(): void {
		$dto       = EmbedDto::from_array( $this->sample_payload() );
		$round     = EmbedDto::from_array( $dto->to_array() );

		$this->assertEquals( $dto, $round );
	}

	public function test_colors_filter_drops_malformed_entries(): void {
		$payload           = $this->sample_payload();
		$payload['colors'] = array(
			array( 'name' => 'Black', 'rgb' => '#000000' ),
			array( 'rgb' => '#ff0000' ),                   // missing name → drop.
			'not-an-array',                                  // wrong type → drop.
			array( 'name' => 'White' ),                      // no rgb is fine.
		);

		$dto = EmbedDto::from_array( $payload );

		$this->assertCount( 2, $dto->colors );
		$this->assertSame( 'Black', $dto->colors[0]['name'] );
		$this->assertSame( '#000000', $dto->colors[0]['rgb'] ?? null );
		$this->assertSame( 'White', $dto->colors[1]['name'] );
		$this->assertArrayNotHasKey( 'rgb', $dto->colors[1] );
	}

	public function test_colors_keep_only_enabled_entries(): void {
		$payload           = $this->sample_payload();
		$payload['colors'] = array(
			array( 'variant_id' => 'a', 'name' => 'Black', 'rgb' => '#404040', 'enabled' => true ),
			array( 'variant_id' => 'b', 'name' => 'Blau', 'rgb' => '#184070', 'enabled' => false ), // disabled → drop.
			array( 'name' => 'Eiche', 'rgb' => '#ab681f' ),                                          // no flag → keep.
		);

		$dto = EmbedDto::from_array( $payload );

		$this->assertSame(
			array(
				array( 'name' => 'Black', 'rgb' => '#404040' ),
				array( 'name' => 'Eiche', 'rgb' => '#ab681f' ),
			),
			$dto->colors
		);
	}

	public function test_handles_missing_optional_fields(): void {
		$dto = EmbedDto::from_array(
			array(
				'embed_id'     => 'abc',
				'row_number'   => '5',
				'product_name' => 'Nameless',
			)
		);

		$this->assertSame( 'abc', $dto->embed_id );
		$this->assertSame( 5, $dto->row_number );
		$this->assertSame( '', $dto->brand_name );
		$this->assertSame( '', $dto->type );
		$this->assertSame( array(), $dto->colors );
		$this->assertSame( array(), $dto->tags );
	}

	public function test_the_fields_the_dto_used_to_drop_are_kept(): void {
		$dto = EmbedDto::from_array( $this->full_payload() );

		$this->assertSame( '3c9e1f02-7db1-11ef-9b4a-6e2d1c0f7a31', $dto->product_id );
		$this->assertSame( array( '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c03' ), $dto->variants );
		$this->assertSame( '#f4efe8', $dto->color_a );
		$this->assertSame( '#d9cfc1', $dto->color_b );
		$this->assertSame( 'env9', $dto->environment );
		$this->assertTrue( $dto->can_edit );
		$this->assertFalse( $dto->colors_editable );
		$this->assertFalse( $dto->environment_editable );
	}

	/**
	 * Dots and editing need every colour of the product, its variant id and
	 * whether the viewer shows it, in the order the API lists them.
	 */
	public function test_every_colour_is_kept_with_its_variant_id_and_flag(): void {
		$dto = EmbedDto::from_array( $this->full_payload() );

		$this->assertSame(
			array(
				array(
					'variant_id' => '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c01',
					'name'       => 'Black',
					'rgb'        => '#404040',
					'enabled'    => false,
				),
				array(
					'variant_id' => '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c03',
					'name'       => 'Eiche',
					'rgb'        => '#ab681f',
					'enabled'    => true,
				),
				array(
					'variant_id' => '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c02',
					'name'       => 'Blau',
					'rgb'        => '#184070',
					'enabled'    => false,
				),
			),
			$dto->all_colors
		);
	}

	public function test_the_legacy_colors_stay_the_shown_ones_for_the_picker_and_the_block(): void {
		$dto = EmbedDto::from_array( $this->full_payload() );

		$this->assertSame( array( array( 'name' => 'Eiche', 'rgb' => '#ab681f' ) ), $dto->colors );
		$this->assertSame( array( array( 'name' => 'Eiche', 'rgb' => '#ab681f' ) ), $dto->to_array()['colors'] );
	}

	public function test_the_shown_colors_helper_keeps_the_variant_ids_in_display_order(): void {
		$payload                = $this->full_payload();
		$payload['colors'][1]   = array_merge( $payload['colors'][1], array( 'enabled' => true ) );
		$payload['colors'][2]   = array_merge( $payload['colors'][2], array( 'enabled' => true ) );
		$dto                    = EmbedDto::from_array( $payload );

		$this->assertSame(
			array( '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c03', '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c02' ),
			array_column( $dto->shown_colors(), 'variant_id' )
		);
	}

	/**
	 * A cache of to_array() output that is read back with from_array() must not
	 * lose the hidden colours or the edit flags.
	 */
	public function test_the_round_trip_loses_nothing_the_api_sent(): void {
		$dto   = EmbedDto::from_array( $this->full_payload() );
		$round = EmbedDto::from_array( $dto->to_array() );

		$this->assertEquals( $dto, $round );
		$this->assertCount( 3, $round->all_colors );
		$this->assertTrue( $round->can_edit );
		$this->assertFalse( $round->colors_editable );
	}

	public function test_to_array_keeps_every_key_the_picker_reads(): void {
		$array = EmbedDto::from_array( $this->full_payload() )->to_array();

		foreach ( array( 'image', 'embed_url', 'product_name', 'colors', 'tags' ) as $key ) {
			$this->assertArrayHasKey( $key, $array );
		}
		$this->assertArrayHasKey( 'all_colors', $array );
		$this->assertArrayHasKey( 'can_edit', $array );
	}

	public function test_a_payload_without_the_new_fields_gets_safe_defaults(): void {
		$dto = EmbedDto::from_array( array( 'embed_id' => 'abc' ) );

		$this->assertSame( '', $dto->product_id );
		$this->assertSame( array(), $dto->variants );
		$this->assertSame( array(), $dto->all_colors );
		$this->assertFalse( $dto->can_edit, 'Without the flag a viewer must not offer editing.' );
		$this->assertTrue( $dto->colors_editable );
		$this->assertTrue( $dto->environment_editable );
	}

	public function test_a_flag_that_is_not_a_boolean_does_not_switch_editing_on(): void {
		$dto = EmbedDto::from_array( array( 'embed_id' => 'abc', 'can_edit' => 'yes', 'colors_editable' => 0 ) );

		$this->assertFalse( $dto->can_edit );
		$this->assertTrue( $dto->colors_editable );
	}

	public function test_variants_that_are_not_strings_are_dropped(): void {
		$dto = EmbedDto::from_array( array( 'embed_id' => 'abc', 'variants' => array( 'a', 5, null, array( 'b' ), 'c' ) ) );

		$this->assertSame( array( 'a', 'c' ), $dto->variants );
	}

	public function test_a_colour_without_rgb_keeps_an_empty_rgb_in_the_full_list_only(): void {
		$dto = EmbedDto::from_array(
			array(
				'embed_id' => 'abc',
				'colors'   => array( array( 'variant_id' => 'v1', 'name' => 'White' ) ),
			)
		);

		$this->assertSame( '', $dto->all_colors[0]['rgb'] );
		$this->assertTrue( $dto->all_colors[0]['enabled'] );
		$this->assertSame( array( array( 'name' => 'White' ) ), $dto->colors );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function full_payload(): array {
		return array_merge(
			$this->sample_payload(),
			array(
				'product_id'           => '3c9e1f02-7db1-11ef-9b4a-6e2d1c0f7a31',
				'colors'               => array(
					array( 'variant_id' => '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c01', 'name' => 'Black', 'rgb' => '#404040', 'enabled' => false ),
					array( 'variant_id' => '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c03', 'name' => 'Eiche', 'rgb' => '#ab681f', 'enabled' => true ),
					array( 'variant_id' => '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c02', 'name' => 'Blau', 'rgb' => '#184070', 'enabled' => false ),
				),
				'variants'             => array( '3c9e2a10-7db1-11ef-b1c4-5f0a6e7d8c03' ),
				'color_a'              => '#f4efe8',
				'color_b'              => '#d9cfc1',
				'environment'          => 'env9',
				'can_edit'             => true,
				'colors_editable'      => false,
				'environment_editable' => false,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample_payload(): array {
		return array(
			'embed_id'      => 'ad21f15e-7164-11ef-fd5b-87131cfe84d0',
			'row_number'    => 1,
			'product_name'  => 'Felix Learning Tower',
			'brand_name'    => 'tiSsi',
			'image'         => 'https://cloud.arkid.app/covers/FELIX_Static_NATURE_Regular_c.webp',
			'embed_url'     => 'https://catalogue.arkid.app/embed/rSHxXnFkEe_9W4cTHP6E0A',
			'embedder_url'  => '',
			'created'       => '2024-09-13 02:11:08',
			'modified'      => '2024-09-13 02:11:13',
			'edit_url'      => 'https://catalogue.arkid.app/configure/edit/rSHxXnFkEe_9W4cTHP6E0A',
			'analytics_url' => 'https://catalogue.arkid.app/analytics/rSHxXnFkEe_9W4cTHP6E0A',
			'internal_note' => 'A really long internal note. A really long note',
			'buybutton_url' => '',
			'cta_url'       => '',
			'views'         => 225,
			'author_name'   => 'E-Commerce Test User',
			'type'          => 'pdp',
			'colors'        => array(
				array( 'name' => 'Black', 'rgb' => '#404040' ),
				array( 'name' => 'Blau', 'rgb' => '#184070' ),
			),
			'tags'          => array(
				array( 'name' => 'Ads' ),
				array( 'name' => 'Online' ),
			),
		);
	}
}
