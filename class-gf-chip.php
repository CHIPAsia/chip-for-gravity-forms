<?php

defined( 'ABSPATH' ) || die();

GFForms::include_payment_addon_framework();

class GF_Chip extends GFPaymentAddOn {

  private static $_instance = null;
  protected $_slug = 'gravityformschip';
  protected $_title = 'CHIP for Gravity Forms';

  protected $_short_title = 'CHIP';
  protected $_supports_callbacks = true;

  protected $_capabilities = array( 'gravityforms_chip', 'gravityforms_chip_uninstall' );

  protected $_capabilities_settings_page = 'gravityforms_chip';
  protected $_capabilities_form_settings = 'gravityforms_chip';
  protected $_capabilities_uninstall = 'gravityforms_chip_uninstall';

	public function __construct()
	{
		  parent::__construct();
		  // based on: update_option( 'gravityformsaddon_' . $this->_slug . '_settings', $settings );
			add_action( 'update_option_gravityformsaddon_gravityformschip_settings', array($this, 'global_validate_keys'), 10, 3);
	}

  public static function get_instance() {

		if ( self::$_instance == null ) {
			self::$_instance = new GF_Chip();
		}

		return self::$_instance;
	}

  public function pre_init() {
    // inspired by gravityformsstripe
    add_action( 'wp', array( $this, 'maybe_thankyou_page' ), 5 );

    parent::pre_init();
  }

	public function supported_currencies( $currencies ) {
		return array('MYR' => $currencies['MYR']);
	}

  public function plugin_settings_fields() {
		$configuration = array(
			array(
				'title'       => esc_html__( 'CHIP', 'gravityformschip' ),
				'description' => $this->get_description(),
				'fields'      => $this->global_keys_fields(),
			),
			array(
				'title'       => esc_html__( 'Account Status', 'gravityformschip' ),
				'description' => $this->global_account_status_description(),
				'fields'      => array(['type' => 'account_status'])
			),
		);

		if (get_option('gf_chip_global_key_validation')){
			$configuration[] = array(
				'title'       => esc_html__( 'CHIP Optional Configuration', 'gravityformschip' ),
				'description' => esc_html__( 'Further customize the behavior of the payment.', 'gravityformschip' ),
				'fields'      => $this->global_advance_fields(),
			);
		}

		return $configuration;
	}

  public function get_description() {
    ob_start(); ?>
		<p>
			<?php
			printf(
				// translators: $1$s opens a link tag, %2$s closes link tag.
				esc_html__(
					'Cash, Card and Coin Handling Integrated Platform. %1$sLearn more%2$s. %3$s%3$sThis is a global configuration where this configuration will function as a fallback for each settings in the forms.',
          'gravityformschip'
				),
				'<a href="https://www.chip-in.asia/" target="_blank">',
        '</a>',
				'<br>'
			);
			?>
		</p>
		<?php

		return ob_get_clean();
	}

  public function global_keys_fields() {
		return array(
			array(
				'name' => 'brand_id',
				'label' => esc_html__('Brand ID', 'gravityformschip'),
				'type' => 'text',
				'required' => true,
				'tooltip' => '<h6>' . esc_html__('Brand ID', 'gravityformschip') . '</h6>' . esc_html__('Brand ID enables you to represent your Brand suitable for the system using the same CHIP account.', 'gravityformschip')
		  ),
			array(
				'name' => 'private_key',
				'label' => esc_html__('Private Key', 'gravityformschip'),
				'type' => 'text',
				'required' => true,
				'tooltip' => '<h6>' . esc_html__('Private Key', 'gravityformschip') . '</h6>' . esc_html__('Private key is used to identify your account with CHIP. You are recommended to create dedicated private key for each website.', 'gravityformschip')
		  ),

		);
	}

	public function global_advance_fields() {
		return array(
			array(
				'name'   => 'send_receipt',
				'label'  => 'Purchase Send Receipt',
				'type'   => 'toggle',
				'default_value' => 'false',
				'tooltip' => '<h6>' . esc_html__('Purchase Send Receipt', 'gravityformschip') . '</h6>' . esc_html__('Whether to send receipt email when it\'s paid. If configured, the receipt email will be send by CHIP. Default is not send.', 'gravityformschip')
				),
			array(
				'name'     => 'due_strict',
				'label'    => esc_html__( 'Due Strict', 'gravityformschip' ),
				'type'     => 'toggle',
				'tooltip' => '<h6>' . esc_html__('Due Strict', 'gravityformschip') . '</h6>' . esc_html__('Whether to permit payments when Purchase\'s due has passed. By default those are permitted (and status will be set to overdue once due moment is passed). If this is set to true, it won\'t be possible to pay for an overdue invoice, and when due is passed the Purchase\'s status will be set to expired.', 'gravityformschip')
			),
			array(
				'name'      => 'due_strict_timing',
				'label'     => esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ),
				'type'      => 'text',
				'placeholder' => '60 for 60 minutes',
				'tooltip'   => '<h6>' . esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ) . '</h6>' . esc_html__( 'Set due time to enforce due timing for purchases. 60 for 60 minutes. If due_strict is set while due strict timing unset, it will default to 1 hour. Leave blank if unsure', 'gravityformschip' )
			),
		);
	}

	public function global_account_status_description() {
		$state = 'Not set';
		if (get_option('gf_chip_global_key_validation')){
			$state = 'Success';
		} else if (!empty(get_option( 'gf_chip_global_error_code' ))){
			$state = get_option( 'gf_chip_global_error_code' );
		}
		ob_start(); ?>
		<p>
			<?php
			printf(
				// translators: $1$s opens a link tag, %2$s closes link tag.
				esc_html__(
					'Your %1$sCHIP%2$s Brand ID and Private Key settings: %3$s%5$s%4$s.',
          'gravityformschip'
				),
				'<a href="https://gate.chip-in.asia/" target="_blank">',
        '</a>',
				'<strong>',
				'</strong>',
				$state
			);
			?>
		</p>
		<?php

		return ob_get_clean();
	}

	public function global_validate_keys($old_value, $new_value, $option_name){
	  if ($option_name != 'gravityformsaddon_gravityformschip_settings'){
			return false;
		}

		$chip = GFChipAPI::get_instance($new_value['private_key'], $new_value['brand_id']);
		$public_key = $chip->get_public_key();

		if (is_string($public_key)){
			update_option( 'gf_chip_global_key_validation', true );
			update_option( 'gf_chip_global_error_code', '' );
		} else if (is_array($public_key['__all__'])){
			$error_code_a = array_column($public_key['__all__'], 'code');
			$error_code = implode(', ',$error_code_a);

			update_option( 'gf_chip_global_key_validation', false);
			update_option( 'gf_chip_global_error_code', $error_code );
		} else {
			update_option( 'gf_chip_global_key_validation', false);
			update_option( 'gf_chip_global_error_code', 'unspecified error!' );
		}
	}

	public function feed_settings_fields() {
		$feed_settings_fields = parent::feed_settings_fields();
		$feed_settings_fields[0]['description'] = esc_html__('Configuration page for CHIP for Gravity Forms.', 'gravityformschip');
		
		// Remove subscription option from Transaction type
    unset($feed_settings_fields[0]['fields'][1]['choices'][2]);

		// Ensure transaction type mandatory
		$transaction_type_array = $feed_settings_fields[0]['fields'][1]['required'] = true;

		// Temporarily remove transaction type section
		$transaction_type_array = $feed_settings_fields[0]['fields'][1];
    unset($feed_settings_fields[0]['fields'][1]);

		// Temporarily remove product and services section
		$product_and_services = $feed_settings_fields[2];
		$other_settings = $feed_settings_fields[3];
		unset($feed_settings_fields[2]);
		unset($feed_settings_fields[3]);

		// Add CHIP configuration settings
		$feed_settings_fields[0]['fields'][] = array(
			'name'     => 'chipConfigurationType',
			'label'    => esc_html__( 'Configuration Type', 'gravityformschip' ),
			'type'     => 'select',
			'required' => true,
			'onchange' => "jQuery(this).parents('form').submit();",
			'choices'  => array(
				array(
					'label' => esc_html__( 'Select configuration type', 'gravityformschip' ),
					'value' => ''
				),
				array(
					'label' => esc_html__( 'Global Configuration', 'gravityformschip' ),
					'value' => 'global'
				),
				array(
					'label' => esc_html__( 'Form Configuration', 'gravityformschip' ),
					'value' => 'form'
				),
			),
			'tooltip'  => '<h6>' . esc_html__( 'Configuration Type', 'gravityformschip' ) . '</h6>' . esc_html__( 'Select a configuration type. If you want to configure CHIP on form basis, you may use Form Configuration. If you want to use globally set keys, choose Global Configuration.', 'gravityformschip' )
		);

		$feed_settings_fields[] = array(
			'title'      => esc_html__( 'CHIP Form Configuration Settings', 'gravityformschip' ),
			'dependency' => array(
				'field'  => 'chipConfigurationType',
				'values' => array( 'form' )
			),
			'description' => esc_html__('Set your Brand ID and Private Key for the use of CHIP with this forms', 'gravityformschip'),
			'fields'     => array(
			  array(
					'name'     => 'brand_id',
					'label'    => esc_html__( 'Brand ID', 'gravityformschip' ),
					'type'     => 'text',
					'class'  => 'medium',
					'required' => true,
					'tooltip' => '<h6>' . esc_html__('Brand ID', 'gravityformschip') . '</h6>' . esc_html__('Brand ID enables you to represent your Brand suitable for the system using the same CHIP account.', 'gravityformschip')
				),
				array(
					'name'     => 'private_key',
					'label'    => esc_html__( 'Private Key', 'gravityformschip' ),
					'type'     => 'text',
					'class'  => 'medium',
					'required' => true,
					'tooltip' => '<h6>' . esc_html__('Private Key', 'gravityformschip') . '</h6>' . esc_html__('Private key is used to identify your account with CHIP. You are recommended to create dedicated private key for each website.', 'gravityformschip')
				),
			)
		);

		$feed_settings_fields[] = array(
			'title'      => esc_html__( 'CHIP Optional Configuration', 'gravityformschip' ),
			'dependency' => array(
				'field'  => 'chipConfigurationType',
				'values' => array( 'form' )
			),
			'description' => esc_html__('Further customize the behavior of the payment.', 'gravityformschip'),
			'fields'     => array(
			  array(
					'name'     => 'send_receipt',
					'label'    => esc_html__( 'Purchase Send Receipt', 'gravityformschip' ),
					'type'     => 'toggle',
					'tooltip' => '<h6>' . esc_html__('Purchase Send Receipt', 'gravityformschip') . '</h6>' . esc_html__('Whether to send receipt email for this Purchase when it\'s paid.', 'gravityformschip')
				),
				array(
					'name'     => 'due_strict',
					'label'    => esc_html__( 'Due Strict', 'gravityformschip' ),
					'type'     => 'toggle',
					'tooltip' => '<h6>' . esc_html__('Due Strict', 'gravityformschip') . '</h6>' . esc_html__('Whether to permit payments when Purchase\'s due has passed. By default those are permitted (and status will be set to overdue once due moment is passed). If this is set to true, it won\'t be possible to pay for an overdue invoice, and when due is passed the Purchase\'s status will be set to expired.', 'gravityformschip')
				),
				array(
					'name'      => 'due_strict_timing',
					'label'     => esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ),
					'type'      => 'text',
					'placeholder' => '60 for 60 minutes',
					'tooltip'   => '<h6>' . esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ) . '</h6>' . esc_html__( 'Set due time to enforce due timing for purchases. 60 for 60 minutes. If due_strict is set while due strict timing unset, it will default to 1 hour. Leave blank if unsure', 'gravityformschip' )
				),
				
			)
		);

		// Readd transaction type section
		$feed_settings_fields[0]['fields'][] = $transaction_type_array;

		// Readd product and services section
		$feed_settings_fields[] = $product_and_services;
		$feed_settings_fields[] = $other_settings;

		return $feed_settings_fields;
	}

	public function other_settings_fields() {
		$other_settings_fields = parent::other_settings_fields();
		$other_settings_fields[0]['name'] = 'clientInformation';
		$other_settings_fields[0]['label'] = esc_html__( 'Client Information.', 'gravityformschip' );
		$other_settings_fields[0]['field_map'] = $this->client_info_fields();
		$other_settings_fields[0]['tooltip'] = '<h6>' . esc_html__( 'Client Information', 'gravityformschip' ) . '</h6>' . esc_html__( 'Map your Form Fields to the available listed fields. Only email are required to be set and other fields are optional. You may refer to CHIP API for further information about the specific fields.', 'gravityformschip' );

		$conditional_logic = $other_settings_fields[1];
		unset($other_settings_fields[1]);

		$other_settings_fields[] = array(
			'name'      => 'purchaseInformation',
			'label'     => esc_html__( 'Purhase Information', 'gravityformschip' ),
			'type'      => 'field_map',
			'field_map' => $this->purchase_info_fields(),
			'tooltip'   => '<h6>' . esc_html__( 'Purchase Information', 'gravityformschip' ) . '</h6>' . esc_html__( 'Map your Form Fields to the available listed fields.', 'gravityformschip' )
		);

		$other_settings_fields[] = $conditional_logic;

		return $other_settings_fields;
	}

	public function option_choices() {
		return array();
	}

	public function client_info_fields() {

		$client_info_fields = array(
      array( 'name' => 'email',                   'label' => esc_html__( 'Email', 'gravityformschip' ),                   'required' => true ),
      array( 'name' => 'full_name',               'label' => esc_html__( 'Full Name', 'gravityformschip' )  ,             'required' => false ),
      array( 'name' => 'bank_account',            'label' => esc_html__( 'Bank Account Number', 'gravityformschip' ),     'required' => false ),
      array( 'name' => 'bank_code',               'label' => esc_html__( 'Bank Code', 'gravityformschip' ),               'required' => false ),
      array( 'name' => 'personal_code',           'label' => esc_html__( 'Personal Code', 'gravityformschip' ),           'required' => false ),
      array( 'name' => 'street_address',          'label' => esc_html__( 'Street Address', 'gravityformschip' ),          'required' => false ),
      array( 'name' => 'country',                 'label' => esc_html__( 'Country', 'gravityformschip' ),                 'required' => false ),
      array( 'name' => 'city',                    'label' => esc_html__( 'City', 'gravityformschip' ),                    'required' => false ),
      array( 'name' => 'zip_code',                'label' => esc_html__( 'Zip Code', 'gravityformschip' ),                'required' => false ),
      array( 'name' => 'shipping_street_address', 'label' => esc_html__( 'Shipping Street Address', 'gravityformschip' ), 'required' => false ),
      array( 'name' => 'shipping_country',        'label' => esc_html__( 'Shipping Country', 'gravityformschip' ),        'required' => false ),
      array( 'name' => 'shipping_city',           'label' => esc_html__( 'Shipping City', 'gravityformschip' ),           'required' => false ),
      array( 'name' => 'shipping_zip_code',       'label' => esc_html__( 'Shipping Zip Code', 'gravityformschip' ),       'required' => false ),
      array( 'name' => 'legal_name',              'label' => esc_html__( 'Legal Name', 'gravityformschip' ),              'required' => false ),
      array( 'name' => 'brand_name',              'label' => esc_html__( 'Brand Name', 'gravityformschip' ),              'required' => false ),
      array( 'name' => 'registration_number',     'label' => esc_html__( 'Registration Number', 'gravityformschip' ),     'required' => false ),
      array( 'name' => 'tax_number',              'label' => esc_html__( 'Tax Number', 'gravityformschip' ),              'required' => false ),
		);

		return apply_filters('gf_chip_client_info_fields', $client_info_fields);
	}

	public function purchase_info_fields() {
    $purchase_info_fields = array(
			array( 'name' => 'notes',               'label' => esc_html__( 'Purchase Note', 'gravityformschip' )  ,             'required' => false ),
		);

		return apply_filters( 'gf_chip_purchase_info_fields', $purchase_info_fields );
	}

	public function redirect_url( $feed, $submission_data, $form, $entry ) {
		// error_log('this is feed: ' . print_r($feed, true));
		// error_log('this is submission data: ' . print_r($submission_data, true));
		// error_log('this is form: ' . print_r($form, true));
		// error_log('this is entry: ' . print_r($entry, true));

		$entry_id  = $entry['id'];
		
    $configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global');
		
		$payment_amount_location  = rgars( $feed, 'meta/paymentAmount'); // location for payment amount
		$full_name_location       = rgars( $feed, 'meta/clientInformation_full_name'); // location for buyer full name
		$email_location           = rgars( $feed, 'meta/clientInformation_email'); // location for buyer email address
		$notes_location           = rgars( $feed, 'meta/purchaseInformation_notes'); // location for purchase notes

    // This if the total amount choose to form total
		if ($payment_amount_location == 'form_total'){
			$amount       = rgar( $submission_data, 'payment_amount' ) * 100;
			$product_name = rgar( $form, 'title' );
      $product_qty  = '1';
		} else {
      // This if the total amount choose to specific product.
			$items = rgar( $submission_data, 'line_items');
			foreach ($items as $item){
				if ($item['id'] == $payment_amount_location){
					$amount       = $item['unit_price'] * 100;
					$product_name = $item['name'];
          $product_qty  = $item['quantity'];
					break;
				}
			}
		}

	  $currency  = rgar( $entry, 'currency');
		$full_name = rgar( $entry, $full_name_location);
		$email     = rgar( $entry, $email_location);
		$notes     = rgar( $entry, $notes_location);

		if ($gf_global_settings = get_option('gravityformsaddon_gravityformschip_settings')){
			$private_key  = rgar($gf_global_settings, 'private_key');
		  $brand_id     = rgar($gf_global_settings, 'brand_id');
			$due_strict   = rgar($gf_global_settings, 'due_strict');
			$due_timing   = rgar($gf_global_settings, 'due_strict_timing', 60);
      $send_receipt = rgar($gf_global_settings, 'send_receipt', false);
		}
		
		if ($configuration_type == 'form'){
			$private_key  = rgars($feed, 'meta/private_key');
			$brand_id     = rgars($feed, 'meta/brand_id');
			$due_strict   = rgars($feed, 'meta/due_strict');
			$due_timing   = rgars($feed, 'meta/due_strict_timing', 60);
      $send_receipt = rgars($feed, 'meta/send_receipt', false);
		}

		$chip = GFChipAPI::get_instance($private_key, $brand_id);

    $redirect_url_args = array(
      'callback' => $this->_slug,
      'entry_id' => $entry_id,
    );

		$params = array(
      'success_callback' => $this->get_redirect_url($redirect_url_args),
      'success_redirect' => $this->get_redirect_url($redirect_url_args),
      'failure_redirect' => $this->get_redirect_url($redirect_url_args),
			'creator_agent'    => 'Gravity Forms: '. GF_CHIP_MODULE_VERSION,
			'reference'        => $entry_id,
			'platform'         => 'api',
      'send_receipt'     => $send_receipt == '1',
			'due'              => time() + (absint( $due_timing ) * 60),
      'brand_id'         => $brand_id,
			'client'           => array(
				'email'     => $email,
				'full_name' => substr($full_name,0,30),
			),
			'purchase'         => array(
				'currency'   => $currency,
				'notes'      => substr($notes, 0, 10000),
				'due_strict' => $due_strict == '1',
				'products'   => array([
					'name'     => substr($product_name, 0, 256),
					'price'    => round($amount),
					'quantity' => $product_qty,
				]),
			),
		);

		$payment = $chip->create_payment($params);

    if (!rgar($payment, 'id')) {
      return false;
    }

    // Store chip payment id
    gform_update_meta( $entry_id, 'chip_payment_id', rgar($payment, 'id'),  rgar( $form, 'id' ));

		return $payment['checkout_url'];
	}

  public function get_redirect_url($args = array()) {
    return add_query_arg(
      $args, 
      home_url( '/' ) 
    );
  }

  public function callback() {
    $entry_id = intval(rgget( 'entry_id' ));

    $processed_feeds = gform_get_meta($entry_id, 'processed_feeds');

    // Taking only the first array because chip feed should only one per entry.
    if (count($processed_feeds['gravityformschip']) != 1){
      exit('Unexpected feed count for entry: #' . $entry_id);
    }

    $feed_id   = $processed_feeds['gravityformschip'][0];
    $feed      = GFAPI::get_feed( $feed_id );

    $configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global');

    if ($gf_global_settings = get_option('gravityformsaddon_gravityformschip_settings')){
			$private_key  = rgar($gf_global_settings, 'private_key');
		  $brand_id     = rgar($gf_global_settings, 'brand_id');
		}
		
		if ($configuration_type == 'form'){
			$private_key  = rgars($feed, 'meta/private_key');
			$brand_id     = rgars($feed, 'meta/brand_id');
		}

    $chip = GFChipAPI::get_instance($private_key, $brand_id);

    // Get CHIP Payment ID
    $payment_id = gform_get_meta($entry_id, 'chip_payment_id');

    $chip_payment     = $chip->get_payment($payment_id);
    $transaction_data = rgar($chip_payment, 'transaction_data');
    $payment_method   = rgar($transaction_data, 'payment_method');

    $type = 'fail_payment';
    if ($chip_payment['status'] == 'paid') {
      $type = 'complete_payment';
    }

    $action = array(
      'id'               => $payment_id,
      'type'             => $type,
      'transaction_id'   => $payment_id,
      'entry_id'         => $entry_id,
      'payment_method'   => $payment_method,
    );

    if ($this->is_duplicate_callback( $payment_id )) {
      $action['abort_callback'] = 'true';
    }

    // Acquire lock to prevent concurrency
    $GLOBALS['wpdb']->get_results(
      "SELECT GET_LOCK('chip_gf_payment', 15);"
    );

    return $action;
  }

  public function post_callback( $callback_action, $result ) {
    // Release lock to enable concurrency
    $GLOBALS['wpdb']->get_results(
      "SELECT RELEASE_LOCK('chip_gf_payment');"
    );

    $entry_id = $callback_action['entry_id'];
    $entry    = GFAPI::get_entry( $entry_id );
    $url      = rgar($entry, 'source_url');
    $message  = esc_html__('. Payment failed. ', 'gravityformschip');

    if ($callback_action['type'] == 'complete_payment') {
      $entry_id        = $callback_action['entry_id'];
      $form_id         = $entry['form_id'];
            
      $message = esc_html__('. Payment successful. ', 'gravityformschip');
      $url     = $this->get_confirmation_url( $entry_id, $form_id );
    }

    // Output payment status
    echo $message;

    // Output redirection link
    printf(
      '<a href="%1$s">%2$s</a>%3$s', esc_url( $url ), esc_html__('Click here', 'gravityformschip'), esc_html__(' to redirect confirmation page', 'gravityformschip')
    );

    // Redirect user automatically
    echo '<script>window.location.replace(\''. esc_url_raw($url) . '\')</script>';
	}

  // This method inspired by gravityformsstripe plugin
  public function get_confirmation_url( $entry_id, $form_id ) {
    $redirect_url_args = array(
      'gf_chip_success' => 'true',
      'entry_id' => $entry_id,
      'form_id' => $form_id
    );

    $redirect_url_args['hash'] = wp_hash( implode($redirect_url_args) );
    
    return $this->get_redirect_url($redirect_url_args);
  }

  public function maybe_thankyou_page() {
    if (!rgget('gf_chip_success') OR !rgget('entry_id') OR !rgget('form_id')) {
      return;
    }

    $entry_id = sanitize_key(rgget('entry_id'));
    $form_id  = sanitize_key(rgget('form_id'));

    if (wp_hash( 'true' . $entry_id . $form_id ) != rgget('hash')){
      return;
    }

    $form  = GFAPI::get_form($form_id);
    $entry = GFAPI::get_entry($entry_id);

    if ( ! class_exists( 'GFFormDisplay' ) ) {
      require_once( GFCommon::get_base_path() . '/form_display.php' );
    }

    $confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );

    if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
      header( "Location: {$confirmation['redirect']}" );
      exit;
    }

    GFFormDisplay::$submission[ $form_id ] = array(
      'is_confirmation'      => true,
      'confirmation_message' => $confirmation,
      'form'                 => $form,
      'lead'                 => $entry,
    );
  }

  public function uninstall() {
    $option_names = array(
      'gf_chip_global_key_validation',
      'gf_chip_global_error_code'
    );
    
    foreach($option_names as $option_name){
      delete_option($option_name);
    }

    parent::uninstall();
  }
}