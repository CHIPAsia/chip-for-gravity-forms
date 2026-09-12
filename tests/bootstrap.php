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

/**
 * In-memory entry meta store standing in for Gravity Forms' real one.
 *
 * These were previously empty no-ops, which meant a read always returned ''
 * and a write/discard could not be observed. That hid real defects: code that
 * wrote a value and then re-read it looked correct even when the write never
 * reached anything, and a comparison against stored meta could not be
 * exercised at all. Three shipped bugs lived in exactly that seam.
 *
 * Behaviour deliberately mirrors Gravity Forms:
 *  - a missing key returns '' (GF does not return null)
 *  - a value that was stored as '' is therefore indistinguishable from
 *    missing, exactly as in production
 *  - 0 / '0' / false are returned as stored, NOT coerced to ''
 *  - meta is per entry id and per key
 *
 * Tests that need a clean slate call GF_Chip_Test_Meta::reset().
 */
if ( ! class_exists( 'GF_Chip_Test_Meta' ) ) {
	/**
	 * Test double for the Gravity Forms entry meta store.
	 */
	class GF_Chip_Test_Meta {

		/**
		 * Stored meta, keyed by entry id then meta key.
		 *
		 * @var array
		 */
		private static $store = array();

		/**
		 * Every write, in order, for assertions about call counts.
		 *
		 * @var array
		 */
		private static $writes = array();

		/**
		 * Every delete, in order.
		 *
		 * @var array
		 */
		private static $deletes = array();

		/**
		 * Clears all state.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$store   = array();
			self::$writes  = array();
			self::$deletes = array();
		}

		/**
		 * Stores a value.
		 *
		 * @param int    $entry_id   Entry id.
		 * @param string $meta_key   Meta key.
		 * @param mixed  $meta_value Value.
		 * @return bool
		 */
		public static function set( $entry_id, $meta_key, $meta_value ) {
			$entry_id = (int) $entry_id;

			self::$store[ $entry_id ][ $meta_key ] = $meta_value;
			self::$writes[]                        = array(
				'entry_id' => $entry_id,
				'key'      => $meta_key,
				'value'    => $meta_value,
			);

			return true;
		}

		/**
		 * Reads a value, mirroring Gravity Forms' '' for a missing key.
		 *
		 * @param int    $entry_id Entry id.
		 * @param string $meta_key Meta key.
		 * @return mixed
		 */
		public static function get( $entry_id, $meta_key ) {
			$entry_id = (int) $entry_id;

			if ( ! isset( self::$store[ $entry_id ] ) || ! array_key_exists( $meta_key, self::$store[ $entry_id ] ) ) {
				return '';
			}

			return self::$store[ $entry_id ][ $meta_key ];
		}

		/**
		 * Deletes a value.
		 *
		 * @param int    $entry_id Entry id.
		 * @param string $meta_key Meta key.
		 * @return void
		 */
		public static function delete( $entry_id, $meta_key ) {
			$entry_id = (int) $entry_id;

			unset( self::$store[ $entry_id ][ $meta_key ] );
			self::$deletes[] = array(
				'entry_id' => $entry_id,
				'key'      => $meta_key,
			);
		}

		/**
		 * Whether a key exists for an entry (distinguishes set-from-absent,
		 * which get() alone cannot do because GF returns '' for both).
		 *
		 * @param int    $entry_id Entry id.
		 * @param string $meta_key Meta key.
		 * @return bool
		 */
		public static function has( $entry_id, $meta_key ) {
			$entry_id = (int) $entry_id;

			return isset( self::$store[ $entry_id ] ) && array_key_exists( $meta_key, self::$store[ $entry_id ] );
		}

		/**
		 * All writes recorded, for call-count assertions.
		 *
		 * @return array
		 */
		public static function writes() {
			return self::$writes;
		}

		/**
		 * Writes matching a key.
		 *
		 * @param string $meta_key Meta key.
		 * @return array
		 */
		public static function writes_for( $meta_key ) {
			return array_values(
				array_filter(
					self::$writes,
					function ( $w ) use ( $meta_key ) {
						return $w['key'] === $meta_key;
					}
				)
			);
		}

		/**
		 * All deletes recorded.
		 *
		 * @return array
		 */
		public static function deletes() {
			return self::$deletes;
		}

		/**
		 * Whether a key was deleted.
		 *
		 * @param string $meta_key Meta key.
		 * @return bool
		 */
		public static function was_deleted( $meta_key ) {
			foreach ( self::$deletes as $d ) {
				if ( $d['key'] === $meta_key ) {
					return true;
				}
			}

			return false;
		}
	}
}

if ( ! function_exists( 'gform_update_meta' ) ) {
	function gform_update_meta( $entry_id, $meta_key, $meta_value, $form_id = null ) {
		return GF_Chip_Test_Meta::set( $entry_id, $meta_key, $meta_value );
	}
}

if ( ! function_exists( 'gform_get_meta' ) ) {
	function gform_get_meta( $entry_id, $meta_key ) {
		return GF_Chip_Test_Meta::get( $entry_id, $meta_key );
	}
}

if ( ! function_exists( 'gform_delete_meta' ) ) {
	function gform_delete_meta( $entry_id, $meta_key ) {
		GF_Chip_Test_Meta::delete( $entry_id, $meta_key );
	}
}

// WordPress HTTP API stubs used by includes/class-gf-chip-api.php.
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

// Minimal stubs so includes/class-gf-chip.php can load without the full Gravity Forms framework.
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

		/**
		 * Mirrors the shape Gravity Forms core returns from
		 * GFPaymentAddOn::feed_settings_fields() for its first two sections:
		 * the transactionType field (with the Subscription choice) and the
		 * Subscription Settings section it gates.
		 *
		 * Core's own version is in includes/addon/class-gf-payment-addon.php.
		 * Kept minimal — only the parts GF_Chip::feed_settings_fields() reads.
		 *
		 * @return array
		 */
		public function feed_settings_fields() {
			return array(
				array(
					'description' => '',
					'fields'      => array(
						array(
							'name'     => 'feedName',
							'label'    => 'Name',
							'type'     => 'text',
							'required' => true,
						),
						array(
							'name'     => 'transactionType',
							'label'    => 'Transaction Type',
							'type'     => 'select',
							'onchange' => "jQuery(this).parents('form').submit();",
							'choices'  => array(
								array(
									'label' => 'Select a transaction type',
									'value' => '',
								),
								array(
									'label' => 'Products and Services',
									'value' => 'product',
								),
								array(
									'label' => 'Subscription',
									'value' => 'subscription',
								),
							),
						),
					),
				),
				array(
					'title'      => 'Subscription Settings',
					'dependency' => array(
						'field'  => 'transactionType',
						'values' => array( 'subscription' ),
					),
					'fields'     => array(
						array(
							'name'    => 'recurringAmount',
							'label'   => 'Recurring Amount',
							'type'    => 'select',
							'choices' => array(),
						),
					),
				),
				array(
					'title'  => 'Products and Services',
					'fields' => array(),
				),
				array(
					'title'  => 'Other Settings',
					'fields' => array(),
				),
			);
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
	require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip.php';
}

// Load plugin classes under test (API and Bootstrap do not require Gravity Forms for tested methods).
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-api.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-bootstrap.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-schedule.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-renewals.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-subscriptions-page.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update.php';
