<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Admin;

use Arkid\CatalogueLink\Admin\ColorDots;
use Arkid\CatalogueLink\Api\EmbedDto;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ColorDotsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'__'       => static fn( string $s ): string => $s,
				'esc_attr' => static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $colors
	 */
	private function embed( array $colors ): EmbedDto {
		return EmbedDto::from_array( array( 'embed_id' => 'e1', 'colors' => $colors ) );
	}

	public function test_a_viewer_without_colours_has_no_dots(): void {
		$this->assertSame( '', ColorDots::render( $this->embed( array() ) ) );
	}

	public function test_each_colour_is_a_dot_in_display_order(): void {
		$html = ColorDots::render(
			$this->embed(
				array(
					array( 'variant_id' => 'v1', 'name' => 'Copper Terra', 'rgb' => '#b55f39', 'enabled' => true ),
					array( 'variant_id' => 'v2', 'name' => 'Moon Graphite', 'rgb' => '#929498', 'enabled' => false ),
				)
			)
		);

		$this->assertSame( 2, substr_count( $html, 'arkid-catalogue-link__dot ' ) + substr_count( $html, 'arkid-catalogue-link__dot"' ) );
		$this->assertLessThan( strpos( $html, 'Moon Graphite' ), strpos( $html, 'Copper Terra' ) );
		$this->assertStringContainsString( 'style="--arkid-dot:#b55f39"', $html );
		$this->assertStringContainsString( 'style="--arkid-dot:#929498"', $html );
	}

	/**
	 * A hidden colour must not look the same as a shown one, and must not rely on
	 * how it looks to be told apart: its state is in its label.
	 */
	public function test_a_hidden_colour_is_marked_and_says_so_in_words(): void {
		$html = ColorDots::render(
			$this->embed(
				array(
					array( 'variant_id' => 'v1', 'name' => 'Copper Terra', 'rgb' => '#b55f39', 'enabled' => true ),
					array( 'variant_id' => 'v2', 'name' => 'Moon Graphite', 'rgb' => '#929498', 'enabled' => false ),
				)
			)
		);

		$this->assertStringContainsString( 'aria-label="Copper Terra: shown"', $html );
		$this->assertStringContainsString( 'title="Copper Terra: shown"', $html );
		$this->assertStringContainsString( 'data-state="shown"', $html );
		$this->assertStringContainsString( 'aria-label="Moon Graphite: hidden"', $html );
		$this->assertStringContainsString( 'data-state="hidden"', $html );
		$this->assertSame( 1, substr_count( $html, 'is-hidden' ) );
		$this->assertSame( 2, substr_count( $html, 'role="img"' ) );
	}

	/**
	 * The names and colours come from the API and end up in HTML and in a style
	 * attribute.
	 */
	public function test_hostile_values_cannot_leave_their_attribute(): void {
		$html = ColorDots::render(
			$this->embed(
				array(
					array(
						'variant_id' => 'v"><script>x</script>',
						'name'       => '"><img src=x onerror=alert(1)>',
						'rgb'        => 'red;background:url(https://evil.example/x)',
						'enabled'    => true,
					),
				)
			)
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringNotContainsString( 'style=', $html, 'A colour that is not a hex colour gets no style at all.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function colours(): array {
		return array(
			'six digits'        => array( '#B55F39', '#b55f39' ),
			'three digits'      => array( '#fa0', '#fa0' ),
			'padded'            => array( ' #ffffff ', '#ffffff' ),
			'no hash'           => array( 'b55f39', '' ),
			'a name'            => array( 'red', '' ),
			'a function'        => array( 'rgb(0,0,0)', '' ),
			'a declaration'     => array( '#fff;color:red', '' ),
			'a newline inside'  => array( "#fff\n;color:red", '' ),
			'four digits'       => array( '#ffff', '' ),
			'empty'             => array( '', '' ),
		);
	}

	/**
	 * @dataProvider colours
	 */
	public function test_only_a_hex_colour_goes_into_a_style( string $given, string $expected ): void {
		$this->assertSame( $expected, ColorDots::css_color( $given ) );
	}
}
