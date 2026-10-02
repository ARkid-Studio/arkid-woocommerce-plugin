<?php
/**
 * Build the iframe / button-trigger HTML used by every renderer.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class IframeFactory {

	public function __construct( private readonly Options $options ) {}

	/** Hosts the viewer iframe may point at. */
	private const DEFAULT_ALLOWED_HOSTS = array( 'catalogue.arkid.app' );

	/**
	 * Append `?source=woocommerce` (or merge) to the embed URL.
	 *
	 * Returns '' for anything that isn't an https URL on an allowed host. Embed
	 * URLs reach us from post meta and from block attributes in post_content,
	 * neither of which is host-checked on the way in — and every builder below
	 * early-returns on an empty URL, so one check here covers all render paths.
	 */
	public function viewer_url( string $embed_url ): string {
		if ( '' === $embed_url || ! $this->is_allowed_host( $embed_url ) ) {
			return '';
		}
		return add_query_arg( 'source', 'woocommerce', $embed_url );
	}

	private function is_allowed_host( string $url ): bool {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );

		/**
		 * Filters the hosts a viewer iframe may be loaded from.
		 *
		 * @param mixed $hosts Bare hostnames; subdomains are allowed.
		 */
		$allowed = apply_filters(
			'arkid_catalogue_link_allowed_embed_hosts',
			self::DEFAULT_ALLOWED_HOSTS
		);

		if ( ! is_array( $allowed ) ) {
			return false;
		}

		foreach ( $allowed as $candidate ) {
			$candidate = strtolower( is_scalar( $candidate ) ? (string) $candidate : '' );
			if ( '' === $candidate ) {
				continue;
			}
			if ( $host === $candidate || str_ends_with( $host, '.' . $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inline iframe HTML.
	 *
	 * Sizing is handled entirely by the `arkid-catalogue-link__viewer` CSS
	 * class (full container width, 16/9 aspect ratio, `min-height: 550px`).
	 * Themes can override that one class to change the viewer dimensions.
	 */
	public function build_iframe( string $embed_url, string $product_name ): string {
		$src = $this->viewer_url( $embed_url );
		if ( '' === $src ) {
			return '';
		}

		$title = sprintf(
			/* translators: %s: product name */
			__( '%s 3D viewer', 'arkid-catalogue-link' ),
			$product_name
		);

		return sprintf(
			'<iframe class="arkid-catalogue-link__viewer" src="%1$s" loading="lazy" allow="xr-spatial-tracking" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-popups allow-forms" title="%2$s"></iframe>',
			esc_url( $src ),
			esc_attr( $title )
		);
	}

	/**
	 * Image-based modal trigger — renders the product/embed thumbnail with a
	 * play icon overlay. Used as a gallery slide for the `last_image` position.
	 */
	public function build_image_trigger( string $embed_url, string $product_name, string $thumbnail_url, string $modifier = '' ): string {
		$src = $this->viewer_url( $embed_url );
		if ( '' === $src ) {
			return '';
		}

		$class = trim( 'arkid-catalogue-link__trigger ' . $modifier );

		$open_label = sprintf(
			/* translators: %s: product name */
			__( 'Open 3D viewer for %s', 'arkid-catalogue-link' ),
			$product_name
		);

		$preview_html = '' !== $thumbnail_url
			? sprintf(
				'<img src="%s" alt="" loading="lazy" />',
				esc_url( $thumbnail_url )
			)
			: '';

		return sprintf(
			'<button type="button" class="%1$s" aria-haspopup="dialog" aria-expanded="false" aria-controls="arkid-catalogue-link__dialog" data-embed-url="%2$s" data-product-name="%3$s">%4$s<span class="arkid-catalogue-link__play-icon" aria-hidden="true">▶</span><span class="screen-reader-text">%5$s</span></button>',
			esc_attr( $class ),
			esc_url( $src ),
			esc_attr( $product_name ),
			$preview_html, // Pre-escaped above.
			esc_html( $open_label )
		);
	}

	/**
	 * Text-based modal trigger — standalone button with configurable label
	 * + the ARkid logo (same icon used by the Shopify and Magento plugins).
	 * No thumbnail. Used for the `button_only` position.
	 */
	public function build_text_trigger( string $embed_url, string $product_name ): string {
		$src = $this->viewer_url( $embed_url );
		if ( '' === $src ) {
			return '';
		}

		$button_text = $this->options->button_text();

		return sprintf(
			'<button type="button" class="arkid-catalogue-link__trigger arkid-catalogue-link__trigger--standalone" aria-haspopup="dialog" aria-expanded="false" aria-controls="arkid-catalogue-link__dialog" data-embed-url="%1$s" data-product-name="%2$s"><span class="arkid-catalogue-link__brand-icon" aria-hidden="true">%3$s</span><span class="arkid-catalogue-link__trigger-label">%4$s</span></button>',
			esc_url( $src ),
			esc_attr( $product_name ),
			self::brand_icon_svg(), // Pre-trusted: bundled SVG asset shipped with the plugin.
			esc_html( $button_text )
		);
	}

	/**
	 * Inline ARkid logo SVG. Read once per request, cached in static memory.
	 *
	 * Inlined (rather than served via `<img src>`) so the icon inherits the
	 * surrounding text colour through `fill="currentColor"`, matching the
	 * Shopify / Magento plugins' light/dark logic without needing two assets.
	 */
	private static function brand_icon_svg(): string {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$path = ARKID_CATALOGUE_LINK_PATH . 'assets/arkid-icon.svg';
		if ( ! is_readable( $path ) ) {
			$cached = '';
			return $cached;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file shipped with the plugin, not a remote URL.
		$svg = (string) file_get_contents( $path );
		// Strip leading XML declaration / BOM if present so the markup nests
		// cleanly inside the button.
		$svg    = preg_replace( '/^\s*<\?xml[^>]*\?>\s*/i', '', $svg ) ?? $svg;
		$cached = trim( $svg );
		return $cached;
	}

	/**
	 * `<a href>` no-script fallback (visible only when JS is disabled).
	 */
	public function build_noscript_link( string $embed_url, string $product_name ): string {
		$src = $this->viewer_url( $embed_url );
		if ( '' === $src ) {
			return '';
		}
		$label = sprintf(
			/* translators: %s: product name */
			__( 'Open the 3D viewer for %s in a new window', 'arkid-catalogue-link' ),
			$product_name
		);
		return sprintf(
			'<noscript><a href="%s" target="_blank" rel="noopener">%s</a></noscript>',
			esc_url( $src ),
			esc_html( $label )
		);
	}
}
