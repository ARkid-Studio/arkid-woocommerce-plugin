<?php
/**
 * The id of a viewer as it stands in the address of its page.
 *
 * ARkid names a viewer by a UUID in the API and by 22 characters in the address of
 * its viewer (`/embed/CvCHjmHfEfD2pVmj9PjNPg`): the UUID in base 62, least
 * significant digit first, or in older addresses the base64url of its 16 bytes.
 * The last character tells the two apart: 0 to 7 in base 62 (the value has 128
 * bits, the 22nd digit only 3), A, Q, g or w in base64url (the last 4 bits of the
 * 22nd character are padding). A port of `embed-id.ts`, without BigInt or an
 * extension: the 128-bit number is a row of 16 bytes.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Matching;

defined( 'ABSPATH' ) || exit;

final class ViewerId {

	private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

	/**
	 * The UUID, in lower case, of the 22-character id of a viewer address, or null
	 * when `$id` is not one.
	 */
	public static function uuid( string $id ): ?string {
		$hex = self::base62_hex( $id ) ?? self::base64url_hex( $id );
		if ( null === $hex ) {
			return null;
		}

		return implode(
			'-',
			array(
				substr( $hex, 0, 8 ),
				substr( $hex, 8, 4 ),
				substr( $hex, 12, 4 ),
				substr( $hex, 16, 4 ),
				substr( $hex, 20 ),
			)
		);
	}

	/**
	 * The id of the viewer an embed code or a viewer address shows, from its
	 * `/embed/<22 characters>` path, or null.
	 */
	public static function from_code( string $code ): ?string {
		if ( 1 !== preg_match( '#/embed/([A-Za-z0-9_-]{22})(?![A-Za-z0-9_-])#', $code, $found ) ) {
			return null;
		}

		return self::uuid( $found[1] );
	}

	/**
	 * The viewer of a product's link: the one of `$embed_id`, else the one an embed
	 * code shows, for links that only kept the code.
	 *
	 * @param array<int, Candidate> $embeds The viewers of the account, by `id`.
	 */
	public static function linked( array $embeds, string $embed_id, string $embed_code = '' ): ?Candidate {
		$by_id = static function ( string $id ) use ( $embeds ): ?Candidate {
			if ( '' === $id ) {
				return null;
			}
			$wanted = strtolower( $id );
			foreach ( $embeds as $embed ) {
				if ( strtolower( $embed->id ) === $wanted ) {
					return $embed;
				}
			}

			return null;
		};

		return $by_id( $embed_id ) ?? $by_id( '' === $embed_code ? '' : (string) self::from_code( $embed_code ) );
	}

	/**
	 * The 32 hex digits of a base 62 id, or null.
	 */
	private static function base62_hex( string $id ): ?string {
		if ( 1 !== preg_match( '/^[0-9A-Za-z]{21}[0-7]$/D', $id ) ) {
			return null;
		}

		// 16 bytes, most significant first; each digit is `value * 62 + digit`.
		$bytes = array_fill( 0, 16, 0 );
		for ( $i = 21; $i >= 0; --$i ) {
			$carry = strpos( self::BASE62, $id[ $i ] );
			if ( false === $carry ) {
				return null;
			}
			for ( $j = 15; $j >= 0; --$j ) {
				$value     = $bytes[ $j ] * 62 + $carry;
				$bytes[ $j ] = $value & 0xFF;
				$carry     = $value >> 8;
			}
			if ( 0 !== $carry ) {
				// 22 digits reach beyond 128 bits.
				return null;
			}
		}

		return bin2hex( pack( 'C*', ...$bytes ) );
	}

	/**
	 * The 32 hex digits of a base64url id, or null.
	 */
	private static function base64url_hex( string $id ): ?string {
		if ( 1 !== preg_match( '/^[0-9A-Za-z_-]{21}[AQgw]$/D', $id ) ) {
			return null;
		}

		// 22 characters are 132 bits: 16 bytes and four bits of padding.
		$bytes = base64_decode( strtr( $id, '-_', '+/' ) . '==', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the id in an address, not obfuscated code.

		return false !== $bytes && 16 === strlen( $bytes ) ? bin2hex( $bytes ) : null;
	}
}
