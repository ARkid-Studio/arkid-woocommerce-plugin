<?php
/**
 * Asks ARkid about one viewer and says what the answer means for a link.
 *
 * The one place that decides that a 404 is "the viewer is gone" and that
 * everything else that goes wrong is "could not ask". The product edit screen
 * and the refresh job both use it, so they cannot disagree.
 *
 * A live GET each time, never a list: a list says nothing about a viewer that
 * was created after it was read.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\Api\ApiException;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Api\NotFoundException;

defined( 'ABSPATH' ) || exit;

class EmbedLookup {

	public function __construct( private readonly ClientFactory $api ) {}

	public function find( string $embed_id ): Lookup {
		try {
			$client = $this->api->create();
			if ( null === $client ) {
				return Lookup::unavailable();
			}

			return Lookup::found( $client->get_embed( $embed_id ) );
		} catch ( NotFoundException $e ) {
			return Lookup::not_found();
		} catch ( ApiException $e ) {
			return Lookup::unavailable();
		} catch ( \Throwable $e ) {
			// Whatever else goes wrong while asking must never take a product
			// save or the edit screen down with it.
			return Lookup::unavailable();
		}
	}
}
