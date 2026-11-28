<?php
/**
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Tests\Integration\Settings;

use Arkid\CatalogueLink\Settings\IntegrationSettings;
use Arkid\CatalogueLink\Tests\Integration\TestCase;

final class IntegrationSettingsTest extends TestCase {

	/**
	 * `Options::button_text()` is read for the "Button only" trigger and nowhere
	 * else, so the field description must not promise more. It used to say the
	 * label also applied to the modal-opening button of the last-image position.
	 */
	public function test_the_button_text_description_does_not_claim_the_last_image_button(): void {
		$settings = new IntegrationSettings();

		$field = $settings->get_form_fields()['button_text'] ?? array();
		$this->assertIsArray( $field );
		$description = (string) ( $field['description'] ?? '' );

		$this->assertStringContainsString( '"Button only"', $description );
		$this->assertStringNotContainsStringIgnoringCase( 'last-image', $description );
		$this->assertStringNotContainsStringIgnoringCase( 'last image', $description );
		$this->assertStringNotContainsStringIgnoringCase( 'modal', $description );
	}
}
