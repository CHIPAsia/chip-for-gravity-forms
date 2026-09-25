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
	 * Registers admin-side components.
	 *
	 * Only the navigation filter is added here; the page renders through the
	 * callback registered with it, so nothing in the admin runs on the front
	 * end.
	 *
	 * @return void
	 */
	public function init_admin() {
		parent::init_admin();

		GF_Chip_Subscriptions_Page::register();
	}

	/**
	 * Runs before init. Registers actions for thank-you page and AJAX handlers.
	 */
	public function pre_init() {
		// Inspired by gravityformsstripe.
		add_action( 'wp', array( $this, 'maybe_thankyou_page' ), 5 );
		add_action( 'wp', array( 'GF_Chip_Card_Update_Page', 'maybe_handle' ), 4 );
		add_action( 'admin_post_chip_send_card_update', array( 'GF_Chip_Renewal_Notifications', 'handle_admin_send' ) );
		add_action( 'admin_post_chip_retry_renewal', array( 'GF_Chip_Renewal_Notifications', 'handle_admin_retry' ) );
		add_action( 'admin_post_chip_charge_now', array( 'GF_Chip_Renewal_Notifications', 'handle_admin_charge_now' ) );
		add_action( 'admin_post_chip_charge_now_confirm', array( 'GF_Chip_Renewal_Notifications', 'handle_admin_charge_now_confirm' ) );
		GF_Chip_Renewal_Notifications::register();
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
		add_action( 'gform_post_save_feed_settings', array( $this, 'maybe_store_public_key_on_feed_save' ), 10, 4 );
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
		// Anchored on the plugin's base URL, not on __FILE__. This method
		// lives in includes/, and plugins_url() resolves a relative path
		// against the directory of the file passed to it, so the old form
		// looked for includes/assets/logo.svg. No such directory exists —
		// the logo ships at the plugin root — and the icon silently 404'd.
		return $this->get_base_url() . '/assets/logo.svg';
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
		// Same anchor bug as get_menu_icon(): plugins_url() with __FILE__
		// resolves against includes/, so this pointed at a screenshot path
		// that does not exist and the "View configuration screenshot" link
		// 404'd.
		$img_url = $this->get_base_url() . '/assets/form-settings.png';
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
				// translators: %1$s is the opening anchor tag, %2$s is the closing anchor tag for the screenshot link.
				esc_html__( 'To use this global configuration on a form, choose "Global Configuration" in the form\'s CHIP feed settings. %1$sView configuration screenshot%2$s.', 'chip-for-gravity-forms' ),
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
			$company_uid = $chip->get_company_uid();
			if ( is_string( $company_uid ) && '' !== $company_uid ) {
				update_option( 'gf_chip_public_key_' . $company_uid, $public_key, false );
			}
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
	 * After feed save: store public key by company_uid when Form Configuration has brand_id and secret_key.
	 *
	 * @param int    $feed_id   Feed ID.
	 * @param int    $form_id   Form ID.
	 * @param array  $settings  Saved feed meta (e.g. chipConfigurationType, brand_id, secret_key).
	 * @param object $addon    GFAddOn instance that saved the feed.
	 */
	public function maybe_store_public_key_on_feed_save( $feed_id, $form_id, $settings, $addon ) {
		if ( ! is_object( $addon ) || ! isset( $addon->_slug ) || $addon->_slug !== $this->_slug ) {
			return;
		}
		$configuration_type = isset( $settings['chipConfigurationType'] ) ? $settings['chipConfigurationType'] : '';
		if ( 'form' !== $configuration_type ) {
			return;
		}
		$brand_id   = isset( $settings['brand_id'] ) ? trim( (string) $settings['brand_id'] ) : '';
		$secret_key = isset( $settings['secret_key'] ) ? trim( (string) $settings['secret_key'] ) : '';
		if ( '' === $brand_id || '' === $secret_key ) {
			return;
		}
		$chip       = GF_CHIP_API::get_instance( $secret_key, $brand_id );
		$public_key = $chip->get_public_key();
		if ( ! is_string( $public_key ) ) {
			return;
		}
		$company_uid = $chip->get_company_uid();
		if ( is_string( $company_uid ) && '' !== $company_uid ) {
			update_option( 'gf_chip_public_key_' . $company_uid, $public_key, false );
		}
	}

	/**
	 * Resolves CHIP credentials and optional settings for the given feed.
	 *
	 * @param array $feed Feed config.
	 * @return array Keys: secret_key, brand_id, due_strict, due_timing, refund.
	 */
	public function get_credentials_for_feed( $feed ) {
		$configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global' );

		$secret_key = '';
		$brand_id   = '';
		$due_strict = '';
		$due_timing = 60;
		$refund     = false;

		$gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' );
		if ( $gf_global_settings ) {
			$secret_key = rgar( $gf_global_settings, 'secret_key' );
			$brand_id   = rgar( $gf_global_settings, 'brand_id' );
			$due_strict = rgar( $gf_global_settings, 'due_strict' );
			$due_timing = rgar( $gf_global_settings, 'due_strict_timing', 60 );
			$refund     = rgar( $gf_global_settings, 'enable_refund', false );
		}

		if ( 'form' === $configuration_type ) {
			$secret_key = rgars( $feed, 'meta/secret_key' );
			$brand_id   = rgars( $feed, 'meta/brand_id' );
			$due_strict = rgars( $feed, 'meta/due_strict' );
			$due_timing = rgars( $feed, 'meta/due_strict_timing', 60 );
			$refund     = rgars( $feed, 'meta/enable_refund', false );
		}

		return array(
			'secret_key' => $secret_key,
			'brand_id'   => $brand_id,
			'due_strict' => $due_strict,
			'due_timing' => $due_timing,
			'refund'     => $refund,
		);
	}

	/**
	 * Card payment method group that CHIP recurring tokens require.
	 *
	 * Mirrors chip-for-woocommerce's CARD_GROUP: the card NETWORKS only.
	 * Recurring tokens are card-only, so a subscription cannot be offered FPX,
	 * DuitNow QR or the e-wallets even though a one-time payment can.
	 *
	 * Do NOT add the generic 'card' key here. In the sibling plugins 'card' is
	 * a UI multiselect key that is expanded to these networks before the API
	 * call; CHIP rejects it outright if sent:
	 *   HTTP 400 {"payment_method_whitelist":{"0":[{"message":
	 *   "\"card\" is not a valid choice.","code":"invalid_choice"}]}}
	 *
	 * @var array
	 */
	const RECURRING_CARD_METHODS = array( 'visa', 'mastercard', 'maestro' );

	/**
	 * Payment method whitelist for a recurring (subscription) purchase.
	 *
	 * @return array
	 */
	public static function get_recurring_payment_method_whitelist() {
		$whitelist = self::RECURRING_CARD_METHODS;

		return (array) apply_filters( 'gf_chip_recurring_payment_method_whitelist', $whitelist );
	}

	/**
	 * Extra CHIP purchase params needed to obtain a recurring token.
	 *
	 * Returns an empty array for anything that is not a subscription feed.
	 * This must stay strictly gated: an unconditional `force_recurring` would
	 * tokenise one-time payments too and change behaviour for every existing
	 * user of the plugin.
	 *
	 * @param array $feed The Gravity Forms feed object.
	 * @return array Params to merge into the purchase payload, or empty.
	 */
	public static function get_checkout_token_params( $feed ) {
		$transaction_type = is_array( $feed ) ? rgars( $feed, 'meta/transactionType' ) : '';

		if ( 'subscription' !== $transaction_type ) {
			return array();
		}

		return array(
			'force_recurring'          => true,
			'payment_method_whitelist' => self::get_recurring_payment_method_whitelist(),
		);
	}

	/**
	 * Merges subscription token params into a purchase payload.
	 *
	 * Split out from redirect_url() so the wiring itself is unit testable —
	 * the params builder being correct is worthless if it is never applied.
	 *
	 * @param array $params The purchase params built so far.
	 * @param array $feed   The Gravity Forms feed object.
	 * @return array The payload with token params merged for a subscription feed.
	 */
	public static function merge_checkout_token_params( $params, $feed ) {
		$token_params = self::get_checkout_token_params( $feed );

		if ( empty( $token_params ) ) {
			// Leave a one-time payload byte-for-byte unchanged.
			return $params;
		}

		return array_merge( (array) $params, $token_params );
	}

	/**
	 * The amount to collect on a subscription's first charge, in cents.
	 *
	 * Gravity Forms reports three numbers that are NOT interchangeable, and
	 * core states plainly which is which (class-gf-payment-addon.php,
	 * get_order_data):
	 *
	 *  - `payment_amount` — the form total. It is the recurring value of a
	 *    subscription, but a trial does not replace it: with
	 *    trial_product = 'enter_amount', core keeps payment_amount at the
	 *    full price and only reports the trial separately.
	 *  - `trial` — the amount to collect first when a trial is configured.
	 *    Zero means the first cycle is free. This is the field to prefer.
	 *  - `setup_fee` — collected on top of whichever of the two applies.
	 *
	 * Reading payment_amount alone therefore charges full price during a
	 * trial and never collects a setup fee. A setup fee is money owed now:
	 * it must be collected immediately, including when a trial means the
	 * recurring amount is not.
	 *
	 * Returns 0 for a genuinely free trial — the caller must then skip
	 * capture rather than authorise, so no funds are held on the customer's
	 * card for a payment that is not being taken.
	 *
	 * `$has_trial` must come from the feed (`meta/trial_enabled`), NOT from
	 * the submission data. Core's get_order_data() always returns a `trial`
	 * key, so its presence says nothing; and when the trial is configured as
	 * `enter_amount` with a value of '0' core's own
	 * `rgar(...) ? ... : 0` treats it as falsy and returns an int 0 —
	 * identical to having no trial at all. The feed is the only reliable
	 * signal, and without it a free trial would be charged in full.
	 *
	 * @param array $submission_data Submission data from the payment add-on.
	 * @param bool  $has_trial       Whether the feed configures a trial.
	 * @return int Amount in the smallest currency unit.
	 */
	public static function resolve_first_charge_cents( $submission_data, $has_trial = false ) {
		$payment_amount = (float) rgar( $submission_data, 'payment_amount', 0 );

		// A one-time feed has neither a trial nor a setup fee, so its first
		// charge is the form total. Checked explicitly rather than inferring
		// from zero values, so the intent survives.
		if ( ! rgar( $submission_data, 'is_subscription' ) ) {
			return (int) round( $payment_amount * 100 );
		}

		// A configured trial replaces the first charge. It may legitimately
		// be zero, which is a free trial rather than "no trial".
		$trial_amount = (float) rgar( $submission_data, 'trial', 0 );
		$first_cycle  = $has_trial ? $trial_amount : $payment_amount;
		$setup_fee    = (float) rgar( $submission_data, 'setup_fee', 0 );

		return (int) round( ( $first_cycle + $setup_fee ) * 100 );
	}

	/**
	 * Whether a subscription's first charge must be authorised without
	 * capture.
	 *
	 * Only a genuinely free first charge skips capture. `skip_capture` means
	 * "authorise now, do not take the money", so setting it while an amount
	 * is owed would reserve funds on the customer's card and leave the
	 * merchant unpaid.
	 *
	 * A trial with a setup fee is the case to be careful about: the setup
	 * fee is payable immediately even though the trial amount is zero, so
	 * the decision keys on the total first charge and never on the mere
	 * presence of a trial.
	 *
	 * @param int $amount_cents Resolved first charge.
	 * @return bool
	 */
	public static function should_skip_first_capture( $amount_cents ) {
		return (int) $amount_cents <= 0;
	}

	/**
	 * The product name to send for a subscription's first charge.
	 *
	 * The name matters when the charge is not the recurring one. A free trial
	 * still needs a named product, and a setup fee is what is actually being
	 * collected, so labelling it with the subscription's name would describe
	 * a payment the customer is not making.
	 *
	 * Core removes the trial and setup-fee fields from the line items once
	 * they are flagged as such, so their own names are not available here —
	 * hence the fixed labels.
	 *
	 * @param array  $submission_data    Submission data from the payment add-on.
	 * @param string $recurring_label    The subscription's own label.
	 * @param int    $amount_cents       Resolved first charge.
	 * @param bool   $has_trial          Whether the feed configures a trial.
	 * @return string
	 */
	public static function resolve_first_charge_label( $submission_data, $recurring_label, $amount_cents, $has_trial = false ) {
		$setup_fee = (float) rgar( $submission_data, 'setup_fee', 0 );
		$trial     = (float) rgar( $submission_data, 'trial', 0 );

		if ( $setup_fee > 0 && $has_trial && $trial <= 0 ) {
			// A free trial with a setup fee: the fee is the whole charge.
			return __( 'Setup fee', 'chip-for-gravity-forms' );
		}

		if ( (int) $amount_cents <= 0 ) {
			// A free trial with nothing to collect: the product still needs
			// a name, and this is the honest description of the purchase.
			return __( 'Free trial', 'chip-for-gravity-forms' );
		}

		return $recurring_label;
	}

	/**
	 * Extracts the recurring token from a CHIP purchase response.
	 *
	 * CHIP returns the token in one of two shapes:
	 *  - `is_recurring_token === true`: the purchase **id itself** is the token
	 *    and there is no separate `recurring_token` field;
	 *  - otherwise: the `recurring_token` field holds it.
	 *
	 * Returns null when neither is present, rather than falling back to the
	 * purchase id — a wrong token would be stored and the subscription could
	 * never be renewed.
	 *
	 * @param mixed $purchase Decoded CHIP purchase response.
	 * @return string|null
	 */
	public static function extract_recurring_token( $purchase ) {
		if ( ! is_array( $purchase ) ) {
			return null;
		}

		$is_recurring_token = ! empty( $purchase['is_recurring_token'] );
		if ( $is_recurring_token && ! empty( $purchase['id'] ) ) {
			return (string) $purchase['id'];
		}

		if ( ! empty( $purchase['recurring_token'] ) ) {
			return (string) $purchase['recurring_token'];
		}

		return null;
	}

	/**
	 * The amount to charge for one renewal cycle, in cents.
	 *
	 * Resolution order, most authoritative first:
	 *
	 *  1. `chip_sub_amount` — the recurring amount the customer agreed to when
	 *     they subscribed. It wins over anything recomputed from the form now:
	 *     the form's price may have changed since, and charging the current
	 *     price would silently bill a customer more than they consented to.
	 *  2. The feed's own recurring amount, passed in by the caller. This is
	 *     what covers a trial subscription set up before the marker existed:
	 *     its first charge was legitimately zero, so nothing was stored then,
	 *     and without this fallback such a subscription would never renew.
	 *  3. The entry's `payment_amount` — the last settled charge. Only reached
	 *     for entries that predate both markers; for a plain subscription it
	 *     equals the recurring amount.
	 *
	 * Returns 0 when nothing usable is found, which the caller must treat as a
	 * refusal to charge rather than as a free cycle.
	 *
	 * @param array $entry          Entry with chip_sub_* meta flattened in.
	 * @param int   $feed_cents     Recurring amount derived from the feed, or 0.
	 * @return int Amount in cents.
	 */
	public static function resolve_renewal_amount_cents( $entry, $feed_cents = 0 ) {
		$stored = rgar( $entry, 'chip_sub_amount' );

		if ( is_numeric( $stored ) && (int) $stored > 0 ) {
			return (int) $stored;
		}

		if ( (int) $feed_cents > 0 ) {
			return (int) $feed_cents;
		}

		return (int) round( (float) rgar( $entry, 'payment_amount' ) * 100 );
	}

	/**
	 * The recurring amount configured on the feed, in cents.
	 *
	 * Core's `get_submission_data()` already resolves the amount for the
	 * payment field, and for a subscription that field is `recurringAmount`.
	 * Crucially it also excludes the trial and setup-fee products from the
	 * total, so what comes back is the amount due on a NORMAL cycle — which is
	 * exactly what a renewal has to charge. Reimplementing that exclusion here
	 * would drift from core the moment it changes.
	 *
	 * Returns 0 when the feed is not a subscription or nothing can be
	 * resolved, which the caller treats as "no answer", not as a zero charge.
	 *
	 * @param array $feed  Payment feed.
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return int Amount in cents.
	 */
	private function resolve_feed_recurring_cents( $feed, $form, $entry ) {
		if ( empty( $feed ) || empty( $form ) ) {
			return 0;
		}

		if ( 'subscription' !== rgars( $feed, 'meta/transactionType' ) ) {
			return 0;
		}

		$submission_data = $this->get_submission_data( $feed, $form, $entry );

		if ( ! is_array( $submission_data ) ) {
			return 0;
		}

		return (int) round( (float) rgar( $submission_data, 'payment_amount' ) * 100 );
	}

	/**
	 * Purchase params for one renewal cycle.
	 *
	 * A renewal must NOT reuse the purchase the token was issued on. CHIP only
	 * charges a purchase that can still be paid, and the original was settled
	 * on the first cycle, so charging it is rejected outright:
	 *
	 *   400 purchase_charge_wrong_status
	 *   "Only purchases that can be paid for can be charged."
	 *
	 * Every cycle therefore gets its own purchase, which the saved token then
	 * authorises. `force_recurring` is deliberately absent: it asks CHIP to
	 * ISSUE a token, and the token already exists.
	 *
	 * `success_callback` is set even though the token charge normally settles
	 * synchronously: a `pending_charge` is only ever resolved by that callback,
	 * and without it such a cycle would be collected at CHIP but never
	 * reflected here.
	 *
	 * @param array  $credentials  Credentials from get_credentials_for_feed().
	 * @param array  $entry        Entry with chip_sub_* meta flattened in.
	 * @param int    $amount_cents Amount for this cycle, in cents.
	 * @param string $product_name Line-item label.
	 * @param string $email        Client email.
	 * @param string $full_name    Client name.
	 * @param string $timezone     Purchase timezone.
	 * @return array
	 */
	public static function build_renewal_purchase_params( $credentials, $entry, $amount_cents, $product_name, $email, $full_name, $timezone ) {
		return array(
			'creator_agent'    => 'Gravity Forms: ' . ( defined( 'GF_CHIP_MODULE_VERSION' ) ? GF_CHIP_MODULE_VERSION : '' ),
			'reference'        => (string) rgar( $entry, 'id' ),
			'platform'         => 'gravityforms',
			'send_receipt'     => false,
			'brand_id'         => rgar( $credentials, 'brand_id' ),
			'success_callback' => add_query_arg(
				array(
					'callback' => 'gravityformschip',
					'entry_id' => rgar( $entry, 'id' ),
				),
				home_url( '/' )
			),
			'client'           => array(
				'email'     => $email,
				'full_name' => $full_name,
			),
			'purchase'         => array(
				'timezone'   => $timezone,
				'currency'   => rgar( $entry, 'currency' ),
				'due'        => time() + 3600,
				'due_strict' => false,
				'products'   => array(
					array(
						'name'     => substr( $product_name, 0, 256 ),
						'price'    => (int) $amount_cents,
						'quantity' => 1,
					),
				),
			),
		);
	}

	/**
	 * Persists the recurring token and subscription id for an entry.
	 *
	 * Called once a subscription purchase has been paid. Without a stored
	 * token the renewal engine has nothing to charge, so the caller must treat
	 * a false return as a hard failure worth surfacing rather than a warning —
	 * a subscription with no token looks active but can never renew.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param int   $form_id  Form ID.
	 * @param mixed $purchase Decoded CHIP purchase response.
	 * @return bool True when a token was stored.
	 */
	public static function persist_subscription_token( $entry_id, $form_id, $purchase ) {
		return self::store_token( $entry_id, $form_id, $purchase, true );
	}

	/**
	 * Refreshes the token after a renewal charge.
	 *
	 * Same as persist_subscription_token(), except that it never rewrites the
	 * subscription id. On a renewal the purchase response describes the NEW
	 * cycle's purchase, and the token's owner must stay the purchase the token
	 * was issued against — that is the id the card-update flow deletes the old
	 * token with, so overwriting it would revoke the wrong subscription.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param int   $form_id  Form ID.
	 * @param mixed $purchase Decoded CHIP charge response.
	 * @return bool True when a token was stored.
	 */
	public static function refresh_subscription_token( $entry_id, $form_id, $purchase ) {
		return self::store_token( $entry_id, $form_id, $purchase, false );
	}

	/**
	 * Shared token store.
	 *
	 * @param int   $entry_id         Entry ID.
	 * @param int   $form_id          Form ID.
	 * @param mixed $purchase         Decoded CHIP purchase or charge response.
	 * @param bool  $write_subscription_id Whether to (re)record the owner id.
	 * @return bool True when a token was stored.
	 */
	private static function store_token( $entry_id, $form_id, $purchase, $write_subscription_id ) {
		$token = self::extract_recurring_token( $purchase );

		if ( null === $token || '' === $token ) {
			return false;
		}

		gform_update_meta( $entry_id, 'chip_recurring_token', $token, $form_id );

		if ( $write_subscription_id ) {
			// The purchase id identifies the CHIP subscription and is needed to
			// charge or delete the token later. It is not always the token
			// itself (see extract_recurring_token), so store it separately.
			$subscription_id = is_array( $purchase ) && ! empty( $purchase['id'] )
				? (string) $purchase['id']
				: $token;
			gform_update_meta( $entry_id, 'chip_subscription_id', $subscription_id, $form_id );
		}

		return true;
	}

	/**
	 * Subscription states this plugin tracks in `chip_sub_status`.
	 *
	 * Distinct from Gravity Forms' own `payment_status`, which core uses for
	 * its own UI predicates. Core's cancel-button gate reads `payment_status`,
	 * so the two must stay in step: cancelling sets both.
	 *
	 * @var array
	 */
	const SUBSCRIPTION_STATES = array( 'pending', 'active', 'on-hold', 'cancelled', 'expired', 'failed' );

	/**
	 * Whether an entry is a subscription.
	 *
	 * Gravity Forms stores transaction_type '2' for a subscription and '1'
	 * for a one-time payment.
	 *
	 * @param mixed $entry Entry object.
	 * @return bool
	 */
	public static function is_subscription_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return false;
		}

		return '2' === (string) rgar( $entry, 'transaction_type' );
	}

	/**
	 * Whether the entry-detail refund UI should render.
	 *
	 * Deliberately NOT gated on the transaction type: a subscription's
	 * payments are refundable too. The previous check required
	 * transaction_type === '1', which hid the refund button on every
	 * subscription entry.
	 *
	 * Accepts 'Active' as well as 'Paid'. Gravity Forms records a
	 * subscription's payment_status as 'Active' (see core's
	 * start_subscription(), which sets it), not 'Paid' — so requiring 'Paid'
	 * hid the refund button on exactly the entries this predicate was
	 * widened to support. A subscription payment is a payment: it is
	 * refundable, and a merchant needs to be able to give the money back.
	 *
	 * @param mixed $entry Entry object.
	 * @return bool
	 */
	public static function should_render_refund_ui( $entry ) {
		if ( ! is_array( $entry ) ) {
			return false;
		}

		if ( empty( $entry['transaction_id'] ) || empty( $entry['payment_method'] ) ) {
			return false;
		}

		$status = (string) rgar( $entry, 'payment_status' );

		return in_array( $status, array( 'Paid', 'Active' ), true );
	}

	/**
	 * Reads the plugin's own subscription state for an entry.
	 *
	 * Returns `pending` for any unrecognised or absent value rather than
	 * passing it through, so a typo cannot silently bypass the renewal
	 * engine's `active` filter.
	 *
	 * @param mixed $entry Entry object.
	 * @return string One of SUBSCRIPTION_STATES.
	 */
	public static function get_subscription_state( $entry ) {
		$state = is_array( $entry ) ? rgar( $entry, 'chip_sub_status' ) : '';

		return in_array( $state, self::SUBSCRIPTION_STATES, true ) ? $state : 'pending';
	}

	/**
	 * Whether a subscription may be cancelled.
	 *
	 * Mirrors the condition Gravity Forms core applies before rendering its
	 * Cancel Subscription button, so the plugin can assert the precondition
	 * rather than discovering it in the admin UI.
	 *
	 * @param mixed $entry Entry object.
	 * @return bool
	 */
	public static function can_cancel_subscription( $entry ) {
		if ( ! self::is_subscription_entry( $entry ) ) {
			return false;
		}

		$status = rgar( $entry, 'payment_status' );

		return 'Cancelled' !== $status && 'Failed' !== $status;
	}

	/**
	 * Keeps or removes the Subscription transaction type choice.
	 *
	 * The choice is located by its VALUE rather than a fixed array index.
	 * The previous implementation assumed `choices[2]` was Subscription, so a
	 * Gravity Forms update that reordered its choices would have silently
	 * removed whichever option then sat at index 2.
	 *
	 * @param array $field            The transactionType field.
	 * @param bool  $withhold_subscription True to remove the Subscription choice.
	 * @return array The choices, reindexed.
	 */
	public static function get_transaction_type_choices( $field, $withhold_subscription ) {
		$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();

		if ( ! $withhold_subscription ) {
			// Reindex so a caller that later unsets cannot leave a gap.
			return array_values( $choices );
		}

		$kept = array();
		foreach ( $choices as $choice ) {
			if ( isset( $choice['value'] ) && 'subscription' === $choice['value'] ) {
				continue;
			}
			$kept[] = $choice;
		}

		return $kept;
	}

	/**
	 * The card-only notice shown when Subscription is the transaction type.
	 *
	 * CHIP recurring tokens are card-only, so a subscription checkout cannot
	 * offer FPX, DuitNow QR or e-wallets. Without this, an administrator
	 * choosing Subscription had nothing in the UI explaining why the payment
	 * methods they expect are missing at checkout -- the constraint lived only
	 * in code comments and the public readme FAQ.
	 *
	 * Dependency-gated on transactionType so a one-time feed never shows it.
	 *
	 * @return array Field definition for Gravity Forms.
	 */
	public static function card_only_notice_field() {
		$html = sprintf(
			'<p style="margin:0.5em 0 0;padding:0.6em 0.8em;background:#fff8e5;border-left:4px solid #dba617;max-width:640px;">%s</p>',
			esc_html__(
				'Subscriptions are card-only. CHIP issues recurring tokens for cards only, so FPX, DuitNow QR and e-wallets are not available for a subscription feed. One-time forms are unaffected and can still offer every method your CHIP brand has enabled.',
				'chip-for-gravity-forms'
			)
		);

		return array(
			'name'       => 'chipCardOnlyNotice',
			'type'       => 'html',
			'html'       => $html,
			'dependency' => array(
				'field'  => 'transactionType',
				'values' => array( 'subscription' ),
			),
		);
	}

	/**
	 * Whether the configured brand can accept card payments.
	 *
	 * CHIP recurring tokens are card-only, so subscriptions are only offered
	 * when a card payment method is available. When no credentials are
	 * configured yet, cards are assumed available so the option is not
	 * hidden from an operator who has not finished setup.
	 *
	 * @return bool
	 */
	private function brand_supports_cards() {
		return (bool) apply_filters( 'gf_chip_brand_supports_cards', true );
	}

	/**
	 * Feed settings fields (configuration type, Brand ID, Secret Key, optional config, client/purchase/misc mapping).
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$feed_settings_fields                   = parent::feed_settings_fields();
		$feed_settings_fields[0]['description'] = esc_html__( 'Configuration page for CHIP for Gravity Forms.', 'chip-for-gravity-forms' );

		// Transaction type. Subscription is offered only when the brand can
		// take cards, because CHIP recurring tokens are card-only.
		$feed_settings_fields[0]['fields'][1]['choices'] = self::get_transaction_type_choices(
			$feed_settings_fields[0]['fields'][1],
			! $this->brand_supports_cards()
		);

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

		// Explain the card-only constraint where the choice is made, but only
		// when it applies.
		if ( $this->brand_supports_cards() ) {
			$feed_settings_fields[0]['fields'][] = self::card_only_notice_field();
		}

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

		// Gravity Forms stores the amount field under a different key per
		// transaction type: a subscription feed writes recurringAmount and
		// hides paymentAmount entirely, so reading paymentAmount here left
		// $payment_amount_location empty, no line item matched, and the
		// purchase was sent with an empty name and a zero price. CHIP
		// rejected it with HTTP 400, no payment id was stored, and the
		// customer saw the default confirmation instead of the payment page.
		$payment_amount_location = $this->get_payment_field( $feed ); // Location for payment amount.
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

		// A subscription's first charge is not simply the amount field: a
		// configured trial replaces the first cycle, and a setup fee is
		// collected on top of whichever applies. Gravity Forms reports all
		// three separately and never folds them together, so resolving it
		// here is the only place the correct first charge can be produced.
		// A one-time feed is left exactly as it was.
		$is_subscription = 'subscription' === rgars( $feed, 'meta/transactionType' );
		if ( $is_subscription ) {
			// The feed is the only reliable signal that a trial exists: core
			// reports a `trial` key unconditionally, and an enter_amount
			// trial of '0' comes back as an int 0, indistinguishable from
			// no trial at all.
			$has_trial = ! empty( rgars( $feed, 'meta/trial_enabled' ) );

			$first_charge_cents = self::resolve_first_charge_cents(
				array(
					'is_subscription' => true,
					'payment_amount'  => $amount / 100,
					'trial'           => rgar( $submission_data, 'trial' ),
					'setup_fee'       => rgar( $submission_data, 'setup_fee' ),
				),
				$has_trial
			);

			$amount       = $first_charge_cents;
			$product_name = self::resolve_first_charge_label( $submission_data, $product_name, $first_charge_cents, $has_trial );
		}

		if ( ! empty( $full_name_location_array ) ) {
			if ( array_key_exists( $name_location, $full_name_location_array ) ) {
				foreach ( $full_name_location_array[ $name_location ] as $full_name_location ) {
					$full_name .= ' ' . rgar( $entry, $full_name_location );
				}
				$full_name = trim( $full_name );
			}
		}

		$client_meta_data = $this->get_chip_client_meta_data( $feed, $entry, $form );

		$credentials = $this->get_credentials_for_feed( $feed );
		$secret_key  = $credentials['secret_key'];
		$brand_id    = $credentials['brand_id'];
		$due_strict  = $credentials['due_strict'];
		$due_timing  = $credentials['due_timing'];

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

		// A genuinely free first charge (a trial with nothing payable up
		// front) must only authorise the card so the token can be stored.
		// Every other subscription collects in full: setting skip_capture
		// while an amount is owed would hold funds on the customer's card
		// and leave the merchant unpaid. One-time feeds never set it.
		if ( $is_subscription && self::should_skip_first_capture( $amount ) ) {
			$params['skip_capture'] = true;
		}

		// A subscription feed must request a recurring token, and only card
		// methods can produce one. Merged before the filter below so an
		// operator can still override the whole payload.
		$params = self::merge_checkout_token_params( $params, $feed );

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
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			$action = $this->process_webhook_callback();
			return $action;
		}

		$entry_id = intval( rgget( 'entry_id' ) );
		$this->log_debug( 'Started ' . __METHOD__ . '(): for entry id #' . $entry_id );

		$entry           = GFAPI::get_entry( $entry_id );
		$submission_feed = $this->get_payment_feed( $entry );

		$this->log_debug( __METHOD__ . "(): Entry ID #$entry_id is set to Feed ID #" . $submission_feed['id'] );

		$credentials = $this->get_credentials_for_feed( $submission_feed );
		$secret_key  = $credentials['secret_key'];
		$brand_id    = $credentials['brand_id'];

		$chip = GF_CHIP_API::get_instance( $secret_key, $brand_id );

		// Get CHIP Payment ID.
		$payment_id = gform_get_meta( $entry_id, 'chip_payment_id' );

		$chip_payment = $chip->get_payment( $payment_id );

		$this->log_debug( __METHOD__ . "(): Entry ID #$entry_id get purchases information" . wp_json_encode( $chip_payment ) );

		$action = $this->build_callback_action_from_chip_payment( $payment_id, $entry_id, $chip_payment );
		if ( null === $action ) {
			return null;
		}

		// Acquire per-payment lock to prevent duplicate processing (allows other payments to run in parallel).
		$lock_name = 'chip_gf_payment_' . $payment_id;
		$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT GET_LOCK(%s, 15)', $lock_name ) );

		if ( $this->is_duplicate_callback( $payment_id ) ) {
			$action['abort_callback'] = 'true';
		}

		$this->log_debug( 'End of ' . __METHOD__ . '(): params return value: ' . wp_json_encode( $action ) );

		return $action;
	}

	/**
	 * Processes POST webhook callback (X-Signature present): verify signature and use payload, or fallback to get_payment.
	 *
	 * @return array|null Action array or null on abort.
	 */
	private function process_webhook_callback() {
		$raw_body = file_get_contents( 'php://input' );
		$payload  = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
			$this->log_debug( __METHOD__ . '(): Invalid JSON or missing payload id.' );
			return null;
		}

		$payment_id = $payload['id'];
		$company_id = isset( $payload['company_id'] ) ? trim( (string) $payload['company_id'] ) : '';

		$entry_id = absint( rgget( 'entry_id' ) );
		if ( ! $entry_id ) {
			$this->log_debug( __METHOD__ . '(): Missing entry_id in callback params.' );
			return null;
		}
		$stored_payment_id = gform_get_meta( $entry_id, 'chip_payment_id' );
		if ( (string) $stored_payment_id !== (string) $payment_id ) {
			$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} chip_payment_id does not match payload id {$payment_id}." );
			return null;
		}

		$public_key = is_string( $company_id ) && '' !== $company_id ? get_option( 'gf_chip_public_key_' . $company_id, '' ) : '';
		$public_key = is_string( $public_key ) ? str_replace( '\n', "\n", $public_key ) : '';

		if ( '' !== $public_key ) {
			$signature_b64 = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) : '';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- CHIP webhook X-Signature is base64-encoded.
			$signature = $signature_b64 ? base64_decode( $signature_b64, true ) : false;
			if ( false === $signature ) {
				$this->log_debug( __METHOD__ . '(): Failed to decode X-Signature.' );
				return null;
			}
			$key = openssl_pkey_get_public( $public_key );
			if ( false === $key ) {
				$this->log_debug( __METHOD__ . '(): Invalid public key.' );
				return null;
			}
			// CHIP webhook: sha256WithRSAEncryption; OpenSSL hashes $raw_body internally.
			$verified = ( 1 === openssl_verify( $raw_body, $signature, $key, OPENSSL_ALGO_SHA256 ) );
			// Key resource is freed when $key goes out of scope; openssl_pkey_free() is deprecated in PHP 8.0+.
			if ( ! $verified ) {
				$this->log_debug( __METHOD__ . '(): Signature verification failed.' );
				return null;
			}

			$action = $this->build_callback_action_from_webhook_payload( $payload, $entry_id );
		} else {
			$entry           = GFAPI::get_entry( $entry_id );
			$submission_feed = $this->get_payment_feed( $entry );
			$credentials     = $this->get_credentials_for_feed( $submission_feed );
			$secret_key      = $credentials['secret_key'];
			$brand_id        = $credentials['brand_id'];
			$chip            = GF_CHIP_API::get_instance( $secret_key, $brand_id );
			$chip_payment    = $chip->get_payment( $payment_id );
			$action          = $this->build_callback_action_from_chip_payment( $payment_id, $entry_id, $chip_payment );
		}

		if ( null === $action ) {
			return null;
		}

		// Acquire per-payment lock (allows other payments to run in parallel).
		$lock_name = 'chip_gf_payment_' . $payment_id;
		$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT GET_LOCK(%s, 15)', $lock_name ) );
		if ( $this->is_duplicate_callback( $payment_id ) ) {
			$action['abort_callback'] = 'true';
		}

		return $action;
	}

	/**
	 * Builds callback action array from CHIP get_payment response.
	 *
	 * @param string     $payment_id   Purchase ID.
	 * @param int        $entry_id     Entry ID.
	 * @param array|null $chip_payment Response from get_payment.
	 * @return array|null Action array or null if chip_payment invalid.
	 */
	private function build_callback_action_from_chip_payment( $payment_id, $entry_id, $chip_payment ) {
		if ( ! is_array( $chip_payment ) ) {
			return null;
		}
		$transaction_data = rgar( $chip_payment, 'transaction_data' );
		$payment_method   = is_array( $transaction_data ) ? rgar( $transaction_data, 'payment_method' ) : '';
		$status           = isset( $chip_payment['status'] ) ? $chip_payment['status'] : '';
		$total            = isset( $chip_payment['purchase']['total'] ) ? (int) $chip_payment['purchase']['total'] : 0;
		return $this->build_callback_action( $payment_id, $entry_id, $status, $payment_method, $total );
	}

	/**
	 * Builds callback action array from webhook payload (e.g. purchase.paid).
	 *
	 * @param array $payload  Decoded webhook JSON (id, status, company_id, purchase.total, transaction_data.payment_method).
	 * @param int   $entry_id Entry ID.
	 * @return array Action array.
	 */
	private function build_callback_action_from_webhook_payload( array $payload, $entry_id ) {
		$payment_id       = isset( $payload['id'] ) ? $payload['id'] : '';
		$status           = isset( $payload['status'] ) ? $payload['status'] : '';
		$transaction_data = isset( $payload['transaction_data'] ) && is_array( $payload['transaction_data'] ) ? $payload['transaction_data'] : array();
		$payment_method   = isset( $transaction_data['payment_method'] ) ? $transaction_data['payment_method'] : '';
		$total            = isset( $payload['purchase']['total'] ) ? (int) $payload['purchase']['total'] : 0;
		return $this->build_callback_action( $payment_id, $entry_id, $status, $payment_method, $total );
	}

	/**
	 * Builds the action array for post_callback (type, transaction_id, entry_id, payment_method, amount, abort_callback).
	 *
	 * @param string $payment_id     Purchase ID.
	 * @param int    $entry_id       Entry ID.
	 * @param string $status         Payment status (e.g. paid, error).
	 * @param string $payment_method Payment method code.
	 * @param int    $total_cents    Total amount in cents.
	 * @return array Action array.
	 */
	private function build_callback_action( $payment_id, $entry_id, $status, $payment_method, $total_cents ) {
		$type   = ( 'paid' === $status ) ? 'complete_payment' : 'fail_payment';
		$action = array(
			'id'             => $payment_id,
			'type'           => $type,
			'transaction_id' => $payment_id,
			'entry_id'       => $entry_id,
			'payment_method' => $payment_method,
			'amount'         => sprintf( '%.2f', $total_cents / 100 ),
		);
		if ( 'paid' !== $status && 'error' !== $status ) {
			$action['abort_callback'] = 'true';
		}
		return $action;
	}

	/**
	 * Runs after callback; releases lock and logs.
	 *
	 * @param array  $callback_action Action from callback().
	 * @param string $result          Result.
	 */
	public function post_callback( $callback_action, $result ) {
		if ( null === $callback_action ) {
			exit;
		}
		if ( ! is_array( $callback_action ) || empty( $callback_action['entry_id'] ) ) {
			$this->log_debug( __METHOD__ . '(): Invalid callback action; skipping.' );
			return;
		}

		$this->log_debug( 'Start of ' . __METHOD__ . '(): for entry id: #' . $callback_action['entry_id'] );

		// Release per-payment lock (same identifier as in callback).
		$lock_name = 'chip_gf_payment_' . ( isset( $callback_action['transaction_id'] ) ? $callback_action['transaction_id'] : '' );
		if ( '' !== $lock_name ) {
			$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		$entry_id = $callback_action['entry_id'];
		$entry    = GFAPI::get_entry( $entry_id );
		$url      = rgar( $entry, 'source_url' );
		$message  = __( 'Payment failed. ', 'chip-for-gravity-forms' );

		if ( 'complete_payment' === $callback_action['type'] ) {
			$entry_id = $callback_action['entry_id'];
			$form_id  = $entry['form_id'];

			$message = __( ' Payment successful. ', 'chip-for-gravity-forms' );
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

		$submission_feed = $this->get_payment_feed( $entry );
		$credentials     = $this->get_credentials_for_feed( $submission_feed );
		$secret_key      = $credentials['secret_key'];
		$brand_id        = $credentials['brand_id'];

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

	/**
	 * Cron entry point for subscriptions, called hourly by Gravity Forms core.
	 *
	 * Core schedules this in pre_init() -> setup_cron() when the add-on
	 * overrides this method, so overriding it is what turns the cron on at
	 * all; an empty body would schedule a cron that does nothing.
	 *
	 * @return array Summary counts for logging and manual runs.
	 */
	public function check_status() {
		$summary = array(
			'checked' => 0,
			'charged' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		foreach ( $this->get_due_subscription_entries() as $entry ) {
			++$summary['checked'];

			$result = GF_Chip_Renewals::charge( $entry );

			switch ( rgar( $result, 'status' ) ) {
				case 'charged':
					++$summary['charged'];
					break;
				case 'failed':
					++$summary['failed'];
					break;
				default:
					++$summary['skipped'];
			}
		}

		if ( $summary['checked'] > 0 ) {
			$this->log_debug( __METHOD__ . '(): ' . wp_json_encode( $summary ) );
		}

		return $summary;
	}

	/**
	 * Charges one subscription after the renewal engine has decided it is due.
	 *
	 * The schedule is advanced BEFORE the charge is attempted. A crash
	 * mid-charge then costs one billing cycle, recoverable from the entry
	 * notes, rather than re-charging the customer on the next cron run.
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @param bool  $force Attempt a charge even when the subscription is
	 *                     on-hold. Set only by the operator-initiated retry;
	 *                     the cron must never set it, or every run would
	 *                     bypass the dunning ladder.
	 * @param bool  $any_time Charge before the scheduled date. Only an
	 *                        operator-confirmed "Charge now" sets this. The
	 *                        cycle is neither advanced nor consumed.
	 * @return array Result with a status of charged|failed|skipped|expired.
	 */
	public function charge_renewal( $entry, $force = false, $any_time = false ) {
		$entry_id = rgar( $entry, 'id' );

		$form = GFAPI::get_form( rgar( $entry, 'form_id' ) );
		$feed = $this->get_payment_feed( $entry, $form );

		if ( empty( $feed ) ) {
			$this->log_debug( __METHOD__ . "(): No feed for entry #{$entry_id}." );
			return array(
				'status' => 'skipped',
				'note'   => '',
			);
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$length = (int) rgars( $feed, 'meta/billingCycle_length' );
		$unit   = (string) rgars( $feed, 'meta/billingCycle_unit' );

		// The stored counter wins once it exists. Re-reading recurringTimes
		// from the feed here would reset a finite plan every cycle, so a
		// 12-installment subscription would charge forever.
		$remaining = GF_Chip_Renewals::resolve_remaining(
			gform_get_meta( $entry_id, 'chip_sub_remaining' ),
			(int) rgars( $feed, 'meta/recurringTimes' )
		);

		$plan = GF_Chip_Renewals::plan_renewal(
			$entry,
			$now,
			$length > 0 ? $length : 1,
			$unit,
			$remaining,
			$force,
			$any_time
		);

		if ( 'charge' !== $plan['action'] ) {
			return array(
				'status' => 'skipped',
				'note'   => '',
			);
		}

		$form_id = rgar( $form, 'id' );

		// Everything needed to charge must be read and validated BEFORE the
		// schedule is advanced. Advancing first is what makes an hourly cron
		// safe (see the class docblock), but it also consumes a cycle: a later
		// refusal would then burn an installment and quietly shorten the plan.
		$token = rgar( $entry, 'chip_recurring_token' );

		// The id the token was issued against. It is NOT the purchase to
		// charge: CHIP only charges a purchase that can still be paid, and
		// this one was settled on the first cycle. It stays the reference for
		// the token itself, which is why it is still read here.
		$token_owner = (string) gform_get_meta( $entry_id, 'chip_subscription_id' );
		if ( '' === $token_owner ) {
			$token_owner = (string) gform_get_meta( $entry_id, 'chip_payment_id' );
		}

		if ( empty( $token ) || empty( $token_owner ) ) {
			return array(
				'status' => 'skipped',
				'note'   => '',
			);
		}

		// chip_sub_amount is the agreed recurring amount and is the primary
		// source. The feed's own recurring amount is the fallback for a
		// subscription whose first cycle was a free trial, where nothing was
		// stored. Resolving through both means a legacy or trial entry still
		// renews at the amount the customer agreed to.
		$amount_cents = self::resolve_renewal_amount_cents(
			$entry,
			$this->resolve_feed_recurring_cents( $feed, $form, $entry )
		);

		if ( $amount_cents <= 0 ) {
			// Nothing to charge. This is a configuration problem, not a
			// declined card, so it must not consume a cycle or reach the
			// dunning ladder — that would email the customer about a failed
			// payment that was never attempted, and could expire a
			// subscription that is not actually in arrears.
			$this->log_debug( __METHOD__ . "(): No renewable amount for entry #{$entry_id}; skipping without consuming a cycle." );
			return array(
				'status' => 'skipped',
				'note'   => '',
			);
		}

		// Advance now: everything below may fail without the customer being
		// charged twice on a later run.
		if ( null !== $plan['claim'] ) {
			gform_update_meta( $entry_id, 'chip_sub_next_payment', $plan['claim'], $form_id );
		}
		gform_update_meta( $entry_id, 'chip_sub_remaining', $plan['remaining'], $form_id );

		// Claim the subscription with a lock so two concurrent cron runs
		// cannot both charge it.
		$lock = 'chip_gf_renewal_' . $entry_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock, same pattern the callback path already uses.
		$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );

		$credentials = $this->get_credentials_for_feed( $feed );
		$chip        = GF_CHIP_API::get_instance( $credentials['secret_key'], $credentials['brand_id'] );

		$email_location = rgars( $feed, 'meta/clientInformation_email' );
		$name_location  = rgars( $feed, 'meta/clientInformation_full_name' );

		// Each cycle gets its own purchase, then the saved token authorises
		// the charge against it.
		$purchase = $chip->create_payment(
			self::build_renewal_purchase_params(
				$credentials,
				$entry,
				$amount_cents,
				(string) rgar( $form, 'title' ),
				(string) rgar( $entry, $email_location ),
				(string) rgar( $entry, $name_location ),
				$this->get_timezone()
			)
		);

		if ( ! is_array( $purchase ) || empty( $purchase['id'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing the advisory lock above.
			$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			// Nothing was created, so there is no purchase id to report: the
			// failure is the create response itself.
			return $this->handle_renewal_failure( $entry, $feed, '', $purchase, $plan, $any_time );
		}

		$renewal_purchase_id = (string) $purchase['id'];

		$result = $chip->charge_recurring( $renewal_purchase_id, $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing the advisory lock above.
		$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

		if ( ! is_array( $result ) || empty( $result['id'] ) ) {
			return $this->handle_renewal_failure( $entry, $feed, $renewal_purchase_id, $result, $plan, $any_time );
		}

		$status = isset( $result['status'] ) ? (string) $result['status'] : '';

		if ( 'paid' !== $status ) {
			// The charge is in flight (pending_charge and similar). The
			// callback settles it, so leave the retry counters alone rather
			// than treating an unsettled charge as a failure.
			$this->add_note(
				$entry_id,
				sprintf(
					/* translators: %s: charge status. */
					esc_html__( 'Renewal charge initiated; awaiting settlement (status: %s).', 'chip-for-gravity-forms' ),
					$status
				)
			);

			gform_update_meta( $entry_id, 'chip_sub_status', 'pending', $form_id );

			return array(
				'status' => 'charged',
				'note'   => '',
			);
		}

		// The cycle is collected. Record it the same way the first payment
		// was, so the entry's transaction id and the refund surface both
		// point at the purchase that actually holds the money.
		$this->record_renewal_payment( $entry, $renewal_purchase_id, $amount_cents );

		// Refresh the recurring token if CHIP rotated it. Today it does not
		// (the same token comes back), but a rotation that went unstored
		// would break every later cycle silently.
		self::refresh_subscription_token( $entry_id, $form_id, $result );

		// Success: clear the dunning counters and, on the final instalment,
		// expire rather than schedule another cycle.
		gform_update_meta( $entry_id, 'chip_sub_retry_count', 0, $form_id );
		gform_update_meta( $entry_id, 'chip_sub_last_payment', gmdate( 'Y-m-d H:i:s' ), $form_id );

		// An early charge claims no date because the schedule did not move --
		// NOT because the plan ran out. Reading null as "final instalment"
		// here would expire a healthy subscription the moment an operator
		// collected early, so the early path returns before that check.
		if ( $any_time ) {
			$this->add_note(
				$entry_id,
				sprintf(
					/* translators: %s: the still-scheduled next payment date. */
					esc_html__( 'Early charge collected. The scheduled cycle is unchanged; next payment remains %s.', 'chip-for-gravity-forms' ),
					(string) rgar( $entry, 'chip_sub_next_payment' )
				),
				'success'
			);

			$this->post_payment_action(
				$entry,
				array(
					'type'           => GF_Chip_Renewal_Notifications::EVENT_RENEWED,
					'amount'         => isset( $result['purchase']['total'] ) ? (int) $result['purchase']['total'] : 0,
					'transaction_id' => $renewal_purchase_id,
					'payment_status' => 'Paid',
				)
			);

			return array(
				'status' => 'charged',
				'note'   => '',
			);
		}

		if ( null === $plan['claim'] ) {
			gform_update_meta( $entry_id, 'chip_sub_status', 'expired', $form_id );
			$this->add_note(
				$entry_id,
				esc_html__( 'Final subscription payment collected. Subscription complete.', 'chip-for-gravity-forms' ),
				'success'
			);
			return array(
				'status' => 'expired',
				'note'   => '',
			);
		}

		gform_update_meta( $entry_id, 'chip_sub_status', 'active', $form_id );

		$amount = isset( $result['purchase']['total'] ) ? (int) $result['purchase']['total'] : 0;

		$this->add_note(
			$entry_id,
			sprintf(
				/* translators: 1: formatted amount, 2: next payment date. */
				esc_html__( 'Renewal charge successful: %1$s. Next payment: %2$s.', 'chip-for-gravity-forms' ),
				GFCommon::to_money( $amount, rgar( $entry, 'currency' ) ),
				$plan['claim']
			),
			'success'
		);

		$this->post_payment_action(
			$entry,
			array(
				'type'           => GF_Chip_Renewal_Notifications::EVENT_RENEWED,
				'amount'         => $amount,
				'transaction_id' => $renewal_purchase_id,
				'payment_status' => 'Paid',
			)
		);

		return array(
			'status' => 'charged',
			'note'   => '',
		);
	}

	/**
	 * Records a collected renewal cycle against the entry.
	 *
	 * The entry must point at the purchase that actually holds the money for
	 * the CURRENT cycle: `transaction_id` drives the refund button, and the
	 * first cycle's purchase cannot be charged or refunded a second time.
	 *
	 * `chip_payment_id` is deliberately left alone. It is the id the recurring
	 * token was issued against — the reference the card-update flow deletes
	 * the old token with (`delete_recurring_token()`), so replacing it with a
	 * later cycle's purchase id would revoke the wrong thing.
	 *
	 * @param array  $entry               Entry.
	 * @param string $renewal_purchase_id Purchase created for this cycle.
	 * @param int    $amount_cents        Amount collected, in cents.
	 * @return void
	 */
	private function record_renewal_payment( $entry, $renewal_purchase_id, $amount_cents ) {
		$entry_id = rgar( $entry, 'id' );
		$form_id  = rgar( $entry, 'form_id' );
		$amount   = $amount_cents / 100;

		$live = GFAPI::get_entry( $entry_id );

		if ( is_array( $live ) ) {
			$live['transaction_id'] = $renewal_purchase_id;
			$live['payment_amount'] = $amount;
			$live['payment_date']   = gmdate( 'Y-m-d H:i:s' );

			GFAPI::update_entry( $live );
		}

		$this->insert_transaction(
			$entry_id,
			'payment',
			$renewal_purchase_id,
			$amount,
			true,
			$renewal_purchase_id
		);
	}

	/**
	 * Applies the dunning ladder after a failed renewal charge.
	 *
	 * The retry date is measured from the ORIGINAL due date, so a slow cron
	 * cannot stretch the ladder. When the ladder is exhausted the
	 * subscription expires rather than retrying forever.
	 *
	 * @param array  $entry    Entry.
	 * @param array  $feed     Payment feed.
	 * @param string $purchase CHIP purchase id.
	 * @param mixed  $result   Failed charge response.
	 * @param array  $plan     Plan produced by the renewal engine.
	 * @param bool   $early    True for an operator's early charge, whose
	 *                         failure must leave the schedule untouched.
	 * @return array Result with a failed status.
	 */
	private function handle_renewal_failure( $entry, $feed, $purchase, $result, $plan, $early = false ) {
		$entry_id = rgar( $entry, 'id' );
		$form_id  = rgar( $entry, 'form_id' );

		// An early charge that fails must leave NO trace on the schedule.
		//
		// The scheduled cycle has not happened yet, so this failure is not a
		// missed payment: the customer is not in arrears, the dunning ladder
		// has not started, and the subscription must not be expired by a
		// collection the customer never owed on that date. Counting it would
		// burn a ladder slot and could expire a healthy subscription because
		// an operator tried to collect early.
		if ( $early ) {
			$this->add_note(
				$entry_id,
				sprintf(
					/* translators: %s: API response. */
					esc_html__( 'Early charge (brought forward by an operator) failed. The scheduled cycle is unaffected. Response: %s', 'chip-for-gravity-forms' ),
					wp_json_encode( $result )
				),
				'error'
			);

			return array(
				'status' => 'failed',
				'note'   => '',
			);
		}

		$retry_count = (int) gform_get_meta( $entry_id, 'chip_sub_retry_count' );
		++$retry_count;

		gform_update_meta( $entry_id, 'chip_sub_retry_count', $retry_count, $form_id );

		// The installment was consumed by the pre-charge advance. Give it
		// back, or the customer is billed fewer times than agreed.
		$remaining = GF_Chip_Renewals::restore_installment(
			gform_get_meta( $entry_id, 'chip_sub_remaining' )
		);
		gform_update_meta( $entry_id, 'chip_sub_remaining', $remaining, $form_id );

		// Anchor the ladder on the date that was DUE, taken from the plan --
		// never by re-reading chip_sub_next_payment, which the pre-charge
		// advance has already moved a cycle forward.
		$next = GF_Chip_Renewals::next_attempt_from_plan( $plan, $retry_count );

		if ( null === $next ) {
			$this->log_debug( __METHOD__ . '(): ladder exhausted or no due anchor for entry #' . $entry_id . '.' );
		}

		$this->add_note(
			$entry_id,
			sprintf(
				/* translators: 1: attempt number, 2: API response. */
				esc_html__( 'Renewal charge failed (attempt %1$d). Response: %2$s', 'chip-for-gravity-forms' ),
				$retry_count,
				wp_json_encode( $result )
			),
			'error'
		);

		// The ladder is exhausted, so the subscription ends in this same pass.
		//
		// ONE event is announced here, not two. The failure and the expiry are
		// the same moment, and firing both means two emails for one payment --
		// a merchant who configured a notification on each would double-message
		// the customer. The expiry is what has actually happened, so it is the
		// expiry that gets announced.
		//
		// The built-in "update your card" email is deliberately not sent on this
		// path either. The card-update link stops working the moment the
		// subscription is expired, so mailing one here hands the customer a link
		// that is already dead by the time they click it. They were dunned on
		// every earlier attempt, while action was still possible.
		if ( null === $next ) {
			gform_update_meta( $entry_id, 'chip_sub_status', 'expired', $form_id );
			gform_update_meta( $entry_id, 'chip_sub_next_payment', '', $form_id );

			$this->add_note(
				$entry_id,
				esc_html__( 'Renewal retries exhausted. Subscription expired.', 'chip-for-gravity-forms' ),
				'error'
			);

			$this->post_payment_action(
				$entry,
				array(
					'type'           => GF_Chip_Renewal_Notifications::EVENT_EXPIRED,
					'amount'         => rgar( $plan, 'amount' ),
					'transaction_id' => $purchase,
					'payment_status' => 'Expired',
				)
			);

			return array(
				'status' => 'failed',
				'note'   => '',
			);
		}

		// There are retries left, so the customer still has something to do.
		// One email per attempt position, so a cron that runs twice inside a
		// retry window does not send two identical messages. Sent only to the
		// entry's own address.
		GF_Chip_Renewal_Notifications::maybe_send_dunning_email( $entry_id, $retry_count );

		$this->post_payment_action(
			$entry,
			array(
				'type'           => GF_Chip_Renewal_Notifications::EVENT_FAILED,
				'amount'         => rgar( $plan, 'amount' ),
				'transaction_id' => $purchase,
				'payment_status' => 'Failed',
			)
		);

		gform_update_meta( $entry_id, 'chip_sub_status', 'on-hold', $form_id );
		gform_update_meta( $entry_id, 'chip_sub_next_payment', $next, $form_id );

		return array(
			'status' => 'failed',
			'note'   => '',
		);
	}

	/**
	 * Finds subscription entries that are due for a renewal attempt.
	 *
	 * @return array List of entries with chip_sub_* meta flattened in.
	 */
	private function get_due_subscription_entries() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cron due-query over the entry meta table; the result must be current, so caching would be incorrect.
		$entry_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT entry_id FROM {$wpdb->prefix}gf_entry_meta
				 WHERE meta_key = %s AND meta_value <= %s
				 LIMIT %d",
				'chip_sub_next_payment',
				gmdate( 'Y-m-d H:i:s' ),
				GF_Chip_Renewals::BATCH_SIZE
			)
		);

		$entries = array();

		foreach ( (array) $entry_ids as $entry_id ) {
			$entry = GFAPI::get_entry( (int) $entry_id );

			if ( ! is_array( $entry ) ) {
				continue;
			}

			// chip_sub_amount carries the agreed RECURRING amount and is what
			// the renewal engine charges; chip_subscription_id identifies the
			// purchase the token was issued against. Without both flattened in
			// the cron path sees neither and cannot charge correctly.
			foreach ( array( 'chip_recurring_token', 'chip_sub_next_payment', 'chip_sub_status', 'chip_sub_retry_count', 'chip_sub_amount', 'chip_subscription_id', 'chip_payment_id' ) as $key ) {
				$entry[ $key ] = gform_get_meta( (int) $entry_id, $key );
			}

			if ( GF_Chip_Renewals::is_due( $entry, gmdate( 'Y-m-d H:i:s' ) ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Supported notification events for this addon.
	 *
	 * @param array $form Form.
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		return array(
			'complete_payment'                           => esc_html__( 'Payment Completed', 'chip-for-gravity-forms' ),
			'refund_payment'                             => esc_html__( 'Payment Refunded', 'chip-for-gravity-forms' ),
			'fail_payment'                               => esc_html__( 'Payment Failed', 'chip-for-gravity-forms' ),
			GF_Chip_Renewal_Notifications::EVENT_RENEWED => esc_html__( 'Subscription Renewed', 'chip-for-gravity-forms' ),
			GF_Chip_Renewal_Notifications::EVENT_FAILED  => esc_html__( 'Subscription Payment Failed', 'chip-for-gravity-forms' ),
			GF_Chip_Renewal_Notifications::EVENT_EXPIRED => esc_html__( 'Subscription Expired', 'chip-for-gravity-forms' ),
		);
	}

	/**
	 * Completes payment: parent logic and triggers delayed feeds.
	 *
	 * This is also where a subscription is activated. Gravity Forms only
	 * calls process_subscription()/start_subscription() when an
	 * authorization was performed (core: `if ( ! empty( $this->authorization
	 * ) )`), and a hosted-redirect gateway performs none — so for CHIP that
	 * branch is never taken and nothing else ever calls start_subscription().
	 * Driving it from here is what makes a subscription chargeable at all.
	 *
	 * @param array $entry  Entry (by reference).
	 * @param array $action Callback action.
	 * @return bool
	 */
	public function complete_payment( &$entry, $action ) {
		$entry_id = rgar( $entry, 'id' );
		$form     = GFAPI::get_form( rgar( $entry, 'form_id' ) );
		$feed     = $this->get_payment_feed( $entry, $form );

		// Core's complete_payment() rewrites transaction_type to '1'
		// unconditionally, so a subscription purchase loses its type here and
		// every later read of the entry sees a one-time payment. The feed is
		// read above, before that happens.
		parent::complete_payment( $entry, $action );

		$transaction_id = rgar( $action, 'transaction_id' );

		$this->trigger_payment_delayed_feeds( $transaction_id, $feed, $entry, $form );

		$this->maybe_store_subscription_token( $entry, $feed, $form );

		$this->maybe_activate_subscription( $entry, $feed, $action );

		return true;
	}

	/**
	 * Starts the subscription on its first successful payment.
	 *
	 * Runs only for a subscription feed and only when no schedule exists yet:
	 * a renewal settles through this same callback, and the renewal engine
	 * has already advanced the schedule by then, so re-running activation
	 * would drift the billing anchor forward on every renewal.
	 *
	 * @param array $entry  Entry, after core's complete_payment() ran.
	 * @param array $feed   The payment feed.
	 * @param array $action Callback action.
	 * @return void
	 */
	private function maybe_activate_subscription( $entry, $feed, $action ) {
		if ( 'subscription' !== rgars( $feed, 'meta/transactionType' ) ) {
			return;
		}

		$entry_id = (int) rgar( $entry, 'id' );

		if ( '' !== (string) gform_get_meta( $entry_id, 'chip_sub_next_payment' ) ) {
			return;
		}

		// The CHIP purchase id identifies both the payment and the
		// subscription, so passing it as the subscription id keeps
		// transaction_id pointing at the purchase — which the refund path
		// needs. Core's start_subscription() writes transaction_id from this
		// field.
		$subscription = array(
			'subscription_id' => (string) gform_get_meta( $entry_id, 'chip_payment_id' ),
			'amount'          => rgar( $action, 'amount' ),
			'is_success'      => true,
		);

		$this->start_subscription( $entry, $subscription );

		// The amount the customer agreed to pay EVERY cycle. This is the
		// feed's recurring amount, not the first charge: a trial subscription
		// legitimately collects zero up front, and storing that would make
		// the renewal engine read 0 and skip the subscription forever.
		// Core's get_submission_data() already excludes the trial and setup
		// fee for a subscription feed, so its total is the recurring amount.
		$form_id   = rgar( $entry, 'form_id' );
		$recurring = $this->resolve_feed_recurring_cents( $feed, GFAPI::get_form( $form_id ), $entry );

		if ( $recurring <= 0 ) {
			// Fall back to what was actually collected, which is correct for
			// every subscription whose first cycle is a normal one.
			$recurring = (int) round( (float) rgar( $action, 'amount' ) * 100 );
		}

		if ( $recurring > 0 ) {
			gform_update_meta( $entry_id, 'chip_sub_amount', $recurring, $form_id );
		}
	}

	/**
	 * Cancels a subscription at CHIP.
	 *
	 * Overriding this is what makes Gravity Forms core render its Cancel
	 * Subscription button: core gates the button on
	 * payment_method_is_overridden( 'cancel' ). Core's
	 * ajax_cancel_subscription() calls this, and only on true does it call
	 * cancel_subscription() to move the entry to Cancelled.
	 *
	 * @param array $entry Entry object.
	 * @param array $feed  Payment feed.
	 * @return bool True when the token was revoked at CHIP.
	 */
	public function cancel( $entry, $feed ) {
		$entry_id = rgar( $entry, 'id' );

		if ( ! self::can_cancel_subscription( $entry ) ) {
			$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} is not a cancellable subscription." );
			return false;
		}

		$payment_id = gform_get_meta( $entry_id, 'chip_payment_id' );

		if ( empty( $payment_id ) ) {
			$this->log_debug( __METHOD__ . "(): No chip_payment_id for entry #{$entry_id}." );
			return false;
		}

		$credentials = $this->get_credentials_for_feed( $feed );
		$chip        = GF_CHIP_API::get_instance( $credentials['secret_key'], $credentials['brand_id'] );

		// Revoke the stored token so it can never be charged again. This is
		// the part that actually stops the money; the entry status below is
		// bookkeeping.
		$result = $chip->delete_recurring_token( $payment_id );

		if ( ! is_array( $result ) ) {
			$this->log_debug( __METHOD__ . "(): Failed to delete recurring token for entry #{$entry_id}." );
			return false;
		}

		// Clear the local token, schedule and state so the renewal engine
		// cannot pick this subscription up, and so every admin surface stops
		// presenting it as live.
		self::mark_cancelled( $entry_id, rgar( $entry, 'form_id' ) );

		return true;
	}

	/**
	 * Moves a subscription's own state to cancelled and clears its schedule.
	 *
	 * Extracted from cancel() so the transition is directly testable: cancel()
	 * itself needs a live CHIP API to revoke the token, but the local state
	 * change does not, and it is the part that decides what the admin shows
	 * and whether the link action is offered.
	 *
	 * Core sets the GF payment_status to 'Cancelled' separately, in
	 * cancel_subscription(), which it calls after cancel() returns true.
	 *
	 * @param int      $entry_id The entry id.
	 * @param int|null $form_id  The form id, for gform_update_meta.
	 * @return void
	 */
	public static function mark_cancelled( $entry_id, $form_id = null ) {
		$entry_id = (int) $entry_id;

		gform_update_meta( $entry_id, 'chip_sub_status', 'cancelled', $form_id );

		// Without a token or a due date the renewal engine cannot charge it.
		gform_delete_meta( $entry_id, 'chip_recurring_token' );
		gform_delete_meta( $entry_id, 'chip_sub_next_payment' );

		// Clear the retry position so a future resubscribe starts clean.
		gform_delete_meta( $entry_id, 'chip_sub_retry_count' );
	}

	/**
	 * Starts a subscription after its first payment succeeded.
	 *
	 * Core's start_subscription() sets payment_status/transaction_type and
	 * fires its own action; this adds the plugin's own scheduling state on
	 * top rather than duplicating core's work.
	 *
	 * @param array $entry        Entry object.
	 * @param array $subscription Subscription data from core.
	 * @return array The entry.
	 */
	public function start_subscription( $entry, $subscription ) {
		$entry = parent::start_subscription( $entry, $subscription );

		$entry_id = rgar( $entry, 'id' );
		$form     = GFAPI::get_form( rgar( $entry, 'form_id' ) );
		$feed     = $this->get_payment_feed( $entry, $form );

		$length    = (int) rgars( $feed, 'meta/billingCycle_length' );
		$unit      = (string) rgars( $feed, 'meta/billingCycle_unit' );
		$remaining = (int) rgars( $feed, 'meta/recurringTimes' );

		$cycle = GF_Chip_Schedule::apply_cycle(
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			$length > 0 ? $length : 1,
			$unit,
			$remaining
		);

		gform_update_meta( $entry_id, 'chip_sub_status', $cycle['expired'] ? 'expired' : 'active', rgar( $form, 'id' ) );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', 0, rgar( $form, 'id' ) );

		// The counter must exist from the start. resolve_remaining() prefers a
		// STORED value, so with nothing stored it re-reads recurringTimes from
		// the feed every cycle — and a 1-installment plan would then be
		// scheduled for a second charge.
		gform_update_meta( $entry_id, 'chip_sub_remaining', (int) $cycle['remaining'], rgar( $form, 'id' ) );

		if ( null !== $cycle['next'] ) {
			gform_update_meta(
				$entry_id,
				'chip_sub_next_payment',
				$cycle['next']->format( 'Y-m-d H:i:s' ),
				rgar( $form, 'id' )
			);
		}

		return $entry;
	}

	/**
	 * Stores the recurring token when a subscription purchase completes.
	 *
	 * The callback action carries no token — only the payment id — so the full
	 * purchase is fetched here, where it is still available. Only runs for a
	 * subscription feed; a one-time purchase has no token to store.
	 *
	 * @param array $entry Entry object.
	 * @param array $feed  The payment feed.
	 * @param array $form  Form object.
	 * @return void
	 */
	private function maybe_store_subscription_token( $entry, $feed, $form ) {
		if ( 'subscription' !== rgars( $feed, 'meta/transactionType' ) ) {
			return;
		}

		$entry_id   = rgar( $entry, 'id' );
		$payment_id = gform_get_meta( $entry_id, 'chip_payment_id' );

		if ( empty( $payment_id ) ) {
			$this->log_debug( __METHOD__ . "(): No chip_payment_id for entry #{$entry_id}, cannot store token." );
			return;
		}

		$credentials = $this->get_credentials_for_feed( $feed );
		$chip        = GF_CHIP_API::get_instance( $credentials['secret_key'], $credentials['brand_id'] );
		$purchase    = $chip->get_payment( $payment_id );

		if ( ! self::persist_subscription_token( $entry_id, rgar( $form, 'id' ), $purchase ) ) {
			// A subscription with no token looks active but can never renew,
			// so this must be visible rather than a debug-only line.
			$this->add_note(
				$entry_id,
				esc_html__( 'Subscription created without a recurring token. The subscription cannot be renewed automatically; please check the CHIP configuration for this form.', 'chip-for-gravity-forms' ),
				'error'
			);
			$this->log_debug( __METHOD__ . "(): No recurring token returned for entry #{$entry_id}." );
			return;
		}

		$token = gform_get_meta( $entry_id, 'chip_recurring_token' );
		$this->add_note(
			$entry_id,
			sprintf(
				/* translators: %s: masked token reference. */
				esc_html__( 'Recurring token stored for this subscription (reference: %s).', 'chip-for-gravity-forms' ),
				substr( (string) $token, 0, 12 )
			),
			'success'
		);
	}

	/**
	 * Entry info (refund button and UI) for paid CHIP entries.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $entry   Entry.
	 */
	public function entry_info( $form_id, $entry ) {

		// Render core's entry info first, which is what draws the Cancel
		// Subscription button for a subscription entry. Not calling parent::
		// here was why that button never appeared, however cancel() was
		// implemented.
		parent::entry_info( $form_id, $entry );

		// Return if there is nothing to refund.
		if ( ! self::should_render_refund_ui( $entry ) ) {
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
				if ( ! confirm( <?php echo wp_json_encode( esc_html__( 'Are you sure you want to refund this payment? This action cannot be undone.', 'chip-for-gravity-forms' ) ); ?> ) ) {
					return;
				}

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

		$credentials = $this->get_credentials_for_feed( $feed );
		$secret_key  = $credentials['secret_key'];
		$brand_id    = $credentials['brand_id'];
		$refund      = $credentials['refund'];

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

		global $wpdb;
		$like = $wpdb->esc_like( 'gf_chip_public_key_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall: options table name; LIKE value prepared.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE %s', $like ) );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $option_name ) {
				delete_option( $option_name );
			}
		}

		parent::uninstall();
	}
}
