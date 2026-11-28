<?php
/**
 * What uninstall.php leaves behind.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration;

final class UninstallTest extends TestCase {

	/**
	 * Includes the script once for the whole run (it declares a function), so
	 * everything that has to be checked about it is checked from this one test.
	 */
	public function test_the_viewers_screen_preferences_of_every_user_are_removed_and_other_user_meta_is_not(): void {
		$first  = (int) self::factory()->user->create();
		$second = (int) self::factory()->user->create();

		update_user_meta( $first, 'arkid_viewers_per_page', 50 );
		update_user_meta( $second, 'arkid_viewers_per_page', 100 );
		update_user_meta( $second, 'managewoocommerce_page_arkid-viewerscolumnshidden', array( 'created' ) );
		// Preferences of other screens, and a near miss, belong to someone else.
		update_user_meta( $first, 'edit_shop_order_per_page', 30 );
		update_user_meta( $first, 'managewoocommerce_page_wc-settingscolumnshidden', array( 'x' ) );
		update_user_meta( $first, 'arkid_viewers_per_page_extra', 7 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'arkid-catalogue-link/arkid-catalogue-link.php' );
		}
		require ARKID_CATALOGUE_LINK_PATH . 'uninstall.php';

		$this->assertSame( '', get_user_meta( $first, 'arkid_viewers_per_page', true ) );
		$this->assertSame( '', get_user_meta( $second, 'arkid_viewers_per_page', true ) );
		$this->assertSame( '', get_user_meta( $second, 'managewoocommerce_page_arkid-viewerscolumnshidden', true ) );

		$this->assertSame( '30', (string) get_user_meta( $first, 'edit_shop_order_per_page', true ) );
		$this->assertSame( array( 'x' ), get_user_meta( $first, 'managewoocommerce_page_wc-settingscolumnshidden', true ) );
		$this->assertSame( '7', (string) get_user_meta( $first, 'arkid_viewers_per_page_extra', true ) );
	}
}
