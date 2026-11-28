<?php
/**
 * Admin page: WooCommerce → ARkid Viewers.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\ApiException;
use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Api\EmbedSource;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Links\ProductLinks;
use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class ViewersPage {

	public const MENU_SLUG = 'arkid-viewers';

	/**
	 * The screen the page is on, once the menu has been built.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	public function __construct(
		private readonly Options $options,
		private readonly ClientFactory $api
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );

		// WordPress handles the Screen Options form in wp-admin/admin.php, before
		// the admin menu is built and before any `load-*` hook runs. A filter added
		// from the screen's own load hook is too late: the value would never save.
		add_filter(
			'set_screen_option_' . ViewersListTable::PER_PAGE_OPTION,
			array( $this, 'save_screen_option' ),
			10,
			3
		);
	}

	public function register_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'ARkid Viewers', 'arkid-catalogue-link' ),
			__( 'ARkid Viewers', 'arkid-catalogue-link' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		if ( false === $hook ) {
			return;
		}

		$this->hook_suffix = $hook;
		add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		// The columns Screen Options lets the merchant show and hide.
		add_filter( 'manage_' . $hook . '_columns', array( ViewersListTable::class, 'all_columns' ) );
		add_filter( 'default_hidden_columns', array( $this, 'default_hidden_columns' ), 10, 2 );
	}

	/**
	 * Add "Viewers per page" to Screen Options.
	 */
	public function on_load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Viewers per page', 'arkid-catalogue-link' ),
				'default' => ViewersListTable::DEFAULT_PER_PAGE,
				'option'  => ViewersListTable::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Accept the value of Screen Options → "Viewers per page".
	 *
	 * WordPress saves nothing for an option it does not know unless a filter
	 * returns something other than false.
	 *
	 * @param mixed $keep   What is saved if the filter does not decide; false saves nothing.
	 * @param mixed $option The option name.
	 * @param mixed $value  The submitted value.
	 *
	 * @return mixed The number to save, or `$keep`.
	 */
	public function save_screen_option( $keep, $option, $value ) {
		$per_page = is_scalar( $value ) ? (int) $value : 0;

		return $per_page >= 1 && $per_page <= 999 ? $per_page : $keep;
	}

	/**
	 * @param array<int, string> $hidden The columns hidden by default.
	 * @param \WP_Screen         $screen The screen.
	 *
	 * @return array<int, string>
	 */
	public function default_hidden_columns( array $hidden, \WP_Screen $screen ): array {
		return '' !== $this->hook_suffix && $screen->id === $this->hook_suffix
			? array_merge( $hidden, ViewersListTable::default_hidden_columns() )
			: $hidden;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$rows         = array();
		$error        = null;
		$interactions = null;
		$missing_key  = '' === $this->options->api_key();
		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=integration&section=' . IntegrationSettings::ID
		);

		$client = $missing_key ? null : $this->api->create();
		if ( null !== $client ) {
			try {
				$rows = $client->list_embeds();
			} catch ( AuthException $e ) {
				$error = __( 'The API key was rejected. Update it in the integration settings.', 'arkid-catalogue-link' );
			} catch ( ApiException $e ) {
				$error = sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the ARkid API: %s', 'arkid-catalogue-link' ),
					$e->getMessage()
				);
			}

			if ( null === $error && array() !== $rows ) {
				$interactions = $this->interactions( $client );
			}
		}
		$stats_failed = null === $error && array() !== $rows && null === $interactions;
		?>
		<div class="wrap arkid-catalogue-link__viewers">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'ARkid Viewers', 'arkid-catalogue-link' ); ?>
			</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Refresh', 'arkid-catalogue-link' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php if ( $missing_key ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: settings page link */
							wp_kses_post( __( 'No API key configured. %s to load your viewers.', 'arkid-catalogue-link' ) ),
							'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open settings', 'arkid-catalogue-link' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php elseif ( null !== $error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html( $error ); ?></p>
				</div>
			<?php else : ?>
				<?php if ( $stats_failed ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php esc_html_e( 'The interactions of the last 30 days could not be loaded from ARkid, so the interactions column and the "No interactions" view are left out. Refresh to try again.', 'arkid-catalogue-link' ); ?>
						</p>
					</div>
				<?php endif; ?>
				<?php $this->render_table( $rows, $interactions ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, \Arkid\CatalogueLink\Api\EmbedDto> $rows
	 * @param array<string, int>|null                       $interactions
	 */
	private function render_table( array $rows, ?array $interactions ): void {
		$table = new ViewersListTable(
			$rows,
			( new ProductLinks() )->by_embed(),
			$interactions,
			new IframeFactory( $this->options )
		);
		$table->prepare_items();

		if ( array() !== $rows ) {
			$table->views();
		}
		?>
		<form method="get" id="arkid-viewers-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<?php if ( ViewersListTable::SHOW_ALL !== $table->current_show() ) : ?>
				<input type="hidden" name="show" value="<?php echo esc_attr( $table->current_show() ); ?>" />
			<?php endif; ?>
			<?php
			$table->search_box( __( 'Search viewers', 'arkid-catalogue-link' ), 'arkid-viewers' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * The interactions of each viewer in the last 30 days, or null when ARkid
	 * could not say. The viewers screen must show its list either way.
	 *
	 * @return array<string, int>|null By lower-case viewer id.
	 */
	private function interactions( EmbedSource $client ): ?array {
		try {
			return $client->stats( ViewersListTable::STATS_DAYS )->interactions_by_embed();
		} catch ( ApiException $e ) {
			return null;
		} catch ( \Throwable $e ) {
			// Whatever else goes wrong while asking must not take the list down.
			return null;
		}
	}
}
