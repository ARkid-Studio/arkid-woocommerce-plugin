<?php
/**
 * Narrows the Products list to the products of one viewer.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\PostMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Answers `edit.php?post_type=product&arkid_embed=<viewer id>`, the address the
 * viewers list's "+N more" opens. It lists the products that have that viewer
 * selected: the ones the viewers list counts for it.
 *
 * It only narrows what the list shows, to what the person may already see, so
 * it takes no nonce and no capability of its own; the Products list checks both.
 */
final class ProductListFilter {

	public function __construct( private readonly ProductLinks $links ) {}

	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'narrow' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The viewer the address asks for, or '' for none or for an id that cannot be
	 * a viewer's, which is ignored like any other filter value that is not valid.
	 */
	public static function requested_embed_id(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filter in the query string.
		$value = isset( $_GET[ ProductLinks::QUERY_VAR ] ) && is_string( $_GET[ ProductLinks::QUERY_VAR ] )
			? sanitize_text_field( wp_unslash( $_GET[ ProductLinks::QUERY_VAR ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return PostMeta::sanitize_embed_id( $value );
	}

	public function narrow( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! self::is_products_list() ) {
			return;
		}

		$embed_id = self::requested_embed_id();
		if ( '' === $embed_id ) {
			return;
		}

		self::restrict_to( $query, $this->links->product_ids( $embed_id ) );
	}

	/**
	 * Hold the list to these products, and to the ones another filter of it
	 * already holds it to: a search by SKU sets `post__in`, and the products in
	 * both are shown. No product at all lists nothing, not every product: an empty
	 * `post__in` means "no restriction", and 0 is no post.
	 *
	 * @param array<int, int> $ids Product ids.
	 */
	public static function restrict_to( \WP_Query $query, array $ids ): void {
		$already = $query->get( 'post__in' );
		if ( is_array( $already ) && array() !== $already ) {
			$ids = array_values(
				array_intersect(
					array_map( static fn( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, $already ),
					$ids
				)
			);
		}

		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
	}

	/**
	 * Say that the list is narrowed, and how to undo it: without it a shorter list
	 * would be unexplained.
	 */
	public function notice(): void {
		if ( ! self::is_products_list() || '' === self::requested_embed_id() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Showing only the products that show one ARkid viewer.', 'arkid-catalogue-link' ),
			esc_url( admin_url( 'edit.php?post_type=' . PostMeta::POST_TYPE ) ),
			esc_html__( 'Show all products', 'arkid-catalogue-link' )
		);
	}

	/**
	 * Whether this request is the Products list of wp-admin.
	 */
	public static function is_products_list(): bool {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();

		return null !== $screen && 'edit-' . PostMeta::POST_TYPE === $screen->id;
	}
}
