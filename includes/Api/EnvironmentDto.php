<?php
/**
 * An environment a viewer can be set to, from `GET /api/ecom/environment`.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class EnvironmentDto {

	/**
	 * @param string $environment The id to send as `environment`.
	 * @param string $name        Its name, in the language asked for.
	 * @param string $group       The group it is listed under, in the same language.
	 */
	public function __construct(
		public readonly string $environment,
		public readonly string $name,
		public readonly string $group
	) {}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_array( array $payload ): self {
		return new self(
			environment: Cast::str( $payload, 'environment' ),
			name:        Cast::str( $payload, 'name' ),
			group:       Cast::str( $payload, 'group' ),
		);
	}

	/**
	 * @return array{environment: string, name: string, group: string}
	 */
	public function to_array(): array {
		return array(
			'environment' => $this->environment,
			'name'        => $this->name,
			'group'       => $this->group,
		);
	}
}
