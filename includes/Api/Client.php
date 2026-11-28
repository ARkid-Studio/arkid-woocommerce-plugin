<?php
/**
 * HTTP client for the ARkid Ecom API.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Api;

defined( 'ABSPATH' ) || exit;

final class Client implements EmbedSource {

	private const BASE_URL = 'https://catalogue.arkid.app';
	/** Names the shop system, as the Basic auth user and as `?source=`. */
	private const SOURCE     = 'woocommerce';
	private const USER_AGENT = 'arkid-catalogue-link/' . ARKID_CATALOGUE_LINK_VERSION . ' (WordPress)';

	/** Seconds, for reading one viewer or the list of viewers. */
	private const TIMEOUT_READ = 5;
	/** Seconds, for writes and for the two heavier reads (products, statistics). */
	private const TIMEOUT_LONG = 10;

	private const PATH_EMBED       = '/api/ecom/embed';
	private const PATH_PRODUCT     = '/api/ecom/product';
	private const PATH_ENVIRONMENT = '/api/ecom/environment';
	private const PATH_STATS       = '/api/ecom/stats';

	public function __construct( private readonly string $api_key ) {}

	public function get_embed( string $embed_id ): EmbedDto {
		$id = self::path_id( $embed_id );
		if ( null === $id ) {
			throw new NotFoundException( 'Viewer not found: not a viewer id.', 404 );
		}

		return EmbedDto::from_array(
			$this->request( 'GET', self::PATH_EMBED . '/' . $id, array(), null, 'Viewer' )
		);
	}

	public function list_embeds(): array {
		$out = array();
		foreach ( $this->list_rows( self::PATH_EMBED, array(), self::TIMEOUT_READ, 'Viewers' ) as $row ) {
			$out[] = EmbedDto::from_array( $row );
		}
		return $out;
	}

	public function probe(): bool {
		$this->request( 'GET', self::PATH_EMBED, array(), null, 'Viewers' );
		return true;
	}

	public function create_embed( EmbedInput $input ): EmbedDto {
		$this->assert_valid( $input->errors( true ) );

		return EmbedDto::from_array(
			$this->request( 'POST', self::PATH_EMBED, array(), $input->to_body( true ), 'Product', self::TIMEOUT_LONG )
		);
	}

	public function update_embed( string $embed_id, EmbedInput $changes ): EmbedDto {
		$id = self::path_id( $embed_id );
		if ( null === $id ) {
			throw new NotFoundException( 'Viewer not found: not a viewer id.', 404 );
		}
		$this->assert_valid( $changes->errors( false ) );

		return EmbedDto::from_array(
			$this->request( 'PUT', self::PATH_EMBED . '/' . $id, array(), $changes->to_body( false ), 'Viewer', self::TIMEOUT_LONG )
		);
	}

	public function list_products(): array {
		$out = array();
		foreach ( $this->list_rows( self::PATH_PRODUCT, array(), self::TIMEOUT_LONG, 'Products' ) as $row ) {
			$out[] = CatalogProductDto::from_array( $row );
		}
		return $out;
	}

	public function list_environments( string $locale = '' ): array {
		$query = array();
		$lang  = self::api_language( $locale );
		if ( '' !== $lang ) {
			$query['lang'] = $lang;
		}

		$out = array();
		foreach ( $this->list_rows( self::PATH_ENVIRONMENT, $query, self::TIMEOUT_READ, 'Environments' ) as $row ) {
			$out[] = EnvironmentDto::from_array( $row );
		}
		return $out;
	}

	public function stats( int $days = StatsDto::DEFAULT_PERIOD ): StatsDto {
		return StatsDto::from_array(
			$this->request(
				'GET',
				self::PATH_STATS,
				array( 'days' => StatsDto::normalize_period( $days ) ),
				null,
				'Statistics',
				self::TIMEOUT_LONG
			)
		);
	}

	/**
	 * Refuse a write whose input fails the plugin's own check of ARkid's rules,
	 * with the exception ARkid's own 422 would give.
	 *
	 * @param array<string, string> $errors The problems, by field.
	 *
	 * @throws ValidationException When there is any.
	 */
	private function assert_valid( array $errors ): void {
		if ( array() === $errors ) {
			return;
		}
		$invalid = ValidationException::for_fields( $errors );
		throw $invalid;
	}

	/**
	 * The language code the API takes for a WordPress locale: `de_DE` and
	 * `de-AT` give `de`. Anything that is not two or three letters up front
	 * gives '' (no `lang` parameter), so a locale can never carry anything else
	 * into the query.
	 */
	public static function api_language( string $locale ): string {
		$parts    = preg_split( '/[_-]/', trim( $locale ), 2 );
		$language = strtolower( false === $parts ? '' : $parts[0] );

		return 1 === preg_match( '/^[a-z]{2,3}$/D', $language ) ? $language : '';
	}

	/**
	 * A viewer or product id as one safe URL path segment, or null when it
	 * cannot be one (empty, too long, a slash, a dot, a space ...). Ids are
	 * UUIDs, or the 22-character base64url form of viewer URLs.
	 */
	private static function path_id( string $id ): ?string {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $id ) ? rawurlencode( $id ) : null;
	}

	/**
	 * The `data` rows of a list endpoint. Rows that are not objects are skipped.
	 *
	 * @param array<string, string|int> $query
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws ApiException
	 */
	private function list_rows( string $path, array $query, int $timeout, string $what ): array {
		return Cast::rows( $this->request( 'GET', $path, $query, null, $what, $timeout )['data'] ?? array() );
	}

	/**
	 * Build the URL of a request. The query is encoded here, value by value:
	 * `add_query_arg()` encodes only the query string it is given, never the
	 * values passed in its arguments. `source` always comes first and cannot be
	 * overridden.
	 *
	 * @param array<string, string|int> $query
	 */
	private static function url( string $path, array $query ): string {
		$query = array( 'source' => self::SOURCE ) + $query;

		return self::BASE_URL . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @param array<string, string|int> $query
	 * @param array<string, mixed>|null $body    Sent as JSON; null sends no body.
	 * @param string                    $what    What a 404 would mean not to exist, for its message.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AuthException      With no API key, or when ARkid rejects it.
	 * @throws TransportException When the request fails or the answer cannot be read.
	 */
	private function request( string $method, string $path, array $query, ?array $body, string $what, int $timeout = self::TIMEOUT_READ ): array {
		if ( '' === $this->api_key ) {
			throw new AuthException( 'Missing API key.' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			// A redirect after a write would be followed as a body-less GET.
			'redirection' => null === $body ? 2 : 0,
			'user-agent'  => self::USER_AGENT,
			'headers'     => array(
				'Accept'        => 'application/json',
				// HTTP Basic auth header — encoding the credentials is not obfuscation.
				'Authorization' => 'Basic ' . base64_encode( self::SOURCE . ':' . $this->api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		);

		if ( null !== $body ) {
			$json = wp_json_encode( array() === $body ? new \stdClass() : $body );
			if ( false === $json ) {
				throw new TransportException( 'Could not encode the request body.' );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $json;
		}

		$url      = self::url( $path, $query );
		$response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new TransportException( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( $status >= 200 && $status < 300 ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				throw new TransportException( 'ARkid API returned a non-JSON body.' );
			}
			/** @var array<string, mixed> $decoded */
			return $decoded;
		}

		$failure = $this->failure( $method, $status, $raw, $what, $response );
		throw $failure;
	}

	/**
	 * Say what a failed answer means.
	 *
	 * ARkid reports a problem as `{"errors": {"field": "message"}}`. The status
	 * alone does not say whether the merchant has to look at the API key: a bad
	 * key and a refused action are both 403, and a plan limit or a rate limit
	 * must not read as "check your API key".
	 *
	 * @param array<string, mixed>|\WP_Error $response The response, for its headers.
	 */
	private function failure( string $method, int $status, string $raw, string $what, $response ): ApiException {
		$errors = self::error_fields( $raw );

		if ( self::is_key_rejection( $method, $status, $errors ) ) {
			// 401 is the canonical HTTP Basic rejection. Treating it as a
			// transport error made a plainly bad key surface as "could not
			// reach the API" and made the cron retry it as if it were a blip.
			return new AuthException( esc_html( sprintf( 'ARkid API rejected the request (HTTP %d).', $status ) ), $status );
		}
		if ( 404 === $status ) {
			return new NotFoundException( esc_html( sprintf( '%s not found (HTTP 404).', $what ) ), 404 );
		}
		if ( 400 === $status || 422 === $status ) {
			return ValidationException::for_fields( $errors, $status );
		}
		if ( 402 === $status || 403 === $status || 429 === $status ) {
			$retry = 429 === $status ? self::retry_after( $response ) : 0;

			return new RefusedException(
				esc_html( sprintf( 'ARkid refused the request (HTTP %d).', $status ) ),
				$errors,
				$status,
				$retry
			);
		}

		return new TransportException( esc_html( sprintf( 'Unexpected ARkid API status %d.', $status ) ), $status );
	}

	/**
	 * Whether a 401 / 403 is about the API key itself.
	 *
	 * ARkid answers a missing, unknown or revoked key with 403 and
	 * `{"errors": {"auth": "Missing or invalid API key."}}`. The same 403 with
	 * "The user of this API key may not do this." means the key is fine and the
	 * action is not allowed (a read-only key creating a viewer, or a viewer the
	 * key may not change). A read can only fail on the key. A 403 without any
	 * explanation, from a gateway or an older API, has always been read as a bad
	 * key and still is.
	 *
	 * @param array<string, string> $errors
	 */
	private static function is_key_rejection( string $method, int $status, array $errors ): bool {
		if ( 401 === $status ) {
			return true;
		}
		if ( 403 !== $status ) {
			return false;
		}
		if ( array() === $errors ) {
			return true;
		}
		if ( ! isset( $errors['auth'] ) ) {
			return false;
		}

		return 'GET' === $method || false !== stripos( $errors['auth'], 'invalid API key' );
	}

	/**
	 * The `errors` object of an error body, as field => message. Values that
	 * are not text are dropped and long ones cut, so what a caller shows is
	 * bounded. The text is kept as received: escape it where it is output.
	 *
	 * @return array<string, string>
	 */
	private static function error_fields( string $raw ): array {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded['errors'] as $field => $message ) {
			if ( is_scalar( $message ) && '' !== (string) $message ) {
				$out[ substr( (string) $field, 0, 64 ) ] = mb_substr( (string) $message, 0, 500 );
			}
		}
		return $out;
	}

	/**
	 * Seconds a 429 asks to wait, from `Retry-After`, up to an hour. 0 when it
	 * did not say (or said a date).
	 *
	 * @param array<string, mixed>|\WP_Error $response
	 */
	private static function retry_after( $response ): int {
		$value = wp_remote_retrieve_header( $response, 'retry-after' );

		return is_string( $value ) && ctype_digit( $value ) ? min( (int) $value, HOUR_IN_SECONDS ) : 0;
	}
}
