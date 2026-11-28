<?php
/**
 * Which products without a viewer have a match at ARkid.
 *
 * Reads the bounded scan once per request, and matches it against the viewers
 * and the catalogue of the account, which both have to be known: a count made
 * from the viewers alone would call every product that only the catalogue
 * matches "no match". Nothing is kept between requests, and nothing is linked
 * because a name matches.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\Api\CatalogProductDto;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Matching\Candidate;
use Arkid\CatalogueLink\Matching\Coverage;
use Arkid\CatalogueLink\Matching\ShopProduct;

defined( 'ABSPATH' ) || exit;

class ProductMatches {

	private bool $read = false;

	private ?MatchResult $result = null;

	public function __construct(
		private readonly ProductScan $scan,
		private readonly AccountData $account
	) {}

	/**
	 * The matches, or null when the account could not be read, so that there is
	 * nothing to match against.
	 */
	public function result(): ?MatchResult {
		if ( $this->read ) {
			return $this->result;
		}
		$this->read = true;

		$embeds  = $this->account->embeds();
		$catalog = $this->account->catalog();
		if ( null === $embeds || null === $catalog ) {
			return null;
		}

		$scan     = $this->scan->unlinked();
		$coverage = Coverage::classify(
			$scan->products,
			array_map( static fn( EmbedDto $embed ): Candidate => Candidate::of_embed( $embed ), $embeds ),
			array_map( static fn( CatalogProductDto $product ): Candidate => Candidate::of_product( $product ), $catalog ),
			$this->account->suggester()
		);

		$this->result = new MatchResult(
			array_map( static fn( ShopProduct $product ): int => $product->id, $coverage->matches ),
			$scan->truncated,
			count( $scan->products )
		);

		return $this->result;
	}
}
