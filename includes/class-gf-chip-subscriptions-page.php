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
	 * Capability required to view the page.
	 *
	 * Mirrors the capability the add-on already uses for its settings page, so
	 * access is not silently widened.
	 *
	 * @return string
	 */
	public static function capability() {
		return 'gravityforms_edit_settings';
	}

	/**
	 * Registers the page in the Forms navigation.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'gform_addon_navigation', array( __CLASS__, 'add_nav_item' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );

		// The screen is not resolved until the page's own load action, so the
		// screen option is registered there rather than inline. The bulk
		// handler runs there too: it fires after the screen exists and before
		// any output, which is what lets it redirect.
		add_action( 'load-' . self::screen_id(), array( __CLASS__, 'register_screen_option' ) );
		add_action( 'load-' . self::screen_id(), array( __CLASS__, 'maybe_handle_bulk_action' ) );
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

		if ( ! GFCommon::current_user_can_any( self::capability() ) ) {
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
	 * Matches the entry id when the term is numeric, and otherwise searches the
	 * values a subscription entry carries (the customer's email or name as the
	 * mapped fields stored them), so an operator can find a row by the
	 * customer rather than by entry number.
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

		if ( ctype_digit( $search ) ) {
			$matches[] = (int) $search;
		}

		$entry_table = $wpdb->prefix . 'gf_entry';

		// Match against the entry's own scalar values. Escaping is handled by
		// the search-criteria API below where possible; this query is bounded
		// to the subscription set and uses a LIKE on prepared values.
		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$id_list = implode( ',', array_map( 'absint', $subscription_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list search; must reflect current rows.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$entry_table} WHERE id IN ({$id_list}) AND (id = %s OR date_created LIKE %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
				$search,
				$like
			)
		);

		foreach ( (array) $found as $id ) {
			$matches[] = (int) $id;
		}

		// Also match what the entry's own fields hold (email, name…), which is
		// how an operator actually searches for a subscription.
		$meta_table = $wpdb->prefix . 'gf_entry_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list search; must reflect current rows.
		$by_value = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT entry_id FROM {$meta_table} WHERE entry_id IN ({$id_list}) AND meta_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id list is absint-mapped.
				$like
			)
		);

		foreach ( (array) $by_value as $id ) {
			$matches[] = (int) $id;
		}

		$matches = array_values( array_unique( array_filter( array_map( 'absint', $matches ) ) ) );

		return $matches;
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
		if ( ! GFCommon::current_user_can_any( self::capability() ) ) {
			wp_die( esc_html__( 'Access denied.', 'chip-for-gravity-forms' ) );
		}

		// Read-only list filters: they select what is displayed and mutate
		// nothing, so a nonce is not applicable. Each is sanitised, and the
		// status is additionally clamped to the known vocabulary below.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['chip_status'] ) ? sanitize_key( wp_unslash( $_GET['chip_status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Clamp the status filter to the known vocabulary so an arbitrary
		// value cannot be passed into the query.
		if ( '' !== $status && ! in_array( $status, \GF_Chip::SUBSCRIPTION_STATES, true ) ) {
			$status = '';
		}

		$table = new GF_Chip_Subscriptions_Table(
			self::per_page(),
			$status,
			$search,
			gmdate( 'Y-m-d H:i:s' )
		);

		$table->prepare_items();

		self::render_page( $table, $status );
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
