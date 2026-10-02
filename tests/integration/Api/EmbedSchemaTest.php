<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Api;

use Arkid\CatalogueLink\Api\Client;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

/**
 * Pins the ARkid API contract against a payload recorded from the live service.
 *
 * `tests/fixtures/api/*.json` are verbatim responses from
 * `https://catalogue.arkid.app/api/ecom/embed`, captured with a real key. This
 * suite never touches the network: it replays them, so it runs in CI and under
 * QIT's network isolation.
 *
 * It guards OUR side of the contract — that the DTO still reads a real payload
 * correctly. `LiveApiContractTest` guards the SERVER's side, and is opt-in.
 */
final class EmbedSchemaTest extends TestCase {

	/** Every field EmbedDto promises. */
	private const DTO_STRINGS = array(
		'embed_id',
		'product_name',
		'brand_name',
		'image',
		'embed_url',
		'embedder_url',
		'created',
		'modified',
		'edit_url',
		'analytics_url',
		'internal_note',
		'buybutton_url',
		'cta_url',
		'author_name',
		'type',
	);

	private const DTO_INTS = array( 'row_number', 'views' );

	/**
	 * @return array<string, mixed>
	 */
	public static function fixture( string $name ): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/api/' . $name . '.json';
		$json = file_get_contents( $path );
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( "Unreadable fixture: {$path}" );
		}

		return $data;
	}

	private function client(): Client {
		return new Client( 'fixture-key' );
	}

	public function test_the_recorded_list_payload_parses_into_dtos(): void {
		$this->stub_http( '/api/ecom/embed', self::fixture( 'embed-list' ) );

		$embeds = $this->client()->list_embeds();

		$this->assertCount( 6, $embeds );
		$this->assertContainsOnlyInstancesOf( EmbedDto::class, $embeds );
	}

	/**
	 * Field-by-field, against a row captured from the live API. If ARkid renames
	 * or retypes anything, this is what goes red.
	 */
	public function test_every_dto_field_is_read_from_the_real_payload(): void {
		$this->stub_http( '/api/ecom/embed', self::fixture( 'embed-list' ) );

		$embeds = $this->client()->list_embeds();
		$first  = $embeds[0];

		$this->assertSame( '0af0878e-61df-11f0-f6a5-59a3f4f8cd3e', $first->embed_id );
		$this->assertSame( 6, $first->row_number );
		$this->assertSame( 'Product Demo', $first->product_name );
		$this->assertSame( 'ARkid Studio', $first->brand_name );
		$this->assertSame( 'https://cloud.arkid.app/covers/aDemo_c.webp', $first->image );
		$this->assertSame( 'https://catalogue.arkid.app/embed/CvCHjmHfEfD2pVmj9PjNPg?source=magento', $first->embed_url );
		$this->assertSame( '2025-07-16 02:51:43', $first->created );
		$this->assertSame( '2025-07-16 02:52:36', $first->modified );
		$this->assertSame( 'https://catalogue.arkid.app/configure/edit/CvCHjmHfEfD2pVmj9PjNPg', $first->edit_url );
		$this->assertSame( 'https://catalogue.arkid.app/analytics/CvCHjmHfEfD2pVmj9PjNPg', $first->analytics_url );
		$this->assertSame( 22, $first->views );
		$this->assertSame( 'E-Commerce Test User', $first->author_name );
		$this->assertSame( 'pdp', $first->type );
		$this->assertSame( array( array( 'name' => 'Demo', 'rgb' => '#000000' ) ), $first->colors );
		$this->assertSame( array( array( 'name' => 'Online' ) ), $first->tags );
	}

	public function test_the_single_embed_endpoint_returns_a_bare_object_not_a_wrapper(): void {
		$single = self::fixture( 'embed-single' );

		// list_embeds() unwraps `data`; get_embed() must NOT, so a regression
		// that wrapped this response would silently produce an empty DTO.
		$this->assertArrayNotHasKey( 'data', $single );
		$this->assertArrayHasKey( 'embed_id', $single );

		$this->stub_http( '/api/ecom/embed/', $single );

		$dto = $this->client()->get_embed( '0af0878e-61df-11f0-f6a5-59a3f4f8cd3e' );

		$this->assertSame( '0af0878e-61df-11f0-f6a5-59a3f4f8cd3e', $dto->embed_id );
		$this->assertSame( 'Product Demo', $dto->product_name );
	}

	/**
	 * The recorded payload must keep carrying every field the DTO reads. A
	 * fixture edited down to "just the interesting bits" would make the suite
	 * pass while proving nothing.
	 */
	public function test_the_fixture_still_carries_every_field_the_dto_reads(): void {
		$rows = self::fixture( 'embed-list' )['data'];

		foreach ( $rows as $index => $row ) {
			foreach ( self::DTO_STRINGS as $field ) {
				$this->assertArrayHasKey( $field, $row, "row {$index} is missing {$field}" );
				$this->assertIsString( $row[ $field ], "row {$index}: {$field} is not a string" );
			}
			foreach ( self::DTO_INTS as $field ) {
				$this->assertArrayHasKey( $field, $row, "row {$index} is missing {$field}" );
				$this->assertIsInt( $row[ $field ], "row {$index}: {$field} is not an int" );
			}
			$this->assertIsArray( $row['colors'] );
			$this->assertIsArray( $row['tags'] );
		}
	}

	/**
	 * The live API also sends `embed_code` — a ready-made <iframe> string. We
	 * deliberately ignore it and build our own element, because theirs carries a
	 * sandbox we don't want and no `referrerpolicy`. Recorded so that the day it
	 * becomes the only source of the URL, this test explains the decision.
	 */
	public function test_the_embed_code_field_is_present_and_deliberately_unused(): void {
		$row = self::fixture( 'embed-list' )['data'][0];

		$this->assertArrayHasKey( 'embed_code', $row );
		$this->assertStringContainsString( '<iframe', $row['embed_code'] );

		$this->assertArrayNotHasKey( 'embed_code', ( new EmbedDto(
			embed_id: '', row_number: 0, product_name: '', brand_name: '', image: '',
			embed_url: '', embedder_url: '', created: '', modified: '', edit_url: '',
			analytics_url: '', internal_note: '', buybutton_url: '', cta_url: '',
			views: 0, author_name: '', type: '', colors: array(), tags: array()
		) )->to_array() );
	}

	/**
	 * The whole reason IframeFactory has a host allow-list. Answered empirically
	 * against the live service: every embed_url is on catalogue.arkid.app.
	 */
	public function test_every_recorded_embed_url_passes_the_host_allow_list(): void {
		$iframe = new IframeFactory( new Options() );

		foreach ( self::fixture( 'embed-list' )['data'] as $row ) {
			$this->assertNotSame(
				'',
				$iframe->viewer_url( $row['embed_url'] ),
				"The allow-list rejected a real embed_url: {$row['embed_url']}"
			);
		}
	}

	/**
	 * Thumbnails come from a DIFFERENT host than viewers. Applying the embed
	 * allow-list to images "for consistency" would blank every thumbnail, so
	 * pin the asymmetry.
	 */
	public function test_thumbnails_are_served_from_a_host_the_allow_list_does_not_cover(): void {
		$iframe = new IframeFactory( new Options() );

		foreach ( self::fixture( 'embed-list' )['data'] as $row ) {
			$this->assertStringStartsWith( 'https://cloud.arkid.app/', $row['image'] );
			$this->assertSame(
				'',
				$iframe->viewer_url( $row['image'] ),
				'Image hosts are intentionally NOT allow-listed — they are not iframe sources.'
			);
		}
	}

	/**
	 * Every live embed_url already carries `?source=magento`. add_query_arg
	 * replaces rather than appends, so our attribution wins — but if that ever
	 * became an append, ARkid would credit WooCommerce traffic to Magento.
	 */
	public function test_our_source_parameter_replaces_the_one_the_api_ships(): void {
		$iframe = new IframeFactory( new Options() );

		foreach ( self::fixture( 'embed-list' )['data'] as $row ) {
			$this->assertStringContainsString( 'source=magento', $row['embed_url'] );

			$url = $iframe->viewer_url( $row['embed_url'] );

			$this->assertStringContainsString( 'source=woocommerce', $url );
			$this->assertStringNotContainsString( 'source=magento', $url );
			$this->assertSame( 1, substr_count( $url, 'source=' ), 'Exactly one source parameter.' );
		}
	}
}
