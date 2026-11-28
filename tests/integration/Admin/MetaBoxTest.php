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

	public function test_clearing_the_picker_removes_the_link(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );

		$this->post_a_save( $product_id, '' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::embed_url( $product_id ) );
		$this->assertSame( '', PostMeta::embed_image( $product_id ) );
	}

	/**
	 * Unlinking used to delete the position override together with the link, so
	 * a merchant who removed a viewer and picked another one silently lost a
	 * deliberate "Above product" or "Button only" choice.
	 */
	public function test_unlinking_keeps_the_position_override(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'above_product' );

		// The meta box posts the select as it was shown, so the override comes along.
		$this->post_a_save( $product_id, '', 'above_product' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::embed_url( $product_id ) );
		$this->assertSame( '', PostMeta::embed_image( $product_id ) );
		$this->assertSame(
			'above_product',
			PostMeta::position( $product_id ),
			'The position override is the merchant\'s own setting, not part of the link.'
		);
	}

	public function test_relinking_after_an_unlink_still_has_the_position_override(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'button_only' );
		$this->stub_http( '/api/ecom/embed/', $this->embed_payload( 'new-999', 'https://catalogue.arkid.app/e/new-999' ) );

		$this->post_a_save( $product_id, '', 'button_only' );
		$this->post_a_save( $product_id, 'new-999', PostMeta::position( $product_id ) );

		$this->assertSame( 'new-999', PostMeta::embed_id( $product_id ) );
		$this->assertSame( 'button_only', PostMeta::position( $product_id ) );
	}

	public function test_unlinking_with_the_site_default_selected_removes_the_override(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'above_product' );

		// "Use site default" is an explicit choice too: the select posts an empty value.
		$this->post_a_save( $product_id, '', '' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::position( $product_id ) );
	}

	public function test_unlinking_stores_a_position_changed_in_the_same_save(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'above_product' );

		$this->post_a_save( $product_id, '', 'below_product' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( 'below_product', PostMeta::position( $product_id ) );
	}

	public function test_unlinking_ignores_an_unknown_position(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', 'above_product' );

		$this->post_a_save( $product_id, '', 'sideways' );

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

	/**
	 * BF-UNSYNCED. The refresh job drops the snapshot of a viewer that ARkid
	 * answers 404 for and keeps its id. The meta box then said "has not been
	 * synced yet ... will appear once the connection succeeds", which never
	 * happens for a viewer that is gone.
	 */
	public function test_a_viewer_deleted_in_arkid_is_reported_as_gone_not_as_unsynced(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'no longer exists in your ARkid account', $html );
		$this->assertStringNotContainsString( 'not been synced', $html );
		$this->assertStringNotContainsString( 'once the connection succeeds', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_a_viewer_ARkid_cannot_be_asked_about_keeps_the_not_synced_warning_when_there_is_no_snapshot(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'abc-123' );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'not been synced', $html );
		$this->assertStringNotContainsString( 'no longer exists', $html );
	}

	public function test_an_unreachable_arkid_is_said_so_where_the_viewer_is_still_shown(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'could not be checked', $html );
		$this->assertStringContainsString( 'last synced viewer', $html );
		$this->assertStringNotContainsString( 'no longer exists', $html );
		$this->assertStringNotContainsString( 'not been synced', $html );
		// The raw id stays visible so the merchant still sees what is attached.
		$this->assertStringContainsString( '>abc-123<', preg_replace( '/\s+/', '', $html ) ?? '' );
	}

	public function test_a_rejected_api_key_reads_as_could_not_be_checked_not_as_a_deleted_viewer(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		$this->stub_http_status( '/api/ecom/embed/', 401 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'could not be checked', $html );
		$this->assertStringNotContainsString( 'no longer exists', $html );
	}

	public function test_a_healthy_viewer_shows_no_link_notice_at_all(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		$this->stub_http( '/api/ecom/embed/', $this->embed_payload( 'abc-123' ) );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringNotContainsString( 'arkid-catalogue-link__link-notice', $html );
		$this->assertStringContainsString( '#7 - ARkid - Test Chair', $html, 'The live label is shown.' );
	}

	public function test_no_viewer_means_no_request_and_no_notice(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertSame( array(), $this->http_calls );
		$this->assertStringNotContainsString( 'arkid-catalogue-link__link-notice', $html );
	}

	/**
	 * A viewer that has just been created or restored answers 200. A `missing`
	 * left over from before must not be shown as missing, because the live
	 * answer wins over the stored one.
	 */
	public function test_a_viewer_that_exists_now_is_never_shown_as_missing_because_of_an_old_status(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'back-777' );
		update_post_meta( $product_id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );
		$this->stub_http( '/api/ecom/embed/', $this->embed_payload( 'back-777' ) );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringNotContainsString( 'no longer exists', $html );
	}

	public function test_the_stored_missing_status_is_shown_when_arkid_cannot_confirm_it(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		update_post_meta( $product_id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'no longer exists in your ARkid account', $html );
	}

	public function test_the_missing_notice_offers_the_two_ways_out(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'Pick another viewer or remove it', $html );
		$this->assertStringContainsString( 'arkid-catalogue-link__remove-viewer', $html );
	}

	public function test_saving_a_viewer_that_does_not_exist_keeps_the_choice_and_flags_it_missing(): void {
		$product_id = $this->make_product_with_embed( 'old-111', '', 'https://catalogue.arkid.app/e/old-111' );
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$this->post_a_save( $product_id, 'gone-404', 'below_product' );

		$this->assertSame( 'gone-404', PostMeta::embed_id( $product_id ), 'The choice is kept so the merchant can see it.' );
		$this->assertSame( PostMeta::STATUS_MISSING, PostMeta::status( $product_id ) );
		$this->assertSame( '', PostMeta::embed_url( $product_id ), 'Nothing of the previous viewer may render under the new id.' );
		$this->assertSame( '', PostMeta::embed_image( $product_id ) );
		$this->assertSame( 'below_product', PostMeta::position( $product_id ) );
	}

	public function test_saving_a_viewer_that_does_not_exist_queues_a_retry(): void {
		$product_id = $this->make_product_with_embed( 'old-111' );
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$this->post_a_save( $product_id, 'gone-404' );

		$this->assertTrue(
			(bool) as_has_scheduled_action( 'arkid_catalogue_link_refresh_product', array( 'product_id' => $product_id ), 'arkid-catalogue-link' ),
			'A viewer that was just created may not be visible to the API yet; the retry heals it.'
		);
	}

	public function test_saving_a_viewer_that_vanished_drops_its_snapshot_even_when_it_is_the_same_id(): void {
		$product_id = $this->make_product_with_embed( 'same-333', '', 'https://catalogue.arkid.app/e/same-333' );
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$this->post_a_save( $product_id, 'same-333' );

		$this->assertSame( PostMeta::STATUS_MISSING, PostMeta::status( $product_id ) );
		$this->assertSame( '', PostMeta::embed_url( $product_id ), 'ARkid says it is gone, so it must stop rendering.' );
	}

	/**
	 * A stale `missing` must never survive a re-link.
	 */
	public function test_picking_another_viewer_clears_the_missing_status(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		update_post_meta( $product_id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );
		$this->stub_http( '/api/ecom/embed/', $this->embed_payload( 'new-999', 'https://catalogue.arkid.app/e/new-999' ) );

		$this->post_a_save( $product_id, 'new-999' );

		$this->assertSame( '', PostMeta::status( $product_id ) );
		$this->assertSame( array(), get_post_meta( $product_id, PostMeta::KEY_STATUS ), 'The key is removed, not stored empty.' );
		$this->assertSame( 'https://catalogue.arkid.app/e/new-999', PostMeta::embed_url( $product_id ) );
	}

	public function test_picking_another_viewer_while_arkid_is_down_does_not_inherit_the_old_status(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		update_post_meta( $product_id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$this->post_a_save( $product_id, 'new-222' );

		$this->assertSame( 'new-222', PostMeta::embed_id( $product_id ) );
		$this->assertSame( '', PostMeta::status( $product_id ), 'The old viewer\'s verdict says nothing about the new one.' );
	}

	public function test_resaving_the_same_viewer_while_arkid_is_down_keeps_what_was_known(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		update_post_meta( $product_id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$this->post_a_save( $product_id, 'gone-404' );

		$this->assertSame( PostMeta::STATUS_MISSING, PostMeta::status( $product_id ) );
	}

	public function test_removing_the_viewer_clears_the_missing_status(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, PostMeta::KEY_EMBED_ID, 'gone-404' );
		update_post_meta( $product_id, PostMeta::KEY_STATUS, PostMeta::STATUS_MISSING );

		$this->post_a_save( $product_id, '' );

		$this->assertSame( '', PostMeta::embed_id( $product_id ) );
		$this->assertSame( array(), get_post_meta( $product_id, PostMeta::KEY_STATUS ) );
	}

	public function test_a_rejected_key_on_save_is_not_a_missing_viewer(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->stub_http_status( '/api/ecom/embed/', 403 );

		$this->post_a_save( $product_id, 'new-222' );

		$this->assertSame( 'new-222', PostMeta::embed_id( $product_id ), 'The choice is kept for the retry.' );
		$this->assertSame( '', PostMeta::status( $product_id ), 'An API key problem says nothing about the viewer.' );
	}

	// ---- Preview button and colour dots ------------------------------------

	/**
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function viewer_with_colours( array $extra = array() ): array {
		return array_merge(
			$this->embed_payload( 'abc-123' ),
			array(
				'brand_name'    => 'Felix',
				'product_name'  => 'Learning Tower',
				'edit_url'      => 'https://catalogue.arkid.app/configure/edit/abc',
				'analytics_url' => 'https://catalogue.arkid.app/analytics/abc',
				'colors'        => array(
					array( 'variant_id' => 'v1', 'name' => 'Copper', 'rgb' => '#b55f39', 'enabled' => true ),
					array( 'variant_id' => 'v2', 'name' => 'Graphite', 'rgb' => '#929498', 'enabled' => false ),
				),
			),
			$extra
		);
	}

	public function test_the_box_offers_a_preview_of_the_selected_viewer(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		$this->stub_http( '/api/ecom/embed/', $this->viewer_with_colours() );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertMatchesRegularExpression( '/<button[^>]*arkid-catalogue-link__preview[^>]*data-embed-url="https:\/\/catalogue\.arkid\.app\/e\/abc-123\?source=woocommerce"/', $html );
		$this->assertStringContainsString( 'data-product-name="Felix Learning Tower"', $html );
		$this->assertStringContainsString( 'data-edit-url="https://catalogue.arkid.app/configure/edit/abc"', $html );
		$this->assertStringContainsString( 'data-analytics-url="https://catalogue.arkid.app/analytics/abc"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
	}

	public function test_without_a_viewer_there_is_nothing_to_preview(): void {
		$product_id = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertMatchesRegularExpression( '/<button[^>]*arkid-catalogue-link__preview[^>]*\bhidden\b/', $html );
		$this->assertStringNotContainsString( 'data-embed-url', $html );
	}

	/**
	 * What the storefront shows is what is stored with the product; that is what
	 * is previewed when ARkid cannot be asked.
	 */
	public function test_when_arkid_is_unreachable_the_stored_viewer_is_previewed_under_the_product_name(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', '', 'https://catalogue.arkid.app/e/abc-123' );
		wp_update_post( array( 'ID' => $product_id, 'post_title' => 'Shop Chair' ) );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'data-embed-url="https://catalogue.arkid.app/e/abc-123?source=woocommerce"', $html );
		$this->assertStringContainsString( 'data-product-name="Shop Chair"', $html );
		$this->assertStringContainsString( 'data-edit-url=""', $html );
	}

	public function test_a_stored_address_outside_the_arkid_hosts_is_not_previewed(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', '', 'https://evil.example/e/abc-123' );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringNotContainsString( 'data-embed-url', $html );
	}

	public function test_the_colours_of_the_selected_viewer_are_shown_under_its_image(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		$this->stub_http( '/api/ecom/embed/', $this->viewer_with_colours() );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringContainsString( 'aria-label="Copper: shown"', $html );
		$this->assertStringContainsString( 'aria-label="Graphite: hidden"', $html );
		$this->assertDoesNotMatchRegularExpression( '/arkid-catalogue-link__embed-colors"[^>]*\bhidden\b/', $html );
	}

	public function test_no_colours_are_shown_when_the_viewer_cannot_be_asked_about(): void {
		$product_id = $this->make_product_with_embed( 'abc-123' );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$html = $this->render( fn() => $this->metabox()->render( get_post( $product_id ) ) );

		$this->assertStringNotContainsString( 'arkid-catalogue-link__dot', $html );
		$this->assertMatchesRegularExpression( '/arkid-catalogue-link__embed-colors"[^>]*\bhidden\b/', $html );
	}
}
