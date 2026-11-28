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
 * look like a real catalogue. It answers the statistics call of the viewers
 * screen too, from the viewers' own view counts. The payload is
 * arkid-assets-api-stub.json, written next to this file by
 * tests/store-assets/global-setup.js from tests/fixtures/api/embed-list.json.
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
		$prefix       = 'https://catalogue.arkid.app/api/ecom/embed';
		$stats_prefix = 'https://catalogue.arkid.app/api/ecom/stats';
		$is_stats     = str_starts_with( (string) $url, $stats_prefix );
		if ( ! $is_stats && ! str_starts_with( (string) $url, $prefix ) ) {
			return $preempt;
		}

		$raw    = file_get_contents( __DIR__ . '/arkid-assets-api-stub.json' );
		$embeds = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $embeds ) ) {
			return $preempt;
		}

		// Only reads are recorded. Answer a write the way ARkid answers a method it
		// does not offer, instead of handing back the list.
		if ( 'GET' !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
			return array(
				'headers'  => array( 'allow' => 'GET, HEAD' ),
				'body'     => (string) wp_json_encode( array( 'errors' => array( 'method' => 'Allowed: GET, HEAD.' ) ) ),
				'response' => array(
					'code'    => 405,
					'message' => 'Method Not Allowed',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// The viewers screen asks for the traffic of the last 30 days. Answer it from
		// the viewers themselves, as ARkid's own demo answer does: the busier the
		// viewer, the more interactions, so the screenshot reads like a live account.
		if ( $is_stats ) {
			$traffic = array();
			foreach ( $embeds as $embed ) {
				$views = is_array( $embed ) ? (int) ( $embed['views'] ?? 0 ) : 0;
				if ( $views > 0 ) {
					$traffic[] = array(
						'embed_id'     => (string) $embed['embed_id'],
						'impressions'  => $views,
						'interactions' => intdiv( $views * 2, 5 ),
					);
				}
			}
			$series = array(
				'total'         => 0,
				'before'        => 0,
				'series'        => array(),
				'before_series' => array(),
			);

			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'days'                    => 30,
						'dates'                   => array(),
						'impressions'             => $series,
						'interactions'            => $series,
						'ar'                      => $series,
						'avg_interaction_seconds' => 0,
						'embeds'                  => $traffic,
						'unused_brands'           => array(),
						'latest_products'         => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$id   = rawurldecode( substr( $path, strlen( '/api/ecom/embed' ) ) );
		$id   = ltrim( $id, '/' );

		$status = 200;
		if ( '' === $id ) {
			$body = array( 'data' => $embeds );
		} else {
			$body   = array( 'errors' => array( 'embed_id' => 'Not found.' ) );
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
