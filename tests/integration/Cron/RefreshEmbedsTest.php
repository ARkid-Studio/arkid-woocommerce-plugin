<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Cron;

use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Cron\RefreshEmbeds;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class RefreshEmbedsTest extends TestCase {

	private function job(): RefreshEmbeds {
		return new RefreshEmbeds( new ClientFactory( new Options() ) );
	}

	public function set_up(): void {
		parent::set_up();
		update_option( Options::OPTION_KEY, array( 'api_key' => 'test-key' ) );
	}

	public function tear_down(): void {
		delete_option( Options::OPTION_KEY );
		parent::tear_down();
	}

	public function test_a_worker_refreshes_one_product_with_one_request(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', '', 'https://catalogue.arkid.app/e/stale' );
		$this->stub_http(
			'/api/ecom/embed/',
			$this->embed_payload( 'abc-123', 'https://catalogue.arkid.app/e/fresh' )
		);

		$this->job()->refresh_product( $product_id );

		$this->assertSame( 'https://catalogue.arkid.app/e/fresh', PostMeta::embed_url( $product_id ) );
		$this->assertCount( 1, $this->http_calls, 'A worker must make exactly one request.' );
	}

	public function test_the_dispatcher_makes_no_http_calls_of_its_own(): void {
		$this->make_product_with_embed( 'abc-123' );
		$this->make_product_with_embed( 'def-456' );

		$this->job()->sweep();

		$this->assertSame(
			array(),
			$this->http_calls,
			'The dispatcher only enqueues; a blocking call per product in one action is what made this time out.'
		);
	}

	public function test_the_dispatcher_enqueues_one_action_per_product(): void {
		$first  = $this->make_product_with_embed( 'abc-123' );
		$second = $this->make_product_with_embed( 'def-456' );

		$this->job()->sweep();

		foreach ( array( $first, $second ) as $product_id ) {
			$this->assertTrue(
				(bool) as_has_scheduled_action(
					RefreshEmbeds::HOOK_PRODUCT,
					array( 'product_id' => $product_id ),
					RefreshEmbeds::GROUP
				),
				"Product {$product_id} should have its own refresh action."
			);
		}
	}

	public function test_products_without_a_viewer_are_not_swept(): void {
		self::factory()->post->create( array( 'post_type' => 'product' ) );
		// An empty meta value is the registered default, so an EXISTS-style query
		// would sweep this product and burn a request on it.
		$empty = (int) self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $empty, PostMeta::KEY_EMBED_ID, '' );

		$with_viewer = $this->make_product_with_embed( 'abc-123' );

		$this->job()->sweep();

		$this->assertTrue(
			(bool) as_has_scheduled_action( RefreshEmbeds::HOOK_PRODUCT, array( 'product_id' => $with_viewer ), RefreshEmbeds::GROUP )
		);
		$this->assertFalse(
			(bool) as_has_scheduled_action( RefreshEmbeds::HOOK_PRODUCT, array( 'product_id' => $empty ), RefreshEmbeds::GROUP )
		);
	}

	public function test_trashed_products_are_not_swept(): void {
		$trashed = $this->make_product_with_embed( 'abc-123' );
		wp_trash_post( $trashed );

		$this->job()->sweep();

		$this->assertFalse(
			(bool) as_has_scheduled_action( RefreshEmbeds::HOOK_PRODUCT, array( 'product_id' => $trashed ), RefreshEmbeds::GROUP )
		);
	}

	public function test_a_removed_embed_stops_rendering_but_keeps_the_selection(): void {
		$product_id = $this->make_product_with_embed( 'gone-404' );
		$this->stub_http_status( '/api/ecom/embed/', 404 );

		$this->job()->refresh_product( $product_id );

		$this->assertSame( '', PostMeta::embed_url( $product_id ), 'A deleted embed must stop rendering.' );
		$this->assertSame(
			'gone-404',
			PostMeta::embed_id( $product_id ),
			'The selection is kept so it heals if ARkid restores the embed.'
		);
	}

	public function test_a_rejected_api_key_does_not_wipe_snapshots(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', '', 'https://catalogue.arkid.app/e/keep-me' );
		$this->stub_http_status( '/api/ecom/embed/', 401 );

		$this->job()->refresh_product( $product_id );

		$this->assertSame(
			'https://catalogue.arkid.app/e/keep-me',
			PostMeta::embed_url( $product_id ),
			'One bad credential must not empty every product in the store.'
		);
	}

	public function test_a_transient_failure_leaves_the_snapshot_alone(): void {
		$product_id = $this->make_product_with_embed( 'abc-123', '', 'https://catalogue.arkid.app/e/keep-me' );
		$this->stub_http_status( '/api/ecom/embed/', 503 );

		$this->job()->refresh_product( $product_id );

		$this->assertSame( 'https://catalogue.arkid.app/e/keep-me', PostMeta::embed_url( $product_id ) );
	}

	public function test_without_an_api_key_nothing_is_swept_or_requested(): void {
		delete_option( Options::OPTION_KEY );
		$product_id = $this->make_product_with_embed( 'abc-123' );

		$this->job()->sweep();
		$this->job()->refresh_product( $product_id );

		$this->assertSame( array(), $this->http_calls );
	}

	public function test_scheduling_the_recurring_sweep_is_idempotent(): void {
		$job = $this->job();
		$job->maybe_schedule();
		$job->maybe_schedule();

		$this->assertTrue( (bool) as_has_scheduled_action( RefreshEmbeds::HOOK_SWEEP, array(), RefreshEmbeds::GROUP ) );
	}
}
