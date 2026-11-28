<?php
/**
 * ARkid understood the request and the key is fine, but it will not do it.
 *
 * A plan limit (402), a key without the write permission or a viewer the key
 * may not change (403), or too many requests (429). This is not a reason to
 * ask the merchant to check the API key, which is what {@see AuthException}
 * says.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class RefusedException extends ErrorReplyException {

	/**
	 * @param array<string, string> $errors      Message by field name.
	 * @param int                   $retry_after Seconds ARkid asks to wait, 0 when it did not say.
	 */
	public function __construct( string $message, array $errors, int $status, private readonly int $retry_after = 0 ) {
		parent::__construct( $message, $errors, $status );
	}

	public function is_rate_limited(): bool {
		return 429 === $this->status();
	}

	public function retry_after(): int {
		return $this->retry_after;
	}
}
