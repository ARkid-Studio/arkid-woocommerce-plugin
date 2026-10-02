<?php
/**
 * Consent gate.
 *
 * Soft-depends on the WP Consent API (`wp-consent-api`). When the merchant
 * enables `require_consent` in settings:
 *  - If the WP Consent API isn't installed → render nothing (fail closed).
 *  - If it is installed → only render when `wp_has_consent('marketing')` is true.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class Consent {

	public function __construct( private readonly Options $options ) {}

	public function may_render(): bool {
		if ( ! $this->options->require_consent() ) {
			return true;
		}
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return false;
		}
		return (bool) wp_has_consent( 'marketing' );
	}
}
