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

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Mirrors WordPress' absint(): absolute value as a non-negative int.
	 *
	 * The plugin reads entry/form ids through it, so a missing stub makes
	 * those paths unreachable from a unit test.
	 *
	 * @param mixed $maybeint Value to convert.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'rgempty' ) ) {
	/**
	 * Mirrors Gravity Forms' rgempty(): true when the key is absent or the
	 * value is empty-ish. Used by the stub of core's start_subscription()
	 * to decide whether a subscription_start_date was supplied.
	 *
	 * @param string $name  Key name.
	 * @param array  $array Array to read, $_POST by default.
	 * @return bool
	 */
	function rgempty( $name, $array = null ) {
		if ( ! isset( $array ) ) {
			$array = $_POST;
		}

		$val = rgar( $array, $name );

		return empty( $val );
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
	 * Minimal $wpdb stand-in.
	 *
	 * The renewal path takes a MySQL GET_LOCK before charging, and the cron
	 * path reads its due list with get_col(). Tests do not need real locking
	 * or a real database: get_col() returns whatever a test has staged, so the
	 * cron's own wiring can be driven end to end.
	 */
	if ( ! class_exists( 'GF_Chip_Test_WPDB' ) ) {
	/**
	 * Minimal $wpdb stand-in.
	 *
	 * get_col() returns the staged due list, so the cron's own wiring — the
	 * query, the meta flattening, and the charge decision — can be driven
	 * end to end without a database.
	 */
	class GF_Chip_Test_WPDB {

		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Entry ids the next get_col() call returns.
		 *
		 * @var array
		 */
		public static $due_ids = array();

		/**
		 * Every prepared query, in order.
		 *
		 * @var array
		 */
		public static $queries = array();

		/**
		 * Stage the ids a due query returns.
		 *
		 * @param array $ids Entry ids.
		 * @return void
		 */
		public static function set_due_ids( array $ids ) {
			self::$due_ids = $ids;
		}

		/**
		 * Clear staged state.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$due_ids = array();
			self::$queries = array();
		}

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
		 * Returns a single value — the count queries use this.
		 *
		 * A stub without it makes any code path that counts rows untestable,
		 * which is how a hidden-column bug survived the suite: the test could
		 * not get past prepare_items() to reach the header cache at all.
		 *
		 * @param string $query The SQL.
		 * @return int
		 */
		public function get_var( $query = '' ) {
			self::$queries[] = $query;

			return 0;
		}

		/**
		 * Returns a column of results. Only the due-list query is served.
		 *
		 * @param string $query The SQL.
		 * @return array
		 */
		public function get_col( $query = '' ) {
			return self::$due_ids;
		}

		/**
		 * Prepares a query.
		 *
		 * @param string $query The SQL.
		 * @param mixed  ...$args Arguments.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			self::$queries[] = $query;

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
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new GF_Chip_Test_WPDB();
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

		/**
		 * Merges an entry array into the staged store.
		 *
		 * Gravity Forms core calls this from complete_payment() and
		 * start_subscription(), so the subscription activation path needs it
		 * to exist. The real implementation writes to the entry table; here
		 * the staged copy is replaced, which is what a subsequent read-back
		 * in the same request would observe.
		 *
		 * @param array $entry Entry including its id.
		 * @return bool
		 */
		public static function update_entry( $entry ) {
			$id = is_array( $entry ) ? (int) rgar( $entry, 'id' ) : 0;

			if ( 0 === $id ) {
				return false;
			}

			self::$entries[ $id ] = $entry;

			return true;
		}
	}

	class GF_Chip_Test_Submission {

		/**
		 * The staged submission data.
		 *
		 * @var array
		 */
		private static $submission = array();

		/**
		 * Stage submission data for the next call.
		 *
		 * Core's get_submission_data() reports the resolved payment amount
		 * under `payment_amount` and the trial/setup fee separately. A test
		 * expresses the recurring amount as payment_amount here.
		 *
		 * @param array $submission The submission data.
		 * @return void
		 */
		public static function set( array $submission ) {
			self::$submission = $submission;
		}

		/**
		 * The staged submission data.
		 *
		 * @return array
		 */
		public static function get() {
			return self::$submission;
		}

		/**
		 * Clear the staged submission data.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$submission = array();
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

// Core's money formatter, reached on every successful-renewal note.
// Kept faithful to core: an amount and a currency, rendered for display.
if ( ! class_exists( 'GFCommon' ) ) {
	class GFCommon {
		/**
		 * Formats an amount for display.
		 *
		 * @param float  $amount   Amount.
		 * @param string $currency Currency code.
		 * @return string
		 */
		public static function to_money( $amount, $currency = '' ) {
			return number_format( (float) $amount, 2 ) . ' ' . $currency;
		}

		/**
		 * Whether the current user holds any of the given capabilities.
		 *
		 * Reproduces Gravity Forms' own semantics, including the part that
		 * matters most here: the OR with `gform_full_access`. GF grants access
		 * by that capability for administrators and GF-role users, who do NOT
		 * carry the granular `gravityforms_*` capability the page registers.
		 * A stub without the OR would make an access test pass while the real
		 * plugin refused every user.
		 *
		 * @param string|array $caps Capability or capabilities.
		 * @return bool
		 */
		public static function current_user_can_any( $caps ) {
			if ( ! is_array( $caps ) ) {
				return current_user_can( $caps ) || current_user_can( 'gform_full_access' );
			}

			foreach ( $caps as $cap ) {
				if ( current_user_can( $cap ) ) {
					return true;
				}
			}

			return current_user_can( 'gform_full_access' );
		}

		/**
		 * Evaluates a conditional-logic block.
		 *
		 * The plugin calls this to decide whether a merchant's notification
		 * will actually fire. Core returns TRUE for a block with no rules, and
		 * that is the behaviour that matters most here: a notification with no
		 * conditions is one that WILL send, so the built-in email must stand
		 * down. A stub that defaulted to false would report the opposite and
		 * let the double-send back in.
		 *
		 * The operators below cover what a notification's conditional logic
		 * realistically uses; anything unrecognised evaluates false, matching
		 * core's own default of "the rule did not match".
		 *
		 * @param array $logic Conditional logic block.
		 * @param array $form  Form.
		 * @param array $entry Entry.
		 * @return bool
		 */
		public static function evaluate_conditional_logic( $logic, $form = null, $entry = null ) {
			$rules = rgar( $logic, 'rules' );

			if ( empty( $rules ) || ! is_array( $rules ) ) {
				return true;
			}

			$matched = 0;

			foreach ( $rules as $rule ) {
				$field  = (string) rgar( $rule, 'fieldId' );
				$actual = isset( $entry[ $field ] ) ? $entry[ $field ] : null;

				if ( ! self::hermes_rule_matches( rgar( $rule, 'operator' ), $actual, rgar( $rule, 'value' ) ) ) {
					continue;
				}

				++$matched;
			}

			return 'any' === rgar( $logic, 'logicType' ) ? $matched > 0 : $matched === count( $rules );
		}

		/**
		 * Whether one conditional-logic rule matches.
		 *
		 * @param string $operator Operator.
		 * @param mixed  $actual   Value from the entry.
		 * @param mixed  $expected Value from the rule.
		 * @return bool
		 */
		private static function hermes_rule_matches( $operator, $actual, $expected ) {
			switch ( (string) $operator ) {
				case 'is':
					return (string) $actual === (string) $expected;
				case 'isnot':
					return (string) $actual !== (string) $expected;
				case '>':
					return (float) $actual > (float) $expected;
				case '<':
					return (float) $actual < (float) $expected;
				case 'contains':
					return false !== strpos( (string) $actual, (string) $expected );
				default:
					return false;
			}
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
		/** @var string Absolute path to the plugin entry file. */
	protected $_full_path = '';

	/** @var bool Whether the add-on supports callbacks. */
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

		/**
		 * Formats an action's amount for its note. Minimal but faithful:
		 * core only needs `amount_formatted` to exist before build_note().
		 *
		 * @param array  $action   The action.
		 * @param string $currency Currency code.
		 * @return array
		 */
		public function maybe_add_action_amount_formatted( $action, $currency = '' ) {
			if ( empty( $action['amount_formatted'] ) && isset( $action['amount'] ) ) {
				$action['amount_formatted'] = number_format( (float) $action['amount'], 2, '.', '' ) . ' ' . $currency;
			}

			return $action;
		}

		/**
		 * Whether the entry already carries an active subscription.
		 *
		 * Faithful copy of core's own check (class-gf-payment-addon.php):
		 * transaction_type 2 AND a non-empty transaction_id. It is what
		 * gates start_subscription()'s field writes.
		 *
		 * @param array $entry Entry.
		 * @return bool
		 */
		public function has_subscription( $entry ) {
			return '2' === (string) rgar( $entry, 'transaction_type' ) && ! rgempty( 'transaction_id', $entry );
		}

		/**
		 * Trigger payment delayed feeds. No-op in tests.
		 *
		 * @param string $transaction_id Transaction id.
		 * @param array  $feed           Payment feed.
		 * @param array  $entry          Entry.
		 * @param array  $form           Form.
		 * @return void
		 */
		public function trigger_payment_delayed_feeds( $transaction_id, $feed, $entry, $form ) {
		}

		/**
		 * Marks a payment complete.
		 *
		 * Faithful to core (class-gf-payment-addon.php complete_payment()),
		 * INCLUDING the detail that matters to this feature: core writes
		 * `transaction_type = '1'` unconditionally, so a subscription
		 * purchase that arrives through this path loses its type unless
		 * something puts it back. A stub that was "nicer" than core here
		 * would make the activation tests pass without proving anything.
		 *
		 * @param array $entry  Entry (by reference).
		 * @param array $action Callback action.
		 * @return bool
		 */
		public function complete_payment( &$entry, $action ) {
			if ( ! rgar( $action, 'payment_status' ) ) {
				$action['payment_status'] = 'Paid';
			}

			if ( ! rgar( $action, 'transaction_type' ) ) {
				$action['transaction_type'] = 'payment';
			}

			if ( ! rgar( $action, 'payment_date' ) ) {
				$action['payment_date'] = gmdate( 'y-m-d H:i:s' );
			}

			$entry['is_fulfilled']     = '1';
			$entry['transaction_id']   = rgar( $action, 'transaction_id' );
			$entry['transaction_type'] = '1';
			$entry['payment_status']   = $action['payment_status'];
			$entry['payment_amount']   = rgar( $action, 'amount' );
			$entry['payment_date']     = $action['payment_date'];
			$entry['payment_method']   = rgar( $action, 'payment_method' );

			GFAPI::update_entry( $entry );
			$this->insert_transaction( $entry['id'], $action['transaction_type'], rgar( $action, 'transaction_id' ), rgar( $action, 'amount' ) );
			$this->add_note( $entry['id'], 'Payment has been completed.', 'success' );
			$this->post_payment_action( $entry, $action );

			return true;
		}

		/**
		 * Starts a new subscription on the entry.
		 *
		 * Faithful to core (class-gf-payment-addon.php start_subscription()):
		 * sets payment_status Active and transaction_type '2', writes the
		 * entry, then fires its own post_payment_action. The plugin's
		 * override adds its scheduling state on top of this.
		 *
		 * @param array $entry        Entry.
		 * @param array $subscription Subscription data (subscription_id, amount).
		 * @return array The entry.
		 */
		public function start_subscription( $entry, $subscription ) {
			$has_active_subscription = $this->has_subscription( $entry ) && 'Active' === rgar( $entry, 'payment_status' );

			if ( ! $has_active_subscription ) {
				$entry['payment_status']   = 'Active';
				$entry['payment_amount']   = rgar( $subscription, 'amount' );
				$entry['payment_date']     = ! rgempty( 'subscription_start_date', $subscription ) ? $subscription['subscription_start_date'] : gmdate( 'Y-m-d H:i:s' );
				$entry['transaction_id']   = rgar( $subscription, 'subscription_id' );
				$entry['transaction_type'] = '2';
				$entry['is_fulfilled']     = '1';

				GFAPI::update_entry( $entry );

				$subscription['type'] = 'create_subscription';
				$this->post_payment_action( $entry, $subscription );
			}

			return $entry;
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

		/**
		 * Mirrors GFPaymentAddOn::get_payment_field() (core, since GF 2.4.17)
		 * exactly, including its 'form_total' fallback.
		 *
		 * This matters for faithfulness: the production class calls this
		 * method, so a double that omitted it would fatal. Keeping core's
		 * own branch here — rather than a copy of the plugin's — is what
		 * lets the regression test fail on the old code.
		 *
		 * @param array $feed The current feed.
		 * @return string
		 */
		public function get_payment_field( $feed ) {
			$key = rgars( $feed, 'meta/transactionType' ) === 'subscription' ? 'recurringAmount' : 'paymentAmount';

			return rgars( $feed, 'meta/' . $key, 'form_total' );
		}

		/**
		 * Mirrors core's get_submission_data() for the payment fields the
		 * renewal path reads.
		 *
		 * Core builds this from the form's product fields, resolving the
		 * amount for the feed's payment field (recurringAmount for a
		 * subscription) and excluding the trial and setup-fee products from
		 * the total. The stub reads the staged form's line items so a test can
		 * express "the recurring amount is X" without reimplementing core.
		 *
		 * @param array $feed  The current feed.
		 * @param array $form  Form.
		 * @param array $entry Entry.
		 * @return array
		 */
		public function get_submission_data( $feed, $form, $entry ) {
			return GF_Chip_Test_Submission::get();
		}
	}
}

if ( ! class_exists( 'GF_Chip' ) ) {
	require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip.php';
}

/*
 * WP_List_Table stub.
 *
 * The harness runs against a fake ABSPATH that has no wp-admin tree, so the
 * real class is absent. The subscriptions list table extends it and calls a
 * handful of its members, so those need to exist for the class to load.
 *
 * The stub is deliberately faithful to the parts the plugin relies on rather
 * than convenient: `$plural` is stored exactly as core stores it (sanitized
 * via sanitize_key) because the bulk nonce action is derived from it, and
 * `$_args` is populated the same way, so a test that asserts the nonce action
 * is asserting what core would really have produced.
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
	/**
	 * Minimal stand-in for core's WP_List_Table.
	 */
	class WP_List_Table {

		/**
		 * The screen the table is for.
		 *
		 * @var object|null
		 */
		public $screen = null;

		/**
		 * Rows.
		 *
		 * @var array
		 */
		public $items = array();

		/**
		 * Column headers, in core's order.
		 *
		 * @var array
		 */
		public $_column_headers = array();

		/**
		 * Table arguments: singular, plural, ajax, screen.
		 *
		 * @var array
		 */
		public $_args = array();

		/**
		 * Registered bulk actions, resolved lazily by core.
		 *
		 * @var array|null
		 */
		public $_actions = null;

		/**
		 * Pagination state.
		 *
		 * @var array
		 */
		public $_pagination_args = array();

		/**
		 * Constructor.
		 *
		 * @param array $args Table arguments.
		 */
		public function __construct( $args = array() ) {
			$args = array_merge(
				array(
					'plural'   => '',
					'singular' => '',
					'ajax'     => false,
					'screen'   => null,
				),
				$args
			);

			if ( ! $args['plural'] ) {
				$args['plural'] = '';
			}

			// Core lowercases and strips everything outside [a-z0-9_-]. Done
			// inline rather than via sanitize_key() so the stub carries no
			// WordPress function dependency: the value is what the bulk nonce
			// action is derived from, so it has to be exactly what core
			// produces, and a WordPress function may not exist in this test
			// run.
			$args['plural']   = $this->sanitize_key_stub( $args['plural'] );
			$args['singular'] = $this->sanitize_key_stub( $args['singular'] );

			$this->_args = $args;
		}

		/**
		 * Reproduces core's sanitize_key().
		 *
		 * @param string $key Key.
		 * @return string
		 */
		private function sanitize_key_stub( $key ) {
			return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
		}

		/**
		 * Columns, overridden by the subclass.
		 *
		 * @return array
		 */
		public function get_columns() {
			return array();
		}

		/**
		 * Sortable columns, overridden by the subclass.
		 *
		 * @return array
		 */
		protected function get_sortable_columns() {
			return array();
		}

		/**
		 * Views, overridden by the subclass.
		 *
		 * @return array
		 */
		protected function get_views() {
			return array();
		}

		/**
		 * The column that carries the row title.
		 *
		 * Reproduces core's resolution closely enough for the subclass under
		 * test: the declared primary if it exists, otherwise the first
		 * non-checkbox column. A stub returning a constant would hide the bug
		 * where a subclass hardcodes the name and a rename breaks it.
		 *
		 * @return string
		 */
		protected function get_primary_column_name() {
			$columns = $this->get_columns();
			$default = method_exists( $this, 'get_default_primary_column_name' )
				? $this->get_default_primary_column_name()
				: '';

			if ( ! isset( $columns[ $default ] ) ) {
				foreach ( array_keys( $columns ) as $name ) {
					if ( 'cb' !== $name ) {
						$default = $name;
						break;
					}
				}
			}

			return $default;
		}

		/**
		 * Records the pagination arguments.
		 *
		 * @param array $args Args.
		 * @return void
		 */
		protected function set_pagination_args( $args ) {
			$this->_pagination_args = $args;
		}

		/**
		 * Whether the table has rows.
		 *
		 * @return bool
		 */
		public function has_items() {
			return ! empty( $this->items );
		}

		/**
		 * Renders row actions.
		 *
		 * @param array  $actions Actions.
		 * @param object $row     Row.
		 * @return string
		 */
		public function row_actions( $actions, $always_visible = false ) {
			return implode( ' | ', $actions );
		}

		/**
		 * Default row actions for the primary column.
		 *
		 * Core returns just the toggle-row button here; the override in the
		 * plugin's table falls back to this when a row permits no action.
		 *
		 * @param object|array $item        Row.
		 * @param string       $column_name Column.
		 * @param string       $primary     Primary column.
		 * @return string
		 */
		protected function handle_row_actions( $item, $column_name, $primary ) {
			return '';
		}

		/**
		 * The current bulk action in the request.
		 *
		 * Mirrors core: `action` wins, `-1` means nothing selected.
		 *
		 * @return string|false
		 */
		public function current_action() {
			if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] && '' !== $_REQUEST['action'] ) {
				return $_REQUEST['action'];
			}

			return false;
		}
	}
}

// Load plugin classes under test (API and Bootstrap do not require Gravity Forms for tested methods).
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-api.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-bootstrap.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-schedule.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-renewals.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-subscriptions-page.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-subscriptions-table.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update-flow.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update-page.php';
require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-renewal-notifications.php';
