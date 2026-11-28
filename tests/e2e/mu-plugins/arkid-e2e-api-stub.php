<?php
/**
 * Plugin Name: ARkid E2E API stub
 *
 * Stubs the ARkid catalogue API for end-to-end runs.
 *
 * This has to live in WordPress rather than in Playwright: the calls it
 * intercepts are server-side `wp_remote_get()` requests, which page.route()
 * cannot see. Installed by tests/e2e/global-setup.js, removed by
 * tests/e2e/global-teardown.js.
 *
 * It answers by method AND path, the way ARkid's Ecom API does, so a write is
 * never mistaken for the list of viewers:
 *
 *   GET  /embed, /embed/{id}, /product, /environment, /stats
 *   POST /embed          (201; JSON body required, else 415; `product_id` required, else 422)
 *   PUT  /embed/{id}     (200, the viewer with the body applied; JSON body required)
 *   anything else        405 with an `Allow` header
 *
 * Scenarios are chosen by the id in the path, so a spec needs no extra state:
 *
 *   an id ending `-gone`      404, as for a viewer that was deleted
 *   an id ending `-down`      503, as for an outage
 *   an id ending `-readonly`  403 on a PUT, as for a viewer the key may not change
 *
 * and by the key: `bad-key` is answered 403 with ARkid's "Missing or invalid API
 * key.", which is how the real API rejects a key (it does not send a 401).
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( ! str_contains( (string) $url, 'catalogue.arkid.app' ) ) {
			return $preempt;
		}

		$reply = static function ( int $status, array $body, array $headers = array() ): array {
			return array(
				'headers'  => $headers,
				'body'     => (string) wp_json_encode( $body ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$path   = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( 1 !== preg_match( '#^/api/ecom/(embed|product|environment|stats)(?:/([^/]+))?$#', $path, $m ) ) {
			return $reply( 404, array( 'errors' => array( 'path' => 'Not found.' ) ) );
		}
		$endpoint = $m[1];
		$id       = isset( $m[2] ) ? rawurldecode( $m[2] ) : '';

		// ARkid answers a bad key with 403 and this body.
		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$auth    = (string) ( $headers['Authorization'] ?? '' );
		$decoded = base64_decode( substr( $auth, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$secret  = explode( ':', is_string( $decoded ) ? $decoded : '', 2 )[1] ?? '';
		if ( 'bad-key' === $secret ) {
			return $reply( 403, array( 'errors' => array( 'auth' => 'Missing or invalid API key.' ) ) );
		}

		$embed = static function ( string $embed_id ): array {
			return array(
				'embed_id'             => $embed_id,
				'row_number'           => 1,
				'product_id'           => '761023ba-4d2e-11f1-fc62-e0c83dd5547a',
				'product_name'         => 'E2E Viewer',
				'brand_name'           => 'ARkid',
				'image'                => home_url( '/wp-content/uploads/e2e-thumb.png' ),
				// Points back at this site so the iframe genuinely loads and its
				// sandbox/allow attributes are exercised, with no outbound traffic.
				'embed_url'            => 'https://catalogue.arkid.app/e/e2e-viewer',
				'embedder_url'         => '',
				'created'              => '2026-01-01',
				'modified'             => '2026-01-01',
				'internal_note'        => '',
				'type'                 => 'model',
				'colors'               => array(
					array(
						'variant_id' => '887054d4-4d59-11f1-45ee-903d874f4ba7',
						'name'       => 'Copper Terra',
						'rgb'        => '#b55f39',
						'enabled'    => true,
					),
					array(
						'variant_id' => '8dae23b3-4d59-11f1-88ee-7e3b4ee7b7d2',
						'name'       => 'Moon Graphite',
						'rgb'        => '#929498',
						'enabled'    => false,
					),
				),
				'variants'             => array( '887054d4-4d59-11f1-45ee-903d874f4ba7' ),
				'color_a'              => '#ffffff',
				'color_b'              => '#ededed',
				'environment'          => 'env9',
				'can_edit'             => true,
				'colors_editable'      => true,
				'environment_editable' => true,
				'tags'                 => array(),
			);
		};

		$is_json = static function () use ( $args ): bool {
			$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
			return 'application/json' === strtolower( trim( explode( ';', (string) ( $headers['Content-Type'] ?? '' ) )[0] ) );
		};

		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$body = is_array( $body ) ? $body : array();

		if ( 'embed' === $endpoint ) {
			if ( '' !== $id && str_ends_with( $id, '-gone' ) ) {
				return $reply( 404, array( 'errors' => array( 'embed_id' => 'Not found.' ) ) );
			}
			if ( '' !== $id && str_ends_with( $id, '-down' ) ) {
				return $reply( 503, array( 'errors' => array( 'server' => 'Unavailable.' ) ) );
			}

			if ( 'GET' === $method && '' === $id ) {
				return $reply( 200, array( 'data' => array( $embed( 'e2e-viewer' ) ) ) );
			}
			if ( 'GET' === $method ) {
				return $reply( 200, $embed( $id ) );
			}
			if ( 'POST' === $method && '' === $id ) {
				if ( ! $is_json() ) {
					return $reply( 415, array( 'errors' => array( 'body' => 'Send the body as Content-Type: application/json.' ) ) );
				}
				if ( ! isset( $body['product_id'] ) ) {
					return $reply( 422, array( 'errors' => array( 'product_id' => 'Required: the product_id of a product the API lists.' ) ) );
				}
				return $reply( 201, array_merge( $embed( 'e2e-created' ), array_intersect_key( $body, $embed( '' ) ) ) );
			}
			if ( 'PUT' === $method && '' !== $id ) {
				if ( str_ends_with( $id, '-readonly' ) ) {
					return $reply( 403, array( 'errors' => array( 'auth' => 'The user of this API key may not do this.' ) ) );
				}
				if ( ! $is_json() ) {
					return $reply( 415, array( 'errors' => array( 'body' => 'Send the body as Content-Type: application/json.' ) ) );
				}
				return $reply( 200, array_merge( $embed( $id ), array_intersect_key( $body, $embed( '' ) ) ) );
			}

			$allow = '' === $id ? 'GET, HEAD, POST' : 'GET, HEAD, PUT';
			return $reply( 405, array( 'errors' => array( 'method' => 'Allowed: ' . $allow . '.' ) ), array( 'allow' => $allow ) );
		}

		if ( 'GET' !== $method ) {
			return $reply( 405, array( 'errors' => array( 'method' => 'Allowed: GET, HEAD.' ) ), array( 'allow' => 'GET, HEAD' ) );
		}
		if ( '' !== $id ) {
			return $reply( 404, array( 'errors' => array( $endpoint => 'Not found.' ) ) );
		}

		if ( 'product' === $endpoint ) {
			return $reply(
				200,
				array(
					'data' => array(
						array(
							'product_id'           => '761023ba-4d2e-11f1-fc62-e0c83dd5547a',
							'name'                 => 'E2E Product',
							'brand_name'           => 'ARkid',
							'type'                 => 'pdp',
							'image'                => home_url( '/wp-content/uploads/e2e-thumb.png' ),
							'variants'             => array(
								array(
									'variant_id' => '887054d4-4d59-11f1-45ee-903d874f4ba7',
									'name'       => 'Copper Terra',
									'rgb'        => '#b55f39',
								),
							),
							'colors_editable'      => true,
							'environment_editable' => true,
							'color_a'              => '#ffffff',
							'color_b'              => '#ededed',
							'environment'          => 'env9',
						),
					),
				)
			);
		}

		if ( 'environment' === $endpoint ) {
			return $reply(
				200,
				array(
					'data' => array(
						array(
							'environment' => 'env9',
							'name'        => 'Floor',
							'group'       => '3D Environments',
						),
						array(
							'environment' => 'env0',
							'name'        => 'None - Transparent Background',
							'group'       => 'No Environment',
						),
					),
				)
			);
		}

		// stats.
		$days = 7;
		return $reply(
			200,
			array(
				'days'                    => $days,
				'dates'                   => array( '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07' ),
				'impressions'             => array(
					'total'         => 70,
					'before'        => 35,
					'series'        => array( 10, 10, 10, 10, 10, 10, 10 ),
					'before_series' => array( 5, 5, 5, 5, 5, 5, 5 ),
				),
				'interactions'            => array(
					'total'         => 14,
					'before'        => 7,
					'series'        => array( 2, 2, 2, 2, 2, 2, 2 ),
					'before_series' => array( 1, 1, 1, 1, 1, 1, 1 ),
				),
				'ar'                      => array(
					'total'         => 7,
					'before'        => 0,
					'series'        => array( 1, 1, 1, 1, 1, 1, 1 ),
					'before_series' => array( 0, 0, 0, 0, 0, 0, 0 ),
				),
				'avg_interaction_seconds' => 12.5,
				'avg_ar_seconds'          => 30.0,
				'embeds'                  => array(
					array(
						'embed_id'     => 'e2e-viewer',
						'impressions'  => 70,
						'interactions' => 14,
					),
				),
				'unused_brands'           => array(),
				'latest_products'         => array(),
			)
		);
	},
	10,
	3
);
