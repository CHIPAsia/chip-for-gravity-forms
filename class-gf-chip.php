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
    add_action( 'wp_ajax_gf_chip_refund_payment', array( $this, 'chip_refund_payment' ), 10, 0 );

    parent::pre_init();
  }

  public function init() {
    add_filter( 'gform_disable_post_creation', array( $this, 'disable_post_creation' ), 10, 3 );

    $this->add_delayed_payment_support(
      array(
        'option_label' => esc_html__( 'Create post only when payment is received.', 'gravityformschip' )
      )
    );
    parent::init();
  }

  public function get_post_payment_actions_config( $feed_slug ) {
    // if ($feed_slug != $this->_slug) {
    //   return array();
    // }

    // $form = $this->get_current_form();

    // if ( GFCommon::has_post_field( $form['fields'] ) ) {
      return array(
        'position' => 'before',
        'setting'  => 'conditionalLogic',
      );
    // }

    // return array();
  }

  public function supported_currencies( $currencies ) {
    return array('MYR' => $currencies['MYR']);
  }

  public function get_menu_icon() {
    return plugins_url("assets/logo.svg", __FILE__);
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

    return apply_filters('gf_chip_plugin_settings_fields', $configuration);
  }

  public function get_description() {
    ob_start(); ?>
    <p>
      <?php
      printf(
        // translators: $1$s opens a link tag, %2$s closes link tag.
        esc_html__(
          'CHIP - Better Payment & Business Solutions. %1$sLearn more%2$s. %3$s%3$sThis is a global configuration and it is not mandatory to set. You can still configure on per form basis.',
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
        'name'     => 'brand_id',
        'label'    => esc_html__( 'Brand ID', 'gravityformschip' ),
        'type'     => 'text',
        'required' => true,
        'tooltip'  => '<h6>' . esc_html__( 'Brand ID', 'gravityformschip' ) . '</h6>' . esc_html__( 'Brand ID enables you to represent your Brand suitable for the system using the same CHIP account.', 'gravityformschip' )
      ),
      array(
        'name'     => 'secret_key',
        'label'    => esc_html__( 'Secret Key', 'gravityformschip' ),
        'type'     => 'text',
        'required' => true,
        'tooltip'  => '<h6>' . esc_html__( 'Secret Key', 'gravityformschip' ) . '</h6>' . esc_html__( 'Secret key is used to identify your account with CHIP. You are recommended to create dedicated secret key for each website.', 'gravityformschip' )
      ),

    );
  }

  public function global_advance_fields() {
    return array(
      array(
        'name'          => 'enable_refund',
        'label'         => 'Refund',
        'type'          => 'toggle',
        'default_value' => 'false',
        'tooltip'       => '<h6>' . esc_html__( 'Refund features', 'gravityformschip' ) . '</h6>' . esc_html__( 'Whether to enable refund through Gravity Forms. If configured, refund can be made through Gravity Forms --> Entries. Default is disabled.', 'gravityformschip' )
      ),
      array(
        'name'          => 'send_receipt',
        'label'         => 'Purchase Send Receipt',
        'type'          => 'toggle',
        'default_value' => 'false',
        'tooltip'       => '<h6>' . esc_html__( 'Purchase Send Receipt', 'gravityformschip' ) . '</h6>' . esc_html__( 'Whether to send receipt email when it\'s paid. If configured, the receipt email will be send by CHIP. Default is not send.', 'gravityformschip' )
      ),
      array(
        'name'    => 'due_strict',
        'label'   => esc_html__( 'Due Strict', 'gravityformschip' ),
        'type'    => 'toggle',
        'tooltip' => '<h6>' . esc_html__( 'Due Strict', 'gravityformschip' ) . '</h6>' . esc_html__( 'Whether to permit payments when Purchase\'s due has passed. By default those are permitted (and status will be set to overdue once due moment is passed). If this is set to true, it won\'t be possible to pay for an overdue invoice, and when due is passed the Purchase\'s status will be set to expired.', 'gravityformschip' )
      ),
      array(
        'name'        => 'due_strict_timing',
        'label'       => esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ),
        'type'        => 'text',
        'placeholder' => '60 for 60 minutes',
        'tooltip'     => '<h6>' . esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ) . '</h6>' . esc_html__( 'Set due time to enforce due timing for purchases. 60 for 60 minutes. If due_strict is set while due strict timing unset, it will default to 1 hour. Leave blank if unsure', 'gravityformschip' )
      )
    );
  }

  public function global_account_status_description() {
    $state = 'Not set';
    
    if ( get_option( 'gf_chip_global_key_validation' ) ){
      $state = 'Success';
    } else if ( !empty( get_option( 'gf_chip_global_error_code' ) ) ){
      $state = get_option( 'gf_chip_global_error_code' );
    }

    ob_start(); ?>
    <p>
      <?php
      printf(
        // translators: $1$s opens a link tag, %2$s closes link tag.
        esc_html__(
          'Your %1$sCHIP%2$s Brand ID and Secret Key settings: %3$s%5$s%4$s.',
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

    $this->log_debug( __METHOD__ . "(): Updating global keys. Old value " . print_r( $old_value, true ) );

    $chip       = GFChipAPI::get_instance( $new_value['secret_key'], $new_value['brand_id'] );
    $public_key = $chip->get_public_key();

    if ( is_string( $public_key ) ){
      update_option( 'gf_chip_global_key_validation', true );
      update_option( 'gf_chip_global_error_code', '' );

      $debug_log = __METHOD__ . "(): Global keys updated and successfully validated. New value " . print_r( $new_value, true );
    } else if ( is_array( $public_key['__all__'] ) ){
      $error_code_a = array_column( $public_key['__all__'], 'code' );
      $error_code   = implode( ', ', $error_code_a );

      update_option( 'gf_chip_global_key_validation', false );
      update_option( 'gf_chip_global_error_code', $error_code );

      $debug_log = __METHOD__ . "(): Updating global keys failed " . print_r( $old_value, true ) . print_r( $error_code, true);
    } else {
      update_option( 'gf_chip_global_key_validation', false );
      update_option( 'gf_chip_global_error_code', 'unspecified error!' );

      $debug_log =  __METHOD__ . "(): Updating global keys failed with unspecified error";
    }

    $this->log_debug( $debug_log );
  }

  public function feed_settings_fields() {
    $feed_settings_fields = parent::feed_settings_fields();
    $feed_settings_fields[0]['description'] = esc_html__( 'Configuration page for CHIP for Gravity Forms.', 'gravityformschip' );
    
    // Remove subscription option from Transaction type
    unset( $feed_settings_fields[0]['fields'][1]['choices'][2] );

    // Ensure transaction type mandatory
    $feed_settings_fields[0]['fields'][1]['required'] = true;

    // Temporarily remove transaction type section
    $transaction_type_array = $feed_settings_fields[0]['fields'][1];
    unset( $feed_settings_fields[0]['fields'][1] );

    // Temporarily remove product and services section
    $product_and_services = $feed_settings_fields[2];
    $other_settings = $feed_settings_fields[3];
    unset( $feed_settings_fields[2] );
    unset( $feed_settings_fields[3] );

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
      'description' => esc_html__('Set your Brand ID and Secret Key for the use of CHIP with this forms', 'gravityformschip'),
      'fields'     => array(
        array(
          'name'     => 'brand_id',
          'label'    => esc_html__( 'Brand ID', 'gravityformschip' ),
          'type'     => 'text',
          'class'    => 'medium',
          'required' => true,
          'tooltip'  => '<h6>' . esc_html__( 'Brand ID', 'gravityformschip' ) . '</h6>' . esc_html__( 'Brand ID enables you to represent your Brand suitable for the system using the same CHIP account.', 'gravityformschip' )
        ),
        array(
          'name'     => 'secret_key',
          'label'    => esc_html__( 'Secret Key', 'gravityformschip' ),
          'type'     => 'text',
          'class'    => 'medium',
          'required' => true,
          'tooltip'  => '<h6>' . esc_html__( 'Secret Key', 'gravityformschip' ) . '</h6>' . esc_html__( 'Secret key is used to identify your account with CHIP. You are recommended to create dedicated secret key for each website.', 'gravityformschip' )
        ),
      )
    );

    $feed_settings_fields[] = array(
      'title'       => esc_html__( 'CHIP Optional Configuration', 'gravityformschip' ),
      'dependency'  => array(
        'field'  => 'chipConfigurationType',
        'values' => array( 'form' )
      ),
      'description' => esc_html__('Further customize the behavior of the payment.', 'gravityformschip'),
      'fields'      => array(
        array(
          'name'    => 'enable_refund',
          'label'   => esc_html__( 'Refund', 'gravityformschip' ),
          'type'    => 'toggle',
          'tooltip' => '<h6>' . esc_html__( 'Refund features', 'gravityformschip' ) . '</h6>' . esc_html__( 'Whether to enable refund through Gravity Forms. If configured, refund can be made through Gravity Forms --> Entries. Default is disabled.', 'gravityformschip' )
        ),
        array(
          'name'    => 'send_receipt',
          'label'   => esc_html__( 'Purchase Send Receipt', 'gravityformschip' ),
          'type'    => 'toggle',
          'tooltip' => '<h6>' . esc_html__('Purchase Send Receipt', 'gravityformschip') . '</h6>' . esc_html__('Whether to send receipt email for this Purchase when it\'s paid.', 'gravityformschip')
        ),
        array(
          'name'    => 'due_strict',
          'label'   => esc_html__( 'Due Strict', 'gravityformschip' ),
          'type'    => 'toggle',
          'tooltip' => '<h6>' . esc_html__('Due Strict', 'gravityformschip') . '</h6>' . esc_html__('Whether to permit payments when Purchase\'s due has passed. By default those are permitted (and status will be set to overdue once due moment is passed). If this is set to true, it won\'t be possible to pay for an overdue invoice, and when due is passed the Purchase\'s status will be set to expired.', 'gravityformschip')
        ),
        array(
          'name'        => 'due_strict_timing',
          'label'       => esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ),
          'type'        => 'text',
          'placeholder' => '60 for 60 minutes',
          'tooltip'     => '<h6>' . esc_html__( 'Due Strict Timing (minutes)', 'gravityformschip' ) . '</h6>' . esc_html__( 'Set due time to enforce due timing for purchases. 60 for 60 minutes. If due_strict is set while due strict timing unset, it will default to 1 hour. Leave blank if unsure', 'gravityformschip' )
        ),
      )
    );

    // Readd transaction type section
    $feed_settings_fields[0]['fields'][] = $transaction_type_array;

    // Readd product and services section
    $feed_settings_fields[] = $product_and_services;
    $feed_settings_fields[] = $other_settings;

    return apply_filters( 'gf_chip_feed_settings_fields', $feed_settings_fields );
  }

  public function other_settings_fields() {
    $other_settings_fields                 = parent::other_settings_fields();
    $other_settings_fields[0]['name']      = 'clientInformation';
    $other_settings_fields[0]['label']     = esc_html__( 'Client Information.', 'gravityformschip' );
    $other_settings_fields[0]['field_map'] = $this->client_info_fields();
    $other_settings_fields[0]['tooltip']   = '<h6>' . esc_html__( 'Client Information', 'gravityformschip' ) . '</h6>' . esc_html__( 'Map your Form Fields to the available listed fields. Only email are required to be set and other fields are optional. You may refer to CHIP API for further information about the specific fields.', 'gravityformschip' );

    $conditional_logic = $other_settings_fields[1];
    unset($other_settings_fields[1]);

    // This dynamic_field_map inspired by gravityformsstripe plugin
    $other_settings_fields[] = array(
      'name'    => 'clientMetaData',
      'label'   => esc_html__( 'Client Information Metadata', 'gravityformschip' ),
      'type'    => 'dynamic_field_map',
      'limit'   => 15,
      'tooltip' => '<h6>' . esc_html__( 'Client Information Metadata', 'gravityformsstripe' ) . '</h6>' . esc_html__( 'You may send custom key information to CHIP /purchases/ client fields. A maximum of 15 custom keys may be sent. The key name must be 40 characters or less, and the mapped data will be truncated accordingly as per requirements by CHIP. Accepted keys is \'bank_account\', \'bank_code\', \'personal_code\', \'street_address\', \'country\', \'city\', \'zip_code\', \'shipping_street_address\', \'shipping_country\', \'shipping_city\', \'shipping_zip_code\', \'legal_name\', \'brand_name\', \'registration_number\', \'tax_number\'', 'gravityformschip' ),
    );

    $other_settings_fields[] = array(
      'name'      => 'purchaseInformation',
      'label'     => esc_html__( 'Purhase Information', 'gravityformschip' ),
      'type'      => 'field_map',
      'field_map' => $this->purchase_info_fields(),
      'tooltip'   => '<h6>' . esc_html__( 'Purchase Information', 'gravityformschip' ) . '</h6>' . esc_html__( 'Map your Form Fields to the available listed fields.', 'gravityformschip' )
    );

    $other_settings_fields[] = array(
      'name'      => 'miscellaneous',
      'label'     => esc_html__( 'Miscellaneous', 'gravityformschip' ),
      'type'      => 'field_map',
      'field_map' => $this->miscellaneous_info_fields(),
      'tooltip'   => '<h6>' . esc_html__( 'Miscellaneous', 'gravityformschip' ) . '</h6>' . esc_html__( 'Map your Form Fields to the available listed fields.', 'gravityformschip' )
    );

    $other_settings_fields[] = array(
      'name'        => 'cancelUrl',
      'label'       => esc_html__( 'Cancel URL', 'gravityformschip' ),
      'type'        => 'text',
      'placeholder' => 'https://example.com/pages',
      'tooltip'     => '<h6>' . esc_html__( 'Cancel URL', 'gravityformschip' ) . '</h6>' . esc_html__( 'Redirect to custom URL in the event of cancellation. Leaving blank will redirect back to form page in the event of cancellation. Note: You can set success behavior by setting confirmation redirect.', 'gravityformschip' )
    );

    $other_settings_fields[] = $conditional_logic;

    return $other_settings_fields;
  }

  // This method must return empty array to prevent option from showing in feeds settings
  public function option_choices() {
    return array();
  }

  public function client_info_fields() {

    $client_info_fields = array(
      array( 'name' => 'email',     'label' => esc_html__( 'Email', 'gravityformschip' ), 'required' => true ),
      array( 'name' => 'full_name', 'label' => esc_html__( 'Full Name', 'gravityformschip' ), 'required' => false ),
    );

    return apply_filters( 'gf_chip_client_info_fields', $client_info_fields );
  }

  public function purchase_info_fields() {
    $purchase_info_fields = array(
      array( 'name' => 'notes', 'label' => esc_html__( 'Purchase Note', 'gravityformschip' ), 'required' => false ),
    );

    return apply_filters( 'gf_chip_purchase_info_fields', $purchase_info_fields );
  }

  public function miscellaneous_info_fields() {
    $miscellaneous_info_fields = array(
      array( 'name' => 'reference', 'label' => esc_html__( 'Reference', 'gravityformschip' ), 'required' => false ),
    );

    return apply_filters( 'gf_chip_miscellaneous_info_fields', $miscellaneous_info_fields );
  }

  public function redirect_url( $feed, $submission_data, $form, $entry ) {
    // error_log('this is feed: ' . print_r($feed, true));
    // error_log('this is submission data: ' . print_r($submission_data, true));
    // error_log('this is form: ' . print_r($form, true));
    // error_log('this is entry: ' . print_r($entry, true));

    $entry_id = $entry['id'];

    $this->log_debug( __METHOD__ . "(): Started for entry id: #" . $entry_id );

    $configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global' );

    $payment_amount_location = rgars( $feed, 'meta/paymentAmount'); // location for payment amount
    $name_location           = rgars( $feed, 'meta/clientInformation_full_name'); // location for buyer name
    $email_location          = rgars( $feed, 'meta/clientInformation_email'); // location for buyer email address
    $notes_location          = rgars( $feed, 'meta/purchaseInformation_notes'); // location for purchase notes
    $reference_location      = rgars( $feed, 'meta/miscellaneous_reference'); // location for reference

    $full_name_location_array = array();

    foreach ( $form['fields'] as $field ) {
      if ( $field->type == 'name' ) {
        if ($name_location != $field->id) {
          continue;
        }

        $full_name_location_array[$field->id] = array();
        foreach($field->inputs as $input) {
          $full_name_location_array[$field->id][] = $input['id'];
        }
      }
    }

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

    $currency  = rgar( $entry, 'currency' );
    $email     = rgar( $entry, $email_location );
    $notes     = rgar( $entry, $notes_location );
    $reference = rgar( $entry, $reference_location );
    $full_name = rgar( $entry, $name_location, '' );

    if ( !empty($full_name_location_array) ) {
      if ( array_key_exists( $name_location, $full_name_location_array ) ) {
        foreach( $full_name_location_array[$name_location] as $full_name_location ) {
          $full_name .= ' ' . rgar( $entry, $full_name_location );
        }
        $full_name = trim( $full_name );
      }
    }

    $client_meta_data = $this->get_chip_client_meta_data( $feed, $entry, $form );

    if ( $gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' ) ) {
      $secret_key   = rgar( $gf_global_settings, 'secret_key' );
      $brand_id     = rgar( $gf_global_settings, 'brand_id' );
      $due_strict   = rgar( $gf_global_settings, 'due_strict' );
      $due_timing   = rgar( $gf_global_settings, 'due_strict_timing', 60 );
      $send_receipt = rgar( $gf_global_settings, 'send_receipt', false );
    }

    if ($configuration_type == 'form'){
      $secret_key   = rgars( $feed, 'meta/secret_key' );
      $brand_id     = rgars( $feed, 'meta/brand_id' );
      $due_strict   = rgars( $feed, 'meta/due_strict' );
      $due_timing   = rgars( $feed, 'meta/due_strict_timing', 60 );
      $send_receipt = rgars( $feed, 'meta/send_receipt', false );
    }

    $chip = GFChipAPI::get_instance( $secret_key, $brand_id );

    $redirect_url_args = array(
      'callback' => $this->_slug,
      'entry_id' => $entry_id,
    );

    $params = array(
      'success_callback' => $this->get_redirect_url( $redirect_url_args ),
      'success_redirect' => $this->get_redirect_url( $redirect_url_args ),
      'failure_redirect' => $this->get_redirect_url( $redirect_url_args ),
      'cancel_redirect'  => $this->get_redirect_url( $redirect_url_args ),
      'creator_agent'    => 'Gravity Forms: '. GF_CHIP_MODULE_VERSION,
      'reference'        => empty( $reference ) ? $entry_id : substr( $reference, 0, 128 ),
      'platform'         => 'gravityforms',
      'send_receipt'     => $send_receipt == '1',
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
        'due_strict' => $due_strict == '1',
        'products'   => array([
          'name'     => substr( $product_name, 0, 256 ),
          'price'    => round( $amount ),
          'quantity' => $product_qty,
        ]),
      ),
    );

    // merge client array with client meta data array
    $params['client'] += $client_meta_data;

    // enable customization for gateway charges
    $params = apply_filters( 'gf_chip_purchases_api_parameters', $params, array($feed, $submission_data, $form, $entry) );

    $this->log_debug( __METHOD__ . "(): Params keys " . print_r( $params, true ) );

    $payment = $chip->create_payment( $params );

    if ( !rgar( $payment, 'id' ) ) {
      $this->log_debug( __METHOD__ . "(): Attempt to create purchases failed " . print_r( $payment, true ) );
      return false;
    }

    // Store chip payment id
    gform_update_meta( $entry_id, 'chip_payment_id', rgar( $payment, 'id' ),  rgar( $form, 'id' ) );

    // Add note
    $note = esc_html__( 'Customer redirected to payment page. ', 'gravityformschip' );
    $note.= esc_html__( 'URL: ', 'gravityformschip' ) . $payment['checkout_url'];
    $this->add_note( $entry['id'], $note, 'success' );

    // Add is test note
    if ( $payment['is_test'] === true ) {
      $note = __( 'This is test environment where payment status is simulated.', 'gravityformschip' );
      $this->add_note( $entry['id'], $note, 'error' );
    }

    $this->log_debug( __METHOD__ . "(): Attempt to create purchases successful " . print_r( $payment, true ) );

    return $payment['checkout_url'];
  }

  public function get_redirect_url($args = array()) {
    return add_query_arg(
      $args, 
      home_url( '/' ) 
    );
  }

  // This method inspired by gravityformsstripe plugin
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
        $this->log_debug( __METHOD__ . '(): ' . json_encode( $metadata ) );
      }

    }

    return $metadata;

  }

  public function get_timezone(){
    if ( preg_match( '/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string() ) ) {
      return wp_timezone_string();
    }

    return 'UTC';
  }

  public function callback() {
    $entry_id = intval( rgget( 'entry_id' ) );
    $this->log_debug( 'Started ' . __METHOD__ . "(): for entry id #" . $entry_id );

    // This is long way to get a feed_id from entry_id
    // Using the method provided by parent is the choice here
    // $processed_feeds = gform_get_meta($entry_id, 'processed_feeds');
    // $feed_id   = $processed_feeds['gravityformschip'][0];
    // $submission_feed = GFAPI::get_feed( $feed_id );

    // Taking only the first array because chip feed should only one per entry.
    // if (count($processed_feeds['gravityformschip']) != 1){
    //   $msg = 'Unexpected feed count for entry: #' . $entry_id;
    //   $this->log_debug( __METHOD__ . "(): " . $msg );
    //   wp_die($msg);
    // }

    $entry           = GFAPI::get_entry( $entry_id );
    $submission_feed = $this->get_payment_feed( $entry );

    $this->log_debug( __METHOD__ . "(): Entry ID #$entry_id is set to Feed ID #" . $submission_feed['id'] );

    $configuration_type = rgars( $submission_feed, 'meta/chipConfigurationType', 'global' );

    if ( $gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' ) ){
      $secret_key = rgar( $gf_global_settings, 'secret_key' );
      $brand_id   = rgar( $gf_global_settings, 'brand_id' );
    }

    if ( $configuration_type == 'form' ) {
      $secret_key = rgars( $submission_feed, 'meta/secret_key' );
      $brand_id   = rgars( $submission_feed, 'meta/brand_id' );
    }

    $chip = GFChipAPI::get_instance( $secret_key, $brand_id );

    // Get CHIP Payment ID
    $payment_id = gform_get_meta( $entry_id, 'chip_payment_id' );

    $chip_payment = $chip->get_payment( $payment_id );

    $this->log_debug( __METHOD__ . "(): Entry ID #$entry_id get purchases information" . print_r($chip_payment, true) );

    $transaction_data = rgar( $chip_payment, 'transaction_data' );
    $payment_method   = rgar( $transaction_data, 'payment_method' );

    $type = 'fail_payment';
    if ($chip_payment['status'] == 'paid') {
      $type = 'complete_payment';
    }

    $action = array(
      'id'             => $payment_id,
      'type'           => $type,
      'transaction_id' => $payment_id,
      'entry_id'       => $entry_id,
      'payment_method' => $payment_method,
      'amount'         => number_format( $chip_payment['purchase']['total'] / 100, 2 ),
    );

    // Acquire lock to prevent concurrency
    $GLOBALS['wpdb']->get_results(
      "SELECT GET_LOCK('chip_gf_payment', 15);"
    );

    if ( $this->is_duplicate_callback( $payment_id ) ) {
      $action['abort_callback'] = 'true';
    }

    $this->log_debug( 'End of ' . __METHOD__ . "(): params return value: " . print_r( $action, true ) );

    return $action;
  }

  public function post_callback( $callback_action, $result ) {
    $this->log_debug( 'Start of ' . __METHOD__ . "(): for entry id: #" . $callback_action['entry_id'] );

    // Release lock to enable concurrency
    $GLOBALS['wpdb']->get_results(
      "SELECT RELEASE_LOCK('chip_gf_payment');"
    );

    $entry_id = $callback_action['entry_id'];
    $entry    = GFAPI::get_entry( $entry_id );
    $url      = rgar( $entry, 'source_url' );
    $message  = __( '. Payment failed. ', 'gravityformschip' );

    if ( $callback_action['type'] == 'complete_payment' ) {
      $entry_id = $callback_action['entry_id'];
      $form_id  = $entry['form_id'];
            
      $message = __( '. Payment successful. ', 'gravityformschip' );
      $url     = $this->get_confirmation_url( $entry, $form_id );
    } else {
      $submission_feed = $this->get_payment_feed($entry);
      $cancel_url      = rgars( $submission_feed, 'meta/cancelUrl' );

      if ( $cancel_url AND filter_var( $cancel_url, FILTER_VALIDATE_URL ) ) {
        $url = $cancel_url;
      }
    }

    // Output payment status
    echo esc_html( $message );

    // Output redirection link
    printf(
      '<a href="%1$s">%2$s</a>%3$s', esc_url( $url ), esc_html__( 'Click here', 'gravityformschip' ), esc_html__( ' to redirect confirmation page', 'gravityformschip' )
    );

    // Redirect user automatically
    echo '<script>window.location.replace(\''. esc_url_raw($url) . '\')</script>';
    $this->log_debug( 'End of ' . __METHOD__ . "(): for entry id: #" . $callback_action['entry_id'] );
  }

  // This method inspired by gravityformsstripe plugin
  public function get_confirmation_url( $entry, $form_id ) {
    $redirect_url_args = array(
      'gf_chip_success' => 'true',
      'entry_id'        => $entry['id'],
      'form_id'         => $form_id
    );

    $redirect_url_args['hash'] = wp_hash( implode( $redirect_url_args ) );
    
    return add_query_arg(
      $redirect_url_args,
      rgar( $entry, 'source_url' )
    );
  }

  // This method inspired by gravityformsstripe plugin
  public function maybe_thankyou_page() {
    if ( !rgget( 'gf_chip_success' ) OR !rgget( 'entry_id' ) OR !rgget( 'form_id' ) ) {
      return;
    }

    $entry_id = sanitize_key( rgget( 'entry_id' ) );
    $form_id  = sanitize_key( rgget( 'form_id' ) );
    $this->log_debug( __METHOD__ . "(): confirmation page for entry id: #" . $entry_id );

    if (wp_hash( 'true' . $entry_id . $form_id ) != rgget('hash')){
      $this->log_debug( __METHOD__ . "(): wp_hash failure for entry id: #" . $entry_id );
      return;
    }

    $form  = GFAPI::get_form( $form_id );
    $entry = GFAPI::get_entry( $entry_id );

    if ( ! class_exists( 'GFFormDisplay' ) ) {
      require_once( GFCommon::get_base_path() . '/form_display.php' );
    }

    $confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );

    if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
      $this->log_debug( __METHOD__ . "(): confirmation is redirect type for entry id: #" . $entry_id );
      header( "Location: {$confirmation['redirect']}" );
      exit;
    }

    GFFormDisplay::$submission[ $form_id ] = array(
      'is_confirmation'      => true,
      'confirmation_message' => $confirmation,
      'form'                 => $form,
      'lead'                 => $entry,
    );

    $this->log_debug( __METHOD__ . "(): confirmation is non redirect type for entry id: #" . $entry_id );
  }

  // this method content can be inspired from gravityformsauthorizenet
  // this method used for subscription for gravityformsauthorizenet
  // public function check_status() {
  // }

  // this method inspired by gravityformspaypal
  public function supported_notification_events( $form ) {
    return array(
      'complete_payment' => esc_html__( 'Payment Completed', 'gravityformschip' ),
      'refund_payment'   => esc_html__( 'Payment Refunded', 'gravityformschip' ),
      'fail_payment'     => esc_html__( 'Payment Failed', 'gravityformschip' ),
    );
  }

  // default $is_disabled = false
  public function disable_post_creation( $is_disabled, $form, $entry ) {
    $submission_feed = $this->get_payment_feed( $entry, $form );
    $submission_data = $this->get_submission_data( $submission_feed, $form, $entry );

    if ( !$this->is_payment_gateway( $entry['id'] ) ) {
      return $is_disabled;
    }

    if ( !GFCommon::has_post_field($form['fields'] ) ) {
      return $is_disabled;
    }

    if (! $submission_feed || empty( $submission_data['payment_amount'] ) ) {
      return $is_disabled;
    }

    return rgar( $submission_feed['meta'], "delay_{$this->_slug}" ) == '1' ? true : $is_disabled;
  }

  // $action value is function callback() return value
  public function create_post_now( $entry, $action ) {
    $form_id         = $entry['form_id'];
    $form            = GFAPI::get_form( $form_id );
    $submission_feed = $this->get_payment_feed( $entry, $form );

    if ( !$this->is_payment_gateway( $entry['id'] ) ) {
      return;
    }

    if ( !GFCommon::has_post_field( $form['fields'] ) ) {
      return;
    }

    if ( rgars( $submission_feed, 'meta/delay_post_creation' ) == '1' ) {
      $this->log_debug( __METHOD__ . '(): Creating delayed post for entry id: #' . $entry['id'] );
      $post_id = RGFormsModel::create_post( $form, $entry );
      $this->log_debug( __METHOD__ . '(): Post #' . $post_id . ' created for entry id: #' . $entry['id'] );
    }
  }

  public function complete_payment( &$entry, $action ) {
    parent::complete_payment( $entry, $action );

    $transaction_id = rgar( 'transaction_id', $action );
    $form           = GFAPI::get_form( $entry['form_id'] );
    $feed           = $this->get_payment_feed( $entry, $form );

    // this is a hack to allow processing of delayed task
    if( rgar( $feed['meta'], "delay_{$this->_slug}" ) == '1' ){
      gform_update_meta( $entry['id'], "{$this->_slug}_is_fulfilled", false );
    }

    $this->trigger_payment_delayed_feeds( $transaction_id, $feed, $entry, $form );

    return true;
  }

  public function process_feed( $feed, $entry, $form ) {
    if ( $this->_bypass_feed_delay ) {
      RGFormsModel::create_post( $form, $entry );
    }
  }

  // Refund button
  public function entry_info( $form_id, $entry ) {

    // return if no transaction_id
    if ( empty( $entry['transaction_id'] ) OR empty( $entry['payment_method'] ) OR $entry['payment_status'] != 'Paid' OR $entry['transaction_type'] != '1' ) {
      return;
    }

    // return if payment gateway is not chip
    if ( !$this->is_payment_gateway( $entry['id'] ) ) {
      return;
    }

    ?>
    <div id="gf_refund_container">
      <div class="message" style="display:none;"></div>
    </div>
    <br>
    <input id="refundpay" type="button" name="refundpay"
           value="<?php esc_html_e( 'Refund', 'gravityformschip' ) ?>" class="button uninstall-addon red"
           onclick="RefundPayment();"
           onkeypress="RefundPayment();"
           <?php echo esc_attr( defined( 'GF_CHIP_DISABLE_REFUND_PAYMENT' ) ? 'disabled' : '' ) ?>/>
    <img src="<?php echo GFCommon::get_base_url() ?>/images/spinner.svg" id="refund_spinner"
         style="display: none;"/>

    <script type="text/javascript">
      function RefundPayment() {
        
        jQuery('#refund_spinner').fadeIn();

        jQuery.post(ajaxurl, {
            action                 : "gf_chip_refund_payment",
            gf_chip_refund_payment : '<?php echo wp_create_nonce( 'gf_chip_refund_payment' ); ?>',
            entryId                : '<?php echo absint( $entry['id'] ); ?>'
          },
          function (response) {
            if (response) {
              displayMessage(response, "error", "#gf_refund_container");
            } else {
              displayMessage(<?php echo json_encode( esc_html__( 'Refund has been executed successfully.', 'gravityformschip' ) ); ?>, "success", "#gf_refund_container" );

              jQuery('#refundpay').hide();
            }

            jQuery('#refund_spinner').hide();
          }
        );
      }
    </script>

    <?php
  }

  public function chip_refund_payment() {
    check_admin_referer( 'gf_chip_refund_payment', 'gf_chip_refund_payment' );

    if ( defined( 'GF_CHIP_DISABLE_REFUND_PAYMENT' ) ) {
      esc_html_e( 'Refund feature has been disabled by administrators.', 'gravityformschip' );
      die();
    }

    $entry_id = absint( rgpost( 'entryId' ) );

    $entry = GFAPI::get_entry( $entry_id );
    $feed  = $this->get_payment_feed( $entry );

    $this->log_debug( __METHOD__ . "(): Entry ID #" . $entry['id'] . " is set to Feed ID #" . $feed['id'] );

    $configuration_type = rgars( $feed, 'meta/chipConfigurationType', 'global' );

    if ( $gf_global_settings = get_option( 'gravityformsaddon_gravityformschip_settings' ) ){
      $secret_key = rgar( $gf_global_settings, 'secret_key' );
      $brand_id   = rgar( $gf_global_settings, 'brand_id' );
      $refund     = rgar( $gf_global_settings, 'enable_refund', false);
    }

    if ($configuration_type == 'form'){
      $secret_key = rgars( $feed, 'meta/secret_key' );
      $brand_id   = rgars( $feed, 'meta/brand_id' );
      $refund     = rgars( $feed, 'meta/enable_refund', false);
    }

    if (!$refund == '1') {
      esc_html_e( 'Refund feature has been disabled.', 'gravityformschip' );
      die();
    }

    $chip       = GFChipAPI::get_instance( $secret_key, $brand_id );
    $payment_id = rgar( $entry, 'transaction_id' );
    $payment    = $chip->refund_payment( $payment_id, [] );

    if ( !is_array( $payment ) || !array_key_exists( 'id', $payment ) ) {
      echo esc_html( sprintf( __( 'There was an error while refunding the payment. %s', 'gravityformschip' ), var_export( $payment, true ) ) );
      die();
    }

    $action = array(
      'transaction_id' => $payment['id'],
      'amount'         => $entry['payment_amount'],
    );

    if ( !$this->refund_payment( $entry, $action ) ) {
      esc_html_e( 'There was an error while refunding the payment.', 'gravityformschip' );
    }

    die();
  }

  public function uninstall() {
    $option_names = array(
      'gf_chip_global_key_validation',
      'gf_chip_global_error_code'
    );
    
    foreach( $option_names as $option_name ){
      delete_option( $option_name );
    }

    parent::uninstall();
  }
}
