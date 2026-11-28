<?php
/**
 * Contract for the ARkid Ecom API.
 *
 * Exists so consumers can depend on an interface rather than constructing a
 * concrete `Client` inline, which is what made the cron, the metabox save path
 * and the REST controller untestable.
 *
 * Every method calls ARkid when it is called: nothing is cached. A storefront
 * request must never call any of them; they are for wp-admin and background
 * jobs.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

interface EmbedSource {

	/**
	 * @throws AuthException      When ARkid does not accept the API key.
	 * @throws NotFoundException  When the viewer does not exist in the account.
	 * @throws TransportException On network / 5xx / parse error.
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

	/**
	 * Create a viewer of a catalogue product. Needs an API key that may write.
	 *
	 * @throws ValidationException When the input is invalid, or ARkid says so (422).
	 * @throws RefusedException    When the plan, the key's permission or a rate limit says no.
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function create_embed( EmbedInput $input ): EmbedDto;

	/**
	 * Change the fields of a viewer that `$changes` sets and keep the rest.
	 * Needs the viewer's `can_edit`.
	 *
	 * @throws ValidationException
	 * @throws RefusedException    Among others when the key may not change this viewer.
	 * @throws NotFoundException
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function update_embed( string $embed_id, EmbedInput $changes ): EmbedDto;

	/**
	 * The catalogue products a viewer can be created for.
	 *
	 * @return array<int, CatalogProductDto>
	 *
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function list_products(): array;

	/**
	 * The environments a viewer can be set to, named in `$lang` where ARkid has
	 * the language, else in the language of the API key's user.
	 *
	 * @param string $locale A WordPress locale (`de_DE`) or a language code (`de`).
	 *
	 * @return array<int, EnvironmentDto>
	 *
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function list_environments( string $locale = '' ): array;

	/**
	 * The traffic of the last `$days` days. Only 7, 30 and 90 exist; any other
	 * value is read as 30, as the API does.
	 *
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function stats( int $days = StatsDto::DEFAULT_PERIOD ): StatsDto;
}
