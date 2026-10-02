<?php
/**
 * WooCommerce Integration page: WC -> Settings -> Integration -> ARkid Catalogue Link.
 *
 * @package Arkid\CatalogueLink
 */

declare( strict_types = 1 );

namespace Arkid\CatalogueLink\Settings;

use Arkid\CatalogueLink\Api\ApiException;
use Arkid\CatalogueLink\Api\AuthException;
use Arkid\CatalogueLink\Api\ClientFactory;
use Arkid\CatalogueLink\Cron\RefreshEmbeds;

defined( 'ABSPATH' ) || exit;

class IntegrationSettings extends \WC_Integration {

	public const ID = 'arkid-catalogue-link';

	/**
	 * The API key as it was when WooCommerce constructed this integration —
	 * i.e. before any save hook has run. Captured here rather than in the save
	 * hook because `process_admin_options()` (priority 10) overwrites
	 * `$this->settings` from $_POST before our priority-20 callback can read
	 * it, which made every comparison see new === new and never fire.
	 *
	 * @var string
	 */
	private string $key_on_load = '';

	private ?ClientFactory $api = null;

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'ARkid Catalogue Link', 'arkid-catalogue-link' );
		$this->method_description = __(
			'Connect this store to your ARkid catalogue. The 3D / AR viewer you pick on each product is rendered on the product display page.',
			'arkid-catalogue-link'
		);

		$this->init_form_fields();
		$this->init_settings();
		$this->key_on_load = trim( $this->get_option( 'api_key', '' ) );

		add_action(
			'woocommerce_update_options_integration_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
		add_action(
			'woocommerce_update_options_integration_' . $this->id,
			array( $this, 'on_settings_saved' ),
			20
		);
	}

	public function init_form_fields(): void {
		$position_choices = array(
			Options::POSITION_FIRST_IMAGE   => __( 'First image (image gallery)', 'arkid-catalogue-link' ),
			Options::POSITION_LAST_IMAGE    => __( 'Last image (image gallery, opens in modal)', 'arkid-catalogue-link' ),
			Options::POSITION_ABOVE_PRODUCT => __( 'Above product', 'arkid-catalogue-link' ),
			Options::POSITION_BELOW_PRODUCT => __( 'Below product', 'arkid-catalogue-link' ),
			Options::POSITION_BUTTON_ONLY   => __( 'Button only (View in 3D)', 'arkid-catalogue-link' ),
		);

		$this->form_fields = array(
			'api_key'                  => array(
				'title'       => __( 'API key', 'arkid-catalogue-link' ),
				'type'        => 'password_with_reveal',
				'description' => __( 'API key from your ARkid catalogue account. Used as the HTTP Basic auth password (the username is always <code>woocommerce</code>).', 'arkid-catalogue-link' ),
				'desc_tip'    => false,
				'default'     => '',
			),
			'default_position'         => array(
				'title'       => __( 'Default viewer position', 'arkid-catalogue-link' ),
				'type'        => 'select',
				'options'     => $position_choices,
				'default'     => Options::DEFAULT_POSITION,
				'description' => __( 'Where the viewer is rendered on the product display page when no per-product override is set.', 'arkid-catalogue-link' ),
				'desc_tip'    => true,
			),
			'button_text'              => array(
				'title'       => __( 'Button text', 'arkid-catalogue-link' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => Options::default_button_text(),
				'description' => __( 'Label for the standalone "Button only" trigger and for the modal-opening button on the last-image position. Leave empty to use the localized default for each visitor.', 'arkid-catalogue-link' ),
				'desc_tip'    => true,
			),
			'require_consent'          => array(
				'title'       => __( 'Require visitor consent', 'arkid-catalogue-link' ),
				'type'        => 'checkbox',
				'label'       => __( 'Only load the viewer when the visitor has granted marketing consent (requires the WP Consent API plugin).', 'arkid-catalogue-link' ),
				'default'     => 'no',
				'description' => __( 'When enabled and consent is missing, the viewer area is left empty.', 'arkid-catalogue-link' ),
			),
		);
	}

	/**
	 * Custom field type: password input + Reveal toggle.
	 *
	 * @param string                                $key
	 * @param array<string, mixed>                  $data
	 */
	public function generate_password_with_reveal_html( string $key, array $data ): string {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'description'       => '',
			'css'               => '',
			'placeholder'       => '',
			'class'             => '',
			'desc_tip'          => false,
			'custom_attributes' => array(),
		);
		$data      = wp_parse_args( $data, $defaults );
		$value     = $this->get_option( $key );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( (string) $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo esc_html( (string) $data['title'] ); ?></span></legend>
					<input
						class="input-text regular-input <?php echo esc_attr( (string) $data['class'] ); ?>"
						type="password"
						name="<?php echo esc_attr( $field_key ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						style="<?php echo esc_attr( (string) $data['css'] ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="<?php echo esc_attr( (string) $data['placeholder'] ); ?>"
						autocomplete="off"
						<?php echo $this->get_custom_attribute_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					/>
					<button type="button" class="button arkid-catalogue-link__reveal" data-target="<?php echo esc_attr( $field_key ); ?>" aria-pressed="false">
						<?php esc_html_e( 'Reveal', 'arkid-catalogue-link' ); ?>
					</button>
					<?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	public function validate_password_with_reveal_field( string $key, ?string $value ): string {
		return null === $value ? '' : trim( wp_unslash( $value ) );
	}

	/**
	 * Hook: after settings save. Probes the API only when the key actually
	 * changed, and re-syncs every product snapshot because the new key may
	 * belong to a different ARkid account.
	 */
	public function on_settings_saved(): void {
		$this->init_settings();
		$new_key = trim( $this->get_option( 'api_key', '' ) );

		$changed           = ( $new_key !== $this->key_on_load );
		$this->key_on_load = $new_key;

		if ( ! $changed || '' === $new_key ) {
			return;
		}

		// A new key can point at a different ARkid account, which makes every
		// stored per-product snapshot suspect. Re-replicate them all.
		RefreshEmbeds::schedule_full_resync();

		try {
			$this->api()->create_for_key( $new_key )->probe();
			\WC_Admin_Settings::add_message(
				__( 'ARkid Catalogue Link: connected successfully.', 'arkid-catalogue-link' )
			);
		} catch ( AuthException $e ) {
			\WC_Admin_Settings::add_error(
				__( 'ARkid Catalogue Link: the API key was rejected. Double-check the key.', 'arkid-catalogue-link' )
			);
		} catch ( ApiException $e ) {
			\WC_Admin_Settings::add_error(
				esc_html(
					sprintf(
						/* translators: %s: error message */
						__( 'ARkid Catalogue Link: could not reach the API (%s). The key was saved anyway.', 'arkid-catalogue-link' ),
						$e->getMessage()
					)
				)
			);
		}
	}

	/**
	 * WooCommerce constructs integrations itself, so this can't be a constructor
	 * argument. Lazily built, and swappable for tests.
	 */
	private function api(): ClientFactory {
		return $this->api ??= new ClientFactory( new Options() );
	}

	public function set_client_factory( ClientFactory $factory ): void {
		$this->api = $factory;
	}

	/**
	 * Register this integration with WooCommerce.
	 *
	 * @param array<int, class-string> $integrations
	 *
	 * @return array<int, class-string>
	 */
	public static function register( array $integrations ): array {
		$integrations[] = self::class;
		return $integrations;
	}
}
