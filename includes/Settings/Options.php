<?php
/**
 * Typed read-only accessor over the plugin's settings option.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Settings;

defined( 'ABSPATH' ) || exit;

final class Options {

	public const POSITION_FIRST_IMAGE   = 'first_image';
	public const POSITION_LAST_IMAGE    = 'last_image';
	public const POSITION_ABOVE_PRODUCT = 'above_product';
	public const POSITION_BELOW_PRODUCT = 'below_product';
	public const POSITION_BUTTON_ONLY   = 'button_only';

	/** Position used when no per-product override is set. */
	public const DEFAULT_POSITION = self::POSITION_BELOW_PRODUCT;

	public const OPTION_KEY = 'woocommerce_arkid-catalogue-link_settings';

	/**
	 * Default text shown on the standalone "View in 3D" button.
	 *
	 * Localized: returned in the visitor's locale on the storefront,
	 * the admin's locale in settings UI. The merchant can override
	 * the value per store; their saved value is then used verbatim.
	 */
	public static function default_button_text(): string {
		return __( 'See in 3D & AR', 'arkid-catalogue-link' );
	}

	/**
	 * @return array<int, string>
	 */
	public static function positions(): array {
		return array(
			self::POSITION_FIRST_IMAGE,
			self::POSITION_LAST_IMAGE,
			self::POSITION_ABOVE_PRODUCT,
			self::POSITION_BELOW_PRODUCT,
			self::POSITION_BUTTON_ONLY,
		);
	}

	public function api_key(): string {
		$value = $this->raw( 'api_key' );
		return is_string( $value ) ? trim( $value ) : '';
	}

	public function default_position(): string {
		$value = $this->raw( 'default_position' );
		return is_string( $value ) && in_array( $value, self::positions(), true )
			? $value
			: self::DEFAULT_POSITION;
	}

	public function require_consent(): bool {
		return 'yes' === $this->raw( 'require_consent' );
	}

	public function button_text(): string {
		$value = $this->raw( 'button_text' );
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' !== $value ? $value : self::default_button_text();
	}

	/**
	 * @return mixed
	 */
	private function raw( string $key ) {
		$option = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $option ) ) {
			return null;
		}
		return $option[ $key ] ?? null;
	}
}
