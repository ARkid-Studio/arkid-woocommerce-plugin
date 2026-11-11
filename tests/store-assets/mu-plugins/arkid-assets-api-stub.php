<?php
/**
 * Plugin Name: ARkid store-assets API stub
 *
 * Answers the ARkid catalogue API from a recorded payload, so the WordPress.org
 * screenshots show real viewers without needing an API key.
 *
 * Unlike tests/e2e/mu-plugins/arkid-e2e-api-stub.php this serves SEVERAL
 * viewers, and with their real names, covers and embed URLs: the e2e stub
 * exists to keep a test suite offline, this one exists to make a picture
 * look like a real catalogue. The payload is arkid-assets-api-stub.json, written
 * next to this file by tests/store-assets/global-setup.js from
 * tests/fixtures/api/embed-list.json.
 *
 * It has to live in WordPress rather than in Playwright: the calls it
 * intercepts are server-side `wp_remote_get()` requests, which page.route()
 * cannot see. Installed by tests/store-assets/global-setup.js, removed by
 * tests/store-assets/global-teardown.js.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$prefix = 'https://catalogue.arkid.app/api/ecom/embed';
		if ( ! str_starts_with( (string) $url, $prefix ) ) {
			return $preempt;
		}

		$raw    = file_get_contents( __DIR__ . '/arkid-assets-api-stub.json' );
		$embeds = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $embeds ) ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$id   = rawurldecode( substr( $path, strlen( '/api/ecom/embed' ) ) );
		$id   = ltrim( $id, '/' );

		$status = 200;
		if ( '' === $id ) {
			$body = array( 'data' => $embeds );
		} else {
			$body   = array( 'error' => 'Not found' );
			$status = 404;
			foreach ( $embeds as $embed ) {
				if ( is_array( $embed ) && ( $embed['embed_id'] ?? null ) === $id ) {
					$body   = $embed;
					$status = 200;
					break;
				}
			}
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
