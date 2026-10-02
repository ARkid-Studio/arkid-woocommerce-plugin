<?php
/**
 * Per-product post meta keys.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink;

use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class PostMeta {

	public const KEY_EMBED_ID    = '_arkid_embed_id';
	public const KEY_POSITION    = '_arkid_embed_position';
	public const KEY_EMBED_URL   = '_arkid_embed_url';
	public const KEY_EMBED_IMAGE = '_arkid_embed_image';

	public const POST_TYPE = 'product';

	public function register(): void {
		add_action( 'init', array( $this, 'register_meta_keys' ) );
	}

	public function register_meta_keys(): void {
		$auth = static fn(
			bool $allowed,
			string $meta_key,
			int $object_id
		): bool => current_user_can( 'edit_post', $object_id );

		register_post_meta(
			self::POST_TYPE,
			self::KEY_EMBED_ID,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_embed_id' ),
				'auth_callback'     => $auth,
				'default'           => '',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::KEY_POSITION,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_position' ),
				'auth_callback'     => $auth,
				'default'           => '',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::KEY_EMBED_URL,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'default'           => '',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::KEY_EMBED_IMAGE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'default'           => '',
			)
		);
	}

	public static function sanitize_embed_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return preg_match( '/^[a-z0-9-]{1,64}$/i', $value ) === 1 ? $value : '';
	}

	public static function sanitize_position( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return in_array( $value, Options::positions(), true ) ? $value : '';
	}

	/**
	 * Persist all four meta keys for a product. Pass null `$embed` to clear.
	 */
	public static function write( int $product_id, ?EmbedDto $embed, string $position ): void {
		$position = self::sanitize_position( $position );

		if ( null === $embed ) {
			delete_post_meta( $product_id, self::KEY_EMBED_ID );
			delete_post_meta( $product_id, self::KEY_POSITION );
			delete_post_meta( $product_id, self::KEY_EMBED_URL );
			delete_post_meta( $product_id, self::KEY_EMBED_IMAGE );
			return;
		}

		update_post_meta( $product_id, self::KEY_EMBED_ID, $embed->embed_id );
		update_post_meta( $product_id, self::KEY_EMBED_URL, $embed->embed_url );
		update_post_meta( $product_id, self::KEY_EMBED_IMAGE, $embed->image );
		if ( '' === $position ) {
			delete_post_meta( $product_id, self::KEY_POSITION );
		} else {
			update_post_meta( $product_id, self::KEY_POSITION, $position );
		}
	}

	public static function embed_id( int $product_id ): string {
		$value = get_post_meta( $product_id, self::KEY_EMBED_ID, true );
		return is_string( $value ) ? $value : '';
	}

	public static function position( int $product_id ): string {
		$value = get_post_meta( $product_id, self::KEY_POSITION, true );
		return is_string( $value ) ? $value : '';
	}

	public static function embed_url( int $product_id ): string {
		$value = get_post_meta( $product_id, self::KEY_EMBED_URL, true );
		return is_string( $value ) ? $value : '';
	}

	public static function embed_image( int $product_id ): string {
		$value = get_post_meta( $product_id, self::KEY_EMBED_IMAGE, true );
		return is_string( $value ) ? $value : '';
	}
}
