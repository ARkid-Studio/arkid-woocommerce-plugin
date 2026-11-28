<?php
/**
 * Suggests a viewer, or a catalogue product, for a shop product by its name.
 *
 * Suggestions only: nothing is ever linked because a name matches.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class Suggester {

	private CandidateIndex $embeds;

	private CandidateIndex $catalog;

	/**
	 * @param array<int, Candidate> $embeds  The viewers of the account.
	 * @param array<int, Candidate> $catalog The products of ARkid's catalogue.
	 */
	public function __construct( array $embeds, array $catalog ) {
		$this->embeds  = new CandidateIndex( $embeds );
		$this->catalog = new CandidateIndex( $catalog );
	}

	/**
	 * The viewer and the catalogue product that match the title (with the brand,
	 * where the shop has one), or null when neither does.
	 */
	public function suggest( string $title, string $vendor ): ?Suggestion {
		$embed   = $this->embeds->best( $title, $vendor );
		$product = $this->catalog->best( $title, $vendor );

		return null === $embed && null === $product ? null : new Suggestion( $embed, $product );
	}

	/**
	 * Whether a viewer or a catalogue product matches. As `suggest()`, without
	 * looking at the catalogue when a viewer already does.
	 */
	public function has_match( string $title, string $vendor ): bool {
		return null !== $this->embeds->best( $title, $vendor )
			|| null !== $this->catalog->best( $title, $vendor );
	}
}
