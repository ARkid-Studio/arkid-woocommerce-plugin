<?php
/**
 * Admin notices.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class Notices {

	public function __construct( private readonly Options $options ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render_missing_key_notice' ) );
	}

	public function maybe_render_missing_key_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( '' !== $this->options->api_key() ) {
			return;
		}
		if ( $this->is_settings_screen() ) {
			return;
		}

		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=integration&section=' . IntegrationSettings::ID
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'ARkid Catalogue Link', 'arkid-catalogue-link' ),
			wp_kses_post(
				sprintf(
					/* translators: %s: settings page link */
					__( 'is enabled but no API key is set. %s', 'arkid-catalogue-link' ),
					'<a href="' . esc_url( $settings_url ) . '">' .
					esc_html__( 'Enter your API key', 'arkid-catalogue-link' ) .
					'</a>'
				)
			)
		);
	}

	private function is_settings_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return false;
		}
		return 'woocommerce_page_wc-settings' === $screen->id;
	}
}
