<?php
/**
 * The dialog that previews a viewer in wp-admin.
 *
 * One `<dialog>` per screen, empty until a button opens it: the viewer iframe
 * is mounted by `src/admin/preview-dialog.js` only on click. The viewers list
 * and the product meta box both open it.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

defined( 'ABSPATH' ) || exit;

final class PreviewDialog {

	public const ID = 'arkid-catalogue-link-preview';

	/**
	 * Print the dialog.
	 */
	public static function render(): void {
		?>
		<dialog id="<?php echo esc_attr( self::ID ); ?>" class="arkid-catalogue-link__admin-dialog" aria-labelledby="<?php echo esc_attr( self::ID ); ?>-title">
			<div class="arkid-catalogue-link__admin-dialog-inner">
				<div class="arkid-catalogue-link__admin-dialog-header">
					<h2 id="<?php echo esc_attr( self::ID ); ?>-title" class="arkid-catalogue-link__admin-dialog-title"></h2>
					<button type="button" class="arkid-catalogue-link__admin-dialog-dismiss" data-role="dismiss">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Close preview', 'arkid-catalogue-link' ); ?></span>
					</button>
				</div>
				<div class="arkid-catalogue-link__admin-dialog-body">
					<p class="arkid-catalogue-link__admin-dialog-status" role="status"><?php esc_html_e( 'Loading the viewer…', 'arkid-catalogue-link' ); ?></p>
					<div class="arkid-catalogue-link__admin-dialog-host"></div>
				</div>
				<div class="arkid-catalogue-link__admin-dialog-footer">
					<a class="button" data-role="edit" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Edit in ARkid', 'arkid-catalogue-link' ); ?></a>
					<a class="button" data-role="analytics" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Analytics', 'arkid-catalogue-link' ); ?></a>
					<button type="button" class="button button-primary" data-role="close"><?php esc_html_e( 'Close', 'arkid-catalogue-link' ); ?></button>
				</div>
			</div>
		</dialog>
		<?php
	}
}
