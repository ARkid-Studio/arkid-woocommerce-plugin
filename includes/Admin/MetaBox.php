<?php
/**
 * Product edit page meta box: Select2 embed picker + per-product position.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Admin;

use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Api\EmbedDto;
use Arkid\CatalogueLink\Cron\RefreshEmbeds;
use Arkid\CatalogueLink\PostMeta;
use Arkid\CatalogueLink\Settings\Options;

defined( 'ABSPATH' ) || exit;

class MetaBox {

	private const NONCE_ACTION = 'arkid_catalogue_link_metabox';
	private const NONCE_NAME   = 'arkid_catalogue_link_metabox_nonce';

	public function __construct(
		private readonly Options $options,
		private readonly ClientFactory $api
	) {}

	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'arkid-catalogue-link-metabox',
			__( 'ARkid Catalogue Link', 'arkid-catalogue-link' ),
			array( $this, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	public function render( \WP_Post $post ): void {
		$current_id       = PostMeta::embed_id( $post->ID );
		$current_position = PostMeta::position( $post->ID );
		$current_image    = PostMeta::embed_image( $post->ID );
		$current_label    = $this->resolve_current_label( $current_id );

		// Fall back to the raw id when ARkid is unreachable, so the merchant can
		// still see which viewer is attached.
		if ( '' !== $current_id && '' === $current_label ) {
			$current_label = $current_id;
		}

		$position_options = $this->position_choices();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="arkid-catalogue-link__metabox">
			<?php if ( '' !== $current_id && '' === PostMeta::embed_url( $post->ID ) ) : ?>
				<p class="notice notice-warning notice-alt" style="padding:6px 10px;">
					<?php esc_html_e( 'This viewer has not been synced from ARkid yet, so it is not shown on the product page. It will appear once the connection succeeds.', 'arkid-catalogue-link' ); ?>
				</p>
			<?php endif; ?>
			<p>
				<label for="arkid_catalogue_link_embed_id">
					<strong><?php esc_html_e( 'Viewer', 'arkid-catalogue-link' ); ?></strong>
				</label>
				<select
					id="arkid_catalogue_link_embed_id"
					name="arkid_catalogue_link_embed_id"
					class="arkid-catalogue-link__embed-picker"
					data-placeholder="<?php esc_attr_e( 'Search viewers…', 'arkid-catalogue-link' ); ?>"
					data-rest-url="<?php echo esc_attr( rest_url( 'arkid-catalogue-link/v1/embeds' ) ); ?>"
					data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					style="width:100%;"
				>
					<option value=""><?php esc_html_e( '— No viewer —', 'arkid-catalogue-link' ); ?></option>
					<?php if ( '' !== $current_id ) : ?>
						<option value="<?php echo esc_attr( $current_id ); ?>" selected>
							<?php echo esc_html( $current_label ); ?>
						</option>
					<?php endif; ?>
				</select>
				<button
					type="button"
					class="button-link arkid-catalogue-link__remove-viewer"
					data-target="arkid_catalogue_link_embed_id"
					<?php echo '' !== $current_id ? '' : 'hidden'; ?>
				>
					<?php esc_html_e( 'Remove viewer', 'arkid-catalogue-link' ); ?>
				</button>
			</p>

			<p class="arkid-catalogue-link__embed-preview" <?php echo '' !== $current_image ? '' : 'hidden'; ?>>
				<img
					src="<?php echo esc_url( $current_image ); ?>"
					alt=""
					style="max-width:200px;max-height:200px;width:auto;height:auto;border:1px solid #ddd;"
				/>
			</p>

			<p>
				<label for="arkid_catalogue_link_position">
					<strong><?php esc_html_e( 'Position override', 'arkid-catalogue-link' ); ?></strong>
				</label>
				<select id="arkid_catalogue_link_position" name="arkid_catalogue_link_position" style="width:100%;">
					<?php foreach ( $position_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_position, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: site default position label */
					esc_html__( 'Site default: %s', 'arkid-catalogue-link' ),
					esc_html( $this->position_label( $this->options->default_position() ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * @return array<string, string>
	 */
	private function position_choices(): array {
		return array(
			''                              => __( 'Use site default', 'arkid-catalogue-link' ),
			Options::POSITION_FIRST_IMAGE   => __( 'First image (gallery)', 'arkid-catalogue-link' ),
			Options::POSITION_LAST_IMAGE    => __( 'Last image (gallery, modal)', 'arkid-catalogue-link' ),
			Options::POSITION_ABOVE_PRODUCT => __( 'Above product', 'arkid-catalogue-link' ),
			Options::POSITION_BELOW_PRODUCT => __( 'Below product', 'arkid-catalogue-link' ),
			Options::POSITION_BUTTON_ONLY   => __( 'Button only', 'arkid-catalogue-link' ),
		);
	}

	private function position_label( string $position ): string {
		return $this->position_choices()[ $position ] ?? $position;
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'product' !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && is_string( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$embed_id = isset( $_POST['arkid_catalogue_link_embed_id'] ) && is_string( $_POST['arkid_catalogue_link_embed_id'] )
			? PostMeta::sanitize_embed_id(
				sanitize_text_field( wp_unslash( $_POST['arkid_catalogue_link_embed_id'] ) )
			)
			: '';
		$position = isset( $_POST['arkid_catalogue_link_position'] ) && is_string( $_POST['arkid_catalogue_link_position'] )
			? PostMeta::sanitize_position(
				sanitize_text_field( wp_unslash( $_POST['arkid_catalogue_link_position'] ) )
			)
			: '';

		if ( '' === $embed_id ) {
			PostMeta::write( $post_id, null, '' );
			return;
		}

		$embed = $this->fetch_embed( $embed_id );

		if ( null === $embed ) {
			$this->save_unverified( $post_id, $embed_id, $position );
			return;
		}

		PostMeta::write( $post_id, $embed, $position );
	}

	/**
	 * Save path for when ARkid couldn't be reached.
	 *
	 * The snapshot meta (url + image) describes a specific embed, so it must
	 * never be left describing a DIFFERENT embed than the stored id — that
	 * combination makes the storefront render the previous viewer under the new
	 * id. Keep the merchant's selection either way, but drop the snapshot when
	 * we can't vouch for it, and queue a retry.
	 */
	private function save_unverified( int $post_id, string $embed_id, string $position ): void {
		$is_same_embed = PostMeta::embed_id( $post_id ) === $embed_id;

		update_post_meta( $post_id, PostMeta::KEY_EMBED_ID, $embed_id );

		if ( ! $is_same_embed ) {
			delete_post_meta( $post_id, PostMeta::KEY_EMBED_URL );
			delete_post_meta( $post_id, PostMeta::KEY_EMBED_IMAGE );
		}

		if ( '' === $position ) {
			delete_post_meta( $post_id, PostMeta::KEY_POSITION );
		} else {
			update_post_meta( $post_id, PostMeta::KEY_POSITION, $position );
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				RefreshEmbeds::HOOK_PRODUCT,
				array( 'product_id' => $post_id ),
				RefreshEmbeds::GROUP
			);
		}
	}

	private function fetch_embed( string $embed_id ): ?EmbedDto {
		try {
			return $this->api->create()?->get_embed( $embed_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function resolve_current_label( string $embed_id ): string {
		if ( '' === $embed_id ) {
			return '';
		}

		$embed = $this->fetch_embed( $embed_id );
		return null === $embed ? '' : $embed->picker_label();
	}
}
