<?php
/**
 * The "ARkid" column of the Products list.
 *
 * Per product: which viewer it shows (its thumbnail, name, colours, a preview), in
 * what state the link is, or, for a product without a viewer, the viewer or the
 * catalogue product that its name matches.
 *
 * It reads the viewers of the account once for the page (`AccountData`) and the
 * rest from the post meta the list's own query has already loaded. When ARkid
 * cannot answer, a linked product still shows what is stored for it (the
 * thumbnail, the id, the state) and an unlinked one shows nothing, so the list
 * renders as it always did. Suggestions are worked out for the products on the
 * page only.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Links\AccountData;
use Arkid\CatalogueLink\Links\LinkState;
use Arkid\CatalogueLink\Links\ProductScan;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Support\Url;

defined( 'ABSPATH' ) || exit;

final class ProductListColumns {

	public const COLUMN = 'arkid';

	public function __construct(
		private readonly AccountData $account,
		private readonly IframeFactory $iframes
	) {}

	public function register(): void {
		add_filter( 'manage_edit-' . PostMeta::POST_TYPE . '_columns', array( $this, 'add_column' ) );
		add_action( 'manage_' . PostMeta::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Add the column before the date, where WooCommerce's own end.
	 *
	 * @param mixed $columns The columns of the list, by slug.
	 *
	 * @return mixed
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $columns;
		}

		$out = array();
		foreach ( $columns as $slug => $label ) {
			if ( 'date' === $slug ) {
				$out[ self::COLUMN ] = __( 'ARkid', 'arkid-catalogue-link' );
			}
			$out[ $slug ] = $label;
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'ARkid', 'arkid-catalogue-link' );
		}

		return $out;
	}

	/**
	 * @param mixed $column  The column being printed.
	 * @param mixed $post_id The product.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column || ! is_numeric( $post_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo $this->cell( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped where it is built.
	}

	/**
	 * The cell of one product.
	 */
	public function cell( int $product_id ): string {
		$embed_id = PostMeta::embed_id( $product_id );

		return '' === $embed_id ? $this->unlinked_cell( $product_id ) : $this->linked_cell( $product_id, $embed_id );
	}

	/**
	 * A product that has a viewer: the viewer (as far as it is known), the colours
	 * and the state of the link.
	 */
	private function linked_cell( int $product_id, string $embed_id ): string {
		$embed      = $this->account->embed( $embed_id );
		$stored_url = PostMeta::embed_url( $product_id );
		$image      = Url::https( null !== $embed ? $embed->image : PostMeta::embed_image( $product_id ) );
		$known      = $this->account->has_embeds();

		if ( null !== $embed ) {
			// ARkid has it, so a `missing` an earlier refresh left behind is stale.
			$state = LinkState::classify( $embed_id, $stored_url, '' );
			$name  = self::viewer_label( $embed );
		} elseif ( $known ) {
			$state = null;
			$name  = '';
		} else {
			$state = LinkState::of_product( $product_id );
			$name  = '';
		}

		$title = '' !== $name ? $name : $embed_id;
		// A viewer the account does not list has nothing to preview.
		$preview = $known && null === $embed
			? ''
			: PreviewButton::attributes(
				$this->iframes,
				null !== $embed ? $embed->embed_url : $stored_url,
				null !== $embed ? trim( $embed->brand_name . ' ' . $embed->product_name ) : self::title_of( $product_id ),
				null !== $embed ? $embed->edit_url : '',
				null !== $embed ? $embed->analytics_url : ''
			);

		$html = '<div class="arkid-catalogue-link__cell">';
		if ( '' !== $image ) {
			$html .= sprintf(
				'<img class="arkid-catalogue-link__thumb-image" src="%s" alt="" width="40" height="40" loading="lazy" />',
				esc_url( $image )
			);
		}
		$html .= '<div class="arkid-catalogue-link__cell-body">';

		if ( '' !== $preview ) {
			$html .= sprintf(
				'<button type="button" class="button-link arkid-catalogue-link__preview" aria-haspopup="dialog" aria-label="%1$s" %2$s>%3$s</button>',
				esc_attr( self::preview_label( $title ) ),
				$preview,
				esc_html( $title )
			);
		} elseif ( '' !== $name ) {
			$html .= '<span class="arkid-catalogue-link__viewer-name">' . esc_html( $name ) . '</span>';
		} else {
			$html .= '<code class="arkid-catalogue-link__viewer-id">' . esc_html( $embed_id ) . '</code>';
		}

		$html .= ' ' . self::badge( $state );
		if ( null === $state ) {
			$html .= '<span class="description arkid-catalogue-link__note">' . esc_html__( 'Linked to a viewer your ARkid account does not list.', 'arkid-catalogue-link' ) . '</span>';
		}
		if ( null !== $embed ) {
			$html .= ColorDots::render( $embed );
		}

		return $html . '</div></div>';
	}

	/**
	 * A product without a viewer: the match ARkid has for its name, if there is one.
	 */
	private function unlinked_cell( int $product_id ): string {
		$suggester  = $this->account->suggester();
		$suggestion = null === $suggester
			? null
			: $suggester->suggest( self::title_of( $product_id ), ProductScan::brand_of( $product_id ) );

		if ( null === $suggestion ) {
			return '<span aria-hidden="true">&ndash;</span><span class="screen-reader-text">' . esc_html__( 'No viewer', 'arkid-catalogue-link' ) . '</span>';
		}

		// A viewer of the account first; the catalogue product only when no viewer matches.
		$match = $suggestion->embed ?? $suggestion->product;
		$text  = null !== $suggestion->embed
			/* translators: %s: the name of a viewer */
			? __( 'Suggested viewer: %s', 'arkid-catalogue-link' )
			/* translators: %s: the name of a product of the ARkid catalogue */
			: __( 'Suggested from the ARkid catalogue: %s', 'arkid-catalogue-link' );

		$chip = esc_html( sprintf( $text, null === $match ? '' : $match->label() ) );
		$edit = get_edit_post_link( $product_id, 'raw' );
		if ( is_string( $edit ) && '' !== $edit ) {
			$chip = sprintf(
				'<a href="%1$s" title="%2$s">%3$s</a>',
				esc_url( $edit . '#arkid-catalogue-link-metabox' ),
				esc_attr__( 'Open the product to choose a viewer', 'arkid-catalogue-link' ),
				$chip
			);
		}

		return self::badge( null, true ) . '<span class="arkid-catalogue-link__chip">' . $chip . '</span>';
	}

	/**
	 * The state of a link in words, and not by colour alone.
	 *
	 * @param LinkState|null $state The state; null is a viewer the account does not list.
	 * @param bool           $suggested True for the badge of a product that has no viewer and a match.
	 */
	private static function badge( ?LinkState $state, bool $suggested = false ): string {
		if ( $suggested ) {
			$modifier = 'info';
			$label    = __( 'Match available', 'arkid-catalogue-link' );
		} elseif ( null === $state ) {
			$modifier = 'error';
			$label    = __( 'Unknown viewer', 'arkid-catalogue-link' );
		} else {
			[ $modifier, $label ] = match ( $state ) {
				LinkState::Linked    => array( 'ok', __( 'Linked', 'arkid-catalogue-link' ) ),
				LinkState::NotSynced => array( 'warning', __( 'Not synced yet', 'arkid-catalogue-link' ) ),
				LinkState::Missing   => array( 'error', __( 'Viewer missing', 'arkid-catalogue-link' ) ),
				LinkState::None      => array( 'none', '' ),
			};
		}

		return '' === $label ? '' : sprintf( '<span class="arkid-catalogue-link__badge arkid-catalogue-link__badge--%1$s">%2$s</span>', esc_attr( $modifier ), esc_html( $label ) );
	}

	/**
	 * The viewer as the list names it: its number, brand and product.
	 */
	private static function viewer_label( EmbedDto $embed ): string {
		$label = trim( ( $embed->row_number > 0 ? '#' . $embed->row_number . ' ' : '' ) . $embed->brand_name . ' ' . $embed->product_name );

		return '' === $label ? $embed->embed_id : $label;
	}

	/**
	 * The product's title as the matcher reads it: the stored one, without the
	 * "Private:" that `get_the_title()` puts before a private product's.
	 */
	private static function title_of( int $product_id ): string {
		return ProductScan::title( get_post_field( 'post_title', $product_id ) );
	}

	private static function preview_label( string $name ): string {
		return sprintf(
			/* translators: %s: product name */
			__( 'Preview %s', 'arkid-catalogue-link' ),
			$name
		);
	}
}
