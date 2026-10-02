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
