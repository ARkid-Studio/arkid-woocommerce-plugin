<?php
/**
 * A product of the ARkid catalogue that a viewer can be created for, from
 * `GET /api/ecom/product`.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class CatalogProductDto {

	/**
	 * @param string $product_id           The id to send as `product_id` on a create.
	 * @param string $type                 `pdp`, `exp` or `asm`.
	 * @param array<int, array{variant_id: string, name: string, rgb: string}> $variants In display order.
	 * @param bool   $colors_editable      Whether a create can set colour A and B, or a skin fixes them.
	 * @param bool   $environment_editable Whether a create can set the environment, or a skin fixes it.
	 * @param string $color_a              What a new viewer of the product starts with for this account.
	 * @param string $color_b              Same.
	 * @param string $environment          Same.
	 */
	public function __construct(
		public readonly string $product_id,
		public readonly string $name,
		public readonly string $brand_name,
		public readonly string $type,
		public readonly string $image,
		public readonly array $variants,
		public readonly bool $colors_editable,
		public readonly bool $environment_editable,
		public readonly string $color_a,
		public readonly string $color_b,
		public readonly string $environment
	) {}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_array( array $payload ): self {
		$variants = array();
		foreach ( Cast::rows( $payload['variants'] ?? array() ) as $variant ) {
			$variants[] = array(
				'variant_id' => Cast::str( $variant, 'variant_id' ),
				'name'       => Cast::str( $variant, 'name' ),
				'rgb'        => Cast::str( $variant, 'rgb' ),
			);
		}

		return new self(
			product_id:           Cast::str( $payload, 'product_id' ),
			name:                 Cast::str( $payload, 'name' ),
			brand_name:           Cast::str( $payload, 'brand_name' ),
			type:                 Cast::str( $payload, 'type' ),
			image:                Cast::str( $payload, 'image' ),
			variants:             $variants,
			colors_editable:      Cast::flag( $payload, 'colors_editable', true ),
			environment_editable: Cast::flag( $payload, 'environment_editable', true ),
			color_a:              Cast::str( $payload, 'color_a' ),
			color_b:              Cast::str( $payload, 'color_b' ),
			environment:          Cast::str( $payload, 'environment' ),
		);
	}

	/**
	 * The ids of the variants, in display order: what a create shows when it
	 * names none.
	 *
	 * @return array<int, string>
	 */
	public function variant_ids(): array {
		return array_column( $this->variants, 'variant_id' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'product_id'           => $this->product_id,
			'name'                 => $this->name,
			'brand_name'           => $this->brand_name,
			'type'                 => $this->type,
			'image'                => $this->image,
			'variants'             => $this->variants,
			'colors_editable'      => $this->colors_editable,
			'environment_editable' => $this->environment_editable,
			'color_a'              => $this->color_a,
			'color_b'              => $this->color_b,
			'environment'          => $this->environment,
		);
	}
}
