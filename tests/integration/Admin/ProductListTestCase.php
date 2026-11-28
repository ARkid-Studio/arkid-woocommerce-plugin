<?php
/**
 * Shared set-up of the tests of the ARkid parts of the Products list.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Links\AccountData;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

abstract class ProductListTestCase extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Options::OPTION_KEY, array( 'api_key' => 'test-key' ) );
		set_current_screen( 'edit-product' );
		$GLOBALS['hook_suffix'] = 'edit.php';
	}

	public function tear_down(): void {
		delete_option( Options::OPTION_KEY );
		$_GET     = array();
		$_REQUEST = array();
		unset( $GLOBALS['hook_suffix'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	protected function account(): AccountData {
		return new AccountData( new ClientFactory( new Options() ) );
	}

	/**
	 * @param array<string, string> $meta
	 */
	protected function product( string $title, string $embed_id = '', array $meta = array(), string $status = 'publish' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
		if ( '' !== $embed_id ) {
			update_post_meta( $id, PostMeta::KEY_EMBED_ID, $embed_id );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * A product whose viewer is stored the way a successful link leaves it.
	 */
	protected function linked( string $title, string $embed_id ): int {
		return $this->product(
			$title,
			$embed_id,
			array(
				PostMeta::KEY_EMBED_URL   => 'https://catalogue.arkid.app/embed/' . $embed_id . '?source=woocommerce',
				PostMeta::KEY_EMBED_IMAGE => 'https://cloud.arkid.app/covers/' . $embed_id . '.jpg',
			)
		);
	}

	/**
	 * A viewer as the API lists it.
	 *
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	protected function viewer( string $embed_id, string $brand, string $name, int $row = 7, array $extra = array() ): array {
		return array_merge(
			$this->embed_payload( $embed_id, 'https://catalogue.arkid.app/embed/' . $embed_id . '?source=woocommerce' ),
			array(
				'row_number'    => $row,
				'brand_name'    => $brand,
				'product_name'  => $name,
				'edit_url'      => 'https://catalogue.arkid.app/configure/edit/' . $embed_id,
				'analytics_url' => 'https://catalogue.arkid.app/analytics/' . $embed_id,
				'colors'        => array(
					array(
						'variant_id' => 'v1',
						'name'       => 'Oak',
						'rgb'        => '#c19a6b',
						'enabled'    => true,
					),
					array(
						'variant_id' => 'v2',
						'name'       => 'Walnut',
						'rgb'        => '#5c4033',
						'enabled'    => false,
					),
				),
			),
			$extra
		);
	}

	/**
	 * A product of ARkid's catalogue as the API lists it.
	 *
	 * @return array<string, mixed>
	 */
	protected function catalog_product( string $product_id, string $brand, string $name ): array {
		return array(
			'product_id'           => $product_id,
			'name'                 => $name,
			'brand_name'           => $brand,
			'type'                 => 'pdp',
			'image'                => 'https://cloud.arkid.app/covers/' . $product_id . '.jpg',
			'variants'             => array(),
			'colors_editable'      => true,
			'environment_editable' => true,
		);
	}

	/**
	 * Answer the two lists the screen asks for.
	 *
	 * @param array<int, array<string, mixed>> $viewers
	 * @param array<int, array<string, mixed>> $catalog
	 */
	protected function stub_account( array $viewers, array $catalog = array() ): void {
		$this->stub_http( '/api/ecom/embed', array( 'data' => $viewers ) );
		$this->stub_http( '/api/ecom/product', array( 'data' => $catalog ) );
	}

	/**
	 * @return array<int, string>
	 */
	protected function calls_to( string $path ): array {
		return array_values( array_filter( $this->http_calls, static fn( string $url ): bool => str_contains( $url, $path ) ) );
	}
}
