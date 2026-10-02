<?php
/**
 * Main plugin singleton.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink;

use Arkid\CatalogueLink\Admin\Setup as AdminSetup;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Blocks\EmbedBlock;
use Arkid\CatalogueLink\Cron\RefreshEmbeds;
use Arkid\CatalogueLink\Frontend\Consent;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Frontend\Setup as FrontendSetup;
use Arkid\CatalogueLink\Migrations\Migrator;
use Arkid\CatalogueLink\Rest\Controller as RestController;
use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private readonly Options $options;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function __clone() {
		throw new \LogicException( 'Cloning of ARkid Catalogue Link Plugin is forbidden.' );
	}

	public function __wakeup() {
		throw new \LogicException( 'Unserializing of ARkid Catalogue Link Plugin is forbidden.' );
	}

	public function options(): Options {
		return $this->options;
	}

	public function version(): string {
		return ARKID_CATALOGUE_LINK_VERSION;
	}

	private function boot(): void {
		( new Migrator() )->register();
		( new PostMeta() )->register();

		$api = new ClientFactory( $this->options );

		add_filter(
			'woocommerce_integrations',
			array( IntegrationSettings::class, 'register' )
		);

		( new RestController( $api ) )->register();
		( new RefreshEmbeds( $api ) )->register();

		( new EmbedBlock(
			new IframeFactory( $this->options ),
			new Consent( $this->options )
		) )->register();

		if ( is_admin() ) {
			( new AdminSetup( $this->options, $api ) )->register();
		} else {
			( new FrontendSetup( $this->options ) )->register();
		}
	}
}
