<?php
/**
 * Shared `<dialog>` modal rendered once on the page footer.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Frontend;

defined( 'ABSPATH' ) || exit;

class Modal {

	private bool $rendered = false;

	public function ensure_rendered(): void {
		if ( $this->rendered ) {
			return;
		}
		$this->rendered = true;
		add_action( 'wp_footer', array( $this, 'render' ), 100 );
	}

	public function render(): void {
		?>
		<dialog id="arkid-catalogue-link__dialog" class="arkid-catalogue-link__dialog" aria-labelledby="arkid-catalogue-link__dialog-title">
			<div class="arkid-catalogue-link__dialog-frame">
				<h2 id="arkid-catalogue-link__dialog-title" class="screen-reader-text"></h2>
				<button type="button" class="arkid-catalogue-link__dialog-close" aria-label="<?php esc_attr_e( 'Close 3D viewer', 'arkid-catalogue-link' ); ?>">&times;</button>
				<div class="arkid-catalogue-link__dialog-iframe-host" data-iframe-allow="xr-spatial-tracking" data-iframe-sandbox="allow-scripts allow-same-origin allow-popups allow-forms"></div>
			</div>
		</dialog>
		<?php
	}
}
