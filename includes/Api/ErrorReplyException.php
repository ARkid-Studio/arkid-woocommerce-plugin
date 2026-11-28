<?php
/**
 * ARkid answered a 4xx with a body that explains itself.
 *
 * The API reports problems as `{"errors": {"field": "message"}}`. The messages
 * are plain text from ARkid, kept as received: escape them where they are shown.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

abstract class ErrorReplyException extends ApiException {

	/**
	 * @param array<string, string> $errors Message by field name.
	 */
	public function __construct( string $message, private readonly array $errors, int $status ) {
		parent::__construct( $message, $status );
	}

	/**
	 * @return array<string, string> Message by field name.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * The message for one field, or '' when ARkid said nothing about it.
	 */
	public function error_for( string $field ): string {
		return $this->errors[ $field ] ?? '';
	}

	/**
	 * Every message, one after the other, for a place that shows a single line.
	 */
	public function summary(): string {
		return implode( ' ', $this->errors );
	}
}
