<?php
/**
 * What the matcher suggests for a product that has no viewer: a viewer of the
 * account, and a product of ARkid's catalogue a viewer could be made from.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class Suggestion {

	public function __construct(
		public readonly ?Candidate $embed,
		public readonly ?Candidate $product
	) {}
}
