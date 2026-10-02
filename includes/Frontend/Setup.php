<?php
/**
 * Frontend coordinator: wires the storefront subsystems.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Setup {

	public function __construct( private readonly Options $options ) {}

	public function register(): void {
		$iframe   = new IframeFactory( $this->options );
		$consent  = new Consent( $this->options );
		$modal    = new Modal();
		$resolver = new EmbedResolver( $this->options );

		$renderer = new Renderer( $iframe, $consent, $modal, $resolver );
		$renderer->register();

		( new ClassicGalleryInjector( $iframe, $modal, $renderer ) )->register();
		( new BlockGalleryInjector( $iframe, $modal, $renderer ) )->register();
		( new Assets( $this->options ) )->register();
	}
}
