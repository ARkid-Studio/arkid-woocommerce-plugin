<?php
/**
 * 400 / 422: ARkid refused the data of a write, field by field.
 *
 * Also thrown before the request when the plugin's own checks of the same
 * rules fail, so one handler covers both. The field names are those of the API
 * (`color_a`, `variants`, `product_id` ...).
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class ValidationException extends ErrorReplyException {

	/**
	 * @param array<string, string> $errors Message by field name.
	 */
	public static function for_fields( array $errors, int $status = 422 ): self {
		return new self( sprintf( 'ARkid rejected the viewer data (HTTP %d).', $status ), $errors, $status );
	}
}
