<?php
/**
 * Internal REST proxy: keeps the API key on the server.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Rest;

use Arkid\CatalogueLink\Api\ApiException;
use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Api\EmbedSource;
use Arkid\CatalogueLink\Api\NotFoundException;

defined( 'ABSPATH' ) || exit;

class Controller {

	public const NAMESPACE = 'arkid-catalogue-link/v1';

	public function __construct( private readonly ClientFactory $api ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/embeds',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_embeds' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/embeds/(?P<id>[A-Za-z0-9-]{1,64})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_embed' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function check_admin(): bool|\WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new \WP_Error(
			'arkid_catalogue_link_forbidden',
			__( 'You do not have permission to access the ARkid Catalogue Link API.', 'arkid-catalogue-link' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function list_embeds( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client = $this->client();
		if ( $client instanceof \WP_Error ) {
			return $client;
		}

		try {
			$embeds = $client->list_embeds();
		} catch ( AuthException $e ) {
			return new \WP_Error(
				'arkid_catalogue_link_auth',
				esc_html( $e->getMessage() ),
				array( 'status' => 401 )
			);
		} catch ( ApiException $e ) {
			return new \WP_Error(
				'arkid_catalogue_link_transport',
				esc_html( $e->getMessage() ),
				array( 'status' => 502 )
			);
		}

		$search_param = $request->get_param( 'search' );
		$search       = is_string( $search_param ) ? strtolower( $search_param ) : '';
		if ( '' !== $search ) {
			$embeds = array_values(
				array_filter(
					$embeds,
					static function ( EmbedDto $e ) use ( $search ): bool {
						$label = strtolower( $e->picker_label() );
						// An empty label can't contain a non-empty search term.
						return '' !== $label && str_contains( $label, $search );
					}
				)
			);
		}

		$payload = array_map(
			static fn( EmbedDto $e ): array => array(
				'value' => $e->embed_id,
				'label' => $e->picker_label(),
				'embed' => $e->to_array(),
			),
			$embeds
		);

		return new \WP_REST_Response( $payload );
	}

	public function get_embed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client = $this->client();
		if ( $client instanceof \WP_Error ) {
			return $client;
		}

		$id_param = $request->get_param( 'id' );
		$embed_id = is_string( $id_param ) ? $id_param : '';

		try {
			$embed = $client->get_embed( $embed_id );
		} catch ( AuthException $e ) {
			return new \WP_Error(
				'arkid_catalogue_link_auth',
				esc_html( $e->getMessage() ),
				array( 'status' => 401 )
			);
		} catch ( NotFoundException $e ) {
			return new \WP_Error(
				'arkid_catalogue_link_not_found',
				esc_html( $e->getMessage() ),
				array( 'status' => 404 )
			);
		} catch ( ApiException $e ) {
			return new \WP_Error(
				'arkid_catalogue_link_transport',
				esc_html( $e->getMessage() ),
				array( 'status' => 502 )
			);
		}

		return new \WP_REST_Response( $embed->to_array() );
	}

	private function client(): EmbedSource|\WP_Error {
		$client = $this->api->create();
		if ( null === $client ) {
			return new \WP_Error(
				'arkid_catalogue_link_missing_key',
				__( 'No ARkid API key configured. Set one under WooCommerce → Settings → Integration → ARkid Catalogue Link.', 'arkid-catalogue-link' ),
				array( 'status' => 412 )
			);
		}
		return $client;
	}
}
