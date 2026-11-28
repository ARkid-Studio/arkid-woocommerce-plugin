<?php
/**
 * 404: the viewer, or whatever was asked for, does not exist in the account.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class NotFoundException extends ApiException {}
