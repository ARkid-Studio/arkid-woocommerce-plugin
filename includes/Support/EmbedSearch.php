<?php
/**
 * Free-text search over a list of embeds.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Support;

use Arkid\CatalogueLink\Api\EmbedDto;

defined( 'ABSPATH' ) || exit;

final class EmbedSearch {

	/**
	 * Keep the embeds that match every word of the query.
	 *
	 * The words are looked up in the label the merchant sees
	 * (`#row - brand - product - created - note`), in any order and across the
	 * separators, so "felix learning" finds "ARkid - Felix - Learning Tower".
	 * Case, accents and non-ASCII letters do not matter. An empty query keeps
	 * everything.
	 *
	 * @param array<int, EmbedDto> $embeds
	 *
	 * @return array<int, EmbedDto>
	 */
	public static function filter( array $embeds, string $query ): array {
		$words = Text::words( $query );
		if ( array() === $words ) {
			return $embeds;
		}

		return array_values(
			array_filter(
				$embeds,
				static function ( EmbedDto $embed ) use ( $words ): bool {
					$label = Text::fold( $embed->picker_label() );
					if ( '' === $label ) {
						// Every word is non-empty, so an embed without a label never matches.
						return false;
					}

					foreach ( $words as $word ) {
						if ( ! str_contains( $label, $word ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}
}
