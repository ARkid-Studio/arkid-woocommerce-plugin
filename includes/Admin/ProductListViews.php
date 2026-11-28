<?php
/**
 * The ARkid views and filters of the Products list.
 *
 * "With viewer", "Without viewer", "Match available" and "Needs attention", as
 * links with their counts above the list (the way its status views are) and as a
 * dropdown beside the other filters, plus a dropdown of the viewers that products
 * show. Both send the list's own form, and are the query variables `arkid` and
 * `arkid_embed` (the second is `Admin\ProductListFilter`'s: the viewers list's
 * "+N more" opens it).
 *
 * The counts of "with", "without" and "needs attention" are one SQL count each.
 * "Match available" needs the names of the products compared with the viewers and
 * the catalogue of the account: it comes from the bounded scan of
 * `Links\ProductScan` (2,000 products at most, a "+" after the count when there
 * were more) and is left out when ARkid cannot be asked. The list still renders,
 * with a notice that says what is missing and why.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Links\AccountData;
use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\Links\ProductMatches;
use Arkid\CatalogueLink\Links\ProductStates;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Support\Text;

defined( 'ABSPATH' ) || exit;

final class ProductListViews {

	/** The query variable of the views and the dropdown: one of the `STATE_*`. */
	public const QUERY_VAR = 'arkid';

	public const STATE_WITH      = 'with';
	public const STATE_WITHOUT   = 'without';
	public const STATE_MATCH     = 'match';
	public const STATE_ATTENTION = 'attention';

	/**
	 * The counts of the views, once they have been made.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $counts = null;

	public function __construct(
		private readonly AccountData $account,
		private readonly ProductStates $states,
		private readonly ProductMatches $matches
	) {}

	public function register(): void {
		add_filter( 'views_edit-' . PostMeta::POST_TYPE, array( $this, 'add_views' ) );
		add_action( 'restrict_manage_posts', array( $this, 'print_dropdowns' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'narrow' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The state the address asks for, or '' for none or one that is not a state.
	 */
	public static function requested_state(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filter in the query string.
		$value = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] )
			? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return in_array( $value, self::states(), true ) ? $value : '';
	}

	/**
	 * @return array<int, string>
	 */
	private static function states(): array {
		return array( self::STATE_WITH, self::STATE_WITHOUT, self::STATE_MATCH, self::STATE_ATTENTION );
	}

	/**
	 * Whether this is the Products list and the person may see the ARkid parts of
	 * it. They ask ARkid with the shop's API key, which is the store manager's.
	 */
	private static function is_active(): bool {
		return ProductListFilter::is_products_list() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * @param mixed $views The views of the list, by class.
	 *
	 * @return mixed
	 */
	public function add_views( $views ) {
		if ( ! is_array( $views ) || ! self::is_active() ) {
			return $views;
		}

		$current = self::requested_state();
		$base    = admin_url( 'edit.php?post_type=' . PostMeta::POST_TYPE );
		$counts  = $this->counts();

		foreach ( $this->labels() as $state => $label ) {
			if ( ! isset( $counts[ $state ] ) ) {
				continue;
			}

			$views[ 'arkid_' . $state ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( self::QUERY_VAR, $state, $base ) ),
				$state === $current && '' === ProductListFilter::requested_embed_id() ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( $counts[ $state ] )
			);
		}

		return $views;
	}

	/**
	 * The count of each view, as it is shown, in the order of the views; a view
	 * that cannot be counted is left out. Counted once per request.
	 *
	 * @return array<string, string>
	 */
	private function counts(): array {
		if ( null !== $this->counts ) {
			return $this->counts;
		}

		$linked = $this->states->linked();
		$total  = $this->states->total();

		$counts = array(
			self::STATE_WITH    => number_format_i18n( $linked ),
			self::STATE_WITHOUT => number_format_i18n( max( 0, $total - $linked ) ),
		);

		$matches = $this->matches->result();
		if ( null !== $matches ) {
			$counts[ self::STATE_MATCH ] = number_format_i18n( count( $matches->ids ) ) . ( $matches->truncated ? '+' : '' );
		}

		$counts[ self::STATE_ATTENTION ] = number_format_i18n( $this->states->attention_count( $this->account->embed_ids() ) );

		$this->counts = $counts;

		return $counts;
	}

	/**
	 * @return array<string, string>
	 */
	private function labels(): array {
		return array(
			self::STATE_WITH      => __( 'With viewer', 'arkid-catalogue-link' ),
			self::STATE_WITHOUT   => __( 'Without viewer', 'arkid-catalogue-link' ),
			self::STATE_MATCH     => __( 'Match available', 'arkid-catalogue-link' ),
			self::STATE_ATTENTION => __( 'Needs attention', 'arkid-catalogue-link' ),
		);
	}

	/**
	 * The two dropdowns, with the list's other filters.
	 *
	 * @param mixed $post_type The post type of the list.
	 * @param mixed $which     'top' or 'bottom'.
	 */
	public function print_dropdowns( $post_type, $which = 'top' ): void {
		if ( PostMeta::POST_TYPE !== $post_type || 'top' !== $which || ! self::is_active() ) {
			return;
		}

		$current = self::requested_state();
		$counts  = $this->counts();

		printf(
			'<label class="screen-reader-text" for="arkid-catalogue-link-state">%s</label><select name="%s" id="arkid-catalogue-link-state"><option value="">%s</option>',
			esc_html__( 'Filter by ARkid status', 'arkid-catalogue-link' ),
			esc_attr( self::QUERY_VAR ),
			esc_html( $this->prefixed( __( 'All products', 'arkid-catalogue-link' ) ) )
		);
		foreach ( $this->labels() as $state => $label ) {
			// A view that cannot be counted now is not offered, unless it is the one asked for.
			if ( ! isset( $counts[ $state ] ) && $state !== $current ) {
				continue;
			}
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $state ),
				selected( $current, $state, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's helper returns a fixed attribute.
				esc_html( $this->prefixed( $label ) )
			);
		}
		echo '</select>';

		$this->print_viewer_dropdown();
	}

	/**
	 * The viewers that products show, with how many products each is on.
	 */
	private function print_viewer_dropdown(): void {
		$linked = $this->states->by_viewer();
		$wanted = ProductLinks::key( ProductListFilter::requested_embed_id() );
		if ( array() === $linked && '' === $wanted ) {
			return;
		}

		$options = array();
		foreach ( $linked as $key => $count ) {
			$embed           = $this->account->embed( (string) $key );
			$options[ $key ] = array( null !== $embed ? $this->viewer_name( $embed ) : (string) $key, $count );
		}
		if ( '' !== $wanted && ! isset( $options[ $wanted ] ) ) {
			$options[ $wanted ] = array( $wanted, 0 );
		}
		uasort( $options, static fn( array $a, array $b ): int => Text::compare( $a[0], $b[0] ) );

		printf(
			'<label class="screen-reader-text" for="arkid-catalogue-link-embed">%s</label><select name="%s" id="arkid-catalogue-link-embed"><option value="">%s</option>',
			esc_html__( 'Filter by ARkid viewer', 'arkid-catalogue-link' ),
			esc_attr( ProductLinks::QUERY_VAR ),
			esc_html( $this->prefixed( __( 'Any viewer', 'arkid-catalogue-link' ) ) )
		);
		foreach ( $options as $key => [ $name, $count ] ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $key ),
				selected( $wanted, (string) $key, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's helper returns a fixed attribute.
				esc_html(
					sprintf(
						/* translators: 1: the name of a viewer, 2: how many products show it */
						_n( '%1$s (%2$s product)', '%1$s (%2$s products)', $count, 'arkid-catalogue-link' ),
						$name,
						number_format_i18n( $count )
					)
				)
			);
		}
		echo '</select>';
	}

	private function viewer_name( EmbedDto $embed ): string {
		$name = trim( $embed->brand_name . ' ' . $embed->product_name );

		return '' === $name ? $embed->embed_id : $name;
	}

	/**
	 * What a filter is called in the list of dropdowns, which also has categories
	 * and stock states: "ARkid: with viewer".
	 */
	private function prefixed( string $label ): string {
		return sprintf(
			/* translators: %s: a filter of the Products list, for example "With viewer" */
			__( 'ARkid: %s', 'arkid-catalogue-link' ),
			$label
		);
	}

	/**
	 * Hold the list to the products of the state asked for.
	 */
	public function narrow( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! self::is_active() ) {
			return;
		}

		switch ( self::requested_state() ) {
			case self::STATE_WITH:
				$this->add_meta_query(
					$query,
					array(
						'key'     => PostMeta::KEY_EMBED_ID,
						'value'   => '',
						'compare' => '!=',
					)
				);
				break;

			case self::STATE_WITHOUT:
				$this->add_meta_query(
					$query,
					array(
						'relation' => 'OR',
						array(
							'key'     => PostMeta::KEY_EMBED_ID,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => PostMeta::KEY_EMBED_ID,
							'value'   => '',
							'compare' => '=',
						),
					)
				);
				break;

			case self::STATE_ATTENTION:
				ProductListFilter::restrict_to( $query, $this->states->attention_ids( $this->account->embed_ids() ) );
				break;

			case self::STATE_MATCH:
				// Without the account there is nothing to match against, and the notice says so.
				ProductListFilter::restrict_to( $query, $this->matches->result()->ids ?? array() );
				break;
		}
	}

	/**
	 * Add a clause to the list's meta query, next to the ones other filters have.
	 *
	 * @param array<int|string, mixed> $clause
	 */
	private function add_meta_query( \WP_Query $query, array $clause ): void {
		$existing = $query->get( 'meta_query' );

		$query->set(
			'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A filter of an admin list on the plugin's own meta key; the list is paged.
			is_array( $existing ) && array() !== $existing
				? array(
					'relation' => 'AND',
					$existing,
					$clause,
				)
				: array( $clause )
		);
	}

	/**
	 * Say what is missing when ARkid could not be asked, and what a view that was
	 * cut off compared.
	 */
	public function notice(): void {
		if ( ! self::is_active() ) {
			return;
		}

		$error = $this->account->is_configured() ? $this->account_error() : null;
		if ( null !== $error ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s %3$s</p></div>',
				esc_html__( 'ARkid Catalogue Link', 'arkid-catalogue-link' ),
				esc_html( $error ),
				esc_html(
					! $this->account->has_embeds()
						? __( 'The ARkid column shows what is stored for each product, and the "Match available" view and the suggestions are left out.', 'arkid-catalogue-link' )
						: __( 'The "Match available" view is left out, and suggestions only use your viewers.', 'arkid-catalogue-link' )
				)
			);
		}

		$matches = self::STATE_MATCH === self::requested_state() ? $this->matches->result() : null;
		if ( null !== $matches && $matches->truncated ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: a number of products */
						__( 'Only the first %s products without a viewer, in the order of their IDs, were compared with ARkid, so more products may have a match.', 'arkid-catalogue-link' ),
						number_format_i18n( $matches->compared )
					)
				)
			);
		}
	}

	/**
	 * What went wrong asking ARkid, once the screen has asked it.
	 */
	private function account_error(): ?string {
		// Asking is what finds out; the lists are read once and shared with the rest of the screen.
		$this->account->catalog();

		return $this->account->error();
	}
}
