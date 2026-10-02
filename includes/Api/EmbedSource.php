<?php
/**
 * Read-only contract for a source of ARkid embeds.
 *
 * Exists so consumers can depend on an interface rather than constructing a
 * concrete `Client` inline, which is what made the cron, the metabox save path
 * and the REST controller untestable.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

interface EmbedSource {

	/**
	 * @throws AuthException     on 401 / 403.
	 * @throws NotFoundException on 404.
	 * @throws TransportException on network / 5xx / parse error.
	 */
	public function get_embed( string $embed_id ): EmbedDto;

	/**
	 * @return array<int, EmbedDto>
	 *
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function list_embeds(): array;

	/**
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function probe(): bool;
}
