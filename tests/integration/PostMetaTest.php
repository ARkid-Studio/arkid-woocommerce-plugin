<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration;

use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\PostMeta;

final class PostMetaTest extends TestCase {

	private function embed( string $embed_id = 'new-999' ): EmbedDto {
		return EmbedDto::from_array( $this->embed_payload( $embed_id, 'https://catalogue.arkid.app/e/' . $embed_id ) );
	}

	public function test_clearing_the_link_keeps_the_position_that_is_passed_along(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'above_product' );

		PostMeta::write( $product_id, null, 'above_product' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::embed_url( $product_id ) );
		$this->assertSame( '', PostMeta::embed_image( $product_id ) );
		$this->assertSame( 'above_product', PostMeta::position( $product_id ) );
	}

	public function test_an_empty_position_removes_the_override(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'above_product' );

		PostMeta::write( $product_id, null, '' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::position( $product_id ) );
		$this->assertSame( array(), get_post_meta( $product_id, PostMeta::KEY_POSITION ), 'The key is deleted, not stored empty.' );
	}

	public function test_writing_a_link_stores_the_snapshot_and_the_position(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );

		PostMeta::write( $product_id, $this->embed(), 'button_only' );

		$this->assertSame( 'new-999', PostMeta::embed_id( $product_id ) );
		$this->assertSame( 'https://catalogue.arkid.app/e/new-999', PostMeta::embed_url( $product_id ) );
		$this->assertSame( 'button_only', PostMeta::position( $product_id ) );
	}

	public function test_an_unknown_position_is_never_stored(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );

		PostMeta::write_position( $product_id, 'sideways' );

		$this->assertSame( array(), get_post_meta( $product_id, PostMeta::KEY_POSITION ) );
	}
}
