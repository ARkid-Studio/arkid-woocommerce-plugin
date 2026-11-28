<?php
/**
 * What the Products list needs to know about the ARkid account: its viewers and
 * the products of the catalogue.
 *
 * Read from ARkid when first asked, once per request: the viewer names of a page
 * of products, the counts of its views and the suggestions all share one list, as
 * every list screen asks. Nothing is kept between requests, so what the screen
 * shows is what ARkid says now; and when ARkid cannot answer, the screen still
 * renders, with `error()` to say why. A storefront request never makes one.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\Api\ApiException;
use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\CatalogProductDto;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Matching\Candidate;
use Arkid\CatalogueLink\Matching\Suggester;

defined( 'ABSPATH' ) || exit;

class AccountData {

	private bool $embeds_read = false;

	/**
	 * @var array<int, EmbedDto>|null
	 */
	private ?array $embeds = null;

	private bool $catalog_read = false;

	/**
	 * @var array<int, CatalogProductDto>|null
	 */
	private ?array $catalog = null;

	private ?string $error = null;

	private ?Suggester $suggester = null;

	/**
	 * @var array<string, EmbedDto>|null
	 */
	private ?array $by_id = null;

	public function __construct( private readonly ClientFactory $api ) {}

	/**
	 * Whether there is an API key to ask with.
	 */
	public function is_configured(): bool {
		return null !== $this->api->create();
	}

	/**
	 * The viewers of the account, or null when ARkid could not be asked (see `error()`).
	 *
	 * @return array<int, EmbedDto>|null
	 */
	public function embeds(): ?array {
		if ( ! $this->embeds_read ) {
			$this->embeds_read = true;
			$client            = $this->api->create();
			if ( null !== $client ) {
				try {
					$this->embeds = $client->list_embeds();
				} catch ( \Throwable $e ) {
					// Whatever goes wrong while asking must not take the Products list down.
					$this->error = self::explain( $e );
				}
			}
		}

		return $this->embeds;
	}

	/**
	 * The products of ARkid's catalogue, or null when ARkid could not be asked, or
	 * when the viewers could not be, which makes a second try pointless.
	 *
	 * @return array<int, CatalogProductDto>|null
	 */
	public function catalog(): ?array {
		if ( ! $this->catalog_read ) {
			$this->catalog_read = true;
			if ( null === $this->embeds() ) {
				return null;
			}
			$client = $this->api->create();
			if ( null !== $client ) {
				try {
					$this->catalog = $client->list_products();
				} catch ( \Throwable $e ) {
					$this->error = self::explain( $e );
				}
			}
		}

		return $this->catalog;
	}

	/**
	 * Whether both lists were read: what a count of matches needs.
	 */
	public function is_complete(): bool {
		return null !== $this->embeds() && null !== $this->catalog();
	}

	/**
	 * What went wrong, for a notice; null when nothing did, or when there is no key.
	 */
	public function error(): ?string {
		return $this->error;
	}

	/**
	 * The ids of the viewers the account lists, or null when that is not known:
	 * what tells a link to a deleted viewer from one that is fine.
	 *
	 * @return array<int, string>|null
	 */
	public function embed_ids(): ?array {
		$embeds = $this->embeds();

		return null === $embeds ? null : array_map( static fn( EmbedDto $embed ): string => $embed->embed_id, $embeds );
	}

	/**
	 * The viewer of the account with an id, compared without regard to case, or
	 * null when there is none or the account is not known.
	 */
	public function embed( string $embed_id ): ?EmbedDto {
		if ( null === $this->by_id ) {
			$this->by_id = array();
			foreach ( $this->embeds() ?? array() as $embed ) {
				$this->by_id[ ProductLinks::key( $embed->embed_id ) ] = $embed;
			}
		}

		return $this->by_id[ ProductLinks::key( $embed_id ) ] ?? null;
	}

	/**
	 * Suggests viewers and catalogue products for names; null when not even the
	 * viewers are known. When only the catalogue could not be read, it suggests
	 * from the viewers alone.
	 */
	public function suggester(): ?Suggester {
		if ( null === $this->suggester ) {
			$embeds = $this->embeds();
			if ( null === $embeds ) {
				return null;
			}
			$this->suggester = new Suggester(
				array_map( Candidate::of_embed( ... ), $embeds ),
				array_map( Candidate::of_product( ... ), $this->catalog() ?? array() )
			);
		}

		return $this->suggester;
	}

	/**
	 * A failure in words for the merchant.
	 */
	private static function explain( \Throwable $failure ): string {
		if ( $failure instanceof AuthException ) {
			return __( 'The API key was rejected. Update it in the integration settings.', 'arkid-catalogue-link' );
		}
		if ( $failure instanceof ApiException ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Could not reach the ARkid API: %s', 'arkid-catalogue-link' ),
				$failure->getMessage()
			);
		}

		return __( 'ARkid could not be asked for its viewers.', 'arkid-catalogue-link' );
	}
}
