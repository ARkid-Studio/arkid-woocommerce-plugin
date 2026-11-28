<?php
/**
 * What a live question about one viewer came back with.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\Api\EmbedDto;

defined( 'ABSPATH' ) || exit;

final class Lookup {

	/** ARkid has the viewer. */
	public const FOUND = 'found';

	/** ARkid answered 404: the viewer does not exist in the account. */
	public const NOT_FOUND = 'not_found';

	/**
	 * ARkid could not say: no API key, a rejected key, no connection, a 5xx or
	 * an answer that cannot be read. Says nothing about the viewer.
	 */
	public const UNAVAILABLE = 'unavailable';

	private function __construct(
		public readonly string $outcome,
		public readonly ?EmbedDto $embed
	) {}

	public static function found( EmbedDto $embed ): self {
		return new self( self::FOUND, $embed );
	}

	public static function not_found(): self {
		return new self( self::NOT_FOUND, null );
	}

	public static function unavailable(): self {
		return new self( self::UNAVAILABLE, null );
	}

	public function is_found(): bool {
		return self::FOUND === $this->outcome;
	}

	public function is_not_found(): bool {
		return self::NOT_FOUND === $this->outcome;
	}

	public function is_unavailable(): bool {
		return self::UNAVAILABLE === $this->outcome;
	}
}
