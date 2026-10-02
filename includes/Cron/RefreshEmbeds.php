<?php
/**
 * Keeps each product's denormalized embed snapshot in step with ARkid.
 *
 * This is a replication job, not a cache warmer. The storefront renders purely
 * from `_arkid_embed_url` / `_arkid_embed_image` post meta and never calls the
 * API, so without this job a viewer swapped or deleted upstream would keep
 * rendering stale (or dead) on the product page until a human re-saved the
 * product.
 *
 * Shape: a dispatcher walks products in bounded pages and enqueues one action
 * per product; each worker makes exactly one HTTP call. A single action can
 * therefore never exceed max_execution_time, and one hung upstream request
 * cannot poison its neighbours.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Cron;

use Arkid\CatalogueLink\Api\ApiException;
use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Api\NotFoundException;
use Arkid\CatalogueLink\PostMeta;

defined( 'ABSPATH' ) || exit;

class RefreshEmbeds {

	/** Recurring dispatcher. Name unchanged so existing schedules survive upgrade. */
	public const HOOK_SWEEP = 'arkid_catalogue_link_refresh_embeds';

	/** Per-product worker. */
	public const HOOK_PRODUCT = 'arkid_catalogue_link_refresh_product';

	public const GROUP = 'arkid-catalogue-link';

	/** Products dispatched per sweep action. */
	private const PAGE_SIZE = 100;

	public function __construct( private readonly ClientFactory $api ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_schedule' ), 20 );
		add_action( self::HOOK_SWEEP, array( $this, 'sweep' ), 10, 1 );
		add_action( self::HOOK_PRODUCT, array( $this, 'refresh_product' ), 10, 1 );
	}

	public function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		// Matches on empty args only; continuation sweeps carry an after_id, so
		// they never satisfy this check and can't suppress the recurring one.
		if ( as_has_scheduled_action( self::HOOK_SWEEP, array(), self::GROUP ) ) {
			return;
		}

		/** @var int $interval */
		$interval = (int) apply_filters( 'arkid_catalogue_link_refresh_interval', DAY_IN_SECONDS );

		as_schedule_recurring_action(
			time() + $interval,
			$interval,
			self::HOOK_SWEEP,
			array(),
			self::GROUP
		);
	}

	/**
	 * Dispatcher: enqueue one worker per product, then continue if the page was
	 * full. Makes no HTTP calls itself.
	 */
	public function sweep( int $after_id = 0 ): void {
		if ( null === $this->api->create() ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$ids = $this->product_ids_with_embed( $after_id, self::PAGE_SIZE );
		if ( array() === $ids ) {
			return;
		}

		foreach ( $ids as $product_id ) {
			$args = array( 'product_id' => $product_id );
			if ( as_has_scheduled_action( self::HOOK_PRODUCT, $args, self::GROUP ) ) {
				continue;
			}
			as_enqueue_async_action( self::HOOK_PRODUCT, $args, self::GROUP );
		}

		if ( count( $ids ) === self::PAGE_SIZE ) {
			as_enqueue_async_action(
				self::HOOK_SWEEP,
				array( 'after_id' => end( $ids ) ),
				self::GROUP
			);
		}
	}

	/**
	 * Worker: refresh exactly one product.
	 */
	public function refresh_product( int $product_id ): void {
		$client = $this->api->create();
		if ( null === $client ) {
			return;
		}

		$embed_id = PostMeta::embed_id( $product_id );
		if ( '' === $embed_id ) {
			return;
		}

		try {
			$embed = $client->get_embed( $embed_id );
		} catch ( NotFoundException $e ) {
			// Removed upstream: stop rendering it, but keep the selection so
			// the merchant can still see what was chosen and so it heals by
			// itself if ARkid restores the embed.
			delete_post_meta( $product_id, PostMeta::KEY_EMBED_URL );
			delete_post_meta( $product_id, PostMeta::KEY_EMBED_IMAGE );
			return;
		} catch ( AuthException $e ) {
			// A bad key must not be allowed to wipe every snapshot in the store.
			return;
		} catch ( ApiException $e ) {
			// Transient failure; the next sweep retries.
			return;
		}

		PostMeta::write( $product_id, $embed, PostMeta::position( $product_id ) );
	}

	/**
	 * Queue a full re-sync from the start. Used after a migration or an API-key
	 * change, where every snapshot in the store is suspect.
	 */
	public static function schedule_full_resync(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_SWEEP, array( 'after_id' => 0 ), self::GROUP );
		}
	}

	/**
	 * Seek pagination by post ID. LIMIT/OFFSET would skip rows whenever the
	 * underlying set changes mid-sweep, which it does on a live store.
	 *
	 * @return array<int, int>
	 */
	private function product_ids_with_embed( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND pm.meta_value <> ''
				    AND p.post_type = 'product'
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				    AND pm.post_id > %d
				  ORDER BY pm.post_id ASC
				  LIMIT %d",
				PostMeta::KEY_EMBED_ID,
				$after_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ids = array();
		foreach ( $rows as $row ) {
			$id = (int) $row;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
