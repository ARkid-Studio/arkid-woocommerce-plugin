<?php
/**
 * ARkid does not accept the API key: missing, unknown or revoked.
 *
 * 401, or a 403 that names the key (ARkid answers 403 for a bad key). A 403
 * that says the key is fine but may not do this is a {@see RefusedException}.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class AuthException extends ApiException {}
