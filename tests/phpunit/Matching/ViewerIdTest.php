<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Matching;

use Arkid\CatalogueLink\Matching\Candidate;
use Arkid\CatalogueLink\Matching\ViewerId;
use PHPUnit\Framework\TestCase;

/**
 * The 22-character ids of viewer addresses, and the links built on them, as the
 * Shopify plugin reads them.
 */
final class ViewerIdTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function decode_vectors(): array {
		$cases = array();
		foreach ( Vectors::section( 'viewerIdDecode' ) as $vector ) {
			$cases[ sprintf( '"%s"', $vector['id'] ) ] = array( (string) $vector['id'], null === $vector['uuid'] ? null : (string) $vector['uuid'] );
		}

		return $cases;
	}

	/**
	 * @dataProvider decode_vectors
	 */
	public function test_a_viewer_id_decodes_to_the_uuid( string $id, ?string $uuid ): void {
		$this->assertSame( $uuid, ViewerId::uuid( $id ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function round_trips(): array {
		$cases = array();
		foreach ( Vectors::section( 'viewerIdRoundTrip' ) as $vector ) {
			$cases[ sprintf( '%s of %s', $vector['form'], $vector['uuid'] ) ] = array( (string) $vector['id'], (string) $vector['decoded'], (string) $vector['uuid'] );
		}

		return $cases;
	}

	/**
	 * @dataProvider round_trips
	 */
	public function test_both_forms_of_an_id_decode_to_the_uuid_they_were_made_from( string $id, string $decoded, string $uuid ): void {
		$this->assertSame( $decoded, ViewerId::uuid( $id ) );
		$this->assertSame( $uuid, ViewerId::uuid( $id ) );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function code_vectors(): array {
		$cases = array();
		foreach ( Vectors::section( 'embedIdFromCode' ) as $index => $vector ) {
			$cases[ '#' . $index . ' ' . $vector['code'] ] = array( (string) $vector['code'], null === $vector['id'] ? null : (string) $vector['id'] );
		}

		return $cases;
	}

	/**
	 * @dataProvider code_vectors
	 */
	public function test_the_id_of_an_embed_code( string $code, ?string $id ): void {
		$this->assertSame( $id, ViewerId::from_code( $code ) );
	}

	/**
	 * @return array<string, array{0: ?string, 1: ?string, 2: ?string}>
	 */
	public static function link_vectors(): array {
		$cases = array();
		foreach ( Vectors::section( 'linkedEmbed' ) as $index => $vector ) {
			/** @var array<string, ?string> $link */
			$link                 = $vector['link'];
			$cases[ '#' . $index ] = array( $link['embedId'], $link['embedCode'], null === $vector['embed_id'] ? null : (string) $vector['embed_id'] );
		}

		return $cases;
	}

	/**
	 * @dataProvider link_vectors
	 */
	public function test_the_viewer_of_a_link_is_found_by_its_id_else_by_its_code( ?string $embed_id, ?string $embed_code, ?string $expected ): void {
		$embeds = array_map(
			static fn( string $id ): Candidate => new Candidate( $id, 'Brand', 'Product' ),
			array_map( 'strval', (array) Vectors::all()['linkedEmbedList'] )
		);

		$found = ViewerId::linked( $embeds, (string) $embed_id, (string) $embed_code );

		$this->assertSame( $expected, $found?->id );
	}

	public function test_an_id_is_found_without_regard_to_case_and_is_not_confused_by_a_prefix(): void {
		$embeds = array( new Candidate( 'ABCD-1', 'B', 'P' ), new Candidate( 'abcd-12', 'B', 'P' ) );

		$this->assertSame( 'abcd-12', ViewerId::linked( $embeds, 'ABCD-12' )?->id );
		$this->assertSame( 'ABCD-1', ViewerId::linked( $embeds, 'abcd-1' )?->id );
		$this->assertNull( ViewerId::linked( $embeds, 'abcd' ) );
		$this->assertNull( ViewerId::linked( $embeds, '' ) );
	}
}
