<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Admin;

use Arkid\CatalogueLink\Admin\Assets;
use Arkid\CatalogueLink\Admin\PreviewDialog;
use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Settings\Options;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class AssetsTest extends TestCase {

	private function assets(): Assets {
		return new Assets( new IframeFactory( new Options() ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'arkid_catalogue_link_allowed_embed_hosts' );
		parent::tear_down();
	}

	/**
	 * The preview refuses addresses the same way the storefront does, from the
	 * same list.
	 */
	public function test_the_admin_script_is_told_the_hosts_a_viewer_may_load_from(): void {
		$this->assertSame( array( 'catalogue.arkid.app' ), $this->assets()->script_data()['allowedHosts'] );
	}

	public function test_a_host_added_by_the_filter_reaches_the_admin_script_too(): void {
		add_filter(
			'arkid_catalogue_link_allowed_embed_hosts',
			static fn( array $hosts ): array => array_merge( $hosts, array( 'Viewer.Example.com' ) )
		);

		$this->assertSame(
			array( 'catalogue.arkid.app', 'viewer.example.com' ),
			$this->assets()->script_data()['allowedHosts']
		);
	}

	public function test_the_script_gets_the_strings_of_the_preview_and_the_dots_translated(): void {
		$i18n = $this->assets()->script_data()['i18n'];

		$this->assertIsArray( $i18n );
		$this->assertSame( '%s 3D viewer', $i18n['viewerFrameTitle'] );
		$this->assertSame( '%s: shown', $i18n['colorShown'] );
		$this->assertSame( '%s: hidden', $i18n['colorHidden'] );
		$this->assertArrayHasKey( 'searchPlaceholder', $i18n, 'The strings the script already had stay.' );
	}

	public function test_the_dialog_is_empty_and_named(): void {
		$html = $this->render( array( PreviewDialog::class, 'render' ) );

		$this->assertStringContainsString( '<dialog id="arkid-catalogue-link-preview"', $html );
		$this->assertStringContainsString( 'aria-labelledby="arkid-catalogue-link-preview-title"', $html );
		$this->assertStringContainsString( 'role="status"', $html, 'The loading state is announced.' );
		$this->assertStringNotContainsString( '<iframe', $html, 'The viewer is mounted on click, never printed.' );
		$this->assertStringContainsString( 'data-role="close"', $html );
		$this->assertStringContainsString( 'Close preview', $html, 'The icon-only close button has a text name.' );
	}

	public function test_the_dialog_is_only_printed_on_screens_that_open_it(): void {
		$assets = $this->assets();

		$this->assertSame( '', $this->render( array( $assets, 'print_preview_dialog' ) ) );
	}
}
