<?php
/**
 * Plugin Name:       ARkid Catalogue Link
 * Plugin URI:        https://catalogue.arkid.app
 * Description:       Link products in your WooCommerce store with the ARkid catalogue. Embed 3D / AR viewers on the product display page.
 * Version:           1.0.0
 * Author:            ARkid
 * Author URI:        https://catalogue.arkid.app
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       arkid-catalogue-link
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( defined( 'ARKID_CATALOGUE_LINK_VERSION' ) ) {
	return;
}

const ARKID_CATALOGUE_LINK_VERSION  = '1.0.0';
const ARKID_CATALOGUE_LINK_MIN_PHP  = '8.1';
const ARKID_CATALOGUE_LINK_MIN_WC   = '8.0';
const ARKID_CATALOGUE_LINK_MIN_WP   = '7.0';
const ARKID_CATALOGUE_LINK_TEXT_DOMAIN = 'arkid-catalogue-link';

define( 'ARKID_CATALOGUE_LINK_FILE', __FILE__ );
define( 'ARKID_CATALOGUE_LINK_PATH', plugin_dir_path( __FILE__ ) );
define( 'ARKID_CATALOGUE_LINK_URL', plugin_dir_url( __FILE__ ) );

$arkid_catalogue_link_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $arkid_catalogue_link_autoload ) ) {
	require_once $arkid_catalogue_link_autoload;
}

register_activation_hook(
	__FILE__,
	static function (): void {
		// @phpstan-ignore-next-line if.alwaysFalse -- runtime check; PHPStan can't know the host PHP version at analyse time.
		if ( version_compare( PHP_VERSION, ARKID_CATALOGUE_LINK_MIN_PHP, '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'ARkid Catalogue Link requires PHP %1$s or later. You are running PHP %2$s.', 'arkid-catalogue-link' ),
						ARKID_CATALOGUE_LINK_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		// Recurring actions otherwise survive deactivation and keep firing.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'arkid_catalogue_link_refresh_embeds', array(), 'arkid-catalogue-link' );
			as_unschedule_all_actions( 'arkid_catalogue_link_refresh_product', array(), 'arkid-catalogue-link' );
		}
	}
);

/**
 * Declare WooCommerce feature compatibility before WooCommerce boots.
 *
 * - HPOS (custom_order_tables): we don't read or write order tables.
 * - Cart/Checkout Blocks: we don't touch cart or checkout.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				ARKID_CATALOGUE_LINK_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				ARKID_CATALOGUE_LINK_FILE,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'arkid-catalogue-link',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		if ( ! class_exists( '\\Arkid\\CatalogueLink\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>'
						. esc_html__(
							'ARkid Catalogue Link is missing its Composer autoloader. Run `composer install` inside the plugin directory.',
							'arkid-catalogue-link'
						)
						. '</strong></p></div>';
				}
			);
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>'
						. sprintf(
							/* translators: %s: WooCommerce link */
							esc_html__( 'ARkid Catalogue Link requires %s to be installed and active.', 'arkid-catalogue-link' ),
							'<a href="https://woocommerce.com/" target="_blank" rel="noopener">WooCommerce</a>'
						)
						. '</strong></p></div>';
				}
			);
			return;
		}

		\Arkid\CatalogueLink\Plugin::instance();
	}
);
