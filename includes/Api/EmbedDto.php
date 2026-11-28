<?php
/**
 * Immutable value object for an ARkid embed.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class EmbedDto {

	/**
	 * `colors` is the legacy shape the picker and the block read: the colours
	 * the viewer shows, name and rgb only. `all_colors` is what the API sends:
	 * every colour of the product, in display order, with its variant id and
	 * whether the viewer shows it.
	 *
	 * @param array<int, array{name: string, rgb?: string}> $colors                The colours the viewer shows.
	 * @param array<int, array{name: string}>               $tags
	 * @param array<int, string>                            $variants              Ids of the enabled variants, in display order.
	 * @param array<int, array{variant_id: string, name: string, rgb: string, enabled: bool}> $all_colors Every colour of the product.
	 * @param bool                                          $can_edit              Whether the API key may change this viewer.
	 * @param bool                                          $colors_editable       Whether colour A and B can be set, or a skin fixes them.
	 * @param bool                                          $environment_editable  Whether the environment can be set, or a skin fixes it.
	 */
	public function __construct(
		public readonly string $embed_id,
		public readonly int $row_number,
		public readonly string $product_name,
		public readonly string $brand_name,
		public readonly string $image,
		public readonly string $embed_url,
		public readonly string $embedder_url,
		public readonly string $created,
		public readonly string $modified,
		public readonly string $edit_url,
		public readonly string $analytics_url,
		public readonly string $internal_note,
		public readonly string $buybutton_url,
		public readonly string $cta_url,
		public readonly int $views,
		public readonly string $author_name,
		public readonly string $type,
		public readonly array $colors,
		public readonly array $tags,
		public readonly string $product_id = '',
		public readonly array $variants = array(),
		public readonly array $all_colors = array(),
		public readonly string $color_a = '',
		public readonly string $color_b = '',
		public readonly string $environment = '',
		public readonly bool $can_edit = false,
		public readonly bool $colors_editable = true,
		public readonly bool $environment_editable = true
	) {}

	/**
	 * Build a DTO from a decoded API JSON object.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function from_array( array $payload ): self {
		// `to_array()` writes the full list as `all_colors` and the shown ones as
		// `colors`; the API writes the full list as `colors`. Read whichever
		// carries the full list, so that a round trip loses nothing.
		$source = isset( $payload['all_colors'] ) && is_array( $payload['all_colors'] ) && array() !== $payload['all_colors']
			? $payload['all_colors']
			: ( $payload['colors'] ?? array() );
		$all    = self::all_colors( $source );

		return new self(
			embed_id:      Cast::str( $payload, 'embed_id' ),
			row_number:    Cast::int( $payload, 'row_number' ),
			product_name:  Cast::str( $payload, 'product_name' ),
			brand_name:    Cast::str( $payload, 'brand_name' ),
			image:         Cast::str( $payload, 'image' ),
			embed_url:     Cast::str( $payload, 'embed_url' ),
			embedder_url:  Cast::str( $payload, 'embedder_url' ),
			created:       Cast::str( $payload, 'created' ),
			modified:      Cast::str( $payload, 'modified' ),
			edit_url:      Cast::str( $payload, 'edit_url' ),
			analytics_url: Cast::str( $payload, 'analytics_url' ),
			internal_note: Cast::str( $payload, 'internal_note' ),
			buybutton_url: Cast::str( $payload, 'buybutton_url' ),
			cta_url:       Cast::str( $payload, 'cta_url' ),
			views:         Cast::int( $payload, 'views' ),
			author_name:   Cast::str( $payload, 'author_name' ),
			type:          Cast::str( $payload, 'type' ),
			colors:        self::shown( $all ),
			tags:          self::tags( $payload['tags'] ?? array() ),
			product_id:    Cast::str( $payload, 'product_id' ),
			variants:      Cast::strings( $payload['variants'] ?? array() ),
			all_colors:    $all,
			color_a:       Cast::str( $payload, 'color_a' ),
			color_b:       Cast::str( $payload, 'color_b' ),
			environment:   Cast::str( $payload, 'environment' ),
			can_edit:      Cast::flag( $payload, 'can_edit', false ),
			colors_editable:      Cast::flag( $payload, 'colors_editable', true ),
			environment_editable: Cast::flag( $payload, 'environment_editable', true ),
		);
	}

	/**
	 * Convert to a plain array for REST responses. `from_array()` reads it back
	 * without loss.
	 *
	 * `colors` keeps the legacy meaning (the colours the viewer shows) because
	 * the picker and the block read it; the full list is `all_colors`.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'embed_id'      => $this->embed_id,
			'row_number'    => $this->row_number,
			'product_name'  => $this->product_name,
			'brand_name'    => $this->brand_name,
			'image'         => $this->image,
			'embed_url'     => $this->embed_url,
			'embedder_url'  => $this->embedder_url,
			'created'       => $this->created,
			'modified'      => $this->modified,
			'edit_url'      => $this->edit_url,
			'analytics_url' => $this->analytics_url,
			'internal_note' => $this->internal_note,
			'buybutton_url' => $this->buybutton_url,
			'cta_url'       => $this->cta_url,
			'views'         => $this->views,
			'author_name'   => $this->author_name,
			'type'          => $this->type,
			'colors'        => $this->colors,
			'tags'          => $this->tags,
			'product_id'    => $this->product_id,
			'variants'      => $this->variants,
			'all_colors'    => $this->all_colors,
			'color_a'       => $this->color_a,
			'color_b'       => $this->color_b,
			'environment'   => $this->environment,
			'can_edit'      => $this->can_edit,
			'colors_editable'      => $this->colors_editable,
			'environment_editable' => $this->environment_editable,
		);
	}

	/**
	 * The colours the viewer shows, with their variant ids, in display order.
	 *
	 * @return array<int, array{variant_id: string, name: string, rgb: string, enabled: bool}>
	 */
	public function shown_colors(): array {
		return array_values(
			array_filter(
				$this->all_colors,
				static fn( array $color ): bool => $color['enabled']
			)
		);
	}

	/**
	 * Format the picker label per spec:
	 * `#row_number - brand_name - product_name - created - internal_note`.
	 */
	public function picker_label(): string {
		$bits = array(
			'#' . $this->row_number,
			$this->brand_name,
			$this->product_name,
			$this->created,
			$this->internal_note,
		);
		$bits = array_map( 'trim', $bits );
		$bits = array_filter( $bits, static fn( string $bit ): bool => '' !== $bit && '#' !== $bit );

		return implode( ' - ', $bits );
	}

	/**
	 * Every colour of the product. The API lists them all and flags the shown
	 * ones `enabled`; an entry without the flag, as older payloads and
	 * hand-written ones have it, is shown.
	 *
	 * @param mixed $raw
	 * @return array<int, array{variant_id: string, name: string, rgb: string, enabled: bool}>
	 */
	private static function all_colors( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $color ) {
			if ( ! is_array( $color ) || ! isset( $color['name'] ) || ! is_string( $color['name'] ) ) {
				continue;
			}
			$out[] = array(
				'variant_id' => isset( $color['variant_id'] ) && is_string( $color['variant_id'] ) ? $color['variant_id'] : '',
				'name'       => $color['name'],
				'rgb'        => isset( $color['rgb'] ) && is_string( $color['rgb'] ) ? $color['rgb'] : '',
				'enabled'    => false !== ( $color['enabled'] ?? true ),
			);
		}
		return $out;
	}

	/**
	 * The legacy `colors` shape: the shown colours, name and rgb only.
	 *
	 * @param array<int, array{variant_id: string, name: string, rgb: string, enabled: bool}> $all
	 * @return array<int, array{name: string, rgb?: string}>
	 */
	private static function shown( array $all ): array {
		$out = array();
		foreach ( $all as $color ) {
			if ( ! $color['enabled'] ) {
				continue;
			}
			$entry = array( 'name' => $color['name'] );
			if ( '' !== $color['rgb'] ) {
				$entry['rgb'] = $color['rgb'];
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array{name: string}>
	 */
	private static function tags( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $tag ) {
			if ( ! is_array( $tag ) || ! isset( $tag['name'] ) || ! is_string( $tag['name'] ) ) {
				continue;
			}
			$out[] = array( 'name' => $tag['name'] );
		}
		return $out;
	}
}
