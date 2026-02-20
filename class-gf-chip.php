<?php
/**
 * GF_Chip addon class for CHIP payment gateway.
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || die();

GFForms::include_payment_addon_framework();

/**
 * Gravity Forms addon for CHIP payment gateway.
 *
 * Handles global and per-form configuration, payment creation, callbacks, and refunds.
 *
 * @package GravityFormsCHIP
 */
class GF_Chip extends GFPaymentAddOn {

	/**
	 * Singleton instance.
	 *
	 * @var GF_Chip|null
	 */
	private static $_instance = null;

	/**
	 * Plugin main file path (chip-for-gravity-forms.php). Used for base path/URL and plugin identity.
	 *
	 * @var string
	 */
	protected $_full_path;

	/**
	 * Addon slug.
	 *
	 * @var string
	 */
	protected $_slug = 'gravityformschip';

	/**
	 * Addon title.
	 *
	 * @var string
	 */
	protected $_title = 'CHIP for Gravity Forms';

	/**
	 * Short title for menu.
	 *
	 * @var string
	 */
	protected $_short_title = 'CHIP';

	/**
	 * Whether addon supports payment callbacks.
	 *
	 * @var bool
	 */
	protected $_supports_callbacks = true;

	/**
	 * Capability names.
	 *
	 * @var array<string>
	 */
	protected $_capabilities = array( 'gravityforms_chip', 'gravityforms_chip_uninstall' );

	/**
	 * Capability for settings page.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_chip';

	/**
	 * Capability for form settings.
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_chip';

	/**
	 * Capability for uninstall.
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_chip_uninstall';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->_full_path = defined( 'GF_CHIP_PLUGIN_FILE' ) ? GF_CHIP_PLUGIN_FILE : __FILE__;
		parent::__construct();
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @return GF_Chip
	 */
	public static function get_instance() {

		if ( null === self::$_instance ) {
			self::$_instance = new GF_Chip();
		}

		return self::$_instance;
	}

	/**
	 * Runs before init. Registers actions for thank-you page and AJAX handlers.
	 */
	public function pre_init() {
		// Inspired by gravityformsstripe.
		add_action( 'wp', array( $this, 'maybe_thankyou_page' ), 5 );
		add_action( 'wp_ajax_gf_chip_refund_payment', array( $this, 'chip_refund_payment' ), 10, 0 );
		add_action( 'wp_ajax_gf_chip_get_global_credentials', array( $this, 'ajax_get_global_credentials' ), 10, 0 );

		parent::pre_init();
	}

	/**
	 * Runs on init. Registers payment callback handler.
	 */
	public function init() {
		parent::init();
		add_action( 'gform_post_payment_callback', array( $this, 'handle_post_payment_callback' ), 10, 3 );
	}

	/**
	 * Config for post-payment actions position.
	 *
	 * @param string $feed_slug Feed slug.
	 * @return array
	 */
	public function get_post_payment_actions_config( $feed_slug ) {
		return array(
			'position' => 'before',
			'setting'  => 'conditionalLogic',
		);
	}

	/**
	 * Supported currencies for CHIP (MYR).
	 *
	 * @param array $currencies Currency list.
	 * @return array
	 */
	public function supported_currencies( $currencies ) {
		return array( 'MYR' => $currencies['MYR'] );
	}

	/**
	 * Returns the addon menu icon URL.
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return plugins_url( 'assets/logo.svg', __FILE__ );
	}

	/**
	 * Scripts to enqueue. Adds feed settings copy-global script.
	 *
	 * @return array
	 */
	public function scripts() {
		$scripts = array(
			array(
				'handle'  => 'gf_chip_feed_settings_copy_global',
				'src'     => $this->get_base_url() . '/assets/js/feed-settings-copy-global.js',
				'version' => defined( 'GF_CHIP_MODULE_VERSION' ) ? GF_CHIP_MODULE_VERSION : null,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
				'strings' => array(
					'nonce'  => wp_create_nonce( 'gf_chip_get_global_credentials' ),
					'action' => 'gf_chip_get_global_credentials',
					'error'  => __( 'Request failed.', 'chip-for-gravity-forms' ),
				),
			),
		);
		return array_merge( parent::scripts(), $scripts );
	}

	/**
	 * Plugin settings sections (global CHIP keys, account status, optional config).
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$configuration = array(
			array(
				'title'       => esc_html__( 'CHIP', 'chip-for-gravity-forms' ),
				'description' => $this->get_description(),
				'fields'      => $this->global_keys_fields(),
			),
			array(
				'id'          => 'gf_chip_account_status',
				'title'       => esc_html__( 'Account Status', 'chip-for-gravity-forms' ),
				'description' => $this->global_account_status_description(),
				'fields'      => array( array( 'type' => 'account_status' ) ),
			),
		);

		if ( get_option( 'gf_chip_global_key_validation' ) ) {
			$configuration[] = array(
				'title'       => esc_html__( 'CHIP Optional Configuration', 'chip-for-gravity-forms' ),
				'description' => esc_html__( 'Further customize the behavior of the payment.', 'chip-for-gravity-forms' ),
				'fields'      => $this->global_advance_fields(),
			);
		}

		return apply_filters( 'gf_chip_plugin_settings_fields', $configuration );
	}

	/**
	 * Description HTML for the global settings section (intro + screenshot).
	 *
	 * @return string
	 */
	public function get_description() {
		$img_url = plugins_url( 'assets/form-settings.png', __FILE__ );
		ob_start();
		?>
		<p>
			<?php
			printf(
				// translators: %1$s opens link tag, %2$s closes link tag, %3$s is line break.
				esc_html__(
					'CHIP — Digital Finance Platform. %1$sLearn more%2$s. %3$s%3$sGlobal settings are optional. You may configure CHIP per form in each form\'s CHIP feed settings instead.',
					'chip-for-gravity-forms'
				),
				'<a href="https://www.chip-in.asia/" target="_blank" rel="noopener noreferrer">',
				'</a>',
				'<br>'
			);
			?>
			<?php
			printf(
				// translators: %s is the opening anchor tag for the screenshot link.
				esc_html__( 'To use this global configuration on a form, choose "Global Configuration" in the form\'s CHIP feed settings. %sView configuration screenshot%s.', 'chip-for-gravity-forms' ),
				'<a href="' . esc_url( $img_url ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			);
			?>
		</p>
		<?php

		return ob_get_clean();
	}

	/**
	 * Field definitions for Brand ID and Secret Key (global settings).
	 *
	 * @return array
	 */
	public function global_keys_fields() {
		return array(
			array(
				'name'     => 'brand_id',
				'label'    => esc_html__( 'Brand ID', 'chip-for-gravity-forms' ),
				'type'     => 'text',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'Brand ID', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Brand ID enables you to represent your brand in the system using the same CHIP account.', 'chip-for-gravity-forms' ),
			),
			array(
				'name'     => 'secret_key',
				'label'    => esc_html__( 'Secret Key', 'chip-for-gravity-forms' ),
				'type'     => 'text',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'Secret Key', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'The secret key is used to identify your account with CHIP. We recommend creating a dedicated secret key for each website.', 'chip-for-gravity-forms' ),
			),

		);
	}

	/**
	 * Optional global settings (refund, due strict, due timing).
	 *
	 * @return array
	 */
	public function global_advance_fields() {
		return array(
			array(
				'name'          => 'enable_refund',
				'label'         => 'Refund',
				'type'          => 'toggle',
				'default_value' => 'false',
				'tooltip'       => '<h6>' . esc_html__( 'Refund features', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Whether to enable refund through Gravity Forms. If configured, refunds can be made through Gravity Forms → Entries. Default is disabled.', 'chip-for-gravity-forms' ),
			),
			array(
				'name'    => 'due_strict',
				'label'   => esc_html__( 'Due Strict', 'chip-for-gravity-forms' ),
				'type'    => 'toggle',
				'tooltip' => '<h6>' . esc_html__( 'Due Strict', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Whether to permit payments when the purchase\'s due date has passed. By default those are permitted (and status will be set to overdue once the due moment has passed). If this is set to true, it will not be possible to pay for an overdue invoice, and when the due date has passed the purchase\'s status will be set to expired.', 'chip-for-gravity-forms' ),
			),
			array(
				'name'        => 'due_strict_timing',
				'label'       => esc_html__( 'Due Strict Timing (minutes)', 'chip-for-gravity-forms' ),
				'type'        => 'text',
				'placeholder' => '60 for 60 minutes',
				'tooltip'     => '<h6>' . esc_html__( 'Due Strict Timing (minutes)', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Set due time to enforce due timing for purchases (e.g. 60 for 60 minutes). If due_strict is set while due strict timing is unset, it will default to 1 hour. Leave blank if unsure.', 'chip-for-gravity-forms' ),
			),
		);
	}

	/**
	 * Returns the inner HTML for the Account Status block (global settings).
	 *
	 * @return string
	 */
	public function global_account_status_description() {
		return '<div id="gf-chip-account-status-block">' . $this->get_account_status_html() . '</div>';
	}

	/**
	 * Returns the inner HTML for the Account Status block (used for initial render and AJAX refresh).
	 *
	 * @return string
	 */
	public function get_account_status_html() {
		$state = 'Not set';

		if ( get_option( 'gf_chip_global_key_validation' ) ) {
			$state = 'Success';
		} elseif ( ! empty( get_option( 'gf_chip_global_error_code' ) ) ) {
			$state = get_option( 'gf_chip_global_error_code' );
		}

		$display_state = ( 'Success' === $state ) ? '✓ ' . $state : $state;
		$display_state = esc_html( $display_state );

		ob_start();
		?>
		<p>
			<?php
			printf(
				// translators: %1$s and %2$s are strong tags, %3$s is the status (e.g. ✓ Success, Not set, or an error code).
				esc_html__(
					'CHIP API connection: %1$s%3$s%2$s.',
					'chip-for-gravity-forms'
				),
				'<strong>',
				'</strong>',
				esc_html( $display_state )
			);
			?>
		</p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Returns Account Status HTML for form feed settings (Brand ID and Secret Key from feed or current form context).
	 *
	 * @return string
	 */
	public function get_form_feed_account_status_description() {
		$brand_id   = $this->get_setting( 'brand_id', '' );
		$secret_key = $this->get_setting( 'secret_key', '' );
		return $this->get_account_status_html_for_credentials( $brand_id, $secret_key );
	}

	/**
	 * Returns Account Status HTML for given Brand ID and Secret Key (validates and shows Success / Not set / error).
	 *
	 * @param string $brand_id   Brand ID.
	 * @param string $secret_key Secret Key.
	 * @return string
	 */
	public function get_account_status_html_for_credentials( $brand_id = '', $secret_key = '' ) {
		$brand_id   = trim( (string) $brand_id );
		$secret_key = trim( (string) $secret_key );
		$state      = 'Not set';

		if ( '' !== $brand_id && '' !== $secret_key ) {
			$chip       = GF_CHIP_API::get_instance( $secret_key, $brand_id );
			$public_key = $chip->get_public_key();
			if ( is_string( $public_key ) ) {
				$state = 'Success';
			} elseif ( is_array( $public_key ) && ! empty( $public_key['__all__'] ) && is_array( $public_key['__all__'] ) ) {
				$state = implode( ', ', array_column( $public_key['__all__'], 'code' ) );
			} else {
				$state = __( 'An unspecified error occurred.', 'chip-for-gravity-forms' );
			}
		}

		$display_state = ( 'Success' === $state ) ? '✓ ' . $state : esc_html( $state );

		return '<p>' . sprintf(
			// translators: %1$s and %2$s are strong tags, %3$s is the status (e.g. ✓ Success, Not set, or an error code).
			esc_html__( 'CHIP API connection: %1$s%3$s%2$s.', 'chip-for-gravity-forms' ),
			'<strong>',
			'</strong>',
			$display_state
		) . '</p>';
	}

	/**
	 * Saves plugin settings and updates Account Status by validating the new keys.
	 * Called when the user clicks Save Settings on the CHIP settings page.
	 *
	 * @param array $settings Decrypted plugin settings (brand_id, secret_key, etc.).
	 */
	public function update_plugin_settings( $settings ) {
		$this->validate_and_update_account_status( $settings );
		parent::update_plugin_settings( $settings );
		// Sections were built before save; rebuild them so the response has updated Account Status and Optional Configuration (when validation passed).
		$renderer = $this->get_settings_renderer();
		if ( $renderer && method_exists( $renderer, 'set_fields' ) ) {
			$sections = $this->plugin_settings_fields();
			$sections = $this->prepare_settings_sections( $sections, 'plugin_settings' );
			$renderer->set_fields( $sections );
		}
	}

	/**
	 * Validates Brand ID and Secret Key against CHIP API and updates Account Status options.
	 * Used so the Account Status block shows the correct state after Save Settings.
	 *
	 * @param array $settings Plugin settings containing brand_id and secret_key.
	 */
	public function validate_and_update_account_status( $settings ) {
		$brand_id   = isset( $settings['brand_id'] ) ? trim( (string) $settings['brand_id'] ) : '';
		$secret_key = isset( $settings['secret_key'] ) ? trim( (string) $settings['secret_key'] ) : '';

		if ( '' === $brand_id || '' === $secret_key ) {
			update_option( 'gf_chip_global_key_validation', false );
			update_option( 'gf_chip_global_error_code', '' );
			$this->log_debug( __METHOD__ . '(): Global keys cleared or empty; Account Status set to Not set.' );
			return;
		}

		$this->log_debug(
			__METHOD__ . '(): Validating global keys. New value ' . wp_json_encode(
				array(
					'brand_id'   => $brand_id,
					'secret_key' => '***',
				)
			)
		);

		$chip       = GF_CHIP_API::get_instance( $secret_key, $brand_id );
		$public_key = $chip->get_public_key();

		if ( is_string( $public_key ) ) {
			update_option( 'gf_chip_global_key_validation', true );
			update_option( 'gf_chip_global_error_code', '' );
			$this->log_debug( __METHOD__ . '(): Global keys validated successfully.' );
		} elseif ( is_array( $public_key ) && ! empty( $public_key['__all__'] ) && is_array( $public_key['__all__'] ) ) {
			$error_code_a = array_column( $public_key['__all__'], 'code' );
			$error_code   = implode( ', ', $error_code_a );
			update_option( 'gf_chip_global_key_validation', false );
			update_option( 'gf_chip_global_error_code', $error_code );
			$this->log_debug( __METHOD__ . '(): Global keys validation failed: ' . $error_code );
		} else {
			update_option( 'gf_chip_global_key_validation', false );
			update_option( 'gf_chip_global_error_code', __( 'An unspecified error occurred.', 'chip-for-gravity-forms' ) );
			$this->log_debug( __METHOD__ . '(): Global keys validation failed with unspecified error.' );
		}
	}

	/**
	 * Feed settings fields (configuration type, Brand ID, Secret Key, optional config, client/purchase/misc mapping).
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$feed_settings_fields                   = parent::feed_settings_fields();
		$feed_settings_fields[0]['description'] = esc_html__( 'Configuration page for CHIP for Gravity Forms.', 'chip-for-gravity-forms' );

		// Remove subscription option from Transaction type.
		unset( $feed_settings_fields[0]['fields'][1]['choices'][2] );

		// Ensure transaction type mandatory.
		$feed_settings_fields[0]['fields'][1]['required'] = true;

		// Temporarily remove transaction type section.
		$transaction_type_array = $feed_settings_fields[0]['fields'][1];
		unset( $feed_settings_fields[0]['fields'][1] );

		// Temporarily remove product and services section.
		$product_and_services = $feed_settings_fields[2];
		$other_settings       = $feed_settings_fields[3];
		unset( $feed_settings_fields[2] );
		unset( $feed_settings_fields[3] );

		// Add CHIP configuration settings.
		$feed_settings_fields[0]['fields'][] = array(
			'name'     => 'chipConfigurationType',
			'label'    => esc_html__( 'Configuration Type', 'chip-for-gravity-forms' ),
			'type'     => 'select',
			'required' => true,
			'onchange' => "jQuery(this).parents('form').submit();",
			'choices'  => array(
				array(
					'label' => esc_html__( 'Select configuration type', 'chip-for-gravity-forms' ),
					'value' => '',
				),
				array(
					'label' => esc_html__( 'Global Configuration', 'chip-for-gravity-forms' ),
					'value' => 'global',
				),
				array(
					'label' => esc_html__( 'Form Configuration', 'chip-for-gravity-forms' ),
					'value' => 'form',
				),
			),
			'tooltip'  => '<h6>' . esc_html__( 'Configuration Type', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Select a configuration type. If you want to configure CHIP on a per-form basis, use Form Configuration. If you want to use globally set keys, choose Global Configuration.', 'chip-for-gravity-forms' ),
		);

		$copy_btn               = sprintf(
			'<p class="gf-chip-copy-global-wrap" style="margin: 0.75em 0 0.5em 0;"><button type="button" class="button gf-chip-copy-global-config" id="gf-chip-copy-global-config">%s</button></p>',
			esc_html__( 'Copy from global configuration', 'chip-for-gravity-forms' )
		);
		$feed_settings_fields[] = array(
			'title'       => esc_html__( 'CHIP Form Configuration Settings', 'chip-for-gravity-forms' ),
			'dependency'  => array(
				'field'  => 'chipConfigurationType',
				'values' => array( 'form' ),
			),
			'description' => '<p>' . esc_html__( 'Set your Brand ID and Secret Key for the use of CHIP with this form.', 'chip-for-gravity-forms' ) . '</p>' . $copy_btn,
			'fields'      => array(
				array(
					'name'     => 'brand_id',
					'label'    => esc_html__( 'Brand ID', 'chip-for-gravity-forms' ),
					'type'     => 'text',
					'class'    => 'medium',
					'required' => true,
					'tooltip'  => '<h6>' . esc_html__( 'Brand ID', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Brand ID enables you to represent your brand in the system using the same CHIP account.', 'chip-for-gravity-forms' ),
				),
				array(
					'name'     => 'secret_key',
					'label'    => esc_html__( 'Secret Key', 'chip-for-gravity-forms' ),
					'type'     => 'text',
					'class'    => 'medium',
					'required' => true,
					'tooltip'  => '<h6>' . esc_html__( 'Secret Key', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'The secret key is used to identify your account with CHIP. We recommend creating a dedicated secret key for each website.', 'chip-for-gravity-forms' ),
				),
			),
		);

		$feed_settings_fields[] = array(
			'title'       => esc_html__( 'Account Status', 'chip-for-gravity-forms' ),
			'dependency'  => array(
				'field'  => 'chipConfigurationType',
				'values' => array( 'form' ),
			),
			'description' => $this->get_form_feed_account_status_description(),
			'fields'      => array(
				// Placeholder so GF renders the section (description above shows the status).
				array(
					'name'  => '_gf_chip_account_status_placeholder',
					'type'  => 'hidden',
					'label' => '',
				),
			),
		);

		$feed_settings_fields[] = array(
			'title'       => esc_html__( 'CHIP Optional Configuration', 'chip-for-gravity-forms' ),
			'dependency'  => array(
				'field'  => 'chipConfigurationType',
				'values' => array( 'form' ),
			),
			'description' => esc_html__( 'Further customize the behavior of the payment.', 'chip-for-gravity-forms' ),
			'fields'      => array(
				array(
					'name'    => 'enable_refund',
					'label'   => esc_html__( 'Refund', 'chip-for-gravity-forms' ),
					'type'    => 'toggle',
					'tooltip' => '<h6>' . esc_html__( 'Refund features', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Whether to enable refund through Gravity Forms. If configured, refunds can be made through Gravity Forms → Entries. Default is disabled.', 'chip-for-gravity-forms' ),
				),
				array(
					'name'    => 'due_strict',
					'label'   => esc_html__( 'Due Strict', 'chip-for-gravity-forms' ),
					'type'    => 'toggle',
					'tooltip' => '<h6>' . esc_html__( 'Due Strict', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Whether to permit payments when the purchase\'s due date has passed. By default those are permitted (and status will be set to overdue once the due moment has passed). If this is set to true, it will not be possible to pay for an overdue invoice, and when the due date has passed the purchase\'s status will be set to expired.', 'chip-for-gravity-forms' ),
				),
				array(
					'name'        => 'due_strict_timing',
					'label'       => esc_html__( 'Due Strict Timing (minutes)', 'chip-for-gravity-forms' ),
					'type'        => 'text',
					'placeholder' => '60 for 60 minutes',
					'tooltip'     => '<h6>' . esc_html__( 'Due Strict Timing (minutes)', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Set due time to enforce due timing for purchases (e.g. 60 for 60 minutes). If due_strict is set while due strict timing is unset, it will default to 1 hour. Leave blank if unsure.', 'chip-for-gravity-forms' ),
				),
			),
		);

		// Readd transaction type section.
		$feed_settings_fields[0]['fields'][] = $transaction_type_array;

		// Readd product and services section.
		$feed_settings_fields[] = $product_and_services;
		$feed_settings_fields[] = $other_settings;

		return apply_filters( 'gf_chip_feed_settings_fields', array_values( $feed_settings_fields ) );
	}

	/**
	 * Other settings field definitions (client metadata, purchase info, miscellaneous, cancel URL).
	 *
	 * @return array
	 */
	public function other_settings_fields() {
		$other_settings_fields                 = parent::other_settings_fields();
		$other_settings_fields[0]['name']      = 'clientInformation';
		$other_settings_fields[0]['label']     = esc_html__( 'Client Information', 'chip-for-gravity-forms' );
		$other_settings_fields[0]['field_map'] = $this->client_info_fields();
		$other_settings_fields[0]['tooltip']   = '<h6>' . esc_html__( 'Client Information', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Map your form fields to the available listed fields. Only email is required; other fields are optional. You may refer to the CHIP API for further information about the specific fields.', 'chip-for-gravity-forms' );

		$conditional_logic = $other_settings_fields[1];
		unset( $other_settings_fields[1] );

		// This dynamic_field_map inspired by gravityformsstripe plugin.
		$other_settings_fields[] = array(
			'name'    => 'clientMetaData',
			'label'   => esc_html__( 'Client Information Metadata', 'chip-for-gravity-forms' ),
			'type'    => 'dynamic_field_map',
			'limit'   => 15,
			'tooltip' => '<h6>' . esc_html__( 'Client Information Metadata', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'You may send custom key information to CHIP /purchases/ client fields. A maximum of 15 custom keys may be sent. The key name must be 40 characters or less, and the mapped data will be truncated accordingly as per CHIP requirements. Accepted keys are: \'bank_account\', \'bank_code\', \'personal_code\', \'street_address\', \'country\', \'city\', \'zip_code\', \'shipping_street_address\', \'shipping_country\', \'shipping_city\', \'shipping_zip_code\', \'legal_name\', \'brand_name\', \'registration_number\', \'tax_number\'.', 'chip-for-gravity-forms' ),
		);

		$other_settings_fields[] = array(
			'name'      => 'purchaseInformation',
			'label'     => esc_html__( 'Purchase Information', 'chip-for-gravity-forms' ),
			'type'      => 'field_map',
			'field_map' => $this->purchase_info_fields(),
			'tooltip'   => '<h6>' . esc_html__( 'Purchase Information', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Map your form fields to the available listed fields.', 'chip-for-gravity-forms' ),
		);

		$other_settings_fields[] = array(
			'name'      => 'miscellaneous',
			'label'     => esc_html__( 'Miscellaneous', 'chip-for-gravity-forms' ),
			'type'      => 'field_map',
			'field_map' => $this->miscellaneous_info_fields(),
			'tooltip'   => '<h6>' . esc_html__( 'Miscellaneous', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Map your form fields to the available listed fields.', 'chip-for-gravity-forms' ),
		);

		$other_settings_fields[] = array(
			'name'        => 'cancelUrl',
			'label'       => esc_html__( 'Cancel URL', 'chip-for-gravity-forms' ),
			'type'        => 'text',
			'placeholder' => 'https://example.com/pages',
			'tooltip'     => '<h6>' . esc_html__( 'Cancel URL', 'chip-for-gravity-forms' ) . '</h6>' . esc_html__( 'Redirect to a custom URL when the customer cancels. Leaving this blank will redirect back to the form page. Note: You can set success behavior by configuring the confirmation redirect.', 'chip-for-gravity-forms' ),
		);

		$other_settings_fields[] = $conditional_logic;

		return array_values( $other_settings_fields );
	}

	/**
	 * Returns empty array to prevent option from showing in feed settings.
	 *
	 * @return array
	 */
	public function option_choices() {
		return array();
	}

	/**
	 * Client info field mappings (email, full name).
	 *
	 * @return array
	 */
	public function client_info_fields() {

		$client_info_fields = array(
			array(
				'name'     => 'email',
				'label'    => esc_html__( 'Email', 'chip-for-gravity-forms' ),
				'required' => true,
			),
			array(
				'name'     => 'full_name',
				'label'    => esc_html__( 'Full Name', 'chip-for-gravity-forms' ),
				'required' => false,
			),
		);

		return apply_filters( 'gf_chip_client_info_fields', $client_info_fields );
	}

	/**
	 * Purchase info field mappings (notes).
	 *
	 * @return array
	 */
	public function purchase_info_fields() {
		$purchase_info_fields = array(
			array(
				'name'     => 'notes',
				'label'    => esc_html__( 'Purchase Note', 'chip-for-gravity-forms' ),
				'required' => false,
			),
		);

		return apply_filters( 'gf_chip_purchase_info_fields', $purchase_info_fields );
	}

	/**
	 * Miscellaneous field mappings (reference).
	 *
	 * @return array
	 */
	public function miscellaneous_info_fields() {
		$miscellaneous_info_fields = array(
			array(
				'name'     => 'reference',
				'label'    => esc_html__( 'Reference', 'chip-for-gravity-forms' ),
				'required' => false,
			),
		);

		return apply_filters( 'gf_chip_miscellaneous_info_fields', $miscellaneous_info_fields );
	}

	/**
	 * Builds the redirect URL to CHIP checkout for the given feed/entry.
	 *
	 * @param array $feed            Feed config.
	 * @param array $submission_data Submission data.
	 * @param array $form            Form.
	 * @param array $entry           Entry.
	 * @return string Checkout URL or empty on failure.
	 */
	public function redirect_url( $feed, $submission_data, $form, $entry ) {

		$entry_id = $entry['id'];

		$this->log_debug( __METHOD__ . '(): Started for entry id: #' . $entry_id );

		$configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global' );

		$payment_amount_location = rgars( $feed, 'meta/paymentAmount' ); // Location for payment amount.
		$name_location           = rgars( $feed, 'meta/clientInformation_full_name' ); // Location for buyer name.
		$email_location          = rgars( $feed, 'meta/clientInformation_email' ); // Location for buyer email address.
		$notes_location          = rgars( $feed, 'meta/purchaseInformation_notes' ); // Location for purchase notes.
		$reference_location      = rgars( $feed, 'meta/miscellaneous_reference' ); // Location for reference.

		$full_name_location_array = array();

		foreach ( $form['fields'] as $field ) {
			if ( 'name' === $field->type ) {
				if ( $name_location !== (string) $field->id ) {
					continue;
				}

				$full_name_location_array[ $field->id ] = array();
				foreach ( $field->inputs as $input ) {
					$full_name_location_array[ $field->id ][] = $input['id'];
				}
			}
		}

		// This branch when the total amount is set to form total.
		if ( 'form_total' === $payment_amount_location ) {
			$amount       = rgar( $submission_data, 'payment_amount' ) * 100;
			$product_name = rgar( $form, 'title' );
			$product_qty  = '1';
		} else {
			// This if the total amount choose to specific product.
			$items = rgar( $submission_data, 'line_items' );
			foreach ( $items as $item ) {
				if ( (string) $item['id'] === (string) $payment_amount_location ) {
					$amount       = $item['unit_price'] * 100;
					$product_name = $item['name'];
					$product_qty  = $item['quantity'];
					break;
				}
			}
		}

		$currency  = rgar( $entry, 'currency' );
		$email     = rgar( $entry, $email_location );
		$notes     = rgar( $entry, $notes_location );
		$reference = rgar( $entry, $reference_location );
		$full_name = rgar( $entry, $name_location, '' );

		if ( ! empty( $full_name_location_array ) ) {
			if ( array_key_exists( $name_location, $full_name_location_array ) ) {
				foreach ( $full_name_location_array[ $name_location ] as $full_name_location ) {
					$full_name .= ' ' . rgar( $entry, $full_name_location );
				}
				$full_name = trim( $full_name );
			}
		}

		$client_meta_data = $this->get_chip_client_meta_data( $feed, $entry, $form );

		$gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' );
		if ( $gf_global_settings ) {
			$secret_key = rgar( $gf_global_settings, 'secret_key' );
			$brand_id   = rgar( $gf_global_settings, 'brand_id' );
			$due_strict = rgar( $gf_global_settings, 'due_strict' );
			$due_timing = rgar( $gf_global_settings, 'due_strict_timing', 60 );
		}

		if ( 'form' === $configuration_type ) {
			$secret_key = rgars( $feed, 'meta/secret_key' );
			$brand_id   = rgars( $feed, 'meta/brand_id' );
			$due_strict = rgars( $feed, 'meta/due_strict' );
			$due_timing = rgars( $feed, 'meta/due_strict_timing', 60 );
		}

		$chip = GF_CHIP_API::get_instance( $secret_key, $brand_id );

		$redirect_url_args = array(
			'callback' => $this->_slug,
			'entry_id' => $entry_id,
		);

		$params = array(
			'success_callback' => $this->get_redirect_url( $redirect_url_args ),
			'success_redirect' => $this->get_redirect_url( $redirect_url_args ),
			'failure_redirect' => $this->get_redirect_url( $redirect_url_args ),
			'cancel_redirect'  => $this->get_redirect_url( $redirect_url_args ),
			'creator_agent'    => 'Gravity Forms: ' . GF_CHIP_MODULE_VERSION,
			'reference'        => empty( $reference ) ? $entry_id : substr( $reference, 0, 128 ),
			'platform'         => 'gravityforms',
			'send_receipt'     => false,
			'due'              => time() + ( absint( $due_timing ) * 60 ),
			'brand_id'         => $brand_id,
			'client'           => array(
				'email'     => $email,
				'full_name' => substr( $full_name, 0, 30 ),
			),
			'purchase'         => array(
				'timezone'   => apply_filters( 'gf_chip_purchase_timezone', $this->get_timezone() ),
				'currency'   => $currency,
				'notes'      => substr( $notes, 0, 10000 ),
				'due_strict' => '1' === $due_strict,
				'products'   => array(
					array(
						'name'     => substr( $product_name, 0, 256 ),
						'price'    => round( $amount ),
						'quantity' => $product_qty,
					),
				),
			),
		);

		// Merge client array with client meta data array.
		$params['client'] += $client_meta_data;

		// Enable customization for gateway charges.
		$params = apply_filters( 'gf_chip_purchases_api_parameters', $params, array( $feed, $submission_data, $form, $entry ) );

		$this->log_debug( __METHOD__ . '(): Params keys ' . wp_json_encode( $params ) );

		$payment = $chip->create_payment( $params );

		if ( ! rgar( $payment, 'id' ) ) {
			$this->log_debug( __METHOD__ . '(): Attempt to create purchases failed ' . wp_json_encode( $payment ) );
			return false;
		}

		// Store chip payment id.
		gform_update_meta( $entry_id, 'chip_payment_id', rgar( $payment, 'id' ), rgar( $form, 'id' ) );

		// Add note.
		$note  = esc_html__( 'Customer was redirected to the payment page. ', 'chip-for-gravity-forms' );
		$note .= esc_html__( 'URL: ', 'chip-for-gravity-forms' ) . $payment['checkout_url'];
		$this->add_note( $entry['id'], $note, 'success' );

		// Add is test note.
		if ( true === $payment['is_test'] ) {
			$note = __( 'This is a test environment where payment status is simulated.', 'chip-for-gravity-forms' );
			$this->add_note( $entry['id'], $note, 'error' );
		}

		$this->log_debug( __METHOD__ . '(): Attempt to create purchases successful ' . wp_json_encode( $payment ) );

		return $payment['checkout_url'];
	}

	/**
	 * Builds redirect URL with callback and entry_id for CHIP.
	 *
	 * @param array $args Query args (e.g. callback, entry_id).
	 * @return string
	 */
	public function get_redirect_url( $args = array() ) {
		return add_query_arg(
			$args,
			home_url( '/' )
		);
	}

	/**
	 * Builds client metadata array from feed custom keys and form field values.
	 *
	 * @param array $feed  Feed config.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return array
	 */
	public function get_chip_client_meta_data( $feed, $entry, $form ) {

		// Initialize metadata array.
		$metadata = array();

		// Find feed metadata.
		$custom_meta = rgars( $feed, 'meta/clientMetaData' );

		if ( is_array( $custom_meta ) ) {

			// Loop through custom meta and add to metadata for stripe.
			foreach ( $custom_meta as $meta ) {

				// If custom key or value are empty, skip meta.
				if ( empty( $meta['custom_key'] ) || empty( $meta['value'] ) ) {
					continue;
				}

				// Get field value for meta key.
				$field_value = $this->get_field_value( $form, $entry, $meta['value'] );

				if ( ! empty( $field_value ) ) {

					// Add to metadata array.
					$metadata[ $meta['custom_key'] ] = $field_value;
				}
			}

			if ( ! empty( $metadata ) ) {
				$this->log_debug( __METHOD__ . '(): ' . wp_json_encode( $metadata ) );
			}
		}

		return $metadata;
	}

	/**
	 * Timezone string for purchase (WordPress timezone or UTC).
	 *
	 * @return string
	 */
	public function get_timezone() {
		if ( preg_match( '/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string() ) ) {
			return wp_timezone_string();
		}

		return 'UTC';
	}

	/**
	 * Payment callback: fetches payment status from CHIP and returns action for post_callback.
	 *
	 * @return array|null Action array or null.
	 */
	public function callback() {
		$entry_id = intval( rgget( 'entry_id' ) );
		$this->log_debug( 'Started ' . __METHOD__ . '(): for entry id #' . $entry_id );

		$entry           = GFAPI::get_entry( $entry_id );
		$submission_feed = $this->get_payment_feed( $entry );

		$this->log_debug( __METHOD__ . "(): Entry ID #$entry_id is set to Feed ID #" . $submission_feed['id'] );

		$configuration_type = rgars( $submission_feed, 'meta/chipConfigurationType', 'global' );

		$gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' );
		if ( $gf_global_settings ) {
			$secret_key = rgar( $gf_global_settings, 'secret_key' );
			$brand_id   = rgar( $gf_global_settings, 'brand_id' );
		}

		if ( 'form' === $configuration_type ) {
			$secret_key = rgars( $submission_feed, 'meta/secret_key' );
			$brand_id   = rgars( $submission_feed, 'meta/brand_id' );
		}

		$chip = GF_CHIP_API::get_instance( $secret_key, $brand_id );

		// Get CHIP Payment ID.
		$payment_id = gform_get_meta( $entry_id, 'chip_payment_id' );

		$chip_payment = $chip->get_payment( $payment_id );

		$this->log_debug( __METHOD__ . "(): Entry ID #$entry_id get purchases information" . wp_json_encode( $chip_payment ) );

		$transaction_data = rgar( $chip_payment, 'transaction_data' );
		$payment_method   = rgar( $transaction_data, 'payment_method' );

		$status = $chip_payment['status'];

		$type = 'fail_payment';
		if ( 'paid' === $chip_payment['status'] ) {
			$type = 'complete_payment';
		}

		$action = array(
			'id'             => $payment_id,
			'type'           => $type,
			'transaction_id' => $payment_id,
			'entry_id'       => $entry_id,
			'payment_method' => $payment_method,
			'amount'         => sprintf( '%.2f', $chip_payment['purchase']['total'] / 100 ),
		);

		// For status other than 'paid' and 'error', add abort_callback to bypass callback.
		if ( 'paid' !== $status && 'error' !== $status ) {
			$this->log_debug( __METHOD__ . "(): Status '$status' is not 'paid' or 'error', adding abort_callback to bypass callback" );
			$action['abort_callback'] = 'true';
		}

		// Acquire lock to prevent concurrency.
		$GLOBALS['wpdb']->get_results(
			"SELECT GET_LOCK('chip_gf_payment', 15);"
		);

		if ( $this->is_duplicate_callback( $payment_id ) ) {
			$action['abort_callback'] = 'true';
		}

		$this->log_debug( 'End of ' . __METHOD__ . '(): params return value: ' . wp_json_encode( $action ) );

		return $action;
	}

	/**
	 * Runs after callback; releases lock and logs.
	 *
	 * @param array  $callback_action Action from callback().
	 * @param string $result          Result.
	 */
	public function post_callback( $callback_action, $result ) {
		$this->log_debug( 'Start of ' . __METHOD__ . '(): for entry id: #' . $callback_action['entry_id'] );

		// Release lock to enable concurrency.
		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('chip_gf_payment');"
		);

		$entry_id = $callback_action['entry_id'];
		$entry    = GFAPI::get_entry( $entry_id );
		$url      = rgar( $entry, 'source_url' );
		$message  = __( 'Payment failed. ', 'chip-for-gravity-forms' );

		if ( 'complete_payment' === $callback_action['type'] ) {
			$entry_id = $callback_action['entry_id'];
			$form_id  = $entry['form_id'];

			$message = __( 'Payment successful. ', 'chip-for-gravity-forms' );
			$url     = $this->get_confirmation_url( $entry, $form_id );
		} else {
			$submission_feed = $this->get_payment_feed( $entry );
			$cancel_url      = rgars( $submission_feed, 'meta/cancelUrl' );

			if ( $cancel_url && filter_var( $cancel_url, FILTER_VALIDATE_URL ) ) {
				$url = $cancel_url;
			}
		}

		// Output payment status.
		echo esc_html( $message );

		// Output redirection link.
		printf(
			'<a href="%1$s">%2$s</a>%3$s',
			esc_url( $url ),
			esc_html__( 'Click here', 'chip-for-gravity-forms' ),
			esc_html__( ' to redirect to the confirmation page', 'chip-for-gravity-forms' )
		);

		// Redirect user automatically.
		echo '<script>window.location.replace(\'' . esc_url_raw( $url ) . '\')</script>';
		$this->log_debug( 'End of ' . __METHOD__ . '(): for entry id: #' . $callback_action['entry_id'] );
	}

	/**
	 * Handles post-payment callback: confirmation URL redirect or thank-you page.
	 *
	 * @param array  $entry           Entry.
	 * @param array  $callback_action Callback action.
	 * @param string $result          Result.
	 */
	public function handle_post_payment_callback( $entry, $callback_action, $result ) {
		// Only cancel payment if it's a failed payment to prevent retry.
		if ( 'fail_payment' !== rgar( $callback_action, 'type' ) ) {
			return;
		}

		$entry_id   = rgar( $entry, 'id' );
		$payment_id = rgar( $callback_action, 'transaction_id' );

		if ( empty( $payment_id ) ) {
			$this->log_debug( __METHOD__ . "(): No payment ID found for entry #$entry_id, skipping cancel" );
			return;
		}

		$this->log_debug( __METHOD__ . "(): Attempting to cancel payment #$payment_id for entry #$entry_id" );

		$submission_feed    = $this->get_payment_feed( $entry );
		$configuration_type = rgars( $submission_feed, 'meta/chipConfigurationType', 'global' );

		$gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' );
		if ( $gf_global_settings ) {
			$secret_key = rgar( $gf_global_settings, 'secret_key' );
			$brand_id   = rgar( $gf_global_settings, 'brand_id' );
		}

		if ( 'form' === $configuration_type ) {
			$secret_key = rgars( $submission_feed, 'meta/secret_key' );
			$brand_id   = rgars( $submission_feed, 'meta/brand_id' );
		}

		$chip          = GF_CHIP_API::get_instance( $secret_key, $brand_id );
		$cancel_result = $chip->cancel_payment( $payment_id );

		if ( $cancel_result && rgar( $cancel_result, 'id' ) ) {
			$this->log_debug( __METHOD__ . "(): Successfully cancelled payment #$payment_id for entry #$entry_id" );
		} else {
			$this->log_debug( __METHOD__ . "(): Failed to cancel payment #$payment_id for entry #$entry_id. Result: " . wp_json_encode( $cancel_result ) );
		}
	}

	/**
	 * Builds confirmation URL with hash for thank-you page.
	 *
	 * @param array  $entry   Entry.
	 * @param string $form_id Form ID.
	 * @return string
	 */
	public function get_confirmation_url( $entry, $form_id ) {
		$redirect_url_args = array(
			'gf_chip_success' => 'true',
			'entry_id'        => $entry['id'],
			'form_id'         => $form_id,
		);

		$redirect_url_args['hash'] = wp_hash( implode( $redirect_url_args ) );

		return add_query_arg(
			$redirect_url_args,
			rgar( $entry, 'source_url' )
		);
	}

	/**
	 * Handles thank-you/confirmation page: validates hash and displays confirmation.
	 */
	public function maybe_thankyou_page() {
		if ( ! rgget( 'gf_chip_success' ) || ! rgget( 'entry_id' ) || ! rgget( 'form_id' ) ) {
			return;
		}

		$entry_id = sanitize_key( rgget( 'entry_id' ) );
		$form_id  = sanitize_key( rgget( 'form_id' ) );
		$this->log_debug( __METHOD__ . '(): confirmation page for entry id: #' . $entry_id );

		if ( rgget( 'hash' ) !== wp_hash( 'true' . $entry_id . $form_id ) ) {
			$this->log_debug( __METHOD__ . '(): wp_hash failure for entry id: #' . $entry_id );
			return;
		}

		$form  = GFAPI::get_form( $form_id );
		$entry = GFAPI::get_entry( $entry_id );

		if ( ! class_exists( 'GFFormDisplay' ) ) {
			require_once GFCommon::get_base_path() . '/form_display.php';
		}

		$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );

		if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
			$this->log_debug( __METHOD__ . '(): confirmation is redirect type for entry id: #' . $entry_id );
			header( "Location: {$confirmation['redirect']}" );
			exit;
		}

		GFFormDisplay::$submission[ $form_id ] = array(
			'is_confirmation'      => true,
			'confirmation_message' => $confirmation,
			'form'                 => $form,
			'lead'                 => $entry,
		);

		$this->log_debug( __METHOD__ . '(): confirmation is non redirect type for entry id: #' . $entry_id );
	}

	// This method content can be inspired from gravityformsauthorizenet.
	// This method used for subscription for gravityformsauthorizenet.
	// public function check_status() {
	// }.

	/**
	 * Supported notification events for this addon.
	 *
	 * @param array $form Form.
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		return array(
			'complete_payment' => esc_html__( 'Payment Completed', 'chip-for-gravity-forms' ),
			'refund_payment'   => esc_html__( 'Payment Refunded', 'chip-for-gravity-forms' ),
			'fail_payment'     => esc_html__( 'Payment Failed', 'chip-for-gravity-forms' ),
		);
	}

	/**
	 * Completes payment: parent logic and triggers delayed feeds.
	 *
	 * @param array $entry  Entry (by reference).
	 * @param array $action Callback action.
	 * @return bool
	 */
	public function complete_payment( &$entry, $action ) {
		parent::complete_payment( $entry, $action );

		$transaction_id = rgar( 'transaction_id', $action );
		$form           = GFAPI::get_form( $entry['form_id'] );
		$feed           = $this->get_payment_feed( $entry, $form );

		$this->trigger_payment_delayed_feeds( $transaction_id, $feed, $entry, $form );

		return true;
	}

	/**
	 * Entry info (refund button and UI) for paid CHIP entries.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $entry   Entry.
	 */
	public function entry_info( $form_id, $entry ) {

		// Return if no transaction_id.
		if ( empty( $entry['transaction_id'] ) || empty( $entry['payment_method'] ) || 'Paid' !== $entry['payment_status'] || '1' !== $entry['transaction_type'] ) {
			return;
		}

		// Return if payment gateway is not chip.
		if ( ! $this->is_payment_gateway( $entry['id'] ) ) {
			return;
		}

		?>
		<div id="gf_refund_container">
			<div class="message" style="display:none;"></div>
		</div>
		<br>
		<input id="refundpay" type="button" name="refundpay" value="<?php esc_html_e( 'Refund', 'chip-for-gravity-forms' ); ?>"
			class="button uninstall-addon red" onclick="RefundPayment();" onkeypress="RefundPayment();" <?php echo esc_attr( defined( 'GF_CHIP_DISABLE_REFUND_PAYMENT' ) ? 'disabled' : '' ); ?> />
		<img src="<?php echo esc_url( GFCommon::get_base_url() . '/images/spinner.svg' ); ?>" id="refund_spinner" style="display: none;" />

		<script type="text/javascript">
			function RefundPayment() {

				jQuery('#refund_spinner').fadeIn();

				jQuery.post(ajaxurl, {
					action: "gf_chip_refund_payment",
					gf_chip_refund_payment: '<?php echo esc_js( wp_create_nonce( 'gf_chip_refund_payment' ) ); ?>',
					entryId: '<?php echo absint( $entry['id'] ); ?>'
				},
					function (response) {
						if (response) {
							displayMessage(response, "error", "#gf_refund_container");
						} else {
							displayMessage(<?php echo wp_json_encode( esc_html__( 'Refund has been executed successfully.', 'chip-for-gravity-forms' ) ); ?>, "success", "#gf_refund_container");

							jQuery('#refundpay').hide();
						}

						jQuery('#refund_spinner').hide();
					}
				);
			}
		</script>

		<?php
	}

	/**
	 * AJAX: Returns global Brand ID and Secret Key for the "Copy from global configuration" button on feed settings.
	 */
	public function ajax_get_global_credentials() {
		check_ajax_referer( 'gf_chip_get_global_credentials', 'nonce' );

		if ( ! $this->current_user_can_any( $this->_capabilities_form_settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'chip-for-gravity-forms' ) ) );
		}

		$brand_id   = $this->get_plugin_setting( 'brand_id' );
		$secret_key = $this->get_plugin_setting( 'secret_key' );

		if ( empty( $brand_id ) && empty( $secret_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Global configuration is not set.', 'chip-for-gravity-forms' ) ) );
		}

		wp_send_json_success(
			array(
				'brand_id'   => is_string( $brand_id ) ? $brand_id : '',
				'secret_key' => is_string( $secret_key ) ? $secret_key : '',
			)
		);
	}

	/**
	 * AJAX handler: processes refund request and outputs JSON or HTML error.
	 */
	public function chip_refund_payment() {
		check_admin_referer( 'gf_chip_refund_payment', 'gf_chip_refund_payment' );

		if ( defined( 'GF_CHIP_DISABLE_REFUND_PAYMENT' ) ) {
			esc_html_e( 'Refund feature has been disabled by administrators.', 'chip-for-gravity-forms' );
			die();
		}

		$entry_id = absint( rgpost( 'entryId' ) );

		$entry = GFAPI::get_entry( $entry_id );
		$feed  = $this->get_payment_feed( $entry );

		$this->log_debug( __METHOD__ . '(): Entry ID #' . $entry['id'] . ' is set to Feed ID #' . $feed['id'] );

		$configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global' );

		$gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' );
		if ( $gf_global_settings ) {
			$secret_key = rgar( $gf_global_settings, 'secret_key' );
			$brand_id   = rgar( $gf_global_settings, 'brand_id' );
			$refund     = rgar( $gf_global_settings, 'enable_refund', false );
		}

		if ( 'form' === $configuration_type ) {
			$secret_key = rgars( $feed, 'meta/secret_key' );
			$brand_id   = rgars( $feed, 'meta/brand_id' );
			$refund     = rgars( $feed, 'meta/enable_refund', false );
		}

		if ( '1' !== $refund ) {
			esc_html_e( 'Refund feature has been disabled.', 'chip-for-gravity-forms' );
			die();
		}

		$chip       = GF_CHIP_API::get_instance( $secret_key, $brand_id );
		$payment_id = rgar( $entry, 'transaction_id' );
		$payment    = $chip->refund_payment( $payment_id, array() );

		if ( ! is_array( $payment ) || ! array_key_exists( 'id', $payment ) ) {
			$this->log_debug( __METHOD__ . '(): Refund API error. Response: ' . wp_json_encode( $payment ) );
			echo esc_html( __( 'There was an error while refunding the payment.', 'chip-for-gravity-forms' ) );
			die();
		}

		$action = array(
			'transaction_id' => $payment['id'],
			'amount'         => $entry['payment_amount'],
		);

		if ( ! $this->refund_payment( $entry, $action ) ) {
			esc_html_e( 'There was an error while refunding the payment.', 'chip-for-gravity-forms' );
		}

		die();
	}

	/**
	 * Uninstall: deletes plugin options and calls parent uninstall.
	 */
	public function uninstall() {
		$option_names = array(
			'gf_chip_global_key_validation',
			'gf_chip_global_error_code',
		);

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}

		parent::uninstall();
	}
}
