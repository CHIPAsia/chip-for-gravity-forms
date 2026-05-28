<?php
/**
 * PHPUnit bootstrap for CHIP for Gravity Forms.
 *
 * @package GravityFormsCHIP
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../vendor/wordpress/wordpress/' );
}

if ( ! defined( 'GF_CHIP_PLUGIN_PATH' ) ) {
	define( 'GF_CHIP_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'GF_CHIP_MODULE_VERSION' ) ) {
	define( 'GF_CHIP_MODULE_VERSION', 'v1.2.0' );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	echo "Run composer install to install test dependencies.\n";
	exit( 1 );
}
require_once $autoload;

\WP_Mock::bootstrap();

// Stub WordPress functions used by the API but not provided by WP_Mock.
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data   Data to encode.
	 * @param int   $options Optional.
	 * @param int   $depth   Optional.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// Gravity Forms helper stubs (not loaded because Gravity Forms framework is absent).
if ( ! function_exists( 'rgar' ) ) {
	function rgar( $array, $name, $default = null ) {
		if ( isset( $array[ $name ] ) ) {
			return $array[ $name ];
		}
		return $default;
	}
}

if ( ! function_exists( 'rgars' ) ) {
	function rgars( $array, $name, $default = null ) {
		$names = explode( '/', $name );
		$val   = $array;
		foreach ( $names as $current_name ) {
			if ( ! is_array( $val ) || ! isset( $val[ $current_name ] ) ) {
				return $default;
			}
			$val = $val[ $current_name ];
		}
		return $val;
	}
}

if ( ! function_exists( 'rgget' ) ) {
	function rgget( $name, $array = null ) {
		if ( ! isset( $array ) ) {
			$array = $_GET;
		}
		return isset( $array[ $name ] ) ? $array[ $name ] : '';
	}
}

if ( ! function_exists( 'rgpost' ) ) {
	function rgpost( $name, $do_stripslashes = true ) {
		return isset( $_POST[ $name ] ) ? $_POST[ $name ] : '';
	}
}

if ( ! function_exists( 'gform_update_meta' ) ) {
	function gform_update_meta( $entry_id, $meta_key, $meta_value, $form_id = null ) {
	}
}

if ( ! function_exists( 'gform_get_meta' ) ) {
	function gform_get_meta( $entry_id, $meta_key ) {
		return '';
	}
}

// WordPress HTTP API stubs used by class-gf-chip-api.php.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code = '', $message = '', $data = '' ) {
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 200;
	}
}

// Minimal stubs so class-gf-chip.php can load without the full Gravity Forms framework.
if ( ! class_exists( 'GFForms' ) ) {
	class GFForms {
		public static function include_payment_addon_framework() {
		}
	}
}

if ( ! class_exists( 'GFAddOn' ) ) {
	abstract class GFAddOn {
		public static function register( $class ) {
		}
	}
}

if ( ! class_exists( 'GFPaymentAddOn' ) ) {
	abstract class GFPaymentAddOn extends GFAddOn {
		protected $_supports_callbacks = false;
		protected $_slug = '';
		protected $_title = '';
		protected $_short_title = '';
		protected $_capabilities = array();
		protected $_capabilities_settings_page = '';
		protected $_capabilities_form_settings = '';
		protected $_capabilities_uninstall = '';

		public function __construct() {
		}

		public static function get_instance() {
			return null;
		}

		public function log_debug( $message ) {
		}

		public function add_note( $entry_id, $note, $note_type = 'info' ) {
		}

		public function get_payment_feed( $entry, $form = false ) {
			return array();
		}

		public function is_duplicate_callback( $callback_id ) {
			return false;
		}

		public function get_setting( $setting_name, $default = '' ) {
			return $default;
		}

		public function get_plugin_setting( $setting_name ) {
			return null;
		}

		public function get_base_url() {
			return 'https://example.com/wp-content/plugins/chip-for-gravity-forms';
		}
	}
}

if ( ! class_exists( 'GF_Chip' ) ) {
	require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip.php';
}

// Load plugin classes under test (API and Bootstrap do not require Gravity Forms for tested methods).
require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip-api.php';
require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip-bootstrap.php';
