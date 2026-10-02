<?php

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Settings;

use Arkid\CatalogueLink\Settings\Options;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_when_option_missing(): void {
		Functions\expect( 'get_option' )
			->with( Options::OPTION_KEY, array() )
			->andReturn( array() );
		Functions\when( '__' )->returnArg( 1 );

		$options = new Options();

		$this->assertSame( '', $options->api_key() );
		$this->assertSame( Options::DEFAULT_POSITION, $options->default_position() );
		$this->assertFalse( $options->require_consent() );
		$this->assertSame( 'See in 3D & AR', $options->button_text() );
	}

	public function test_reads_saved_values(): void {
		Functions\expect( 'get_option' )
			->andReturn(
				array(
					'api_key'          => '  my-key  ',
					'default_position' => Options::POSITION_FIRST_IMAGE,
					'require_consent'  => 'yes',
					'button_text'      => 'See in 3D',
				)
			);

		$options = new Options();

		$this->assertSame( 'my-key', $options->api_key() );
		$this->assertSame( Options::POSITION_FIRST_IMAGE, $options->default_position() );
		$this->assertTrue( $options->require_consent() );
		$this->assertSame( 'See in 3D', $options->button_text() );
	}

	public function test_invalid_position_falls_back_to_default(): void {
		Functions\expect( 'get_option' )
			->andReturn( array( 'default_position' => 'sidebar' ) );

		$this->assertSame( Options::DEFAULT_POSITION, ( new Options() )->default_position() );
	}

	public function test_button_text_falls_back_to_default_on_blank(): void {
		Functions\expect( 'get_option' )
			->andReturn( array( 'button_text' => '   ' ) );
		Functions\when( '__' )->returnArg( 1 );

		$this->assertSame( 'See in 3D & AR', ( new Options() )->button_text() );
	}
}
