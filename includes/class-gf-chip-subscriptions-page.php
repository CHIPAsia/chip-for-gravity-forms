<?php
/**
 * Admin subscriptions list.
 *
 * Gravity Forms core renders no subscription list of its own — it only draws
 * a Cancel button on the entry detail. A merchant with subscriptions has no
 * way to see them, so this page is that view.
 *
 * Deliberately read-mostly. The only write actions are "retry now" and
 * "cancel", both of which reuse the same code paths the cron and core use, so
 * there is no second implementation of the charging rules to keep in step.
 *
 * No card data is ever displayed or entered here. The recurring token is shown
 * masked, and changing a card is done by sending the customer a link.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions list page.
 */
class GF_Chip_Subscriptions_Page {

	/**
	 * Rows shown per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Slug of the page.
	 *
	 * @return string
	 */
	public static function slug() {
		return 'gravityformschip_subscriptions';
	}

	/**
	 * The list table for this screen, built once per request.
	 *
	 * @var GF_Chip_Subscriptions_Table|null
	 */
	private static $table = null;

	/**
	 * Capability required for the actions on this screen.
	 *
	 * Single source of truth for both the page and its handlers, so a button
	 * can never be offered to a user the action would then refuse.
	 *
	 * @return string
	 */
	public static function capability() {
		return 'gravityforms_edit_settings';
	}

	/**
	 * Whether the current user may use this screen and its actions.
	 *
	 * Must go through GFCommon::current_user_can_any() rather than a bare
	 * current_user_can(). Gravity Forms grants access by a DIFFERENT capability
	 * than the one registered: an administrator or a GF-role user typically
	 * carries `gform_full_access` and does NOT carry
	 * `gravityforms_edit_settings`. GFCommon::current_user_can_any() ORs the
	 * two, so it passes for exactly the users who can see the page.
	 *
	 * Every caller — the page renderer, the bulk handler and the two
	 * admin-post handlers — must use this method. A handler that used a bare
	 * current_user_can() refused every user who reaches the screen through
	 * gform_full_access, so pressing the button answered
	 * "You are not allowed to do that." while the page it was on rendered fine.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return (bool) GFCommon::current_user_can_any( self::capability() );
	}

	/**
	 * Registers the page in the Forms navigation.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'gform_addon_navigation', array( __CLASS__, 'add_nav_item' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );

		// The screen is not resolved until the page's own load action, so
		// everything that needs it is registered there rather than inline.
		//
		// `load-{hook}` is also the last moment before the admin header is
		// printed, and that ordering is load-bearing: Screen Options renders
		// its column toggles from get_column_headers(), which caches the
		// first lookup for the screen. A WP_List_Table registers the filter
		// that supplies those headers from its CONSTRUCTOR, so a table built
		// inside the page callback is built too late: the cache has already
		// been filled with an empty array by the time the header renders, and
		// the columns panel comes out blank with no error.
		//
		// Building the table here — as core's own list screens do, e.g.
		// wp-admin/edit.php — is what makes the toggles appear.
		add_action( 'load-' . self::screen_id(), array( __CLASS__, 'register_screen_option' ) );
		add_action( 'load-' . self::screen_id(), array( __CLASS__, 'maybe_handle_bulk_action' ) );
		add_action( 'load-' . self::screen_id(), array( __CLASS__, 'init_table' ) );
	}

	/**
	 * Builds the list table before the admin header renders.
	 *
	 * Also prepares it here, so the same prepared table is both displayed and
	 * known to the column-header filter.
	 *
	 * @return void
	 */
	public static function init_table() {
		$table = new GF_Chip_Subscriptions_Table(
			self::per_page(),
			self::current_status(),
			self::current_search(),
			gmdate( 'Y-m-d H:i:s' )
		);

		$table->prepare_items();

		self::$table = $table;
	}

	/**
	 * The status filter for this request, clamped to the known vocabulary.
	 *
	 * @return string
	 */
	public static function current_status() {
		// Read-only list filter: it selects what is displayed and mutates
		// nothing, so a nonce is not applicable.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['chip_status'] ) ? sanitize_key( wp_unslash( $_GET['chip_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $status && ! in_array( $status, \GF_Chip::SUBSCRIPTION_STATES, true ) ) {
			$status = '';
		}

		return $status;
	}

	/**
	 * The search term for this request.
	 *
	 * @return string
	 */
	public static function current_search() {
		// Read-only list filter: see current_status().
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The prepared list table, built in init_table().
	 *
	 * @return GF_Chip_Subscriptions_Table
	 */
	public static function table() {
		if ( ! self::$table instanceof GF_Chip_Subscriptions_Table ) {
			self::init_table();
		}

		return self::$table;
	}

	/**
	 * The bulk actions this screen offers.
	 *
	 * Defined once here so the table renders exactly the set the handler
	 * validates against — an action can never appear in the dropdown without a
	 * handler, or be handled without being offered.
	 *
	 * Each action maps to an operation that already exists and is already
	 * guarded, so bulk is not a second implementation of the charging or
	 * cancelling rules: it is those rules applied to a selection.
	 *
	 * @return array
	 */
	public static function bulk_actions() {
		return array(
			'chip_bulk_retry'  => __( 'Retry now', 'chip-for-gravity-forms' ),
			'chip_bulk_link'   => __( 'Send update-card link', 'chip-for-gravity-forms' ),
			'chip_bulk_cancel' => __( 'Cancel subscription', 'chip-for-gravity-forms' ),
		);
	}

	/**
	 * Applies a bulk action to the selected subscriptions.
	 *
	 * Runs on the page's load action, so it executes before anything is
	 * rendered and can redirect.
	 *
	 * Every selected row still passes its own guard: a bulk action is a
	 * convenience for a selection, never a way around the rules that decide
	 * whether one row may be charged, linked or cancelled. Rows the guard
	 * refuses are counted as skipped and reported, rather than being silently
	 * dropped or forced through.
	 *
	 * @return void
	 */
	public static function maybe_handle_bulk_action() {
		// WP_List_Table posts the chosen action as `action` (or `action2` for
		// the bottom tablenav). Read-only inspection of the request; the nonce
		// is verified below before anything is acted on.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) {
				$candidate = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
				if ( '-1' !== $candidate && '' !== $candidate ) {
					$action = $candidate;
					break;
				}
			}
		}

		$entry_ids = isset( $_REQUEST['chip_entry'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['chip_entry'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $action ) {
			return;
		}

		if ( ! isset( self::bulk_actions()[ $action ] ) ) {
			return;
		}

		// From here on the request is a state change, so the nonce is
		// mandatory. WP_List_Table printed it against the bulk actions nonce
		// action derived from the table's plural name.
		check_admin_referer( GF_Chip_Subscriptions_Table::bulk_nonce_action() );

		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chip-for-gravity-forms' ) );
		}

		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );

		if ( empty( $entry_ids ) ) {
			self::redirect_after_bulk( $action, 0, 0 );
		}

		$applied = 0;
		$skipped = 0;

		foreach ( $entry_ids as $entry_id ) {
			$entry = GFAPI::get_entry( $entry_id );

			if ( ! is_array( $entry ) || is_wp_error( $entry ) ) {
				++$skipped;
				continue;
			}

			$entry = GF_Chip_Card_Update::hydrate( $entry );

			if ( self::apply_bulk_action( $action, $entry ) ) {
				++$applied;
			} else {
				++$skipped;
			}
		}

		self::redirect_after_bulk( $action, $applied, $skipped );
	}

	/**
	 * Whether one entry qualifies for one bulk action.
	 *
	 * A bulk action must never be a way around the rule that decides whether a
	 * single row may be charged, linked or cancelled — it is only a way to
	 * apply that rule to several rows at once. Exposing the decision as a
	 * predicate is what makes that testable: the switch below performs the
	 * side effect, and this decides whether it is allowed to.
	 *
	 * @param string $action One of bulk_actions().
	 * @param array  $entry  Hydrated entry.
	 * @return bool
	 */
	public static function can_apply_bulk_action( $action, $entry ) {
		switch ( $action ) {
			case 'chip_bulk_retry':
				return self::can_retry( $entry );

			case 'chip_bulk_link':
				return self::can_send_link( $entry );

			case 'chip_bulk_cancel':
				return self::can_cancel( $entry );
		}

		return false;
	}

	/**
	 * Applies one bulk action to one entry.
	 *
	 * Each branch delegates to the operation the single-row button uses, so
	 * the guards and side effects are identical.
	 *
	 * @param string $action One of bulk_actions().
	 * @param array  $entry  Hydrated entry.
	 * @return bool True when the action was applied.
	 */
	private static function apply_bulk_action( $action, $entry ) {
		if ( ! self::can_apply_bulk_action( $action, $entry ) ) {
			return false;
		}

		$entry_id = absint( rgar( $entry, 'id' ) );
		$addon    = GF_Chip::get_instance();

		switch ( $action ) {
			case 'chip_bulk_retry':
				// Force: an operator asked for it. The attempt is still
				// counted by the charge path, exactly as the single-row
				// retry does.
				$result = $addon->charge_renewal( $entry, true );

				return is_array( $result ) && isset( $result['status'] ) && 'charged' === $result['status'];

			case 'chip_bulk_link':
				return (bool) GF_Chip_Renewal_Notifications::maybe_send_dunning_email( $entry_id, 0, true );

			case 'chip_bulk_cancel':
				$form = GFAPI::get_form( absint( rgar( $entry, 'form_id' ) ) );
				$feed = $addon->get_payment_feed( $entry, $form );

				if ( empty( $feed ) ) {
					return false;
				}

				// cancel() revokes the token at CHIP; cancel_subscription()
				// then moves the entry to Cancelled, which is what core's own
				// Cancel button does.
				if ( ! $addon->cancel( $entry, $feed ) ) {
					return false;
				}

				$addon->cancel_subscription( $entry, $feed );

				return true;
		}

		return false;
	}

	/**
	 * Returns to the list with a summary of what the bulk action did.
	 *
	 * @param string $action  The action performed.
	 * @param int    $applied How many rows were acted on.
	 * @param int    $skipped How many were refused by their own guard.
	 * @return void
	 */
	private static function redirect_after_bulk( $action, $applied, $skipped ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::slug(),
					'chip_bulk'    => $action,
					'bulk_applied' => (int) $applied,
					'bulk_skipped' => (int) $skipped,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The admin screen id of this page.
	 *
	 * Gravity Forms registers its menu with the `gf_edit_forms` slug, whose
	 * admin_page_hook is `forms`, so the screen id WordPress derives is
	 * `forms_page_<slug>`. Screen options and the per-page row count hang off
	 * that id.
	 *
	 * @return string
	 */
	public static function screen_id() {
		return 'forms_page_' . self::slug();
	}

	/**
	 * Registers the "Rows per page" screen option.
	 *
	 * Attached to the screen's own load action rather than run inline,
	 * because the admin screen is not resolved until then.
	 *
	 * @return void
	 */
	public static function register_screen_option() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Subscriptions per page', 'chip-for-gravity-forms' ),
				'default' => self::PER_PAGE,
				'option'  => self::per_page_option(),
			)
		);
	}

	/**
	 * The user-meta key holding the chosen row count.
	 *
	 * @return string
	 */
	public static function per_page_option() {
		return 'chip_subscriptions_per_page';
	}

	/**
	 * Persists the screen option.
	 *
	 * `set-screen-option` refuses unknown options by default, so the filter is
	 * required for the chosen row count to survive a reload.
	 *
	 * @param mixed  $status Current value.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		if ( self::per_page_option() !== $option ) {
			return $status;
		}

		return absint( $value );
	}

	/**
	 * The rows-per-page the screen should use.
	 *
	 * @return int
	 */
	public static function per_page() {
		$stored = (int) get_user_option( self::per_page_option() );

		return $stored > 0 ? $stored : self::PER_PAGE;
	}

	/**
	 * Adds the left-nav entry.
	 *
	 * @param array $menus Existing menu items.
	 * @return array
	 */
	public static function add_nav_item( $menus ) {
		if ( ! is_array( $menus ) ) {
			$menus = array();
		}

		$menus[] = array(
			'name'       => self::slug(),
			'label'      => esc_html__( 'CHIP Subscriptions', 'chip-for-gravity-forms' ),
			'callback'   => array( __CLASS__, 'render' ),
			'permission' => self::capability(),
		);

		return $menus;
	}

	// -----------------------------------------------------------------
	// Pure presentation helpers — unit tested.
	// -----------------------------------------------------------------

	/**
	 * Reports the result of a manual retry, if one just ran.
	 *
	 * The handler redirects here with ?retry=<status>, so the operator sees
	 * what happened rather than being returned to an unchanged list.
	 *
	 * @return void
	 */
	public static function render_retry_notice() {
		// Read-only display of a redirect parameter: no state changes here, so
		// no nonce is required.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect flag.
		$retry = isset( $_GET['retry'] ) ? sanitize_key( wp_unslash( $_GET['retry'] ) ) : '';

		if ( '' === $retry ) {
			return;
		}

		$messages = array(
			'charged' => array( 'success', __( 'Retry succeeded: the outstanding payment was collected.', 'chip-for-gravity-forms' ) ),
			'failed'  => array( 'error', __( 'Retry declined by the gateway. The subscription remains on hold and the attempt has been counted.', 'chip-for-gravity-forms' ) ),
			'skipped' => array( 'warning', __( 'Nothing to retry: the subscription is not due, or has no stored card.', 'chip-for-gravity-forms' ) ),
			'refused' => array( 'error', __( 'Retry refused: only a live subscription with a stored card can be retried.', 'chip-for-gravity-forms' ) ),
		);

		if ( ! isset( $messages[ $retry ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $retry ][0] ),
			esc_html( $messages[ $retry ][1] )
		);
	}

	/**
	 * Human label for a subscription state.
	 *
	 * @param string $state One of GF_Chip::SUBSCRIPTION_STATES.
	 * @return string
	 */
	public static function describe_status( $state ) {
		$labels = array(
			'pending'   => __( 'Pending', 'chip-for-gravity-forms' ),
			'active'    => __( 'Active', 'chip-for-gravity-forms' ),
			'on-hold'   => __( 'On hold', 'chip-for-gravity-forms' ),
			'cancelled' => __( 'Cancelled', 'chip-for-gravity-forms' ),
			'expired'   => __( 'Expired', 'chip-for-gravity-forms' ),
			'failed'    => __( 'Failed', 'chip-for-gravity-forms' ),
		);

		// Normalise through the same vocabulary the engine uses, so an
		// unrecognised value cannot render as blank.
		$state = GF_Chip::get_subscription_state( array( 'chip_sub_status' => $state ) );

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $labels['pending'];
	}

	/**
	 * Whether a row should offer a "retry now" action.
	 *
	 * A retry is meaningful when the subscription is still live but its
	 * collection is stuck: on hold after a failed attempt, or active and past
	 * due (the cron simply has not run yet).
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @return bool
	 */
	public static function can_retry( $entry ) {
		if ( ! GF_Chip::is_subscription_entry( $entry ) ) {
			return false;
		}

		if ( empty( $entry['chip_recurring_token'] ) ) {
			return false;
		}

		$state = GF_Chip::get_subscription_state( $entry );

		return in_array( $state, array( 'on-hold', 'active' ), true );
	}

	/**
	 * Whether a row should offer a "cancel" action.
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @return bool
	 */
	public static function can_cancel( $entry ) {
		if ( ! GF_Chip::can_cancel_subscription( $entry ) ) {
			return false;
		}

		return in_array(
			GF_Chip::get_subscription_state( $entry ),
			array( 'active', 'on-hold', 'pending' ),
			true
		);
	}

	/**
	 * Whether the admin may send an update-card link for this subscription.
	 *
	 * Uses the same eligibility rule the send itself enforces, so the button
	 * never appears for a subscription the action would refuse — a cancelled
	 * subscription has no future charge to redirect.
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @return bool
	 */
	public static function can_send_link( $entry ) {
		return GF_Chip_Card_Update::can_offer_link( $entry );
	}

	/**
	 * Masks a recurring token for display.
	 *
	 * A token authorises charges, so the full value never reaches the browser.
	 * The trailing part is kept so two subscriptions can be told apart.
	 *
	 * @param mixed $token Recurring token.
	 * @return string
	 */
	public static function mask_token( $token ) {
		$token = is_scalar( $token ) ? (string) $token : '';

		if ( '' === $token ) {
			return '—';
		}

		if ( strlen( $token ) <= 4 ) {
			return str_repeat( '•', strlen( $token ) );
		}

		return str_repeat( '•', 8 ) . substr( $token, -4 );
	}

	/**
	 * Formats a stored UTC datetime for display.
	 *
	 * Stored values are UTC; the admin sees the site's local time.
	 *
	 * @param mixed $datetime UTC 'Y-m-d H:i:s'.
	 * @return string
	 */
	public static function format_datetime( $datetime ) {
		$datetime = is_scalar( $datetime ) ? (string) $datetime : '';

		if ( '' === $datetime ) {
			return '—';
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( false === $timestamp ) {
			return '—';
		}

		return date_i18n( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Whether a subscription is overdue right now.
	 *
	 * Used to highlight rows the cron has not collected yet.
	 *
	 * @param array  $entry Entry with chip_sub_* meta flattened in.
	 * @param string $now   Current UTC datetime.
	 * @return bool
	 */
	public static function is_overdue( $entry, $now ) {
		if ( 'active' !== GF_Chip::get_subscription_state( $entry ) ) {
			return false;
		}

		$next = rgar( $entry, 'chip_sub_next_payment' );

		if ( empty( $next ) ) {
			return false;
		}

		return GF_Chip_Renewals::compare_datetime( $next, $now ) <= 0;
	}

	// -----------------------------------------------------------------
	// Data access.
	// -----------------------------------------------------------------

	/**
	 * Counts subscription entries.
	 *
	 * @param string $status Optional chip_sub_status filter.
	 * @param string $search Optional search term (entry id, or an email/name
	 *                       value on the entry).
	 * @return int
	 */
	public static function count_subscriptions( $status = '', $search = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'gf_entry_meta';

		$search = trim( (string) $search );

		if ( '' === $search ) {
			if ( '' === $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list count; must reflect current rows.
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT entry_id) FROM {$table} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
						'chip_sub_status'
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list count; must reflect current rows.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT entry_id) FROM {$table} WHERE meta_key = %s AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
					'chip_sub_status',
					$status
				)
			);
		}

		// A search narrows the set to subscription entry ids that match either
		// the entry id itself or one of the entry's own values.
		$ids = self::search_entry_ids( $search );

		if ( empty( $ids ) ) {
			return 0;
		}

		$id_list = implode( ',', array_map( 'absint', $ids ) );

		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list count; must reflect current rows.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT entry_id) FROM {$table} WHERE meta_key = %s AND entry_id IN ({$id_list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
					'chip_sub_status'
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list count; must reflect current rows.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT entry_id) FROM {$table} WHERE meta_key = %s AND meta_value = %s AND entry_id IN ({$id_list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
				'chip_sub_status',
				$status
			)
		);
	}

	/**
	 * Subscription entry ids matching a search term.
	 *
	 * Matches the entry id when the term is numeric, and otherwise the entry's
	 * own FIELD values — the customer's name, email, product — which is how an
	 * operator actually searches for a subscription.
	 *
	 * Scoped deliberately to field values. Searching every row of
	 * gf_entry_meta also matches Gravity Forms' own bookkeeping — serialized
	 * blobs like processed_feeds, gform_product_info and submission_speeds
	 * contain arbitrary digits — so a search for "77" matched an unrelated
	 * entry whose serialized feed happened to contain that number. Field keys
	 * are plain ids ("3", "4.1"), so they are the only keys worth matching.
	 *
	 * @param string $search Search term.
	 * @return array Entry ids.
	 */
	public static function search_entry_ids( $search ) {
		global $wpdb;

		$matches = array();
		$search  = trim( (string) $search );

		if ( '' === $search ) {
			return $matches;
		}

		// A subscription set, so a search can never surface a one-time entry.
		$subscription_ids = self::all_subscription_entry_ids();

		if ( empty( $subscription_ids ) ) {
			return $matches;
		}

		$id_list = implode( ',', array_map( 'absint', $subscription_ids ) );

		if ( ctype_digit( $search ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list search; must reflect current rows.
			$by_id = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}gf_entry WHERE id IN ({$id_list}) AND id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
					(int) $search
				)
			);

			foreach ( (array) $by_id as $id ) {
				$matches[] = (int) $id;
			}
		}

		// Field values only: the meta keys Gravity Forms writes for form
		// fields are plain ids ("3") or ids with a sub-key ("4.1"). Everything
		// else on gf_entry_meta is bookkeeping, not something a person searches
		// by.
		$like = '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list search; must reflect current rows.
		$by_value = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT entry_id FROM {$wpdb->prefix}gf_entry_meta
				 WHERE meta_key REGEXP '^[0-9]+([.][0-9]+)?$'
				   AND meta_value LIKE %s
				   AND entry_id IN ({$id_list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
				$like
			)
		);

		foreach ( (array) $by_value as $id ) {
			$matches[] = (int) $id;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $matches ) ) ) );
	}

	/**
	 * Every entry id carrying a subscription status.
	 *
	 * @return array
	 */
	private static function all_subscription_entry_ids() {
		global $wpdb;

		$table = $wpdb->prefix . 'gf_entry_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list; must reflect current rows.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT entry_id FROM {$table} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'chip_sub_status'
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Fetches a page of subscriptions.
	 *
	 * Driven from the entry meta table because chip_sub_status is the marker
	 * that an entry is a subscription, then each row is loaded through the
	 * Gravity Forms API so the entry data is consistent with the rest of the
	 * admin.
	 *
	 * @param int    $per_page Rows per page.
	 * @param string $status   Optional chip_sub_status filter.
	 * @param string $search   Optional search term.
	 * @return array List of entry arrays with chip_sub_* meta flattened in.
	 */
	public static function get_subscriptions( $per_page = self::PER_PAGE, $status = '', $search = '' ) {
		global $wpdb;

		$per_page = max( 1, (int) $per_page );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list paging.
		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$offset = ( $page - 1 ) * $per_page;

		$table = $wpdb->prefix . 'gf_entry_meta';

		$search = trim( (string) $search );

		if ( '' !== $search ) {
			$ids = self::search_entry_ids( $search );

			if ( empty( $ids ) ) {
				return array();
			}

			$id_list = implode( ',', array_map( 'absint', $ids ) );

			if ( '' === $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list; must reflect current rows.
				$entry_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT entry_id FROM {$table} WHERE meta_key = %s AND entry_id IN ({$id_list}) ORDER BY entry_id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
						'chip_sub_status',
						$per_page,
						$offset
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list; must reflect current rows.
				$entry_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT entry_id FROM {$table} WHERE meta_key = %s AND meta_value = %s AND entry_id IN ({$id_list}) ORDER BY entry_id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
						'chip_sub_status',
						$status,
						$per_page,
						$offset
					)
				);
			}
		} elseif ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list; must reflect current rows.
			$entry_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT entry_id FROM {$wpdb->prefix}gf_entry_meta
					 WHERE meta_key = %s
					 ORDER BY entry_id DESC
					 LIMIT %d OFFSET %d",
					'chip_sub_status',
					$per_page,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list; must reflect current rows.
			$entry_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT entry_id FROM {$wpdb->prefix}gf_entry_meta
					 WHERE meta_key = %s AND meta_value = %s
					 ORDER BY entry_id DESC
					 LIMIT %d OFFSET %d",
					'chip_sub_status',
					$status,
					$per_page,
					$offset
				)
			);
		}

		$rows = array();

		foreach ( (array) $entry_ids as $entry_id ) {
			$entry = GFAPI::get_entry( (int) $entry_id );

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$rows[] = self::hydrate( $entry );
		}

		return $rows;
	}

	/**
	 * Flattens the plugin's subscription meta onto an entry.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function hydrate( $entry ) {
		$entry_id = rgar( $entry, 'id' );

		foreach ( array( 'chip_recurring_token', 'chip_sub_next_payment', 'chip_sub_status', 'chip_sub_retry_count' ) as $key ) {
			$entry[ $key ] = gform_get_meta( $entry_id, $key );
		}

		return $entry;
	}

	// -----------------------------------------------------------------
	// Rendering.
	// -----------------------------------------------------------------

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'Access denied.', 'chip-for-gravity-forms' ) );
		}

		// Built on load-{hook}, before the admin header rendered the column
		// preferences this table supplies. See register().
		self::render_page( self::table(), self::table()->status() );
	}

	/**
	 * Renders the page shell around the list table.
	 *
	 * @param GF_Chip_Subscriptions_Table $table  Prepared list table.
	 * @param string                      $status Active status filter.
	 * @return void
	 */
	private static function render_page( $table, $status ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CHIP Subscriptions', 'chip-for-gravity-forms' ); ?></h1>

			<?php
			self::render_notice();
			self::render_bulk_notice();
			?>

			<p class="description">
				<?php esc_html_e( 'Recurring subscriptions collected by the CHIP gateway. Card changes are sent to the customer as a secure link; card data is never entered here.', 'chip-for-gravity-forms' ); ?>
			</p>

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::slug() ); ?>" />
				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="chip_status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>

				<?php
				$table->search_box( __( 'Search subscriptions', 'chip-for-gravity-forms' ), 'chip-subscription' );
				$table->display();
				?>
			</form>

			<?php self::render_retry_notice(); ?>
		</div>
		<?php
	}

	/**
	 * Reports what a bulk action did.
	 *
	 * Reports applied and skipped counts so an operator can see that a
	 * selection was only partly actionable, instead of assuming every row was
	 * charged.
	 *
	 * @return void
	 */
	private static function render_bulk_notice() {
		// Display-only outcome flags from the redirect: nothing is changed
		// here, so no nonce applies.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['chip_bulk'] ) ? sanitize_key( wp_unslash( $_GET['chip_bulk'] ) ) : '';

		if ( '' === $action || ! isset( self::bulk_actions()[ $action ] ) ) {
			return;
		}

		$applied = isset( $_GET['bulk_applied'] ) ? absint( wp_unslash( $_GET['bulk_applied'] ) ) : 0;
		$skipped = isset( $_GET['bulk_skipped'] ) ? absint( wp_unslash( $_GET['bulk_skipped'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$label = self::bulk_actions()[ $action ];

		if ( $applied > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: bulk action label, 2: number of subscriptions. */
						_n(
							'%1$s applied to %2$d subscription.',
							'%1$s applied to %2$d subscriptions.',
							$applied,
							'chip-for-gravity-forms'
						),
						$label,
						$applied
					)
				)
			);
		}

		if ( $skipped > 0 || 0 === $applied ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of subscriptions, 2: bulk action label. */
						_n(
							'%1$d subscription was skipped: it does not qualify for "%2$s".',
							'%1$d subscriptions were skipped: they do not qualify for "%2$s".',
							max( 1, $skipped ),
							'chip-for-gravity-forms'
						),
						max( 1, $skipped ),
						$label
					)
				)
			);
		}
	}

	/**
	 * Renders the outcome notice after an admin send.
	 *
	 * Reports the outcome using only the sent entry id; nothing from the
	 * request is echoed.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only outcome flag, no state change.
		$sent = isset( $_GET['sent'] ) ? absint( wp_unslash( $_GET['sent'] ) ) : 0;

		if ( $sent <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: entry id. */
					__( 'Update-card link sent for entry #%d.', 'chip-for-gravity-forms' ),
					$sent
				)
			)
		);
	}

	/**
	 * URL for this page, optionally filtered by status.
	 *
	 * @param string $status Status filter.
	 * @return string
	 */
	public static function page_url( $status = '' ) {
		$args = array( 'page' => self::slug() );

		if ( '' !== $status ) {
			$args['chip_status'] = $status;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * URL of the Gravity Forms entry detail for an entry.
	 *
	 * Gravity Forms addresses an entry by TWO parameters: `id` is the FORM id
	 * and `lid` is the ENTRY id. Passing the entry id as `id` and omitting
	 * `lid` sends the browser to the entry list of a form that does not exist,
	 * so the operator lands somewhere that is not the entry at all.
	 *
	 * The cancel and card-update flows both live on the entry detail, so this
	 * links there rather than duplicating them.
	 *
	 * @param int $form_id  Form id.
	 * @param int $entry_id Entry id.
	 * @return string
	 */
	public static function entry_url( $form_id, $entry_id ) {
		return add_query_arg(
			array(
				'page' => 'gf_entries',
				'view' => 'entry',
				'id'   => absint( $form_id ),
				'lid'  => absint( $entry_id ),
			),
			admin_url( 'admin.php' )
		);
	}
}
