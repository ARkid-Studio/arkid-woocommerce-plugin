<?php
/**
 * Network failure, 5xx, an unexpected status or a malformed body: ARkid could
 * not be asked, or its answer cannot be used. Worth retrying later.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class TransportException extends ApiException {}
