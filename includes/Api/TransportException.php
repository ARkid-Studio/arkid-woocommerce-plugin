<?php
/**
 * Network / 5xx / malformed-JSON failure.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class TransportException extends ApiException {}
