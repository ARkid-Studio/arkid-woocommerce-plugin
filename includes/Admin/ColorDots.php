<?php
/**
 * The colours of a viewer, as a row of dots.
 *
 * One dot per colour of the product, in display order. A colour the viewer
 * shows is a filled dot; one it does not show keeps its own colour but is struck
 * through. Each dot says its state in words as well, so the state is never carried
 * by colour or by the stripe alone.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\EmbedDto;

defined( 'ABSPATH' ) || exit;

final class ColorDots {

	/**
	 * The dots of a viewer, or '' when its product has no colours.
	 *
	 * The result is HTML, escaped here.
	 */
	public static function render( EmbedDto $embed ): string {
		return self::render_colors( $embed->all_colors );
	}

	/**
	 * @param array<int, array{variant_id: string, name: string, rgb: string, enabled: bool}> $colors
	 */
	public static function render_colors( array $colors ): string {
		if ( array() === $colors ) {
			return '';
		}

		$dots = '';
		foreach ( $colors as $color ) {
			$label = self::label( $color['name'], $color['enabled'] );
			$rgb   = self::css_color( $color['rgb'] );

			$dots .= sprintf(
				'<span class="arkid-catalogue-link__dot%1$s" role="img" data-state="%2$s" data-variant-id="%3$s"%4$s title="%5$s" aria-label="%5$s"></span>',
				$color['enabled'] ? '' : ' is-hidden',
				$color['enabled'] ? 'shown' : 'hidden',
				esc_attr( $color['variant_id'] ),
				'' === $rgb ? '' : ' style="--arkid-dot:' . esc_attr( $rgb ) . '"',
				esc_attr( $label )
			);
		}

		return '<span class="arkid-catalogue-link__dots">' . $dots . '</span>';
	}

	/**
	 * What a dot says about its colour, for the tooltip and the screen reader.
	 */
	public static function label( string $name, bool $shown ): string {
		return $shown
			/* translators: %s: colour name */
			? sprintf( __( '%s: shown', 'arkid-catalogue-link' ), $name )
			/* translators: %s: colour name */
			: sprintf( __( '%s: hidden', 'arkid-catalogue-link' ), $name );
	}

	/**
	 * A colour that is safe to put in a style attribute, or '' for anything else.
	 *
	 * ARkid sends `#rrggbb`. Nothing but a hex colour is accepted, so a value
	 * cannot carry a second declaration or a URL into the style.
	 */
	public static function css_color( string $rgb ): string {
		$rgb = trim( $rgb );

		return 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/Di', $rgb ) ? strtolower( $rgb ) : '';
	}
}
