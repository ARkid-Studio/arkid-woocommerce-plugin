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
	/** What ARkid last said about the linked viewer. See `STATUS_*`; absent means nothing is known against it. */
	public const KEY_STATUS = '_arkid_embed_status';

	/**
	 * ARkid answered 404 for the linked viewer: it was deleted, or belongs to
	 * another account. Set only by a live answer (the refresh job or a save),
	 * never derived from a list, so a viewer created a minute ago cannot be
	 * mistaken for a missing one. Cleared by a link, an unlink and a refresh
	 * that finds the viewer.
	 */
	public const STATUS_MISSING = 'missing';

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

		register_post_meta(
			self::POST_TYPE,
			self::KEY_STATUS,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_status' ),
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

	public static function sanitize_status( mixed $value ): string {
		return self::STATUS_MISSING === $value ? self::STATUS_MISSING : '';
	}

	/**
	 * Persist the link and the position override for a product.
	 *
	 * Pass null `$embed` to clear the link (id, URL and image). The position
	 * override is the merchant's own setting, not part of the link, so it is
	 * written either way: an empty `$position` means "use the site default" and
	 * removes it, any other value is kept. Unlinking therefore keeps a deliberate
	 * "Above product" or "Button only" choice for the next viewer.
	 *
	 * A link or an unlink always clears the status: it described the viewer that
	 * was linked before.
	 */
	public static function write( int $product_id, ?EmbedDto $embed, string $position ): void {
		self::write_position( $product_id, $position );

		if ( null === $embed ) {
			delete_post_meta( $product_id, self::KEY_EMBED_ID );
			delete_post_meta( $product_id, self::KEY_EMBED_URL );
			delete_post_meta( $product_id, self::KEY_EMBED_IMAGE );
			delete_post_meta( $product_id, self::KEY_STATUS );
			return;
		}

		update_post_meta( $product_id, self::KEY_EMBED_ID, $embed->embed_id );
		update_post_meta( $product_id, self::KEY_EMBED_URL, $embed->embed_url );
		update_post_meta( $product_id, self::KEY_EMBED_IMAGE, $embed->image );
		delete_post_meta( $product_id, self::KEY_STATUS );
	}

	/**
	 * ARkid says the linked viewer does not exist. Stop rendering it (drop the
	 * snapshot) and remember why, but keep the selection and the position, so
	 * the merchant can see what was chosen and so it heals by itself if ARkid
	 * restores the viewer.
	 */
	public static function mark_missing( int $product_id ): void {
		delete_post_meta( $product_id, self::KEY_EMBED_URL );
		delete_post_meta( $product_id, self::KEY_EMBED_IMAGE );
		update_post_meta( $product_id, self::KEY_STATUS, self::STATUS_MISSING );
	}

	/**
	 * Forget what ARkid said about the viewer that was linked before. For a save
	 * that links a different viewer without having been able to ask about it.
	 */
	public static function clear_status( int $product_id ): void {
		delete_post_meta( $product_id, self::KEY_STATUS );
	}

	/**
	 * Store the per-product position override. An empty or unknown value removes it.
	 */
	public static function write_position( int $product_id, string $position ): void {
		$position = self::sanitize_position( $position );

		if ( '' === $position ) {
			delete_post_meta( $product_id, self::KEY_POSITION );
			return;
		}

		update_post_meta( $product_id, self::KEY_POSITION, $position );
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

	/**
	 * `STATUS_MISSING`, or '' when ARkid has not said anything against the link.
	 */
	public static function status( int $product_id ): string {
		return self::sanitize_status( get_post_meta( $product_id, self::KEY_STATUS, true ) );
	}
}
