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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A count over the plugin's own meta keys, for a list screen; `attention_sql()` returns a prepared statement.
		$count = $wpdb->get_var( $this->attention_sql( 'COUNT( DISTINCT p.ID )', '', $account_ids ) );

		return is_numeric( $count ) ? (int) $count : 0;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own meta keys, for a list screen; `attention_sql()` returns a prepared statement.
		$rows = $wpdb->get_col( $this->attention_sql( 'DISTINCT p.ID', 'ORDER BY p.ID ASC', $account_ids ) );

		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $id ) {
			if ( is_numeric( $id ) ) {
				$found[] = (int) $id;
			}
		}

		return $found;
	}

	/**
	 * The prepared statement that selects the products that need attention, so that
	 * the count and the list cannot disagree.
	 *
	 * @param string                  $select      What to select; fixed text of this class.
	 * @param string                  $order       The ORDER BY clause, or ''; fixed text of this class.
	 * @param array<int, string>|null $account_ids The ids of the viewers the account lists, null when that is not known.
	 */
	private function attention_sql( string $select, string $order, ?array $account_ids ): string {
		global $wpdb;

		$unknown = '';
		$values  = array();
		if ( null !== $account_ids ) {
			$ids = array_values( array_unique( array_map( 'strtolower', $account_ids ) ) );
			if ( array() === $ids ) {
				// An account without a viewer lists none of the links' viewers.
				$unknown = ' OR 1 = 1';
			} else {
				$unknown = ' OR LOWER( e.meta_value ) NOT IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%s' ) ) . ' )';
				$values  = $ids;
			}
		}

		// With the account's list, the product page showing nothing is told by the
		// snapshot alone (a viewer in the list that a refresh once found missing is
		// not missing now); without it, by the stored status as well.
		$stored = null === $account_ids
			? "s.meta_value = 'missing' OR u.meta_id IS NULL OR u.meta_value = ''"
			: "u.meta_id IS NULL OR u.meta_value = ''";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The meta keys are fixed; the only variable parts are the SELECT and ORDER BY text of this class, the `OR` built from constants, and one placeholder per viewer id of the account.
		return $wpdb->prepare(
			"SELECT {$select}
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value <> ''
			   LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
			   LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			  WHERE p.post_type = %s
			    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			    AND ( {$stored}{$unknown} )
			  {$order}",
			array_merge(
				array( PostMeta::KEY_EMBED_ID, PostMeta::KEY_EMBED_URL, PostMeta::KEY_STATUS, PostMeta::POST_TYPE ),
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}
}
