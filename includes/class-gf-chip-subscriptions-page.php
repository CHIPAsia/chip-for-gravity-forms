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
	 * @return int
	 */
	public static function count_subscriptions( $status = '' ) {
		global $wpdb;

		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list count; must reflect current rows.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT entry_id) FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = %s",
					'chip_sub_status'
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list count; must reflect current rows.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT entry_id) FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = %s AND meta_value = %s",
				'chip_sub_status',
				$status
			)
		);
	}

	/**
	 * Fetches a page of subscriptions.
	 *
	 * Driven from the entry meta table because chip_sub_status is the marker
	 * that an entry is a subscription, then each row is loaded through the
	 * Gravity Forms API so the entry data is consistent with the rest of the
	 * admin.
	 *
	 * @param int    $page   Page number, 1-based.
	 * @param string $status Optional chip_sub_status filter.
	 * @return array List of entry arrays with chip_sub_* meta flattened in.
	 */
	public static function get_subscriptions( $page = 1, $status = '' ) {
		global $wpdb;

		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list; must reflect current rows.
			$entry_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT entry_id FROM {$wpdb->prefix}gf_entry_meta
					 WHERE meta_key = %s
					 ORDER BY entry_id DESC
					 LIMIT %d OFFSET %d",
					'chip_sub_status',
					self::PER_PAGE,
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
					self::PER_PAGE,
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

			$entry = self::hydrate( $entry );

			$rows[] = $entry;
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

		// Both values are read-only list filters: they select what is displayed
		// and mutate nothing, so a nonce is not applicable. They are sanitised
		// and the status is additionally clamped to the known vocabulary below.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['chip_status'] ) ? sanitize_key( wp_unslash( $_GET['chip_status'] ) ) : '';
		$page   = isset( $_GET['chip_page'] ) ? max( 1, absint( wp_unslash( $_GET['chip_page'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Clamp the status filter to the known vocabulary so an arbitrary
		// value cannot be passed into the query.
		if ( '' !== $status && ! in_array( $status, \GF_Chip::SUBSCRIPTION_STATES, true ) ) {
			$status = '';
		}

		$rows  = self::get_subscriptions( $page, $status );
		$total = self::count_subscriptions( $status );
		$now   = gmdate( 'Y-m-d H:i:s' );

		self::render_table( $rows, $total, $page, $status, $now );
	}

	/**
	 * Renders the table markup.
	 *
	 * @param array  $rows   Rows.
	 * @param int    $total  Total matching rows.
	 * @param int    $page   Current page.
	 * @param string $status Status filter.
	 * @param string $now    Current UTC datetime.
	 * @return void
	 */
	private static function render_table( $rows, $total, $page, $status, $now ) {
		$statuses = \GF_Chip::SUBSCRIPTION_STATES;
		?>
		<div class="wrap gform-wrap">
			<h1><?php esc_html_e( 'CHIP Subscriptions', 'chip-for-gravity-forms' ); ?></h1>

			<?php self::render_notice(); ?>

			<p class="description">
				<?php esc_html_e( 'Recurring subscriptions collected by the CHIP gateway. Card changes are sent to the customer as a secure link; card data is never entered here.', 'chip-for-gravity-forms' ); ?>
			</p>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( self::page_url( '' ) ); ?>"<?php echo '' === $status ? ' class="current"' : ''; ?>>
						<?php esc_html_e( 'All', 'chip-for-gravity-forms' ); ?>
					</a>
				</li>
				<?php foreach ( $statuses as $state ) : ?>
					| <li>
						<a href="<?php echo esc_url( self::page_url( $state ) ); ?>"<?php echo $status === $state ? ' class="current"' : ''; ?>>
							<?php echo esc_html( self::describe_status( $state ) ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Entry', 'chip-for-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Form', 'chip-for-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'chip-for-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Token', 'chip-for-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Next payment', 'chip-for-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Retries', 'chip-for-gravity-forms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'chip-for-gravity-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No subscriptions found.', 'chip-for-gravity-forms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $overdue = self::is_overdue( $row, $now ); ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( self::entry_url( rgar( $row, 'form_id' ), rgar( $row, 'id' ) ) ); ?>">
									#<?php echo absint( rgar( $row, 'id' ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( rgar( $row, 'form_id' ) ); ?></td>
							<td><?php echo esc_html( self::describe_status( rgar( $row, 'chip_sub_status' ) ) ); ?></td>
							<td><code><?php echo esc_html( self::mask_token( rgar( $row, 'chip_recurring_token' ) ) ); ?></code></td>
							<td>
								<?php echo esc_html( self::format_datetime( rgar( $row, 'chip_sub_next_payment' ) ) ); ?>
								<?php if ( $overdue ) : ?>
									<strong class="chip-overdue"><?php esc_html_e( '(overdue)', 'chip-for-gravity-forms' ); ?></strong>
								<?php endif; ?>
							</td>
							<td><?php echo absint( rgar( $row, 'chip_sub_retry_count' ) ); ?></td>
							<td>
								<?php if ( self::can_retry( $row ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( GF_Chip_Renewal_Notifications::admin_retry_url( rgar( $row, 'id' ) ) ); ?>">
										<?php esc_html_e( 'Retry now', 'chip-for-gravity-forms' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( self::can_cancel( $row ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( self::entry_url( rgar( $row, 'form_id' ), rgar( $row, 'id' ) ) ); ?>">
										<?php esc_html_e( 'Cancel', 'chip-for-gravity-forms' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( self::can_send_link( $row ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( GF_Chip_Renewal_Notifications::admin_send_url( rgar( $row, 'id' ) ) ); ?>">
										<?php esc_html_e( 'Send update-card link', 'chip-for-gravity-forms' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php self::render_retry_notice(); ?>

			<?php self::render_pagination( $total, $page, $status ); ?>
		</div>
		<?php
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
	 * Renders pagination controls.
	 *
	 * @param int    $total  Total rows.
	 * @param int    $page   Current page.
	 * @param string $status Status filter.
	 * @return void
	 */
	private static function render_pagination( $total, $page, $status ) {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'chip_page', '%#%' ),
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'add_args'  => '' === $status ? array() : array( 'chip_status' => $status ),
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);

		echo '</div></div>';
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
