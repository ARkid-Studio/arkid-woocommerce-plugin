<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Frontend;

use Arkid\CatalogueLink\Frontend\IframeFactory;
use Arkid\CatalogueLink\Settings\Options;
require_once __DIR__ . '/wp-parse-url-stub.php';

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class IframeFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'esc_url'      => static fn( string $u ): string => htmlspecialchars( $u, ENT_QUOTES ),
				'esc_attr'     => static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES ),
				'esc_html'     => static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES ),
				'__'           => static fn( string $s ): string => $s,
				'add_query_arg' => static function ( string $key, string $value, string $url ): string {
					$sep = str_contains( $url, '?' ) ? '&' : '?';
					return $url . $sep . $key . '=' . $value;
				},
				'wp_parse_url'  => static fn( string $url, int $component = -1 ) => wp_parse_url_stub( $url, $component ),
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_viewer_url_appends_woocommerce_source(): void {
		$factory = new IframeFactory( $this->options() );

		$this->assertSame(
			'https://catalogue.arkid.app/embed/abc?source=woocommerce',
			$factory->viewer_url( 'https://catalogue.arkid.app/embed/abc' )
		);
	}

	public function test_viewer_url_returns_empty_for_empty_input(): void {
		$factory = new IframeFactory( $this->options() );
		$this->assertSame( '', $factory->viewer_url( '' ) );
	}

	public function test_iframe_includes_safe_attributes(): void {
		$factory = new IframeFactory( $this->options() );

		$html = $factory->build_iframe( 'https://catalogue.arkid.app/embed/abc', 'Felix' );

		$this->assertStringContainsString( 'src="https://catalogue.arkid.app/embed/abc?source=woocommerce"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'allow="xr-spatial-tracking"', $html );
		$this->assertStringContainsString( 'allowfullscreen', $html );
		$this->assertStringContainsString( 'sandbox="allow-scripts allow-same-origin allow-popups allow-forms"', $html );
		$this->assertStringContainsString( 'title="', $html );
	}

	public function test_iframe_carries_viewer_class_and_no_inline_size(): void {
		// Sizing is handled by the `arkid-catalogue-link__viewer` CSS class —
		// the iframe should NOT have any inline `style` attribute or
		// width/height attributes.
		$factory = new IframeFactory( $this->options() );

		$html = $factory->build_iframe( 'https://catalogue.arkid.app/e/x', 'p' );

		$this->assertStringContainsString( 'class="arkid-catalogue-link__viewer"', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringNotContainsString( 'aspect-ratio', $html );
		$this->assertStringNotContainsString( 'min-height', $html );
	}

	public function test_image_trigger_carries_data_url_and_thumbnail(): void {
		$factory = new IframeFactory( $this->options() );

		$html = $factory->build_image_trigger(
			'https://catalogue.arkid.app/embed/abc',
			'Felix',
			'https://cdn.example/img.webp',
			'arkid-catalogue-link__trigger--gallery'
		);

		$this->assertStringContainsString( 'data-embed-url="https://catalogue.arkid.app/embed/abc?source=woocommerce"', $html );
		$this->assertStringContainsString( 'aria-controls="arkid-catalogue-link__dialog"', $html );
		$this->assertStringContainsString( 'src="https://cdn.example/img.webp"', $html );
		$this->assertStringContainsString( 'arkid-catalogue-link__trigger--gallery', $html );
	}

	public function test_text_trigger_uses_configured_button_text_and_omits_image(): void {
		$factory = new IframeFactory( $this->options( button_text: 'Show in 3D' ) );

		$html = $factory->build_text_trigger(
			'https://catalogue.arkid.app/embed/abc',
			'Felix'
		);

		$this->assertStringContainsString( 'data-embed-url="https://catalogue.arkid.app/embed/abc?source=woocommerce"', $html );
		$this->assertStringContainsString( 'arkid-catalogue-link__trigger--standalone', $html );
		$this->assertStringContainsString( '>Show in 3D<', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_text_trigger_includes_inline_brand_icon_svg(): void {
		$factory = new IframeFactory( $this->options() );

		$html = $factory->build_text_trigger( 'https://catalogue.arkid.app/e/x', 'p' );

		$this->assertStringContainsString( 'arkid-catalogue-link__brand-icon', $html );
		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringContainsString( 'fill="currentColor"', $html );
		$this->assertStringNotContainsString( '<?xml', $html );
	}

	public function test_text_trigger_falls_back_to_default_text(): void {
		Functions\when( '__' )->returnArg( 1 );
		$factory = new IframeFactory( $this->options() );

		$html = $factory->build_text_trigger( 'https://catalogue.arkid.app/e/x', 'p' );

		$this->assertStringContainsString(
			'>' . htmlspecialchars( Options::default_button_text(), ENT_QUOTES ) . '<',
			$html
		);
	}

	public function test_noscript_link_uses_embed_url(): void {
		$factory = new IframeFactory( $this->options() );

		$html = $factory->build_noscript_link( 'https://catalogue.arkid.app/e/x', 'p' );

		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'href="https://catalogue.arkid.app/e/x?source=woocommerce"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
	}

	private function options( ?string $button_text = null ): Options {
		$option = array(
			'button_text' => $button_text ?? '',
		);

		Functions\expect( 'get_option' )
			->andReturn( $option );

		return new Options();
	}

	/**
	 * @dataProvider provide_disallowed_urls
	 */
	public function test_viewer_url_rejects_urls_outside_the_allow_list( string $url ): void {
		$factory = new IframeFactory( $this->options() );

		$this->assertSame( '', $factory->viewer_url( $url ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_disallowed_urls(): array {
		return array(
			'other host'        => array( 'https://evil.example/e/abc' ),
			'http scheme'       => array( 'http://catalogue.arkid.app/e/abc' ),
			'lookalike suffix'  => array( 'https://notcatalogue.arkid.app.evil.example/e/abc' ),
			'no scheme'         => array( 'catalogue.arkid.app/e/abc' ),
			'javascript scheme' => array( 'javascript:alert(1)' ),
			'empty'             => array( '' ),
		);
	}

	public function test_viewer_url_allows_subdomains_of_an_allowed_host(): void {
		$factory = new IframeFactory( $this->options() );

		$this->assertStringStartsWith(
			'https://eu.catalogue.arkid.app/e/abc',
			$factory->viewer_url( 'https://eu.catalogue.arkid.app/e/abc' )
		);
	}

	public function test_every_builder_renders_nothing_for_a_disallowed_host(): void {
		$factory = new IframeFactory( $this->options() );
		$bad     = 'https://evil.example/e/abc';

		$this->assertSame( '', $factory->build_iframe( $bad, 'p' ) );
		$this->assertSame( '', $factory->build_text_trigger( $bad, 'p' ) );
		$this->assertSame( '', $factory->build_noscript_link( $bad, 'p' ) );
		$this->assertSame( '', $factory->build_image_trigger( $bad, 'p', 'https://cdn.example/i.webp' ) );
	}
}
