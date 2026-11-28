<?php
/**
 * How many of the shop's products have a viewer, and which need a look.
 *
 * Counts and lists for the views of the Products list, from the plugin's own
 * post meta in one query each, whatever the size of the shop: the list screens
 * must not read every product to say "12 with a viewer". The products are the
 * ones `ProductLinks` and the refresh job walk: not in the trash, not an
 * auto-draft.
 *
 * "Needs attention" is a link that shows nothing on the product page: no snapshot
 * stored (`LinkState::NotSynced`), or one ARkid said does not exist
 * (`LinkState::Missing`). When the viewers of the account are known there is one
 * more, the viewer the account does not list. It has to be told by the live list
 * and not by a cache of it, and a product whose viewer the account lists is not
 * "missing" whatever a refresh left behind, as on the product edit screen.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\PostMeta;

defined( 'ABSPATH' ) || exit;

class ProductStates {

	/**
	 * Every product of the shop.
	 */
	public function total(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count of the shop's products, as a list screen's views need; core's wp_count_posts() counts per status and is no cheaper.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				  WHERE p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * The products that have a viewer selected, whatever its state.
	 */
	public function linked(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count over the plugin's own meta key, for a list screen.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value <> ''
				  WHERE p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				PostMeta::KEY_EMBED_ID,
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * How many products have each viewer selected.
	 *
	 * @return array<int|string, int> By lower-case viewer id, as `ProductLinks::key()` makes it (PHP makes an integer of an id that is all digits).
	 */
	public function by_viewer(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped count over the plugin's own meta key, for a list screen: one row per viewer, not per product.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER( e.meta_value ) AS embed_id, COUNT( DISTINCT p.ID ) AS products
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value <> ''
				  WHERE p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				  GROUP BY LOWER( e.meta_value )",
				PostMeta::KEY_EMBED_ID,
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $row instanceof \stdClass && is_string( $row->embed_id ?? null ) && is_numeric( $row->products ?? null ) ) {
				$out[ $row->embed_id ] = (int) $row->products;
			}
		}

		return $out;
	}

	/**
	 * How many products need attention.
	 *
	 * @param array<int, string>|null $account_ids The ids of the viewers the account lists, null when that is not known.
	 */
	public function attention_count( ?array $account_ids ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped count over the plugin's own meta keys, for a list screen: a row per viewer and state, not per product.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER( e.meta_value ) AS embed_id,
				        ( u.meta_value IS NOT NULL AND u.meta_value <> '' ) AS synced,
				        ( s.meta_value = %s ) AS missing,
				        COUNT( DISTINCT p.ID ) AS products
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value <> ''
				   LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
				   LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				  WHERE p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				  GROUP BY LOWER( e.meta_value ), synced, missing",
				PostMeta::STATUS_MISSING,
				PostMeta::KEY_EMBED_ID,
				PostMeta::KEY_EMBED_URL,
				PostMeta::KEY_STATUS,
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$known = null === $account_ids ? null : self::known( $account_ids );
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! $row instanceof \stdClass || ! is_string( $row->embed_id ?? null ) || ! is_numeric( $row->products ?? null ) ) {
				continue;
			}
			if ( self::needs_attention( $row->embed_id, '1' === (string) $row->synced, '1' === (string) $row->missing, $known ) ) {
				$count += (int) $row->products;
			}
		}

		return $count;
	}

	/**
	 * The products that need attention, by id.
	 *
	 * @param array<int, string>|null $account_ids The ids of the viewers the account lists, null when that is not known.
	 *
	 * @return array<int, int>
	 */
	public function attention_ids( ?array $account_ids ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of the plugin's own meta keys for the products that have a viewer, for a filter of a list screen; the state is decided below, where the account's viewers are known.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id,
				        LOWER( e.meta_value ) AS embed_id,
				        ( u.meta_value IS NOT NULL AND u.meta_value <> '' ) AS synced,
				        ( s.meta_value = %s ) AS missing
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value <> ''
				   LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
				   LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				  WHERE p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				  ORDER BY p.ID ASC",
				PostMeta::STATUS_MISSING,
				PostMeta::KEY_EMBED_ID,
				PostMeta::KEY_EMBED_URL,
				PostMeta::KEY_STATUS,
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$known = null === $account_ids ? null : self::known( $account_ids );
		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! $row instanceof \stdClass || ! is_numeric( $row->id ?? null ) || ! is_string( $row->embed_id ?? null ) ) {
				continue;
			}
			if ( self::needs_attention( $row->embed_id, '1' === (string) $row->synced, '1' === (string) $row->missing, $known ) ) {
				$found[ (int) $row->id ] = (int) $row->id;
			}
		}

		return array_values( $found );
	}

	/**
	 * The viewers of the account, as a set of lower-case ids.
	 *
	 * @param array<int, string> $account_ids
	 *
	 * @return array<int|string, true>
	 */
	private static function known( array $account_ids ): array {
		return array_fill_keys( array_map( 'strtolower', $account_ids ), true );
	}

	/**
	 * Whether the link of a product needs a look.
	 *
	 * With the account's viewers, the product page shows nothing when the account
	 * does not list the viewer or no snapshot is stored (a viewer in the list that
	 * a refresh once found missing is not missing now, and a deleted viewer's
	 * snapshot is gone, so the status adds nothing). Without them it is told by
	 * the snapshot and by the stored status.
	 *
	 * @param string                     $embed_id The viewer, in lower case.
	 * @param bool                       $synced   Whether a snapshot is stored.
	 * @param bool                       $missing  Whether the stored status says the viewer is gone.
	 * @param array<int|string, true>|null $known  The viewers of the account, null when that is not known.
	 */
	private static function needs_attention( string $embed_id, bool $synced, bool $missing, ?array $known ): bool {
		if ( null === $known ) {
			return $missing || ! $synced;
		}

		return ! isset( $known[ $embed_id ] ) || ! $synced;
	}
}
