<?php
/**
 * HTTP client for the ARkid catalogue API.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class Client implements EmbedSource {

	private const BASE_URL    = 'https://catalogue.arkid.app';
	private const AUTH_USER   = 'woocommerce';
	private const TIMEOUT     = 5;
	private const USER_AGENT  = 'arkid-catalogue-link/' . ARKID_CATALOGUE_LINK_VERSION . ' (WordPress)';

	public function __construct( private readonly string $api_key ) {}

	/**
	 * Fetch a single embed by ID.
	 *
	 * @throws AuthException     on 401 / 403.
	 * @throws NotFoundException on 404.
	 * @throws TransportException on network / 5xx / parse error.
	 */
	public function get_embed( string $embed_id ): EmbedDto {
		return EmbedDto::from_array(
			$this->request( '/api/ecom/embed/' . rawurlencode( $embed_id ) )
		);
	}

	/**
	 * Fetch all embeds. Returns a list, possibly empty.
	 *
	 * @return array<int, EmbedDto>
	 *
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function list_embeds(): array {
		$payload = $this->request( '/api/ecom/embed' );
		if ( ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $payload['data'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				/** @var array<string, mixed> $row */
				$out[] = EmbedDto::from_array( $row );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return $out;
	}

	/**
	 * Quick connectivity / auth probe used by the Settings page.
	 *
	 * @return true on success.
	 *
	 * @throws AuthException
	 * @throws TransportException
	 */
	public function probe(): bool {
		$this->request( '/api/ecom/embed' );
		return true;
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws AuthException
	 * @throws NotFoundException
	 * @throws TransportException
	 */
	private function request( string $path ): array {
		if ( '' === $this->api_key ) {
			throw new AuthException( 'Missing API key.' );
		}

		$url = self::BASE_URL . $path;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'user-agent'  => self::USER_AGENT,
				'headers'     => array(
					'Accept'        => 'application/json',
					// HTTP Basic auth header — encoding the credentials is not obfuscation.
					'Authorization' => 'Basic ' . base64_encode( self::AUTH_USER . ':' . $this->api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TransportException( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( 401 === $status || 403 === $status ) {
			// 401 is the canonical HTTP Basic rejection. Treating it as a
			// transport error made a plainly bad key surface as "could not
			// reach the API" and made the cron retry it as if it were a blip.
			throw new AuthException(
				esc_html( sprintf( 'ARkid API rejected the request (HTTP %d).', $status ) )
			);
		}
		if ( 404 === $status ) {
			throw new NotFoundException( 'Embed not found (HTTP 404).' );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new TransportException( esc_html( sprintf( 'Unexpected ARkid API status %d.', $status ) ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new TransportException( 'ARkid API returned a non-JSON body.' );
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}
}
