<?php
/**
 * The fields of a viewer that a write may set.
 *
 * Mirrors the rules of ARkid's `EcomEmbedInput`, so a bad value is caught
 * before a request is spent on it and so that the same handler serves both the
 * local check and ARkid's own 422. A field left `null` is not sent: ARkid then
 * keeps the value the viewer has, or on a create takes the account's default.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class EmbedInput {

	private const UUID_PATTERN        = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
	private const COLOR_PATTERN       = '/^#[0-9a-fA-F]{6}$/D';
	private const ENVIRONMENT_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/D';
	private const NAME_MAX            = 255;
	private const URL_MAX             = 510;

	/**
	 * @param string|null        $product_id   The catalogue product. Create only.
	 * @param string|null        $name         The viewer's internal note.
	 * @param string|null        $color_a      `#rrggbb`. Only where the viewer's `colors_editable` is true.
	 * @param string|null        $color_b      `#rrggbb`. Same.
	 * @param string|null        $environment  An environment id from `list_environments()`. Only where `environment_editable` is true.
	 * @param array<int, string>|null $variants The ids of the variants to show, in display order. At least one.
	 * @param string|null        $embedder_url The shop page that shows the viewer. ARkid keeps a page URL it already has.
	 */
	public function __construct(
		public readonly ?string $product_id = null,
		public readonly ?string $name = null,
		public readonly ?string $color_a = null,
		public readonly ?string $color_b = null,
		public readonly ?string $environment = null,
		public readonly ?array $variants = null,
		public readonly ?string $embedder_url = null
	) {}

	/**
	 * The problems of this input, by field. Empty when it is fine to send.
	 *
	 * @param bool $create A create requires `product_id`; an update refuses it.
	 *
	 * @return array<string, string>
	 */
	public function errors( bool $create ): array {
		$errors = array();

		if ( $create ) {
			if ( null === $this->product_id || 1 !== preg_match( self::UUID_PATTERN, $this->product_id ) ) {
				$errors['product_id'] = 'Required: the product_id of a product the API lists.';
			}
		} elseif ( null !== $this->product_id ) {
			$errors['product_id'] = 'Read-only field.';
		}

		if ( null !== $this->name && mb_strlen( $this->name ) > self::NAME_MAX ) {
			$errors['name'] = 'A string of at most 255 characters.';
		}

		foreach ( array(
			'color_a' => $this->color_a,
			'color_b' => $this->color_b,
		) as $field => $color ) {
			if ( null !== $color && 1 !== preg_match( self::COLOR_PATTERN, $color ) ) {
				$errors[ $field ] = 'A color as #RRGGBB.';
			}
		}

		if ( null !== $this->environment && 1 !== preg_match( self::ENVIRONMENT_PATTERN, $this->environment ) ) {
			$errors['environment'] = 'An environment id from the list of environments.';
		}

		if ( null !== $this->variants ) {
			$error = $this->variants_error( $this->variants );
			if ( '' !== $error ) {
				$errors['variants'] = $error;
			}
		}

		if ( null !== $this->embedder_url ) {
			$url = trim( $this->embedder_url );
			if ( 1 !== preg_match( '~^https?://\S+$~iD', $url ) || mb_strlen( $url ) > self::URL_MAX ) {
				$errors['embedder_url'] = 'An http or https URL of at most 510 characters.';
			}
		}

		return $errors;
	}

	/**
	 * The JSON body of the write: the fields that are set, normalised the way
	 * ARkid stores them (lower-case colours and ids).
	 *
	 * @param bool $create Whether to include `product_id`.
	 *
	 * @return array<string, mixed>
	 */
	public function to_body( bool $create ): array {
		$body = array();

		if ( $create && null !== $this->product_id ) {
			$body['product_id'] = strtolower( $this->product_id );
		}
		if ( null !== $this->name ) {
			$body['name'] = $this->name;
		}
		if ( null !== $this->color_a ) {
			$body['color_a'] = strtolower( $this->color_a );
		}
		if ( null !== $this->color_b ) {
			$body['color_b'] = strtolower( $this->color_b );
		}
		if ( null !== $this->environment ) {
			$body['environment'] = $this->environment;
		}
		if ( null !== $this->variants ) {
			$body['variants'] = array_map( 'strtolower', $this->variants );
		}
		if ( null !== $this->embedder_url ) {
			$body['embedder_url'] = trim( $this->embedder_url );
		}

		return $body;
	}

	/**
	 * @param array<int, mixed> $variants
	 */
	private function variants_error( array $variants ): string {
		if ( array() === $variants || ! array_is_list( $variants ) ) {
			return 'A non-empty array of variant ids. An embed shows at least one variant.';
		}

		$seen = array();
		foreach ( $variants as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::UUID_PATTERN, $id ) ) {
				return 'Variant ids must be UUIDs.';
			}
			$seen[ strtolower( $id ) ] = true;
		}
		if ( count( $seen ) !== count( $variants ) ) {
			return 'Each variant may appear only once.';
		}

		return '';
	}
}
