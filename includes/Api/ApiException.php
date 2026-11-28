<?php
/**
 * Base API exception.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

abstract class ApiException extends \RuntimeException {

	/**
	 * The HTTP status ARkid answered with, or 0 when it did not answer or the
	 * request never left the site.
	 */
	public function status(): int {
		return $this->getCode();
	}
}
