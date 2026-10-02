<?php
/**
 * Boot, REST surface and settings behaviour.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration;

use Arkid\CatalogueLink\Cron\RefreshEmbeds;
use Arkid\CatalogueLink\Plugin;
use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Settings\Options;

final class PluginTest extends TestCase {

	public function tear_down(): void {
		delete_option( Options::OPTION_KEY );
		$_POST = array();
		parent::tear_down();
	}

	public function test_the_plugin_boots(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}

	public function test_the_rest_namespace_is_registered(): void {
		do_action( 'rest_api_init' );
		$routes = array_keys( rest_get_server()->get_routes() );

		$this->assertContains( '/arkid-catalogue-link/v1/embeds', $routes );
	}

	public function test_the_woocommerce_integration_is_registered(): void {
		$this->assertContains(
			IntegrationSettings::class,
			apply_filters( 'woocommerce_integrations', array() )
		);
	}

	/**
	 * The REST proxy exists to keep the API key server-side, so its permission
	 * callback is the only thing standing between a subscriber and the
	 * catalogue.
	 */
	public function test_the_rest_proxy_is_restricted_to_shop_managers(): void {
		$controller = new \Arkid\CatalogueLink\Rest\Controller(
			new \Arkid\CatalogueLink\Api\ClientFactory( new Options() )
		);

		wp_set_current_user( 0 );
		$anonymous = $controller->check_admin();
		$this->assertInstanceOf( \WP_Error::class, $anonymous );
		$this->assertSame( 401, $anonymous->get_error_data()['status'] );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$subscriber = $controller->check_admin();
		$this->assertInstanceOf( \WP_Error::class, $subscriber );
		$this->assertSame( 403, $subscriber->get_error_data()['status'] );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $controller->check_admin() );
	}

	public function test_the_rest_proxy_reports_a_missing_key_without_leaking(): void {
		delete_option( Options::OPTION_KEY );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/arkid-catalogue-link/v1/embeds' ) );

		$this->assertSame( 412, $response->get_status() );
		$this->assertSame( array(), $this->http_calls, 'No key means no outbound request.' );
	}

	public function test_the_embed_id_route_rejects_path_traversal(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );

		$response = rest_do_request(
			new \WP_REST_Request( 'GET', '/arkid-catalogue-link/v1/embeds/../../wp-config' )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array(), $this->http_calls );
	}

	/**
	 * process_admin_options() overwrites $this->settings from $_POST at priority
	 * 10, so reading it at priority 20 saw the NEW key on both sides of the
	 * comparison and the change was never detected.
	 */
	public function test_changing_the_api_key_queues_a_full_resync(): void {
		update_option( Options::OPTION_KEY, array( 'api_key' => 'OLD-KEY' ) );
		$this->stub_http( '/api/ecom/embed', array( 'data' => array() ) );

		// Constructed before the save, exactly as WooCommerce does it.
		$integration = new IntegrationSettings();

		$_POST = array(
			'woocommerce_arkid-catalogue-link_api_key'          => 'NEW-KEY',
			'woocommerce_arkid-catalogue-link_default_position' => 'below_product',
			'woocommerce_arkid-catalogue-link_button_text'      => '',
		);
		$integration->process_admin_options();
		$integration->on_settings_saved();

		$this->assertSame( 'NEW-KEY', ( new Options() )->api_key() );
		$this->assertTrue(
			(bool) as_has_scheduled_action( RefreshEmbeds::HOOK_SWEEP, array( 'after_id' => 0 ), RefreshEmbeds::GROUP ),
			'A new key may point at a different ARkid account, so every snapshot must be re-checked.'
		);
		$this->assertNotEmpty( $this->http_calls, 'A changed key should be probed.' );
	}

	public function test_saving_without_changing_the_key_does_not_probe(): void {
		update_option( Options::OPTION_KEY, array( 'api_key' => 'SAME-KEY' ) );

		$integration = new IntegrationSettings();

		$_POST = array(
			'woocommerce_arkid-catalogue-link_api_key'          => 'SAME-KEY',
			'woocommerce_arkid-catalogue-link_default_position' => 'below_product',
			'woocommerce_arkid-catalogue-link_button_text'      => '',
		);
		$integration->process_admin_options();
		$integration->on_settings_saved();

		$this->assertSame(
			array(),
			$this->http_calls,
			'An unrelated settings save must not call the API.'
		);
	}
}
