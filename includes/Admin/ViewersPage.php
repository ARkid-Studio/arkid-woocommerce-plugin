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
use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class ViewersPage {

	public const MENU_SLUG = 'arkid-viewers';

	public function __construct(
		private readonly Options $options,
		private readonly ClientFactory $api
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'ARkid Viewers', 'arkid-catalogue-link' ),
			__( 'ARkid Viewers', 'arkid-catalogue-link' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$rows         = array();
		$error        = null;
		$missing_key  = '' === $this->options->api_key();
		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=integration&section=' . IntegrationSettings::ID
		);

		if ( ! $missing_key ) {
			try {
				$rows = $this->api->create()?->list_embeds() ?? array();
			} catch ( AuthException $e ) {
				$error = __( 'The API key was rejected. Update it in the integration settings.', 'arkid-catalogue-link' );
			} catch ( ApiException $e ) {
				$error = sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the ARkid API: %s', 'arkid-catalogue-link' ),
					$e->getMessage()
				);
			}
		}
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
				<?php
				$table = new ViewersListTable( $rows );
				$table->prepare_items();
				$table->display();
				?>
			<?php endif; ?>
		</div>
		<?php
	}
}
