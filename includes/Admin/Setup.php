<?php
/**
 * Admin coordinator: wires the admin-side subsystems.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class Setup {

	public function __construct(
		private readonly Options $options,
		private readonly ClientFactory $api
	) {}

	public function register(): void {
		( new Notices( $this->options ) )->register();
		( new MetaBox( $this->options, $this->api ) )->register();
		( new ViewersPage( $this->options, $this->api ) )->register();
		( new Assets() )->register();

		add_filter(
			'plugin_action_links_' . plugin_basename( ARKID_CATALOGUE_LINK_FILE ),
			array( $this, 'plugin_action_links' )
		);
	}

	/**
	 * @param array<string, string> $links
	 *
	 * @return array<string, string>
	 */
	public function plugin_action_links( array $links ): array {
		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=integration&section=' . IntegrationSettings::ID
		);
		$viewers_url  = admin_url( 'admin.php?page=' . ViewersPage::MENU_SLUG );

		$ours = array(
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'arkid-catalogue-link' )
			),
			'viewers'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $viewers_url ),
				esc_html__( 'Viewers', 'arkid-catalogue-link' )
			),
		);

		return array_merge( $ours, $links );
	}
}
