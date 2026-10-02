<?php
/**
 * Frontend asset enqueue.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class Assets {

	private const HANDLE = 'arkid-catalogue-link-storefront';

	public function __construct( private readonly Options $options ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		$script_path = 'build/storefront.js';
		$style_path  = 'build/storefront.css';
		$asset_path  = ARKID_CATALOGUE_LINK_PATH . 'build/storefront.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		/** @var array{dependencies: array<int, non-empty-string>, version: string} $asset */
		$asset = require $asset_path;

		wp_enqueue_style(
			self::HANDLE,
			ARKID_CATALOGUE_LINK_URL . $style_path,
			array(),
			file_exists( ARKID_CATALOGUE_LINK_PATH . $style_path )
				? (string) filemtime( ARKID_CATALOGUE_LINK_PATH . $style_path )
				: ARKID_CATALOGUE_LINK_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			ARKID_CATALOGUE_LINK_URL . $script_path,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			self::HANDLE,
			'arkidCatalogueLinkStorefront',
			array(
				'requireConsent' => $this->options->require_consent(),
				'consentType'    => 'marketing',
				'i18n'           => array(
					'closeViewer' => __( 'Close 3D viewer', 'arkid-catalogue-link' ),
				),
			)
		);
	}

	private function should_enqueue(): bool {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return true;
		}
		if ( function_exists( 'has_block' ) && has_block( 'arkid-catalogue-link/embed' ) ) {
			return true;
		}
		return false;
	}
}
