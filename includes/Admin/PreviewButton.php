<?php
/**
 * The data attributes that make a button open the viewer preview of wp-admin.
 *
 * One place for the three screens that have such a button (the viewers list, the
 * product edit screen and the Products list), so that they check the address the
 * same way.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Support\Url;

defined( 'ABSPATH' ) || exit;

final class PreviewButton {

	/**
	 * The attributes, escaped and without a leading space, or '' when the viewer
	 * cannot be previewed.
	 *
	 * The address goes through the same check the storefront uses: https, on an
	 * ARkid host. Anything else gets no preview rather than a frame that points
	 * somewhere unexpected. The links of the dialog's footer are only set when
	 * they are https.
	 *
	 * @param string $name Names the viewer in the dialog's title.
	 */
	public static function attributes( IframeFactory $iframes, string $embed_url, string $name, string $edit_url = '', string $analytics_url = '' ): string {
		$url = $iframes->viewer_url( $embed_url );
		if ( '' === $url ) {
			return '';
		}

		return sprintf(
			'data-embed-url="%1$s" data-product-name="%2$s" data-edit-url="%3$s" data-analytics-url="%4$s"',
			esc_url( $url ),
			esc_attr( $name ),
			esc_url( Url::https( $edit_url ) ),
			esc_url( Url::https( $analytics_url ) )
		);
	}
}
