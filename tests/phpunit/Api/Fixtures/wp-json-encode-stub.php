<?php

declare( strict_types = 1 );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only stub for the WP function.
		return (string) json_encode( $data );
	}
}
