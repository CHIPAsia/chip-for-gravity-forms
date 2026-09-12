<?php
/**
 * Unit tests for the admin subscriptions list page helpers.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Subscriptions_Page;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Subscriptions_Page
 */
class GF_Chip_SubscriptionsPageTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();

		// Translation and date helpers used by the pure formatters.
		WP_Mock::userFunction( '__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * A subscription entry fixture.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function subscription( $overrides = array() ) {
		return array_merge(
			array(
				'id'                    => 42,
				'transaction_type'      => '2',
				'payment_status'        => 'Active',
				'chip_sub_status'       => 'active',
				'chip_recurring_token'  => 'tok_abcdefgh1234',
				'chip_sub_next_payment' => '2026-09-01 00:00:00',
			),
			$overrides
		);
	}

	// ---------------------------------------------------------------------
	// Token masking — a full token must never reach the browser.
	// ---------------------------------------------------------------------

	/**
	 * The masked value never contains the token's leading body.
	 */
	public function test_mask_token_hides_the_body(): void {
		$masked = GF_Chip_Subscriptions_Page::mask_token( 'tok_abcdefgh1234' );

		$this->assertStringNotContainsString( 'abcdefgh', $masked );
		$this->assertStringNotContainsString( 'tok_', $masked );
	}

	/**
	 * The last four characters are kept so rows are distinguishable.
	 */
	public function test_mask_token_keeps_last_four(): void {
		$this->assertStringEndsWith( '1234', GF_Chip_Subscriptions_Page::mask_token( 'tok_abcdefgh1234' ) );
	}

	/**
	 * A short token is fully masked — its full length reveals nothing.
	 */
	public function test_mask_token_fully_masks_short_values(): void {
		$masked = GF_Chip_Subscriptions_Page::mask_token( 'abcd' );

		$this->assertStringNotContainsString( 'abcd', $masked );
	}

	/**
	 * A missing token renders a placeholder rather than an empty cell.
	 */
	public function test_mask_token_handles_empty(): void {
		$this->assertSame( '—', GF_Chip_Subscriptions_Page::mask_token( '' ) );
		$this->assertSame( '—', GF_Chip_Subscriptions_Page::mask_token( null ) );
	}

	/**
	 * A non-scalar token does not warn.
	 */
	public function test_mask_token_handles_non_scalar(): void {
		$this->assertSame( '—', GF_Chip_Subscriptions_Page::mask_token( array( 'nope' ) ) );
	}

	// ---------------------------------------------------------------------
	// Status labels.
	// ---------------------------------------------------------------------

	/**
	 * Every state in the vocabulary has a distinct label.
	 */
	public function test_every_state_has_a_label(): void {
		$labels = array();

		foreach ( \GF_Chip::SUBSCRIPTION_STATES as $state ) {
			$label = GF_Chip_Subscriptions_Page::describe_status( $state );

			$this->assertNotSame( '', $label, "state '{$state}' must have a label" );
			$this->assertArrayNotHasKey( $label, $labels, "state '{$state}' duplicates another label" );
			$labels[ $label ] = $state;
		}

		$this->assertCount( count( \GF_Chip::SUBSCRIPTION_STATES ), $labels );
	}

	/**
	 * An unrecognised state renders as Pending, never blank.
	 */
	public function test_unknown_state_renders_as_pending(): void {
		$this->assertSame(
			GF_Chip_Subscriptions_Page::describe_status( 'pending' ),
			GF_Chip_Subscriptions_Page::describe_status( 'wat' )
		);
	}

	// ---------------------------------------------------------------------
	// Retry gating.
	// ---------------------------------------------------------------------

	/**
	 * An on-hold subscription can be retried — that is the whole point of it.
	 */
	public function test_on_hold_subscription_can_be_retried(): void {
		$this->assertTrue(
			GF_Chip_Subscriptions_Page::can_retry( $this->subscription( array( 'chip_sub_status' => 'on-hold' ) ) )
		);
	}

	/**
	 * An active subscription past due can be retried (cron has not run yet).
	 */
	public function test_active_overdue_subscription_can_be_retried(): void {
		$this->assertTrue( GF_Chip_Subscriptions_Page::can_retry( $this->subscription() ) );
	}

	/**
	 * A cancelled subscription must NOT be retried — retrying would resurrect
	 * a cancellation the customer asked for.
	 */
	public function test_cancelled_subscription_cannot_be_retried(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_retry( $this->subscription( array( 'chip_sub_status' => 'cancelled' ) ) )
		);
	}

	/**
	 * An expired subscription cannot be retried.
	 */
	public function test_expired_subscription_cannot_be_retried(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_retry( $this->subscription( array( 'chip_sub_status' => 'expired' ) ) )
		);
	}

	/**
	 * A subscription with no token cannot be retried — nothing to charge.
	 */
	public function test_subscription_without_token_cannot_be_retried(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_retry( $this->subscription( array( 'chip_recurring_token' => '' ) ) )
		);
	}

	/**
	 * A one-time payment is not retryable as a subscription.
	 */
	public function test_one_time_payment_cannot_be_retried(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_retry( $this->subscription( array( 'transaction_type' => '1' ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// Cancel gating.
	// ---------------------------------------------------------------------

	/**
	 * An active subscription can be cancelled.
	 */
	public function test_active_subscription_can_be_cancelled(): void {
		$this->assertTrue( GF_Chip_Subscriptions_Page::can_cancel( $this->subscription() ) );
	}

	/**
	 * An on-hold subscription can be cancelled — a merchant must be able to
	 * stop a failing subscription, not just retry it.
	 */
	public function test_on_hold_subscription_can_be_cancelled(): void {
		$this->assertTrue(
			GF_Chip_Subscriptions_Page::can_cancel( $this->subscription( array( 'chip_sub_status' => 'on-hold' ) ) )
		);
	}

	/**
	 * An already-cancelled subscription offers no cancel action.
	 */
	public function test_cancelled_subscription_cannot_be_cancelled_again(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_cancel( $this->subscription( array( 'chip_sub_status' => 'cancelled' ) ) )
		);
	}

	/**
	 * An expired subscription cannot be cancelled.
	 */
	public function test_expired_subscription_cannot_be_cancelled(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_cancel( $this->subscription( array( 'chip_sub_status' => 'expired' ) ) )
		);
	}

	/**
	 * A one-time payment cannot be cancelled as a subscription.
	 */
	public function test_one_time_payment_cannot_be_cancelled(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::can_cancel( $this->subscription( array( 'transaction_type' => '1' ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// Overdue highlighting.
	// ---------------------------------------------------------------------

	/**
	 * An active subscription past its date is overdue.
	 */
	public function test_active_past_due_is_overdue(): void {
		$this->assertTrue(
			GF_Chip_Subscriptions_Page::is_overdue( $this->subscription(), '2026-09-12 00:00:00' )
		);
	}

	/**
	 * An active subscription not yet due is not overdue.
	 */
	public function test_active_not_yet_due_is_not_overdue(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::is_overdue( $this->subscription(), '2026-08-01 00:00:00' )
		);
	}

	/**
	 * A cancelled subscription past its date is not "overdue" — nothing is
	 * owed, so highlighting it would be misleading.
	 */
	public function test_cancelled_past_due_is_not_overdue(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::is_overdue(
				$this->subscription( array( 'chip_sub_status' => 'cancelled' ) ),
				'2026-09-12 00:00:00'
			)
		);
	}

	/**
	 * A subscription with no next date is not overdue.
	 */
	public function test_missing_date_is_not_overdue(): void {
		$this->assertFalse(
			GF_Chip_Subscriptions_Page::is_overdue(
				$this->subscription( array( 'chip_sub_next_payment' => '' ) ),
				'2026-09-12 00:00:00'
			)
		);
	}

	// ---------------------------------------------------------------------
	// Nav registration.
	// ---------------------------------------------------------------------

	/**
	 * The nav item carries the page slug, a callback, and a capability.
	 */
	public function test_nav_item_is_registered_with_capability(): void {
		$menus = GF_Chip_Subscriptions_Page::add_nav_item( array() );

		$this->assertCount( 1, $menus );
		$this->assertSame( GF_Chip_Subscriptions_Page::slug(), $menus[0]['name'] );
		$this->assertSame( GF_Chip_Subscriptions_Page::capability(), $menus[0]['permission'] );
		$this->assertIsCallable( $menus[0]['callback'] );
	}

	/**
	 * An existing menu list is preserved, not replaced.
	 */
	public function test_nav_item_appends_to_existing_menu(): void {
		$existing = array( array( 'name' => 'something_else' ) );
		$menus    = GF_Chip_Subscriptions_Page::add_nav_item( $existing );

		$this->assertCount( 2, $menus );
		$this->assertSame( 'something_else', $menus[0]['name'] );
	}

	/**
	 * A non-array filter input does not warn.
	 */
	public function test_nav_item_handles_non_array_input(): void {
		$menus = GF_Chip_Subscriptions_Page::add_nav_item( null );

		$this->assertCount( 1, $menus );
	}
}
