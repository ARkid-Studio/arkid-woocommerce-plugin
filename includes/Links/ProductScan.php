<?php
/**
 * Reads the shop's products that have no viewer, for matching them to ARkid.
 *
 * Shopify's plugin reads the first 2,000 products of a shop (8 pages of 250, by
 * id) and counts from them, with a "+" when the shop has more. This is the same
 * bound for a shop that may have 100,000: the first 2,000 products without a
 * viewer, in the order of their ids, so that the same products are read on
 * every request and a count only moves when the shop does. One query reads the
 * ids and titles, one more the brands; nothing is kept between requests.
 *
 * The brand is the term of WooCommerce's own `product_brand` taxonomy (the
 * shop's counterpart of Shopify's vendor), the first by name when a product has
 * several, and '' where the shop has no brands.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\Matching\ShopProduct;
use Arkid\CatalogueLink\PostMeta;

defined( 'ABSPATH' ) || exit;

class ProductScan {

	/** The most products a scan reads. */
	public const LIMIT = 2000;

	/**
	 * @param int $limit The most products to read; a test lowers it.
	 */
	public function __construct( private readonly int $limit = self::LIMIT ) {}

	/** WooCommerce's brand taxonomy (since 9.6). */
	public const BRAND_TAXONOMY = 'product_brand';

	/**
	 * The products without a viewer, up to `LIMIT`.
	 */
	public function unlinked(): ScanResult {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of ids and titles of the products that have no value under the plugin's meta key; there is no core API for "posts without this meta, by id, bounded", and a WP_Query would load every post.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_title AS title
				   FROM {$wpdb->posts} p
				  WHERE p.post_type = %s
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				    AND NOT EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} e
				         WHERE e.post_id = p.ID AND e.meta_key = %s AND e.meta_value <> ''
				    )
				  ORDER BY p.ID ASC
				  LIMIT %d",
				PostMeta::POST_TYPE,
				PostMeta::KEY_EMBED_ID,
				$this->limit + 1
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$titles = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! $row instanceof \stdClass || ! is_numeric( $row->id ?? null ) ) {
				continue;
			}
			$titles[ (int) $row->id ] = self::title( is_string( $row->title ?? null ) ? $row->title : '' );
		}

		$truncated = count( $titles ) > $this->limit;
		if ( $truncated ) {
			$titles = array_slice( $titles, 0, $this->limit, true );
		}

		$brands   = self::brands( array_keys( $titles ) );
		$products = array();
		foreach ( $titles as $id => $title ) {
			$products[] = new ShopProduct( $id, $title, $brands[ $id ] ?? '' );
		}

		return new ScanResult( $products, $truncated );
	}

	/**
	 * A product's title as a person reads it: WordPress stores `&` as `&amp;` in a
	 * title written without the unfiltered_html capability, and the matcher must not
	 * read "amp" as a word.
	 */
	public static function title( string $stored ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $stored, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * The brand of one product, '' for none.
	 */
	public static function brand_of( int $product_id ): string {
		if ( ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return '';
		}

		$terms = get_the_terms( $product_id, self::BRAND_TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			return self::title( $term->name );
		}

		return '';
	}

	/**
	 * The brands of products, in one query.
	 *
	 * @param array<int, int> $product_ids
	 *
	 * @return array<int, string> The first brand by name, by product id; products without one are left out.
	 */
	private static function brands( array $product_ids ): array {
		if ( array() === $product_ids || ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return array();
		}

		$terms = wp_get_object_terms(
			$product_ids,
			self::BRAND_TAXONOMY,
			array(
				'fields'  => 'all_with_object_id',
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$brands = array();
		foreach ( $terms as $term ) {
			// `all_with_object_id` adds the product's id to each term.
			if ( ! property_exists( $term, 'object_id' ) || ! is_numeric( $term->object_id ) ) {
				continue;
			}
			$id = (int) $term->object_id;
			if ( ! isset( $brands[ $id ] ) ) {
				$brands[ $id ] = self::title( $term->name );
			}
		}

		return $brands;
	}
}
