<?php
/**
 * WP_List_Table for ARkid viewers.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\EmbedDto;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ViewersListTable extends \WP_List_Table {

	/**
	 * @var array<int, EmbedDto>
	 */
	private array $rows = array();

	/**
	 * @param array<int, EmbedDto> $rows
	 */
	public function __construct( array $rows = array() ) {
		parent::__construct(
			array(
				'singular' => 'arkid-viewer',
				'plural'   => 'arkid-viewers',
				'ajax'     => false,
			)
		);
		$this->rows = $rows;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'image'         => __( 'Preview', 'arkid-catalogue-link' ),
			'row_number'    => __( '#', 'arkid-catalogue-link' ),
			'product_name'  => __( 'Product', 'arkid-catalogue-link' ),
			'brand_name'    => __( 'Brand', 'arkid-catalogue-link' ),
			'type'          => __( 'Type', 'arkid-catalogue-link' ),
			'internal_note' => __( 'Internal note', 'arkid-catalogue-link' ),
			'created'       => __( 'Created', 'arkid-catalogue-link' ),
			'actions'       => __( 'Actions', 'arkid-catalogue-link' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'row_number'   => array( 'row_number', false ),
			'product_name' => array( 'product_name', false ),
			'brand_name'   => array( 'brand_name', false ),
			'created'      => array( 'created', true ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sort query string.
		$orderby     = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] )
			? sanitize_text_field( wp_unslash( $_GET['orderby'] ) )
			: 'row_number';
		$order_param = isset( $_GET['order'] ) && is_string( $_GET['order'] )
			? sanitize_text_field( wp_unslash( $_GET['order'] ) )
			: '';
		$order       = 'desc' === strtolower( $order_param ) ? 'desc' : 'asc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		usort(
			$this->rows,
			static function ( EmbedDto $a, EmbedDto $b ) use ( $orderby, $order ): int {
				$cmp = match ( $orderby ) {
					'product_name' => strcmp( $a->product_name, $b->product_name ),
					'brand_name'   => strcmp( $a->brand_name, $b->brand_name ),
					'created'      => strcmp( $a->created, $b->created ),
					default        => $a->row_number <=> $b->row_number,
				};
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);

		$this->items = $this->rows;
	}

	/**
	 * @param array<mixed>|object $item        Row.
	 * @param string              $column_name Column slug.
	 */
	public function column_default( $item, $column_name ): string {
		if ( ! $item instanceof EmbedDto ) {
			return '';
		}
		return match ( $column_name ) {
			'row_number'    => '#' . $item->row_number,
			'product_name'  => esc_html( $item->product_name ),
			'brand_name'    => esc_html( $item->brand_name ),
			'internal_note' => esc_html( $item->internal_note ),
			'created'       => esc_html( $item->created ),
			default         => '',
		};
	}

	public function column_image( EmbedDto $item ): string {
		if ( '' === $item->image ) {
			return '';
		}
		return sprintf(
			'<img src="%s" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" />',
			esc_url( $item->image )
		);
	}

	public function column_type( EmbedDto $item ): string {
		if ( '' === $item->type ) {
			return '';
		}
		return sprintf(
			'<span class="arkid-catalogue-link__type-badge" data-type="%1$s">%1$s</span>',
			esc_attr( strtoupper( $item->type ) )
		);
	}

	public function column_actions( EmbedDto $item ): string {
		$links = array();
		if ( '' !== $item->edit_url ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $item->edit_url ),
				esc_html__( 'Edit', 'arkid-catalogue-link' )
			);
		}
		if ( '' !== $item->analytics_url ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $item->analytics_url ),
				esc_html__( 'Analytics', 'arkid-catalogue-link' )
			);
		}
		return implode( ' | ', $links );
	}

	public function no_items(): void {
		esc_html_e( 'No viewers found. Add a viewer in the ARkid Catalogue or check your API key.', 'arkid-catalogue-link' );
	}
}
