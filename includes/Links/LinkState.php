<?php
/**
 * The state of a product's link to an ARkid viewer.
 *
 * One classification for every place that has to say whether a link is in
 * order: the product edit screen, and the list screens that count and filter
 * "needs attention" without calling the API. It reads only what the plugin has
 * stored, so a list of a thousand products costs no request.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

use Arkid\CatalogueLink\PostMeta;

defined( 'ABSPATH' ) || exit;

enum LinkState: string {

	/** No viewer is selected. */
	case None = 'none';

	/** A viewer is selected and the product page can show it. */
	case Linked = 'linked';

	/**
	 * A viewer is selected but nothing is stored to show yet: it has not been
	 * fetched, or ARkid could not be reached when it was tried. The refresh
	 * retries. Not the same as {@see self::Missing}.
	 */
	case NotSynced = 'not_synced';

	/**
	 * A viewer is selected and ARkid says it does not exist (404): deleted, or
	 * from another account's key. It will never sync; the merchant has to act.
	 */
	case Missing = 'missing';

	/**
	 * Classify a link from what is stored for it.
	 *
	 * @param string $embed_id  The selected viewer, '' for none.
	 * @param string $embed_url The stored viewer URL, '' for none.
	 * @param string $status    The stored `PostMeta::KEY_STATUS`.
	 */
	public static function classify( string $embed_id, string $embed_url, string $status ): self {
		if ( '' === $embed_id ) {
			return self::None;
		}
		if ( PostMeta::STATUS_MISSING === $status ) {
			return self::Missing;
		}
		if ( '' === $embed_url ) {
			return self::NotSynced;
		}

		return self::Linked;
	}

	/**
	 * Classify a product from its post meta.
	 */
	public static function of_product( int $product_id ): self {
		return self::classify(
			PostMeta::embed_id( $product_id ),
			PostMeta::embed_url( $product_id ),
			PostMeta::status( $product_id )
		);
	}

	/**
	 * Whether the merchant should look at this link: the product page shows no
	 * viewer although one was chosen.
	 */
	public function needs_attention(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- False positive: the sniff does not know that an enum method has a `$this`.
		return self::Missing === $this || self::NotSynced === $this;
	}
}
