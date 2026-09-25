<?php
/**
 * The subscriptions list table.
 *
 * Extends WP_List_Table rather than hand-rolling a <table>, so the page
 * behaves like every other admin list: Screen Options for the row count,
 * bulk actions with a select-all checkbox, a search box, the status views
 * with their counts, sortable columns, and the standard tablenav/pagination
 * markup.
 *
 * A hand-written table looks close enough to be tempting and is subtly wrong
 * everywhere — no screen options, no bulk selection, no search, no sort
 * indicators, none of the accessibility wiring — and it is the first thing an
 * administrator notices.
 *
 * No card data is displayed. The recurring token is masked.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Subscriptions list table.
 */
class GF_Chip_Subscriptions_Table extends WP_List_Table {

	/**
	 * The table's plural name.
	 *
	 * WP_List_Table derives BOTH its bulk-action nonce action
	 * (`bulk-{plural}`) and its screen-option meta key from this, so the
	 * nonce the table prints and the nonce the bulk handler checks come from
	 * one value. Renaming it in one place cannot desynchronise the two.
	 *
	 * @var string
	 */
	const PLURAL = 'chip_subscriptions';

	/**
	 * Rows requested from the screen option.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Active status filter.
	 *
	 * @var string
	 */
	private $status = '';

	/**
	 * Active search term.
	 *
	 * @var string
	 */
	private $search = '';

	/**
	 * Current UTC datetime, resolved once per request.
	 *
	 * @var string
	 */
	private $now = '';

	/**
	 * The nonce action for this table's bulk actions.
	 *
	 * WP_List_Table prints `wp_nonce_field( 'bulk-' . $args['plural'] )`; the
	 * bulk handler must check the same string. Deriving it here means the two
	 * cannot drift apart.
	 *
	 * @return string
	 */
	public static function bulk_nonce_action() {
		return 'bulk-' . self::PLURAL;
	}

	/**
	 * The active status filter.
	 *
	 * The page needs it to render the table it received, so it is exposed
	 * rather than rebuilt from the request a second time.
	 *
	 * @return string
	 */
	public function status() {
		return (string) $this->status;
	}

	/**
	 * Constructor.
	 *
	 * @param int    $per_page Rows per page.
	 * @param string $status   Status filter.
	 * @param string $search   Search term.
	 * @param string $now      Current UTC datetime.
	 */
	public function __construct( $per_page, $status, $search, $now ) {
		parent::__construct(
			array(
				'singular' => 'chip_subscription',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			)
		);

		$this->per_page = max( 1, (int) $per_page );
		$this->status   = (string) $status;
		$this->search   = (string) $search;
		$this->now      = (string) $now;
	}

	/**
	 * Columns shown in the table.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'entry'        => __( 'Entry', 'chip-for-gravity-forms' ),
			'form'         => __( 'Form', 'chip-for-gravity-forms' ),
			'status'       => __( 'Status', 'chip-for-gravity-forms' ),
			'token'        => __( 'Token', 'chip-for-gravity-forms' ),
			'next_payment' => __( 'Next payment', 'chip-for-gravity-forms' ),
			'retries'      => __( 'Retries', 'chip-for-gravity-forms' ),
			'actions'      => __( 'Actions', 'chip-for-gravity-forms' ),
		);
	}

	/**
	 * Columns that can be sorted.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'entry'        => array( 'entry', false ),
			'next_payment' => array( 'next_payment', false ),
			'retries'      => array( 'retries', false ),
		);
	}

	/**
	 * Bulk actions offered in the tablenav.
	 *
	 * Defined once on the page class so the handler validates against the same
	 * list the table renders.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return GF_Chip_Subscriptions_Page::bulk_actions();
	}

	/**
	 * The status views above the table.
	 *
	 * Rendered by WP_List_Table::views(), which wraps these in the standard
	 * `subsubsub` list and marks the current one — the same markup the Posts
	 * and Users screens use.
	 *
	 * Counts deliberately describe the whole set rather than the current
	 * search: the views answer "how many subscriptions are on hold", and a
	 * search is orthogonal to that.
	 *
	 * @return array
	 */
	public function get_views() {
		$views = array();

		$views['all'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
			esc_url( GF_Chip_Subscriptions_Page::page_url( '' ) ),
			'' === $this->status ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All', 'chip-for-gravity-forms' ),
			GF_Chip_Subscriptions_Page::count_subscriptions( '' )
		);

		foreach ( \GF_Chip::SUBSCRIPTION_STATES as $state ) {
			$views[ $state ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( GF_Chip_Subscriptions_Page::page_url( $state ) ),
				$this->status === $state ? ' class="current" aria-current="page"' : '',
				esc_html( GF_Chip_Subscriptions_Page::describe_status( $state ) ),
				GF_Chip_Subscriptions_Page::count_subscriptions( $state )
			);
		}

		return $views;
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_cb( $row ) {
		$entry_id = absint( rgar( $row, 'id' ) );

		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label>'
				. '<input type="checkbox" id="cb-select-%1$d" name="chip_entry[]" value="%1$d" />',
			$entry_id,
			esc_html(
				sprintf(
					/* translators: %d: entry id. */
					__( 'Select subscription %d', 'chip-for-gravity-forms' ),
					$entry_id
				)
			)
		);
	}

	/**
	 * Entry column — the row's primary link.
	 *
	 * Deliberately returns only the link. The row actions belong in
	 * handle_row_actions(): core's single_row_columns() calls that method
	 * after rendering the primary cell, and it already appends its own
	 * toggle-row button. Building the actions here as well would put TWO
	 * toggle buttons in every row.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_entry( $row ) {
		$entry_id = absint( rgar( $row, 'id' ) );
		$form_id  = absint( rgar( $row, 'form_id' ) );
		$url      = GF_Chip_Subscriptions_Page::entry_url( $form_id, $entry_id );

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
			esc_url( $url ),
			/* translators: %d: entry id. */
			esc_html( sprintf( __( '#%d', 'chip-for-gravity-forms' ), $entry_id ) )
		);
	}

	/**
	 * The row actions this entry's own guards permit.
	 *
	 * Extracted from handle_row_actions() so the decision — which operations
	 * a row offers — is directly testable without reflection. The rendering
	 * below is then a thin wrapper.
	 *
	 * @param array $entry Entry row.
	 * @return array Link HTML keyed by action name.
	 */
	public static function row_action_links( $entry ) {
		$entry_id = absint( rgar( $entry, 'id' ) );
		$actions  = array();

		if ( GF_Chip_Subscriptions_Page::can_send_link( $entry ) ) {
			$actions['send'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( GF_Chip_Renewal_Notifications::admin_send_url( $entry_id ) ),
				esc_html__( 'Send update-card link', 'chip-for-gravity-forms' )
			);
		}

		if ( GF_Chip_Subscriptions_Page::can_retry( $entry ) ) {
			$actions['retry'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( GF_Chip_Renewal_Notifications::admin_retry_url( $entry_id ) ),
				esc_html__( 'Retry now', 'chip-for-gravity-forms' )
			);
		} elseif ( GF_Chip_Subscriptions_Page::can_charge_now( $entry, gmdate( 'Y-m-d H:i:s' ) ) ) {
			// "Charge now" only appears when "Retry now" does not: both act on
			// the same subscription and differ only in whether the schedule
			// makes the charge due. Offering both would be offering one action
			// twice, with a different label for each.
			$actions['charge_now'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( GF_Chip_Renewal_Notifications::admin_charge_now_url( $entry_id ) ),
				esc_html__( 'Charge now', 'chip-for-gravity-forms' )
			);
		}

		return $actions;
	}

	/**
	 * Row actions for the primary column.
	 *
	 * This is where WordPress builds row actions, so this is where they are
	 * built here too. Core calls it once per row, for the primary column
	 * only, and row_actions() appends the single toggle-row button.
	 *
	 * A row whose own guards permit nothing gets core's default (the toggle
	 * button alone), rather than an empty div.
	 *
	 * @param array  $item        Entry row.
	 * @param string $column_name Current column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}

		$actions = self::row_action_links( $item );

		if ( empty( $actions ) ) {
			return parent::handle_row_actions( $item, $column_name, $primary );
		}

		return $this->row_actions( $actions );
	}

	/**
	 * Form column.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_form( $row ) {
		return esc_html( (string) rgar( $row, 'form_id' ) );
	}

	/**
	 * Status column.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_status( $row ) {
		return esc_html( GF_Chip_Subscriptions_Page::describe_status( rgar( $row, 'chip_sub_status' ) ) );
	}

	/**
	 * Token column — masked, never the full value.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_token( $row ) {
		return '<code>' . esc_html( GF_Chip_Subscriptions_Page::mask_token( rgar( $row, 'chip_recurring_token' ) ) ) . '</code>';
	}

	/**
	 * Next payment column, with the overdue marker.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_next_payment( $row ) {
		$value = esc_html( GF_Chip_Subscriptions_Page::format_datetime( rgar( $row, 'chip_sub_next_payment' ) ) );

		if ( GF_Chip_Subscriptions_Page::is_overdue( $row, $this->now ) ) {
			$value .= ' <strong class="chip-overdue">' . esc_html__( '(overdue)', 'chip-for-gravity-forms' ) . '</strong>';
		}

		return $value;
	}

	/**
	 * Retries column.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_retries( $row ) {
		return esc_html( (string) absint( rgar( $row, 'chip_sub_retry_count' ) ) );
	}

	/**
	 * Actions column.
	 *
	 * Each button is offered only when the row passes that operation's own
	 * guard, so a button is never shown for an action that would refuse the
	 * row.
	 *
	 * @param array $row Entry row.
	 * @return string
	 */
	public function column_actions( $row ) {
		$entry_id = absint( rgar( $row, 'id' ) );
		$out      = '';

		if ( GF_Chip_Subscriptions_Page::can_retry( $row ) ) {
			$out .= sprintf(
				'<a class="button button-small" href="%s">%s</a> ',
				esc_url( GF_Chip_Renewal_Notifications::admin_retry_url( $entry_id ) ),
				esc_html__( 'Retry now', 'chip-for-gravity-forms' )
			);
		}

		if ( GF_Chip_Subscriptions_Page::can_send_link( $row ) ) {
			$out .= sprintf(
				'<a class="button button-small" href="%s">%s</a> ',
				esc_url( GF_Chip_Renewal_Notifications::admin_send_url( $entry_id ) ),
				esc_html__( 'Send update-card link', 'chip-for-gravity-forms' )
			);
		}

		if ( GF_Chip_Subscriptions_Page::can_cancel( $row ) ) {
			$out .= sprintf(
				'<a class="button button-small" href="%s">%s</a>',
				esc_url( GF_Chip_Subscriptions_Page::entry_url( absint( rgar( $row, 'form_id' ) ), $entry_id ) ),
				esc_html__( 'Cancel', 'chip-for-gravity-forms' )
			);
		}

		return '' === trim( $out ) ? '&#8212;' : $out;
	}

	/**
	 * Renders a column that has no dedicated method.
	 *
	 * @param array  $row  Entry row.
	 * @param string $name Column name.
	 * @return string
	 */
	public function column_default( $row, $name ) {
		$value = rgar( $row, $name );

		return is_scalar( $value ) ? esc_html( (string) $value ) : '';
	}

	/**
	 * Message shown when nothing matches.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No subscriptions found.', 'chip-for-gravity-forms' );
	}

	/**
	 * The requested sort, sanitised.
	 *
	 * Sorting happens in PHP rather than SQL because a page holds at most
	 * `per_page` rows and the sort keys are not all columns of one table
	 * (entry id lives on gf_entry, the rest on gf_entry_meta).
	 *
	 * @return array [ column, direction ] — empty column when unsorted.
	 */
	private function sort_args() {
		// Read-only list ordering: selects what is displayed, changes nothing.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( self::sort_keys()[ $orderby ] ) ) {
			return array( '', '' );
		}

		return array( $orderby, 'asc' === $order ? 'asc' : 'desc' );
	}

	/**
	 * Map of sortable column key to the row field it sorts on.
	 *
	 * @return array
	 */
	public static function sort_keys() {
		return array(
			'entry'        => 'id',
			'next_payment' => 'chip_sub_next_payment',
			'retries'      => 'chip_sub_retry_count',
		);
	}

	/**
	 * Sorts rows for display.
	 *
	 * @param array  $rows    Rows.
	 * @param string $orderby Column key.
	 * @param string $order   Direction: asc or desc.
	 * @return array
	 */
	public static function sort_rows( $rows, $orderby, $order ) {
		$keys = self::sort_keys();

		if ( ! isset( $keys[ $orderby ] ) ) {
			return $rows;
		}

		$field = $keys[ $orderby ];
		$rows  = array_values( $rows );

		usort(
			$rows,
			function ( $a, $b ) use ( $field, $order ) {
				$a_val = rgar( $a, $field );
				$b_val = rgar( $b, $field );

				if ( in_array( $field, array( 'id', 'chip_sub_retry_count' ), true ) ) {
					$result = absint( $a_val ) <=> absint( $b_val );
				} else {
					$result = strcmp( (string) $a_val, (string) $b_val );
				}

				return 'asc' === $order ? $result : -$result;
			}
		);

		return $rows;
	}

	/**
	 * Loads the rows for the current page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		list( $orderby, $order ) = $this->sort_args();

		$this->items = GF_Chip_Subscriptions_Page::get_subscriptions( $this->per_page, $this->status, $this->search );
		$this->items = self::sort_rows( $this->items, $orderby, $order );

		$total = GF_Chip_Subscriptions_Page::count_subscriptions( $this->status, $this->search );

		// Hidden columns come from the user's Screen Options, never from a
		// literal. Hardcoding an empty array here silently discards the
		// preference: the toggle writes it, this line erased it on the next
		// render, and the column could never actually be hidden.
		$this->_column_headers = array(
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			$this->get_sortable_columns(),
			$this->get_primary_column_name(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $this->per_page,
				'total_pages' => (int) ceil( $total / $this->per_page ),
			)
		);
	}
}
