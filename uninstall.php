<?php
/**
 * Uninstall: remove the settings option, the schema-version marker, any leftover
 * 0.2.x cache rows, and our scheduled actions.
 *
 * Per-product post meta is deliberately KEPT so that reinstalling restores every
 * product's viewer without re-picking it.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Everything that has to happen once per site.
 *
 * Options and the `$wpdb->options` table are both per-blog, so on multisite this
 * has to run inside switch_to_blog() for every site — otherwise a 50-site network
 * keeps 49 copies of the API key after "Delete".
 */
function arkid_catalogue_link_uninstall_site(): void {
	global $wpdb;

	delete_option( 'woocommerce_arkid-catalogue-link_settings' );
	delete_option( 'arkid_catalogue_link_db_version' );
	delete_option( 'arkid_catalogue_link_migrating' );

	// Legacy 0.2.x transient cache. Index-driven first, because delete_transient()
	// is aware of external object caches in a way raw SQL is not.
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

	// Then sweep any rows the index never knew about.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '\\_transient\\_arkid\\_embed\\_%'
		   OR option_name LIKE '\\_transient\\_timeout\\_arkid\\_embed\\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'arkid_catalogue_link_refresh_embeds', array(), 'arkid-catalogue-link' );
		as_unschedule_all_actions( 'arkid_catalogue_link_refresh_product', array(), 'arkid-catalogue-link' );
	}
}

if ( is_multisite() ) {
	// Batched: an unbounded get_sites() on a large network is a memory hazard.
	$arkid_offset    = 0;
	$arkid_batch_max = 200;
	$arkid_found     = 0;
	do {
		$arkid_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $arkid_batch_max,
				'offset' => $arkid_offset,
			)
		);
		$arkid_found = count( $arkid_site_ids );
		foreach ( $arkid_site_ids as $arkid_site_id ) {
			switch_to_blog( $arkid_site_id );
			arkid_catalogue_link_uninstall_site();
			restore_current_blog();
		}
		$arkid_offset += $arkid_batch_max;
	} while ( $arkid_found === $arkid_batch_max );
} else {
	arkid_catalogue_link_uninstall_site();
}
