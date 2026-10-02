<?php
/**
 * Schema-version migrator.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Migrations;

use Arkid\CatalogueLink\Cron\RefreshEmbeds;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	public const OPTION_KEY = 'arkid_catalogue_link_db_version';

	private const LOCK_KEY = 'arkid_catalogue_link_migrating';

	/** Seconds after which a lock is assumed abandoned. */
	private const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/** Versions that carry a data step, in order. */
	private const STEPS = array( '1.0.0' );

	public function register(): void {
		// `init`, NOT `plugins_loaded`. register() is itself called from a
		// plugins_loaded:10 callback, so adding a plugins_loaded callback here
		// at any priority <= 10 lands behind the running pointer and never
		// fires — which is why the version marker was never written. `init` is
		// strictly later, runs for every request type (web, admin, REST, cron,
		// CLI), and guarantees Action Scheduler is booted so a step can enqueue.
		add_action( 'init', array( $this, 'maybe_migrate' ), 5 );
	}

	public function maybe_migrate(): void {
		$stored = get_option( self::OPTION_KEY, '' );
		$from   = is_string( $stored ) ? $stored : '';
		$target = ARKID_CATALOGUE_LINK_VERSION;

		if ( $from === $target ) {
			return;
		}

		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			foreach ( self::STEPS as $version ) {
				if ( '' !== $from && version_compare( $from, $version, '>=' ) ) {
					continue;
				}
				$this->run_step( $version );
			}
			update_option( self::OPTION_KEY, $target, true );
		} finally {
			delete_option( self::LOCK_KEY );
		}
	}

	/**
	 * `add_option()` fails when the row already exists, so it doubles as a
	 * cross-request mutex backed by the UNIQUE index on `option_name`. Without
	 * one, two concurrent requests on an upgraded site both run the steps.
	 */
	private function acquire_lock(): bool {
		if ( false !== add_option( self::LOCK_KEY, (string) time(), '', false ) ) {
			return true;
		}

		$started_raw = get_option( self::LOCK_KEY, 0 );
		$started     = is_numeric( $started_raw ) ? (int) $started_raw : 0;
		if ( time() - $started < self::LOCK_TTL ) {
			return false;
		}

		// Previous holder died mid-migration; take it over.
		update_option( self::LOCK_KEY, (string) time(), false );
		return true;
	}

	private function run_step( string $version ): void {
		match ( $version ) {
			'1.0.0' => $this->drop_legacy_transient_cache(),
			default => null,
		};
	}

	/**
	 * 0.2.x kept a 12-hour transient cache, a reverse index and a stampede
	 * lock. 1.0.0 reads none of them, so remove the rows rather than leaving
	 * them to expire.
	 */
	private function drop_legacy_transient_cache(): void {
		global $wpdb;

		// Index-driven first: `delete_transient()` is object-cache aware, which
		// raw SQL is not.
		$index = get_option( 'arkid_catalogue_link_known_embed_ids', array() );
		if ( is_array( $index ) ) {
			foreach ( $index as $maybe_id ) {
				if ( ! is_string( $maybe_id ) || '' === $maybe_id ) {
					continue;
				}
				delete_transient( 'arkid_embed_' . $maybe_id );
				delete_transient( 'arkid_embed_lock_' . $maybe_id );
			}
		}
		delete_option( 'arkid_catalogue_link_known_embed_ids' );

		// Then sweep rows the index never knew about (it was dropped wholesale
		// by the old flush(), which could outlive the transients it indexed).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\\_transient\\_arkid\\_embed\\_%'
			   OR option_name LIKE '\\_transient\\_timeout\\_arkid\\_embed\\_%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// 0.2.x could leave a product pointing at a new embed id while its
		// url/image snapshot still described the previous one. Re-sync so those
		// products heal on upgrade instead of rendering the wrong viewer until
		// someone re-saves them.
		RefreshEmbeds::schedule_full_resync();
	}
}
