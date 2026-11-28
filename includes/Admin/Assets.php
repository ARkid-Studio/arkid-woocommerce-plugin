<?php
/**
 * Admin asset enqueue.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Settings\IntegrationSettings;

defined( 'ABSPATH' ) || exit;

class Assets {

	private const HANDLE = 'arkid-catalogue-link-admin';

	/**
	 * Whether the screen being built opens viewer previews, and so needs the dialog.
	 *
	 * @var bool
	 */
	private bool $needs_preview_dialog = false;

	public function __construct( private readonly IframeFactory $iframes ) {}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'print_preview_dialog' ) );
	}

	/**
	 * Print the preview dialog on the screens that open it. Empty until a button
	 * is clicked, so it costs a few elements, not a request.
	 */
	public function print_preview_dialog(): void {
		if ( $this->needs_preview_dialog ) {
			PreviewDialog::render();
		}
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_relevant_screen( $hook_suffix ) ) {
			return;
		}

		$script_path = 'build/index.js';
		$style_path  = 'build/index.css';
		$asset_path  = ARKID_CATALOGUE_LINK_PATH . 'build/index.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		/** @var array{dependencies: array<int, non-empty-string>, version: string} $asset */
		$asset = require $asset_path;

		$dependencies = array_unique(
			array_merge(
				$asset['dependencies'],
				array( 'jquery', 'select2', 'wc-enhanced-select' )
			)
		);

		wp_enqueue_style( 'woocommerce_admin_styles' );

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
			array_values( $dependencies ),
			$asset['version'],
			true
		);

		$this->needs_preview_dialog = $this->is_preview_screen( $hook_suffix );

		wp_localize_script( self::HANDLE, 'arkidCatalogueLinkAdmin', $this->script_data() );
	}

	/**
	 * What the admin script is told: where the picker's REST route is, the nonce
	 * for it, the hosts a viewer may be loaded from, and its translated strings.
	 *
	 * @return array<string, mixed>
	 */
	public function script_data(): array {
		return array(
			'restUrl'      => esc_url_raw( rest_url( 'arkid-catalogue-link/v1/embeds' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			// The one list the server checks viewer addresses against; the preview
			// checks the same, so the two cannot disagree.
			'allowedHosts' => $this->iframes->allowed_hosts(),
			'i18n'         => array(
				'searchPlaceholder'      => __( 'Search viewers…', 'arkid-catalogue-link' ),
				'reveal'                 => __( 'Reveal', 'arkid-catalogue-link' ),
				'hide'                   => __( 'Hide', 'arkid-catalogue-link' ),
				'noResults'              => __( 'No viewers found.', 'arkid-catalogue-link' ),
				'searching'              => __( 'Searching…', 'arkid-catalogue-link' ),
				'loadError'              => __( 'Could not load viewers.', 'arkid-catalogue-link' ),
				/* translators: %s: product name */
				'viewerFrameTitle'       => __( '%s 3D viewer', 'arkid-catalogue-link' ),
				'viewerFrameTitleNoName' => __( '3D viewer', 'arkid-catalogue-link' ),
				/* translators: %s: colour name */
				'colorShown'             => __( '%s: shown', 'arkid-catalogue-link' ),
				/* translators: %s: colour name */
				'colorHidden'            => __( '%s: hidden', 'arkid-catalogue-link' ),
			),
		);
	}

	/**
	 * The screens with a "Preview" button: the viewers list, the product edit
	 * screen and the Products list.
	 */
	private function is_preview_screen( string $hook_suffix ): bool {
		if ( 'woocommerce_page_' . ViewersPage::MENU_SLUG === $hook_suffix ) {
			return true;
		}

		if ( $this->is_products_list( $hook_suffix ) ) {
			return true;
		}

		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			return null !== $screen && 'product' === $screen->post_type;
		}

		return false;
	}

	/**
	 * The Products list, for the people who see the ARkid column on it.
	 */
	private function is_products_list( string $hook_suffix ): bool {
		return 'edit.php' === $hook_suffix
			&& ProductListFilter::is_products_list()
			&& current_user_can( 'manage_woocommerce' );
	}

	private function is_relevant_screen( string $hook_suffix ): bool {
		// The Products list: the column's styles and its preview button.
		if ( $this->is_products_list( $hook_suffix ) ) {
			return true;
		}

		// Product edit screens.
		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( null !== $screen && 'product' === $screen->post_type ) {
				return true;
			}
		}

		// Viewers admin page.
		if ( 'woocommerce_page_' . ViewersPage::MENU_SLUG === $hook_suffix ) {
			return true;
		}

		// WC settings → Integration → ARkid (for the reveal toggle).
		if ( 'woocommerce_page_wc-settings' === $hook_suffix ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$tab     = isset( $_GET['tab'] ) && is_string( $_GET['tab'] )
				? sanitize_text_field( wp_unslash( $_GET['tab'] ) )
				: '';
			$section = isset( $_GET['section'] ) && is_string( $_GET['section'] )
				? sanitize_text_field( wp_unslash( $_GET['section'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( 'integration' === $tab && IntegrationSettings::ID === $section ) {
				return true;
			}
		}

		return false;
	}
}
