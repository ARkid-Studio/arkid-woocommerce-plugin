<?php
/**
 * WP_List_Table for ARkid viewers.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Links\LinkedProduct;
use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\Support\ApiTime;
use Arkid\CatalogueLink\Support\EmbedSearch;
use Arkid\CatalogueLink\Support\Text;
use Arkid\CatalogueLink\Support\Url;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ViewersListTable extends \WP_List_Table {

	/** The user option behind Screen Options → "Viewers per page". */
	public const PER_PAGE_OPTION = 'arkid_viewers_per_page';

	public const DEFAULT_PER_PAGE = 20;

	/** The views (the links above the table), by `?show=`. */
	public const SHOW_ALL      = 'all';
	public const SHOW_LIVE     = 'live';
	public const SHOW_UNLINKED = 'unlinked';
	public const SHOW_QUIET    = 'quiet';

	/** How many products a viewer's "Shown on" cell lists before "+N more". */
	private const PRODUCTS_LISTED = 3;

	/** The period of the interactions column and of the "no interactions" view. */
	public const STATS_DAYS = 30;

	/**
	 * @var array<int, EmbedDto>
	 */
	private array $rows = array();

	/**
	 * @var array<string, array<int, LinkedProduct>>
	 */
	private array $linked = array();

	/**
	 * @var array<string, int>|null
	 */
	private ?array $interactions = null;

	private ?IframeFactory $iframes = null;

	private string $show = self::SHOW_ALL;

	/**
	 * @param array<int, EmbedDto>                     $rows         The viewers of the account.
	 * @param array<string, array<int, LinkedProduct>> $linked       The products that have a viewer, by lower-case viewer id.
	 * @param array<string, int>|null                  $interactions Interactions in the last 30 days, by lower-case viewer id; null when ARkid could not say.
	 * @param IframeFactory|null                       $iframes      Decides which viewers may be previewed; null previews none.
	 */
	public function __construct(
		array $rows = array(),
		array $linked = array(),
		?array $interactions = null,
		?IframeFactory $iframes = null
	) {
		parent::__construct(
			array(
				'singular' => 'arkid-viewer',
				'plural'   => 'arkid-viewers',
				'ajax'     => false,
			)
		);
		$this->rows         = $rows;
		$this->linked       = $linked;
		$this->interactions = $interactions;
		$this->iframes      = $iframes;
	}

	/**
	 * Every column the table has, with its heading. Screen Options lists these,
	 * so the merchant can hide the ones they do not need.
	 *
	 * @return array<string, string>
	 */
	public static function all_columns(): array {
		return array(
			'image'         => __( 'Preview', 'arkid-catalogue-link' ),
			'row_number'    => __( '#', 'arkid-catalogue-link' ),
			'product_name'  => __( 'Product', 'arkid-catalogue-link' ),
			'brand_name'    => __( 'Brand', 'arkid-catalogue-link' ),
			'type'          => __( 'Type', 'arkid-catalogue-link' ),
			'internal_note' => __( 'Internal note', 'arkid-catalogue-link' ),
			'shown_on'      => __( 'Shown on', 'arkid-catalogue-link' ),
			'colors'        => __( 'Colours', 'arkid-catalogue-link' ),
			'interactions'  => __( 'Interactions (30 days)', 'arkid-catalogue-link' ),
			'views'         => __( 'Total views', 'arkid-catalogue-link' ),
			'modified'      => __( 'Last updated', 'arkid-catalogue-link' ),
			'created'       => __( 'Created', 'arkid-catalogue-link' ),
			'actions'       => __( 'Actions', 'arkid-catalogue-link' ),
		);
	}

	/**
	 * The columns that start hidden. The merchant can show them in Screen Options.
	 *
	 * @return array<int, string>
	 */
	public static function default_hidden_columns(): array {
		return array( 'created' );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		$columns = self::all_columns();
		if ( null === $this->interactions ) {
			// ARkid could not say, and a count of zero would claim that it could.
			unset( $columns['interactions'] );
		}

		return $columns;
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		$columns = array(
			'row_number'    => array( 'row_number', false ),
			'product_name'  => array( 'product_name', false ),
			'brand_name'    => array( 'brand_name', false ),
			'type'          => array( 'type', false ),
			'internal_note' => array( 'internal_note', false ),
			'shown_on'      => array( 'shown_on', true ),
			'interactions'  => array( 'interactions', true ),
			'views'         => array( 'views', true ),
			'modified'      => array( 'modified', true ),
			'created'       => array( 'created', true ),
		);
		if ( null === $this->interactions ) {
			unset( $columns['interactions'] );
		}

		return $columns;
	}

	/**
	 * The column that stays when the table collapses on a small screen. Without
	 * it WordPress takes the first column, the thumbnail.
	 */
	protected function get_default_primary_column_name(): string {
		return 'product_name';
	}

	/**
	 * The classes core gives a list table, without `fixed`: with this many
	 * columns, equal widths would squeeze the ones that hold names.
	 *
	 * @return array<int, string>
	 */
	protected function get_table_classes(): array {
		return array_values( array_diff( parent::get_table_classes(), array( 'fixed' ) ) );
	}

	/**
	 * The view that is selected: `all` unless the address names another that
	 * can be computed.
	 */
	public function current_show(): string {
		return $this->show;
	}

	public function prepare_items(): void {
		$show = $this->request_value( 'show' );
		if ( ! in_array( $show, array( self::SHOW_LIVE, self::SHOW_UNLINKED, self::SHOW_QUIET ), true )
			|| ( self::SHOW_QUIET === $show && null === $this->interactions )
		) {
			$show = self::SHOW_ALL;
		}
		$this->show = $show;

		$orderby     = $this->request_value( 'orderby' );
		$order_param = $this->request_value( 'order' );
		$orderby     = '' === $orderby ? 'row_number' : $orderby;
		$order       = 'desc' === strtolower( $order_param ) ? 'desc' : 'asc';

		$rows = array_values(
			array_filter(
				$this->rows,
				fn( EmbedDto $embed ): bool => $this->in_view( $embed, $show )
			)
		);

		$type = $this->request_value( 'viewer_type' );
		if ( '' !== $type && isset( $this->type_options()[ $type ] ) ) {
			$rows = array_values( array_filter( $rows, static fn( EmbedDto $embed ): bool => self::option_value( $embed->type ) === $type ) );
		}
		$brand = $this->request_value( 'viewer_brand' );
		if ( '' !== $brand && isset( $this->brand_options()[ $brand ] ) ) {
			$rows = array_values( array_filter( $rows, static fn( EmbedDto $embed ): bool => self::option_value( $embed->brand_name ) === $brand ) );
		}

		$rows = EmbedSearch::filter( $rows, $this->request_value( 's' ) );
		$rows = self::sort_rows( $rows, $orderby, $order, $this->linked, $this->interactions );

		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, self::DEFAULT_PER_PAGE );
		$this->set_pagination_args(
			array(
				'total_items' => count( $rows ),
				'per_page'    => $per_page,
			)
		);
		// After the arguments are set, so a page past the end is the last one.
		$page        = $this->get_pagenum();
		$this->items = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		$this->_column_headers = array(
			$this->get_columns(),
			$this->hidden_columns(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);
	}

	/**
	 * Order viewers by a sortable column.
	 *
	 * Names are compared the way a person reads them: without regard to case or
	 * accents, with numbers by value ("Chair 2" before "Chair 10"). Viewers that
	 * compare equal keep the order of their row number.
	 *
	 * @param array<int, EmbedDto>                     $rows         Viewers.
	 * @param string                                   $orderby      Column slug; unknown slugs sort by row number.
	 * @param string                                   $order        'asc' or 'desc'.
	 * @param array<string, array<int, LinkedProduct>> $linked       The products that have a viewer, by lower-case viewer id.
	 * @param array<string, int>|null                  $interactions Interactions by lower-case viewer id; null sorts them like row numbers.
	 *
	 * @return array<int, EmbedDto>
	 */
	public static function sort_rows( array $rows, string $orderby, string $order, array $linked = array(), ?array $interactions = null ): array {
		usort(
			$rows,
			static function ( EmbedDto $a, EmbedDto $b ) use ( $orderby, $order, $linked, $interactions ): int {
				$cmp = match ( $orderby ) {
					'product_name'  => Text::compare( $a->product_name, $b->product_name ),
					'brand_name'    => Text::compare( $a->brand_name, $b->brand_name ),
					'type'          => Text::compare( $a->type, $b->type ),
					'internal_note' => Text::compare( $a->internal_note, $b->internal_note ),
					'shown_on'      => count( $linked[ ProductLinks::key( $a->embed_id ) ] ?? array() ) <=> count( $linked[ ProductLinks::key( $b->embed_id ) ] ?? array() ),
					'interactions'  => null === $interactions
						? $a->row_number <=> $b->row_number
						: ( $interactions[ ProductLinks::key( $a->embed_id ) ] ?? 0 ) <=> ( $interactions[ ProductLinks::key( $b->embed_id ) ] ?? 0 ),
					'views'         => $a->views <=> $b->views,
					'modified'      => ( ApiTime::parse( $a->modified ) ?? 0 ) <=> ( ApiTime::parse( $b->modified ) ?? 0 ),
					'created'       => ( ApiTime::parse( $a->created ) ?? 0 ) <=> ( ApiTime::parse( $b->created ) ?? 0 ),
					default         => $a->row_number <=> $b->row_number,
				};
				if ( 'desc' === $order ) {
					$cmp = -$cmp;
				}

				return 0 !== $cmp ? $cmp : $a->row_number <=> $b->row_number;
			}
		);

		return $rows;
	}

	/**
	 * Whether a viewer belongs to a view.
	 */
	private function in_view( EmbedDto $embed, string $show ): bool {
		return match ( $show ) {
			self::SHOW_LIVE     => $this->is_linked( $embed ),
			self::SHOW_UNLINKED => ! $this->is_linked( $embed ),
			// A viewer ARkid has no traffic for is not in its list, and has none.
			self::SHOW_QUIET    => null !== $this->interactions && 0 === $this->interactions_of( $embed ),
			default             => true,
		};
	}

	private function is_linked( EmbedDto $embed ): bool {
		return array() !== ( $this->linked[ ProductLinks::key( $embed->embed_id ) ] ?? array() );
	}

	/**
	 * Interactions in the last 30 days. ARkid lists only the viewers that were
	 * viewed in the period (and lists all of them, not a top few), so a viewer it
	 * does not list has had none.
	 */
	private function interactions_of( EmbedDto $embed ): int {
		return $this->interactions[ ProductLinks::key( $embed->embed_id ) ] ?? 0;
	}

	/**
	 * @return array<int|string, string> Viewer types, by value and with the name shown.
	 */
	private function type_options(): array {
		$types = array();
		foreach ( $this->rows as $embed ) {
			$type = self::option_value( $embed->type );
			if ( '' !== $type ) {
				$types[ $type ] = strtoupper( $type );
			}
		}
		uasort( $types, array( Text::class, 'compare' ) );

		return $types;
	}

	/**
	 * @return array<int|string, string> Brand names, by value and with the name shown.
	 */
	private function brand_options(): array {
		$brands = array();
		foreach ( $this->rows as $embed ) {
			$brand = self::option_value( $embed->brand_name );
			if ( '' !== $brand ) {
				$brands[ $brand ] = $brand;
			}
		}
		uasort( $brands, array( Text::class, 'compare' ) );

		return $brands;
	}

	/**
	 * @return array<int, string>
	 */
	private function hidden_columns(): array {
		return null !== $this->screen && function_exists( 'get_hidden_columns' )
			? get_hidden_columns( $this->screen )
			: self::default_hidden_columns();
	}

	/**
	 * A type or brand as the filter dropdowns carry it.
	 *
	 * The request is read through `sanitize_text_field()`, which collapses runs
	 * of white space and trims, so a name that has such spaces would come back
	 * different from what the dropdown sent and find nothing. Both sides go
	 * through the same function instead.
	 */
	private static function option_value( string $name ): string {
		return sanitize_text_field( $name );
	}

	private function request_value( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view, filter, search and sort query string.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] )
			? sanitize_text_field( wp_unslash( $_GET[ $key ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The views: All, Live on a product, Not linked and, when ARkid gave the
	 * interactions, No interactions. Each says how many viewers it holds.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$labels = array(
			self::SHOW_ALL      => __( 'All', 'arkid-catalogue-link' ),
			self::SHOW_LIVE     => __( 'Live on a product', 'arkid-catalogue-link' ),
			self::SHOW_UNLINKED => __( 'Not linked', 'arkid-catalogue-link' ),
			self::SHOW_QUIET    => __( 'No interactions, 30 days', 'arkid-catalogue-link' ),
		);
		if ( null === $this->interactions ) {
			unset( $labels[ self::SHOW_QUIET ] );
		}

		$base  = admin_url( 'admin.php?page=' . ViewersPage::MENU_SLUG );
		$views = array();
		foreach ( $labels as $show => $label ) {
			$count   = count(
				array_filter(
					$this->rows,
					fn( EmbedDto $embed ): bool => $this->in_view( $embed, $show )
				)
			);
			$current = $show === $this->show;

			$views[ $show ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( self::SHOW_ALL === $show ? $base : add_query_arg( 'show', $show, $base ) ),
				$current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * The type and brand filters. They send the page's form, with the search.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || array() === $this->rows ) {
			return;
		}

		echo '<div class="alignleft actions">';
		$this->print_dropdown(
			'viewer_type',
			__( 'Filter by type', 'arkid-catalogue-link' ),
			__( 'All types', 'arkid-catalogue-link' ),
			$this->type_options()
		);
		$this->print_dropdown(
			'viewer_brand',
			__( 'Filter by brand', 'arkid-catalogue-link' ),
			__( 'All brands', 'arkid-catalogue-link' ),
			$this->brand_options()
		);
		submit_button( __( 'Filter', 'arkid-catalogue-link' ), '', 'filter_action', false, array( 'id' => 'arkid-viewers-filter-submit' ) );
		echo '</div>';
	}

	/**
	 * @param array<int|string, string> $options Values, with the name shown for each.
	 */
	private function print_dropdown( string $name, string $label, string $all_label, array $options ): void {
		$selected = $this->request_value( $name );
		$id       = 'arkid-viewers-' . $name;

		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%3$s" id="%1$s"><option value="">%4$s</option>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_html( $all_label )
		);
		foreach ( $options as $value => $text ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $selected, (string) $value, false ),
				esc_html( $text )
			);
		}
		echo '</select>';
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
			'brand_name'    => esc_html( $item->brand_name ),
			'internal_note' => esc_html( $item->internal_note ),
			default         => '',
		};
	}

	public function column_image( EmbedDto $item ): string {
		$image = Url::https( $item->image );
		if ( '' === $image ) {
			return '';
		}

		$img = sprintf(
			'<img class="arkid-catalogue-link__thumb-image" src="%s" alt="" width="48" height="48" loading="lazy" />',
			esc_url( $image )
		);

		$preview = $this->preview_attributes( $item );
		if ( '' === $preview ) {
			return $img;
		}

		// The name beside it opens the same preview, for the keyboard and for
		// screen readers; this is the mouse's way to it.
		return '<button type="button" class="arkid-catalogue-link__preview arkid-catalogue-link__thumb" tabindex="-1" aria-hidden="true"' . $preview . '>' . $img . '</button>';
	}

	public function column_product_name( EmbedDto $item ): string {
		$name    = esc_html( $item->product_name );
		$preview = $this->preview_attributes( $item );
		if ( '' === $preview ) {
			return '<strong>' . $name . '</strong>';
		}

		return sprintf(
			'<strong><button type="button" class="button-link arkid-catalogue-link__preview" aria-haspopup="dialog" aria-label="%1$s"%2$s>%3$s</button></strong>',
			esc_attr( $this->preview_label( $item ) ),
			$preview,
			$name
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

	/**
	 * The products that show the viewer, each a link to its edit screen, the
	 * first few of them; "+N more" opens the Products list of this viewer.
	 */
	public function column_shown_on( EmbedDto $item ): string {
		$products = $this->linked[ ProductLinks::key( $item->embed_id ) ] ?? array();
		if ( array() === $products ) {
			return '<span class="description">' . esc_html__( 'Not linked', 'arkid-catalogue-link' ) . '</span>';
		}

		$list = '';
		foreach ( array_slice( $products, 0, self::PRODUCTS_LISTED ) as $product ) {
			$list .= '<li>' . $this->product_link( $product ) . '</li>';
		}
		$html = '<ul class="arkid-catalogue-link__products">' . $list . '</ul>';

		$more = count( $products ) - self::PRODUCTS_LISTED;
		if ( $more > 0 ) {
			$text = sprintf(
				/* translators: %s: number of further products */
				_n( '+%s more', '+%s more', $more, 'arkid-catalogue-link' ),
				number_format_i18n( $more )
			);
			$url  = ProductLinks::products_url( $item->embed_id );
			$html .= '' === $url
				? '<span class="description">' . esc_html( $text ) . '</span>'
				: '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
		}

		return $html;
	}

	private function product_link( LinkedProduct $product ): string {
		$title = '' === trim( $product->title )
			? __( '(no title)', 'arkid-catalogue-link' )
			: $product->title;
		$url   = get_edit_post_link( $product->id, 'raw' );
		$html  = null === $url || '' === $url
			? esc_html( $title )
			: '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';

		if ( ! $product->is_published() ) {
			$state = get_post_status_object( $product->status );
			if ( null !== $state ) {
				$html .= ' <span class="arkid-catalogue-link__state">' . esc_html( $state->label ) . '</span>';
			}
		}

		return $html;
	}

	public function column_colors( EmbedDto $item ): string {
		return ColorDots::render( $item );
	}

	public function column_interactions( EmbedDto $item ): string {
		return esc_html( number_format_i18n( $this->interactions_of( $item ) ) );
	}

	public function column_views( EmbedDto $item ): string {
		return esc_html( number_format_i18n( $item->views ) );
	}

	/**
	 * When the viewer was last changed, and by whom.
	 */
	public function column_modified( EmbedDto $item ): string {
		$html = $this->time_html( $item->modified, true );
		if ( '' !== $item->author_name ) {
			$html .= '<br /><span class="description">' . esc_html( $item->author_name ) . '</span>';
		}

		return $html;
	}

	public function column_created( EmbedDto $item ): string {
		return $this->time_html( $item->created, false );
	}

	/**
	 * A time as the date it is on this site, "3 hours ago" when `$relative`, and
	 * the exact date and time as a tooltip.
	 *
	 * ARkid writes its times in UTC without saying so; see ApiTime. A date with no
	 * time of day names a day and not an instant, so it is shown as that day: moved
	 * into the site's zone it would read as the day before on a site west of UTC. A
	 * value that is not a time is shown as it came.
	 */
	private function time_html( string $stamp, bool $relative ): string {
		$time = ApiTime::parse( $stamp );
		if ( null === $time ) {
			return esc_html( $stamp );
		}

		$date_format = get_option( 'date_format' );
		$date_format = is_string( $date_format ) && '' !== $date_format ? $date_format : 'Y-m-d';
		$time_format = get_option( 'time_format' );
		$time_format = is_string( $time_format ) && '' !== $time_format ? $time_format : 'H:i';

		$has_time = ApiTime::has_time( $stamp );
		// ApiTime reads a date alone as midnight UTC, so UTC is the zone that
		// gives its day back; null is the site's zone.
		$zone  = $has_time ? null : new \DateTimeZone( 'UTC' );
		$exact = wp_date( $has_time ? $date_format . ' ' . $time_format : $date_format, $time, $zone );
		$exact = false === $exact ? $stamp : $exact;

		if ( $relative && $has_time && $time <= time() ) {
			$text = sprintf(
				/* translators: %s: how long ago, for example "3 hours" */
				__( '%s ago', 'arkid-catalogue-link' ),
				human_time_diff( $time )
			);
		} else {
			$text = (string) wp_date( $date_format, $time, $zone );
		}

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( $has_time ? 'c' : 'Y-m-d', $time ) ),
			esc_attr( $exact ),
			esc_html( $text )
		);
	}

	public function column_actions( EmbedDto $item ): string {
		$links = array();

		if ( '' !== $this->preview_attributes( $item ) ) {
			$links[] = sprintf(
				'<button type="button" class="button-link arkid-catalogue-link__preview" aria-haspopup="dialog" aria-label="%1$s"%2$s>%3$s</button>',
				esc_attr( $this->preview_label( $item ) ),
				$this->preview_attributes( $item ),
				esc_html__( 'Preview', 'arkid-catalogue-link' )
			);
		}
		$edit_url = Url::https( $item->edit_url );
		if ( '' !== $edit_url ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'arkid-catalogue-link' )
			);
		}
		$analytics_url = Url::https( $item->analytics_url );
		if ( '' !== $analytics_url ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $analytics_url ),
				esc_html__( 'Analytics', 'arkid-catalogue-link' )
			);
		}
		return implode( ' | ', $links );
	}

	/**
	 * The data attributes that make a button open the preview of a viewer, or ''
	 * when it cannot be previewed.
	 *
	 * The address is the viewer's own, through the same check the storefront uses:
	 * an https address on an ARkid host. Anything else gets no preview rather than
	 * a frame that points somewhere unexpected.
	 */
	private function preview_attributes( EmbedDto $item ): string {
		if ( null === $this->iframes ) {
			return '';
		}
		$url = $this->iframes->viewer_url( $item->embed_url );
		if ( '' === $url ) {
			return '';
		}

		return sprintf(
			' data-embed-url="%1$s" data-product-name="%2$s" data-edit-url="%3$s" data-analytics-url="%4$s"',
			esc_url( $url ),
			esc_attr( trim( $item->brand_name . ' ' . $item->product_name ) ),
			esc_url( Url::https( $item->edit_url ) ),
			esc_url( Url::https( $item->analytics_url ) )
		);
	}

	private function preview_label( EmbedDto $item ): string {
		return sprintf(
			/* translators: %s: product name */
			__( 'Preview %s', 'arkid-catalogue-link' ),
			$item->product_name
		);
	}

	public function no_items(): void {
		if ( array() === $this->rows ) {
			esc_html_e( 'No viewers found. Add a viewer in the ARkid Catalogue or check your API key.', 'arkid-catalogue-link' );
			return;
		}

		esc_html_e( 'No viewers match these filters.', 'arkid-catalogue-link' );
		printf(
			' <a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . ViewersPage::MENU_SLUG ) ),
			esc_html__( 'Clear the filters', 'arkid-catalogue-link' )
		);
	}
}
