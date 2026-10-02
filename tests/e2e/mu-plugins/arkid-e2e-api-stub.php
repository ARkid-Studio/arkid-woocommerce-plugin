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
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( ! str_contains( (string) $url, 'catalogue.arkid.app' ) ) {
			return $preempt;
		}

		$embed = array(
			'embed_id'     => 'e2e-viewer',
			'row_number'   => 1,
			'product_name' => 'E2E Viewer',
			'brand_name'   => 'ARkid',
			'image'        => home_url( '/wp-content/uploads/e2e-thumb.png' ),
			// Points back at this site so the iframe genuinely loads and its
			// sandbox/allow attributes are exercised, with no outbound traffic.
			'embed_url'    => 'https://catalogue.arkid.app/e/e2e-viewer',
			'created'      => '2026-01-01',
			'type'         => 'model',
		);

		$body = str_contains( (string) $url, '/embed/' )
			? $embed
			: array( 'data' => array( $embed ) );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
