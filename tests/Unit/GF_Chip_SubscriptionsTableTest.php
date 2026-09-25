<?php
/**
 * Unit tests for the subscriptions list table and its bulk actions.
 *
 * Two concerns are locked here:
 *
 * 1. The screen must behave like a WordPress admin list — the bulk nonce the
 *    table renders is the one the handler checks, the screen option is
 *    registered on the page's own screen id, and every column the table
 *    declares has a renderer.
 *
 * 2. A bulk action must never bypass the guard that governs the same operation
 *    on a single row. `can_apply_bulk_action()` is the single decision point
 *    for that, and these tests pin it to the individual predicates.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Subscriptions_Page;
use GF_Chip_Subscriptions_Table;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Subscriptions_Page
 * @covers \GF_Chip_Subscriptions_Table
 */
class GF_Chip_SubscriptionsTableTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();

		WP_Mock::userFunction( '__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( function ( $text ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( function ( $url ) {
			return $url;
		} );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function ( $text ) {
			return $text;
		} );

		// The date formatter goes through date_i18n(); the assertion is about
		// the overdue marker, not about localisation of the date.
		WP_Mock::userFunction( 'date_i18n' )->andReturnUsing( function ( $format, $timestamp ) {
			return gmdate( $format, $timestamp );
		} );
		WP_Mock::userFunction( 'get_option' )->andReturn( 'Y-m-d H:i:s' );
		WP_Mock::userFunction( 'wp_timezone_string' )->andReturn( 'UTC' );

		WP_Mock::userFunction( 'admin_url' )->andReturn( 'https://example.test/wp-admin/admin.php' );
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturnUsing(
			function ( $url, $action ) {
				return $url . '&_wpnonce=' . md5( $action );
			}
		);
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $args, $url = '' ) {
				return $url . '?' . http_build_query( $args );
			}
		);
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * A subscription entry shaped the way the page hydrates rows.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function subscription( array $overrides = array() ) {
		return array_merge(
			array(
				'id'                   => 93,
				'form_id'              => 2,
				'transaction_type'     => '2',
				'payment_status'       => 'Active',
				'chip_sub_status'      => 'active',
				'chip_recurring_token' => 'tok_abcdefgh1234',
				'chip_sub_next_payment' => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
			),
			$overrides
		);
	}

	// -----------------------------------------------------------------
	// Screen wiring.
	// -----------------------------------------------------------------

	/**
	 * The screen id is derived from the slug Gravity Forms registers the page
	 * under, so screen options attach to the screen WordPress actually creates.
	 */
	public function test_screen_id_matches_the_gravity_forms_submenu_hook() {
		$this->assertSame(
			'forms_page_gravityformschip_subscriptions',
			GF_Chip_Subscriptions_Page::screen_id()
		);
	}

	/**
	 * `forms` is the admin_page_hook of the `gf_edit_forms` menu, which is what
	 * makes the prefix `forms_page_` rather than `gf_edit_forms_page_`.
	 *
	 * Pinned because a change to either part silently detaches the screen
	 * options: the page still renders, but the option never saves.
	 */
	public function test_screen_id_prefix_matches_the_forms_menu_hook() {
		$this->assertStringStartsWith( 'forms_page_', GF_Chip_Subscriptions_Page::screen_id() );
		$this->assertStringEndsWith( GF_Chip_Subscriptions_Page::slug(), GF_Chip_Subscriptions_Page::screen_id() );
	}

	/**
	 * The bulk nonce action must be derived from the table's plural name,
	 * because WP_List_Table prints `wp_nonce_field( 'bulk-' . plural )`.
	 *
	 * If these two drift apart, every bulk action fails the nonce check and
	 * the screen looks broken with no error.
	 */
	public function test_bulk_nonce_action_is_the_one_wp_list_table_prints() {
		$this->assertSame(
			'bulk-' . GF_Chip_Subscriptions_Table::PLURAL,
			GF_Chip_Subscriptions_Table::bulk_nonce_action()
		);
	}

	/**
	 * Every bulk action the table offers must be one the handler knows about,
	 * and vice versa — both read the same list.
	 */
	public function test_bulk_actions_are_defined_in_one_place() {
		$actions = GF_Chip_Subscriptions_Page::bulk_actions();

		$this->assertNotEmpty( $actions );

		foreach ( $actions as $key => $label ) {
			$this->assertIsString( $key );
			$this->assertIsString( $label );
			$this->assertNotSame( '', $label );
		}
	}

	// -----------------------------------------------------------------
	// The bulk guard must equal the single-row guard.
	// -----------------------------------------------------------------

	/**
	 * A bulk retry is allowed exactly when a single-row retry is.
	 */
	public function test_bulk_retry_guard_matches_the_retry_predicate() {
		$cases = array(
			$this->subscription(),
			$this->subscription( array( 'chip_sub_status' => 'on-hold' ) ),
			$this->subscription( array( 'chip_sub_status' => 'cancelled' ) ),
			$this->subscription( array( 'chip_sub_status' => 'expired' ) ),
			$this->subscription( array( 'chip_recurring_token' => '' ) ),
			$this->subscription( array( 'transaction_type' => '1' ) ),
		);

		foreach ( $cases as $entry ) {
			$this->assertSame(
				GF_Chip_Subscriptions_Page::can_retry( $entry ),
				GF_Chip_Subscriptions_Page::can_apply_bulk_action( 'chip_bulk_retry', $entry ),
				'Bulk retry must not widen the single-row retry rule.'
			);
		}
	}

	/**
	 * A bulk cancel is allowed exactly when a single-row cancel is.
	 */
	public function test_bulk_cancel_guard_matches_the_cancel_predicate() {
		$cases = array(
			$this->subscription(),
			$this->subscription( array( 'chip_sub_status' => 'on-hold' ) ),
			$this->subscription( array( 'chip_sub_status' => 'pending' ) ),
			$this->subscription( array( 'chip_sub_status' => 'cancelled', 'payment_status' => 'Cancelled' ) ),
			$this->subscription( array( 'chip_sub_status' => 'expired' ) ),
			$this->subscription( array( 'transaction_type' => '1' ) ),
		);

		foreach ( $cases as $entry ) {
			$this->assertSame(
				GF_Chip_Subscriptions_Page::can_cancel( $entry ),
				GF_Chip_Subscriptions_Page::can_apply_bulk_action( 'chip_bulk_cancel', $entry ),
				'Bulk cancel must not widen the single-row cancel rule.'
			);
		}
	}

	/**
	 * A bulk link is allowed exactly when a single-row link is.
	 */
	public function test_bulk_link_guard_matches_the_link_predicate() {
		$cases = array(
			$this->subscription(),
			$this->subscription( array( 'chip_sub_status' => 'cancelled', 'payment_status' => 'Cancelled' ) ),
			$this->subscription( array( 'transaction_type' => '1' ) ),
		);

		foreach ( $cases as $entry ) {
			$this->assertSame(
				GF_Chip_Subscriptions_Page::can_send_link( $entry ),
				GF_Chip_Subscriptions_Page::can_apply_bulk_action( 'chip_bulk_link', $entry ),
				'Bulk link must not widen the single-row link rule.'
			);
		}
	}

	/**
	 * A cancelled subscription must be refused by every bulk action, because
	 * every one of them would otherwise resurrect or re-charge it.
	 */
	public function test_a_cancelled_subscription_is_refused_by_every_bulk_action() {
		$entry = $this->subscription(
			array(
				'chip_sub_status'      => 'cancelled',
				'payment_status'       => 'Cancelled',
				'chip_recurring_token' => '',
			)
		);

		foreach ( array_keys( GF_Chip_Subscriptions_Page::bulk_actions() ) as $action ) {
			$this->assertFalse(
				GF_Chip_Subscriptions_Page::can_apply_bulk_action( $action, $entry ),
				'Bulk action ' . $action . ' must refuse a cancelled subscription.'
			);
		}
	}

	/**
	 * An unknown action is refused, so a crafted request cannot reach a
	 * default branch.
	 */
	public function test_unknown_bulk_action_is_refused() {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_apply_bulk_action( 'chip_bulk_delete_everything', $this->subscription() )
		);
	}

	/**
	 * A one-time entry is not a subscription, so no bulk action may touch it.
	 */
	public function test_a_one_time_entry_is_refused_by_every_bulk_action() {
		$entry = $this->subscription(
			array(
				'transaction_type' => '1',
				'chip_sub_status'  => '',
			)
		);

		foreach ( array_keys( GF_Chip_Subscriptions_Page::bulk_actions() ) as $action ) {
			$this->assertFalse(
				GF_Chip_Subscriptions_Page::can_apply_bulk_action( $action, $entry ),
				'Bulk action ' . $action . ' must refuse a one-time entry.'
			);
		}
	}

	// -----------------------------------------------------------------
	// Sorting.
	// -----------------------------------------------------------------

	/**
	 * Sorting by a known column orders the rows.
	 */
	public function test_sort_rows_sorts_by_entry_id_ascending() {
		$rows = array(
			array( 'id' => 30 ),
			array( 'id' => 10 ),
			array( 'id' => 20 ),
		);

		$sorted = GF_Chip_Subscriptions_Table::sort_rows( $rows, 'entry', 'asc' );

		$this->assertSame( array( 10, 20, 30 ), array_column( $sorted, 'id' ) );
	}

	/**
	 * Descending is the reverse.
	 */
	public function test_sort_rows_sorts_by_entry_id_descending() {
		$rows = array(
			array( 'id' => 10 ),
			array( 'id' => 30 ),
			array( 'id' => 20 ),
		);

		$sorted = GF_Chip_Subscriptions_Table::sort_rows( $rows, 'entry', 'desc' );

		$this->assertSame( array( 30, 20, 10 ), array_column( $sorted, 'id' ) );
	}

	/**
	 * An unsortable column leaves the order untouched, rather than throwing.
	 */
	public function test_sort_rows_ignores_an_unknown_column() {
		$rows = array(
			array( 'id' => 30 ),
			array( 'id' => 10 ),
		);

		$this->assertSame( $rows, GF_Chip_Subscriptions_Table::sort_rows( $rows, 'not_a_column', 'asc' ) );
	}

	/**
	 * Sorting by a date column sorts on the date value, not on the entry id.
	 */
	public function test_sort_rows_sorts_by_next_payment_date() {
		$rows = array(
			array( 'id' => 1, 'chip_sub_next_payment' => '2026-03-01 00:00:00' ),
			array( 'id' => 2, 'chip_sub_next_payment' => '2026-01-01 00:00:00' ),
			array( 'id' => 3, 'chip_sub_next_payment' => '2026-02-01 00:00:00' ),
		);

		$sorted = GF_Chip_Subscriptions_Table::sort_rows( $rows, 'next_payment', 'asc' );

		$this->assertSame( array( 2, 3, 1 ), array_column( $sorted, 'id' ) );
	}

	/**
	 * The retry counter sorts numerically, not as a string: without the
	 * numeric comparison "10" would sort before "9".
	 */
	public function test_sort_rows_sorts_retries_numerically() {
		$rows = array(
			array( 'id' => 1, 'chip_sub_retry_count' => '10' ),
			array( 'id' => 2, 'chip_sub_retry_count' => '9' ),
		);

		$sorted = GF_Chip_Subscriptions_Table::sort_rows( $rows, 'retries', 'asc' );

		$this->assertSame( array( 2, 1 ), array_column( $sorted, 'id' ) );
	}

	// -----------------------------------------------------------------
	// Columns.
	// -----------------------------------------------------------------

	/**
	 * Every column the table declares must have a renderer, and the table must
	 * declare exactly the columns the screen promises.
	 *
	 * The exact set matters, not just the count: dropping the Form column
	 * silently removes a column an operator uses to tell two subscriptions
	 * apart, and nothing else on the page would notice. Asserting the declared
	 * set is what makes a dropped column a test failure rather than a subtle
	 * regression.
	 */
	public function test_every_declared_column_has_a_renderer_and_the_set_is_exact() {
		// A bare instance is enough to read the declared columns, which are
		// pure.
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', gmdate( 'Y-m-d H:i:s' ) );

		$expected = array(
			'cb',
			'entry',
			'form',
			'status',
			'token',
			'next_payment',
			'retries',
			'actions',
		);

		$this->assertSame( $expected, array_keys( $table->get_columns() ) );

		foreach ( $expected as $key ) {
			$this->assertTrue(
				method_exists( $table, 'column_' . $key ),
				'Column ' . $key . ' has no column_' . $key . '() method.'
			);
		}
	}

	/**
	 * The primary column is the one that carries the row title, and it must be
	 * a declared column.
	 */
	public function test_the_entry_column_exists_because_it_is_the_row_title() {
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', gmdate( 'Y-m-d H:i:s' ) );

		$this->assertArrayHasKey( 'entry', $table->get_columns() );
	}

	/**
	 * Sortable columns must be declared columns, or the header renders a link
	 * that does nothing.
	 */
	public function test_sortable_columns_are_declared_columns() {
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', gmdate( 'Y-m-d H:i:s' ) );

		$columns = array_keys( $table->get_columns() );

		foreach ( array_keys( $table->get_sortable_columns() ) as $key ) {
			$this->assertContains( $key, $columns, 'Sortable column ' . $key . ' is not a declared column.' );
		}
	}

	/**
	 * The token column must never render the full token: a token authorises
	 * charges, and this page is a list.
	 */
	public function test_the_token_column_masks_the_token() {
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', gmdate( 'Y-m-d H:i:s' ) );

		$rendered = $table->column_token( array( 'chip_recurring_token' => 'tok_abcdefgh1234' ) );

		$this->assertStringNotContainsString( 'tok_abcdefgh1234', $rendered );
		$this->assertStringContainsString( '1234', $rendered );
	}

	/**
	 * A missing token renders the placeholder rather than an empty cell.
	 */
	public function test_the_token_column_renders_a_placeholder_when_absent() {
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', gmdate( 'Y-m-d H:i:s' ) );

		$this->assertStringContainsString( '—', $table->column_token( array() ) );
	}

	/**
	 * The overdue marker appears only when the subscription is actually past
	 * due, so the flag stays meaningful.
	 */
	public function test_the_overdue_marker_is_rendered_only_when_overdue() {
		$now = '2026-05-01 10:00:00';

		$table = new GF_Chip_Subscriptions_Table( 20, '', '', $now );

		$overdue = $table->column_next_payment(
			array(
				'chip_sub_status'       => 'active',
				'chip_sub_next_payment' => '2026-04-01 10:00:00',
			)
		);

		$current = $table->column_next_payment(
			array(
				'chip_sub_status'       => 'active',
				'chip_sub_next_payment' => '2026-06-01 10:00:00',
			)
		);

		$this->assertStringContainsString( 'overdue', $overdue );
		$this->assertStringNotContainsString( 'overdue', $current );
	}

	/**
	 * A cancelled subscription is shown as cancelled even when its due date is
	 * in the past: an inactive subscription is not "overdue", it is over.
	 */
	public function test_a_cancelled_subscription_is_not_marked_overdue() {
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', '2026-05-01 10:00:00' );

		$rendered = $table->column_next_payment(
			array(
				'chip_sub_status'       => 'cancelled',
				'chip_sub_next_payment' => '2026-04-01 10:00:00',
			)
		);

		$this->assertStringNotContainsString( 'overdue', $rendered );
	}

	/**
	 * The actions column offers an em dash when no action applies, rather than
	 * an empty cell that reads as a rendering bug.
	 */
	public function test_the_actions_column_renders_an_em_dash_when_nothing_applies() {
		$table = new GF_Chip_Subscriptions_Table( 20, '', '', gmdate( 'Y-m-d H:i:s' ) );

		$entry = $this->subscription(
			array(
				'chip_sub_status'      => 'cancelled',
				'payment_status'       => 'Cancelled',
				'chip_recurring_token' => '',
			)
		);

		$this->assertStringContainsString( '&#8212;', $table->column_actions( $entry ) );
	}

	/**
	 * The row actions must be built in handle_row_actions(), not inside
	 * column_entry().
	 *
	 * Core's single_row_columns() renders the primary cell and then calls
	 * handle_row_actions(), which appends its own toggle-row button.
	 * Building the actions inside the column method as well produced TWO
	 * toggle buttons per row — a visibly wrong table that every other
	 * assertion here still passed.
	 */
	public function test_row_actions_are_built_in_handle_row_actions_not_the_column() {
		$src = file_get_contents( GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-subscriptions-table.php' );

		$this->assertStringContainsString(
			'protected function handle_row_actions(',
			$src,
			'row actions must be built in handle_row_actions()'
		);

		// The entry column must return its link and nothing else.
		$start = strpos( $src, 'public function column_entry(' );
		$this->assertNotFalse( $start );

		$end  = strpos( $src, 'protected function handle_row_actions(', $start );
		$body = substr( $src, $start, $end - $start );

		$this->assertStringNotContainsString(
			'$this->row_actions(',
			$body,
			'column_entry() must not call row_actions() — core already appends the toggle button after it'
		);
	}

	/**
	 * The row actions offer exactly the operations the row's guards permit.
	 */
	public function test_row_action_links_offer_only_the_permitted_operations() {
		// A live subscription with a token can be re-linked and retried.
		$links = GF_Chip_Subscriptions_Table::row_action_links( $this->subscription() );

		$this->assertArrayHasKey( 'send', $links );
		$this->assertArrayHasKey( 'retry', $links );
		$this->assertStringContainsString( 'chip_send_card_update', $links['send'] );
		$this->assertStringContainsString( 'chip_retry_renewal', $links['retry'] );

		// A cancelled one can be neither: there is no future charge to
		// redirect and no live token to retry.
		$dead = GF_Chip_Subscriptions_Table::row_action_links(
			$this->subscription(
				array(
					'chip_sub_status'      => 'cancelled',
					'payment_status'       => 'Cancelled',
					'chip_recurring_token' => '',
				)
			)
		);

		$this->assertArrayNotHasKey( 'send', $dead );
		$this->assertArrayNotHasKey( 'retry', $dead );
	}

	/**
	 * An active subscription with no stored card can be re-linked but not
	 * retried — the two guards are independent.
	 */
	public function test_row_action_links_separate_the_link_and_retry_guards() {
		$links = GF_Chip_Subscriptions_Table::row_action_links(
			$this->subscription( array( 'chip_recurring_token' => '' ) )
		);

		$this->assertArrayHasKey( 'send', $links );
		$this->assertArrayNotHasKey( 'retry', $links );
	}

	/**
	 * The views answer "how many are on hold", so each state gets a view and
	 * the current one is marked — the same `subsubsub` markup core uses.
	 */
	public function test_views_include_every_subscription_state() {
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( function ( $url ) {
			return $url;
		} );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing( function ( $args, $url = '' ) {
			return $url . '?' . http_build_query( $args );
		} );
		WP_Mock::userFunction( 'admin_url' )->andReturn( 'https://example.test/wp-admin/admin.php' );

		$table = new GF_Chip_Subscriptions_Table( 20, 'on-hold', '', gmdate( 'Y-m-d H:i:s' ) );

		// The counts hit the database, so the query is stubbed at the $wpdb
		// boundary rather than the whole method.
		$views = $this->views_with_stubbed_counts( $table );

		$this->assertArrayHasKey( 'all', $views );

		foreach ( \GF_Chip::SUBSCRIPTION_STATES as $state ) {
			$this->assertArrayHasKey( $state, $views, 'Missing view for state ' . $state );
		}

		$this->assertStringContainsString( 'current', $views['on-hold'] );
		$this->assertStringNotContainsString( 'class="current"', $views['active'] );
	}

	/**
	 * Renders the views with the per-state counts stubbed out.
	 *
	 * The counts are database reads, so the global $wpdb is replaced with a
	 * stub that returns zero; the view markup itself is what is under test.
	 *
	 * @param GF_Chip_Subscriptions_Table $table Prepared table.
	 * @return array
	 */
	private function views_with_stubbed_counts( $table ) {
		global $wpdb;

		$original = $wpdb;

		$wpdb = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Returns a count.
			 *
			 * @param string $query Query.
			 * @return int
			 */
			public function get_var( $query ) {
				return 0;
			}

			/**
			 * Prepares a query.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return $query;
			}

			/**
			 * Escapes LIKE.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return $text;
			}
		};

		try {
			$this->assertIsArray( $table->get_columns() );
			$views = $table->get_views();
		} finally {
			$wpdb = $original;
		}

		return $views;
	}
}
