<?php
/**
 * Builds API clients from the stored settings.
 *
 * Deliberately not `final`: overriding `create()` in a test subclass is the
 * entire mocking story for every consumer.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class ClientFactory {

	public function __construct( private readonly Options $options ) {}

	/**
	 * Null when no API key is configured — every caller must handle that.
	 */
	public function create(): ?EmbedSource {
		$key = $this->options->api_key();

		return '' === $key ? null : new Client( $key );
	}

	/**
	 * Explicit-key variant, for probing a key that hasn't been saved yet.
	 */
	public function create_for_key( string $key ): EmbedSource {
		return new Client( $key );
	}
}
