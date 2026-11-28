<?php
/**
 * Which shop products have which viewer selected.
 *
 * Read from the plugin's own post meta, never from ARkid, so a list of viewers
 * can say where each one is shown at the cost of one database query.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\PostMeta;

defined( 'ABSPATH' ) || exit;

class ProductLinks {

	/**
	 * The query variable of the Products list that narrows it to the products of
	 * one viewer: `edit.php?post_type=product&arkid_embed=<viewer id>`.
	 */
	public const QUERY_VAR = 'arkid_embed';

	/**
	 * Key a viewer id the way the maps of this class, and the traffic map of
	 * `StatsDto::interactions_by_embed()`, do: a lower-case id, so that a
	 * viewer is found whichever case ARkid or the stored link spells it in.
	 */
	public static function key( string $embed_id ): string {
		return strtolower( $embed_id );
	}

	/**
	 * Every product that has a viewer selected, by viewer.
	 *
	 * The same products the refresh job walks: products that are not in the trash
	 * and not an auto-draft. Drafts, pending, private and scheduled products are
	 * included, because the viewer is selected on them; each says its status.
	 * Products of a viewer are in title order.
	 *
	 * One query, grouped here.
	 *
	 * @return array<string, array<int, LinkedProduct>> Products by lower-case viewer id.
	 */
	public function by_embed(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of the plugin's own meta key, for an admin screen; there is no core API for "all posts that have this meta, with their titles".
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS embed_id, p.ID AS id, p.post_title AS title, p.post_status AS status
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND pm.meta_value <> ''
				    AND p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				  ORDER BY p.post_title ASC, p.ID ASC",
				PostMeta::KEY_EMBED_ID,
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! $row instanceof \stdClass ) {
				continue;
			}
			$id = is_numeric( $row->id ?? null ) ? (int) $row->id : 0;
			if ( $id < 1 ) {
				continue;
			}
			$key = self::key( is_string( $row->embed_id ?? null ) ? $row->embed_id : '' );
			if ( '' === $key ) {
				continue;
			}

			$out[ $key ][] = new LinkedProduct(
				$id,
				is_string( $row->title ?? null ) ? $row->title : '',
				is_string( $row->status ?? null ) ? $row->status : ''
			);
		}

		return $out;
	}

	/**
	 * The ids of the products that have one viewer selected: what `by_embed()`
	 * lists for it, found without reading the products of every other viewer.
	 * The viewer id is compared without regard to case, like the keys of
	 * `by_embed()`.
	 *
	 * @return array<int, int> Product ids, in title order; none for an id that cannot be a viewer's.
	 */
	public function product_ids( string $embed_id ): array {
		global $wpdb;

		$key = self::key( PostMeta::sanitize_embed_id( $embed_id ) );
		if ( '' === $key ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of the plugin's own meta key, for an admin screen; there is no core API for "all posts that have this meta value, whatever its case".
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND LOWER( pm.meta_value ) = %s
				    AND p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				  ORDER BY p.post_title ASC, p.ID ASC",
				PostMeta::KEY_EMBED_ID,
				$key,
				PostMeta::POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$found = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) ) {
				$found[] = (int) $id;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * The Products list narrowed to the products of one viewer, or '' for an id
	 * that cannot be one. `Admin\ProductListFilter` answers that address.
	 */
	public static function products_url( string $embed_id ): string {
		$embed_id = PostMeta::sanitize_embed_id( $embed_id );
		if ( '' === $embed_id ) {
			return '';
		}

		return admin_url(
			'edit.php?' . http_build_query(
				array(
					'post_type'      => PostMeta::POST_TYPE,
					self::QUERY_VAR => $embed_id,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);
	}
}
