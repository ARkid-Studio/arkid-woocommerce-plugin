<?php
/**
 * A shop product that has a viewer selected.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Links;

defined( 'ABSPATH' ) || exit;

final class LinkedProduct {

	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $status
	) {}

	public function is_published(): bool {
		return 'publish' === $this->status;
	}
}
