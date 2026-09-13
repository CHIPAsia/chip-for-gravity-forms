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
	/**
	 * Staged feed for tests that need to drive a real payment path.
	 *
	 * The framework's get_payment_feed() stub used to return an empty array
	 * unconditionally, which meant any code past that point could not be
	 * exercised. Staging a feed makes those paths reachable.
	 */
	/**
	 * Minimal $wpdb stand-in for advisory-lock calls.
	 *
	 * The renewal path takes a MySQL GET_LOCK before charging. Tests do not need
	 * real locking, only for the call to not fatal.
	 */
	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Runs a query, returning an empty result set.
			 *
			 * @param string $query The SQL.
			 * @return array
			 */
			public function get_results( $query = '' ) {
				return array();
			}

			/**
			 * Prepares a query.
			 *
			 * @param string $query The SQL.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return $query;
			}

			/**
			 * Runs a query.
			 *
			 * @param string $query The SQL.
			 * @return int
			 */
			public function query( $query = '' ) {
				return 0;
			}
		};
	}

	/**
	 * Minimal GFAPI stand-in for tests that drive a real payment path.
	 *
	 * Only the calls the renewal path makes are implemented. Form and entry
	 * lookups return whatever a test has staged, so the charge path can run
	 * without a live WordPress.
	 */
	class GFAPI {

		/**
		 * Staged form.
		 *
		 * @var array
		 */
		private static $form = array();

		/**
		 * Staged entries by id.
		 *
		 * @var array
		 */
		private static $entries = array();

		/**
		 * Stage a form.
		 *
		 * @param array $form The form.
		 * @return void
		 */
		public static function set_form( array $form ) {
			self::$form = $form;
		}

		/**
		 * Stage an entry.
		 *
		 * @param array $entry The entry, including its id.
		 * @return void
		 */
		public static function set_entry( array $entry ) {
			self::$entries[ (int) $entry['id'] ] = $entry;
		}

		/**
		 * Clear all staged data.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$form    = array();
			self::$entries = array();
		}

		/**
		 * Staged form.
		 *
		 * @param int $id Form id.
		 * @return array
		 */
		public static function get_form( $id ) {
			return self::$form;
		}

		/**
		 * Staged entry.
		 *
		 * @param int $id Entry id.
		 * @return array|WP_Error
		 */
		public static function get_entry( $id ) {
			$id = (int) $id;

			return isset( self::$entries[ $id ] ) ? self::$entries[ $id ] : new WP_Error( 'not_found', 'no entry' );
		}

		/**
		 * Update a property on a staged entry.
		 *
		 * @param int    $id       Entry id.
		 * @param string $property Property name.
		 * @param mixed  $value    Value.
		 * @return bool
		 */
		public static function update_entry_property( $id, $property, $value ) {
			$id = (int) $id;

			if ( ! isset( self::$entries[ $id ] ) ) {
				return false;
			}

			self::$entries[ $id ][ $property ] = $value;

			return true;
		}
	}

	class GF_Chip_Test_Feed {

		/**
		 * The staged feed.
		 *
		 * @var array
		 */
		private static $feed = array();

		/**
		 * Stage a feed for the next call.
		 *
		 * @param array $feed The feed object.
		 * @return void
		 */
		public static function set( array $feed ) {
			self::$feed = $feed;
		}

		/**
		 * The staged feed.
		 *
		 * @return array
		 */
		public static function get() {
			return self::$feed;
		}

		/**
		 * Clear the staged feed.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$feed = array();
		}
	}

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

// WordPress helpers that are absent from this harness but used by the plugin.
// These are pure functions with no WordPress state, so a faithful stub is safe.
if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Mirrors WordPress' is_email(): a local part, an @, and a domain with a dot.
	 *
	 * WordPress' real implementation requires a period in the domain, so
	 * 'a@b' is invalid while 'a@b.test' is valid.
	 *
	 * @param string $email Candidate.
	 * @return string|false The email when valid, false otherwise.
	 */
	function is_email( $email ) {
		if ( ! is_string( $email ) || strlen( $email ) < 6 ) {
			return false;
		}

		if ( false !== strpos( $email, '..' ) ) {
			return false;
		}

		$parts = explode( '@', $email );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $local, $domain ) = $parts;

		if ( '' === $local || '' === $domain ) {
			return false;
		}

		if ( false === strpos( $domain, '.' ) ) {
			return false;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local ) ) {
			return false;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain ) ) {
			return false;
		}

		return $email;
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

		/**
		 * Returns the feed a test has staged, or an empty array.
		 *
		 * Configurable so a test can drive the real charge path without a live
		 * WordPress: previously this always returned an empty array, which made
		 * everything downstream of it unreachable from a unit test.
		 *
		 * @param array       $entry The entry.
		 * @param array|false $form  The form.
		 * @return array
		 */
		public function get_payment_feed( $entry, $form = false ) {
			return GF_Chip_Test_Feed::get();
		}

		/**
		 * Records the action a test caused, instead of dispatching it.
		 *
		 * @param array $entry  The entry.
		 * @param array $action The action payload.
		 * @return void
		 */
		public function post_payment_action( $entry, $action ) {
		}

		/**
		 * Inserts a transaction row. No-op in tests.
		 *
		 * @param int         $entry_id      Entry id.
		 * @param string      $type          Transaction type.
		 * @param string|null $transaction_id Transaction id.
		 * @param float       $amount        Amount.
		 * @param bool        $is_recurring  Whether recurring.
		 * @param string|null $subscription_id Subscription id.
		 * @return void
		 */
		public function insert_transaction( $entry_id, $type, $transaction_id = null, $amount = 0, $is_recurring = false, $subscription_id = null ) {
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
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update-flow.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update-page.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-renewal-notifications.php';
