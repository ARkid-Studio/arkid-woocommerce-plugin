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
	 * @param array<int, array{name: string, rgb?: string}> $colors
	 * @param array<int, array{name: string}>               $tags
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
		public readonly array $tags
	) {}

	/**
	 * Build a DTO from a decoded API JSON object.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function from_array( array $payload ): self {
		return new self(
			embed_id:      self::str( $payload, 'embed_id' ),
			row_number:    self::int( $payload, 'row_number' ),
			product_name:  self::str( $payload, 'product_name' ),
			brand_name:    self::str( $payload, 'brand_name' ),
			image:         self::str( $payload, 'image' ),
			embed_url:     self::str( $payload, 'embed_url' ),
			embedder_url:  self::str( $payload, 'embedder_url' ),
			created:       self::str( $payload, 'created' ),
			modified:      self::str( $payload, 'modified' ),
			edit_url:      self::str( $payload, 'edit_url' ),
			analytics_url: self::str( $payload, 'analytics_url' ),
			internal_note: self::str( $payload, 'internal_note' ),
			buybutton_url: self::str( $payload, 'buybutton_url' ),
			cta_url:       self::str( $payload, 'cta_url' ),
			views:         self::int( $payload, 'views' ),
			author_name:   self::str( $payload, 'author_name' ),
			type:          self::str( $payload, 'type' ),
			colors:        self::colors( $payload['colors'] ?? array() ),
			tags:          self::tags( $payload['tags'] ?? array() ),
		);
	}

	/**
	 * Convert to a plain array for caching / REST responses.
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
	 * @param array<string, mixed> $payload
	 */
	private static function str( array $payload, string $key ): string {
		$value = $payload[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function int( array $payload, string $key ): int {
		$value = $payload[ $key ] ?? 0;
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array{name: string, rgb?: string}>
	 */
	private static function colors( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $color ) {
			if ( ! is_array( $color ) || ! isset( $color['name'] ) || ! is_string( $color['name'] ) ) {
				continue;
			}
			$entry = array( 'name' => $color['name'] );
			if ( isset( $color['rgb'] ) && is_string( $color['rgb'] ) ) {
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
