<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\MetaBox;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class MetaBoxTest extends TestCase {

	private function metabox(): MetaBox {
		$options = new Options();

		return new MetaBox( $options, new ClientFactory( $options ) );
	}

	private function post_a_save( int $product_id, string $embed_id, string $position = '' ): void {
		$_POST = array(
			'arkid_catalogue_link_metabox_nonce' => wp_create_nonce( 'arkid_catalogue_link_metabox' ),
			'arkid_catalogue_link_embed_id'      => $embed_id,
			'arkid_catalogue_link_position'      => $position,
		);

		$this->metabox()->save( $product_id, get_post( $product_id ) );

		$_POST = array();
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Options::OPTION_KEY, array( 'api_key' => 'test-key' ) );
	}

	public function tear_down(): void {
		delete_option( Options::OPTION_KEY );
		$_POST = array();
		parent::tear_down();
	}

	public function test_a_successful_save_writes_the_whole_snapshot(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->stub_http( '/api/ecom/embed/', $this->embed_payload( 'new-999', 'https://catalogue.arkid.app/e/new-999' ) );

		$this->post_a_save( $product_id, 'new-999', 'below_product' );

		$this->assertSame( 'new-999', PostMeta::embed_id( $product_id ) );
		$this->assertSame( 'https://catalogue.arkid.app/e/new-999', PostMeta::embed_url( $product_id ) );
		$this->assertSame( 'below_product', PostMeta::position( $product_id ) );
	}

	/**
	 * The snapshot describes one specific embed. Leaving it in place while
	 * embed_id moved to a different viewer made the storefront render the
	 * PREVIOUS viewer under the new id.
	 */
	public function test_switching_viewers_while_the_api_is_down_does_not_keep_the_old_snapshot(): void {
		$product_id = $this->make_product_with_embed( 'old-111', '', 'https://catalogue.arkid.app/e/old-111' );

		// No stub registered -> the request fails, as it would with ARkid down.
		$this->post_a_save( $product_id, 'new-222', 'below_product' );

		$this->assertSame( 'new-222', PostMeta::embed_id( $product_id ), 'The merchant\'s choice must be kept.' );
		$this->assertSame(
			'',
			PostMeta::embed_url( $product_id ),
			'A snapshot we could not verify must not survive alongside a different embed id.'
		);
		$this->assertSame( '', PostMeta::embed_image( $product_id ) );
		$this->assertSame( 'below_product', PostMeta::position( $product_id ) );
	}

	public function test_resaving_the_same_viewer_while_the_api_is_down_keeps_the_snapshot(): void {
		$product_id = $this->make_product_with_embed( 'same-333', '', 'https://catalogue.arkid.app/e/same-333' );

		$this->post_a_save( $product_id, 'same-333', 'above_product' );

		$this->assertSame( 'same-333', PostMeta::embed_id( $product_id ) );
		$this->assertSame(
			'https://catalogue.arkid.app/e/same-333',
			PostMeta::embed_url( $product_id ),
			'The snapshot still describes this embed, so it stays valid.'
		);
		$this->assertSame( 'above_product', PostMeta::position( $product_id ) );
	}

	public function test_an_unverified_save_queues_a_retry(): void {
		$product_id = $this->make_product_with_embed( 'old-111' );

		$this->post_a_save( $product_id, 'new-222' );

		$this->assertTrue(
			(bool) as_has_scheduled_action(
				'arkid_catalogue_link_refresh_product',
				array( 'product_id' => $product_id ),
				'arkid-catalogue-link'
			),
			'A snapshot we could not fetch must be retried out of band.'
		);
	}

	public function test_clearing_the_picker_removes_every_key(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'below_product' );

		$this->post_a_save( $product_id, '' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::embed_url( $product_id ) );
		$this->assertSame( '', PostMeta::embed_image( $product_id ) );
		$this->assertSame( '', PostMeta::position( $product_id ) );
	}

	public function test_a_save_without_a_valid_nonce_is_ignored(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );

		$_POST = array(
			'arkid_catalogue_link_metabox_nonce' => 'not-a-real-nonce',
			'arkid_catalogue_link_embed_id'      => 'hijack-999',
		);
		$this->metabox()->save( $product_id, get_post( $product_id ) );
		$_POST = array();

		$this->assertSame( 'abc-123', PostMeta::embed_id( $product_id ) );
	}

	public function test_a_user_without_edit_rights_cannot_write_meta(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post_a_save( $product_id, 'hijack-999' );

		$this->assertSame( 'abc-123', PostMeta::embed_id( $product_id ) );
	}

	public function test_the_editor_warns_when_a_viewer_is_selected_but_not_synced(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'abc-123' );
		// No _arkid_embed_url: selected but never successfully fetched.

		$html = $this->render(
			fn() => $this->metabox()->render( get_post( $product_id ) )
		);

		$this->assertStringContainsString( 'not been synced', $html );
	}
}
