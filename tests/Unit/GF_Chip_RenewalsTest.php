<?php
/**
 * Unit tests for the subscription renewal engine.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Renewals;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Renewals
 */
class GF_Chip_RenewalsTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * A due, active, tokened subscription.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function due_subscription( $overrides = array() ) {
		return array_merge(
			array(
				'id'                      => 42,
				'transaction_type'        => '2',
				'chip_sub_status'         => 'active',
				'chip_recurring_token'    => 'tok_abc',
				'chip_sub_next_payment'   => '2026-09-01 00:00:00',
			),
			$overrides
		);
	}

	// ---------------------------------------------------------------------
	// Due detection — this is what decides whether money moves.
	// ---------------------------------------------------------------------

	/**
	 * An active subscription whose next-payment date has passed is due.
	 */
	public function test_active_subscription_past_due_is_due(): void {
		$this->assertTrue(
			GF_Chip_Renewals::is_due( $this->due_subscription(), '2026-09-12 10:00:00' )
		);
	}

	/**
	 * Exactly at the due second counts as due.
	 */
	public function test_subscription_due_exactly_now_is_due(): void {
		$this->assertTrue(
			GF_Chip_Renewals::is_due( $this->due_subscription(), '2026-09-01 00:00:00' )
		);
	}

	/**
	 * A subscription not yet due is skipped.
	 */
	public function test_subscription_not_yet_due_is_skipped(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due( $this->due_subscription(), '2026-08-31 23:59:59' )
		);
	}

	/**
	 * A cancelled subscription is never charged, even if its date has passed.
	 */
	public function test_cancelled_subscription_is_never_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'chip_sub_status' => 'cancelled' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * An expired subscription is never charged.
	 */
	public function test_expired_subscription_is_never_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'chip_sub_status' => 'expired' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * An on-hold subscription is not auto-charged — the dunning ladder owns it.
	 */
	public function test_on_hold_subscription_is_not_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'chip_sub_status' => 'on-hold' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * A pending subscription (first payment not settled) is not charged.
	 */
	public function test_pending_subscription_is_not_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'chip_sub_status' => 'pending' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * A subscription with no token is not charged — there is nothing to charge.
	 */
	public function test_subscription_without_token_is_not_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'chip_recurring_token' => '' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * A subscription with no next-payment date is not charged.
	 */
	public function test_subscription_without_next_date_is_not_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'chip_sub_next_payment' => '' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * A one-time payment is never treated as a subscription.
	 */
	public function test_one_time_payment_is_not_due(): void {
		$this->assertFalse(
			GF_Chip_Renewals::is_due(
				$this->due_subscription( array( 'transaction_type' => '1' ) ),
				'2026-09-12 10:00:00'
			)
		);
	}

	/**
	 * A non-array entry is not due and does not warn.
	 */
	public function test_non_array_entry_is_not_due(): void {
		$this->assertFalse( GF_Chip_Renewals::is_due( null, '2026-09-12 10:00:00' ) );
	}

	// ---------------------------------------------------------------------
	// UTC handling — a local-time comparison would shift the window.
	// ---------------------------------------------------------------------

	/**
	 * Comparison is a plain string compare on normalised UTC values.
	 */
	public function test_compare_datetime_orders_correctly(): void {
		$this->assertLessThan( 0, GF_Chip_Renewals::compare_datetime( '2026-09-01 00:00:00', '2026-09-02 00:00:00' ) );
		$this->assertGreaterThan( 0, GF_Chip_Renewals::compare_datetime( '2026-09-03 00:00:00', '2026-09-02 00:00:00' ) );
		$this->assertSame( 0, GF_Chip_Renewals::compare_datetime( '2026-09-02 00:00:00', '2026-09-02 00:00:00' ) );
	}

	/**
	 * A same-day time boundary is respected, not truncated to a date compare.
	 */
	public function test_compare_datetime_respects_time_of_day(): void {
		$this->assertLessThan(
			0,
			GF_Chip_Renewals::compare_datetime( '2026-09-02 08:00:00', '2026-09-02 09:00:00' )
		);
	}

	// ---------------------------------------------------------------------
	// Retry ladder.
	// ---------------------------------------------------------------------

	/**
	 * The first failure schedules a retry one day after the due date.
	 */
	public function test_first_retry_is_one_day_after_due_date(): void {
		$this->assertSame(
			'2026-09-02 00:00:00',
			GF_Chip_Renewals::next_retry_at( '2026-09-01 00:00:00', 0 )
		);
	}

	/**
	 * The second failure schedules three days after the due date.
	 */
	public function test_second_retry_is_three_days_after_due_date(): void {
		$this->assertSame(
			'2026-09-04 00:00:00',
			GF_Chip_Renewals::next_retry_at( '2026-09-01 00:00:00', 1 )
		);
	}

	/**
	 * The third failure schedules five days after the due date.
	 */
	public function test_third_retry_is_five_days_after_due_date(): void {
		$this->assertSame(
			'2026-09-06 00:00:00',
			GF_Chip_Renewals::next_retry_at( '2026-09-01 00:00:00', 2 )
		);
	}

	/**
	 * After three failures the ladder is exhausted and the caller expires.
	 */
	public function test_retry_ladder_exhausted_after_three_failures(): void {
		$this->assertNull( GF_Chip_Renewals::next_retry_at( '2026-09-01 00:00:00', 3 ) );
	}

	/**
	 * A count beyond the ladder also returns null rather than an offset.
	 */
	public function test_retry_ladder_beyond_end_returns_null(): void {
		$this->assertNull( GF_Chip_Renewals::next_retry_at( '2026-09-01 00:00:00', 99 ) );
	}

	/**
	 * Retry offsets are measured from the due date, not the previous attempt,
	 * so a late cron run cannot stretch the ladder.
	 */
	public function test_retry_offsets_are_anchored_to_the_due_date(): void {
		$due = '2026-09-01 00:00:00';

		$this->assertSame( '2026-09-02 00:00:00', GF_Chip_Renewals::next_retry_at( $due, 0 ) );
		$this->assertSame( '2026-09-04 00:00:00', GF_Chip_Renewals::next_retry_at( $due, 1 ) );
		$this->assertSame( '2026-09-06 00:00:00', GF_Chip_Renewals::next_retry_at( $due, 2 ) );
	}

	/**
	 * An unparseable due date returns null rather than a bogus date.
	 */
	public function test_unparseable_due_date_returns_null(): void {
		$this->assertNull( GF_Chip_Renewals::next_retry_at( 'not a date', 0 ) );
	}

	// ---------------------------------------------------------------------
	// Card-network cap.
	// ---------------------------------------------------------------------

	/**
	 * Below the cap, charging is still allowed.
	 */
	public function test_network_cap_not_reached_below_limit(): void {
		$this->assertFalse( GF_Chip_Renewals::network_cap_reached( 14 ) );
	}

	/**
	 * At the cap, charging must stop to avoid issuer penalty fees.
	 */
	public function test_network_cap_reached_at_limit(): void {
		$this->assertTrue( GF_Chip_Renewals::network_cap_reached( 15 ) );
	}

	/**
	 * Above the cap, charging must also stop.
	 */
	public function test_network_cap_reached_above_limit(): void {
		$this->assertTrue( GF_Chip_Renewals::network_cap_reached( 20 ) );
	}

	// ---------------------------------------------------------------------
	// Cron hook wiring.
	// ---------------------------------------------------------------------

	/**
	 * The cron hook matches what Gravity Forms schedules.
	 *
	 * Core's setup_cron() builds the hook as "{slug}_cron", so a mismatch
	 * would mean the cron fires into nothing and renewals never run.
	 */
	public function test_cron_hook_matches_core_convention(): void {
		$this->assertSame( 'gf_chip_cron', GF_Chip_Renewals::cron_hook() );
	}

	// ---------------------------------------------------------------------
	// plan_renewal — THE idempotency guard.
	//
	// This decides whether money moves, and whether it moves TWICE. The
	// second question is the one that matters most: a double charge is a real
	// customer billed twice.
	// ---------------------------------------------------------------------

	/**
	 * A due subscription plans a charge, and the claim is the NEXT cycle date.
	 */
	public function test_plan_charges_due_subscription_and_claims_next_date(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription(),
			'2026-09-01 00:00:00',
			1,
			'month',
			0
		);

		$this->assertSame( 'charge', $plan['action'] );
		$this->assertSame( '2026-10-01 00:00:00', $plan['claim'] );
	}

	/**
	 * THE CRITICAL TEST: after the claim is written, a second run in the same
	 * window must NOT charge again.
	 *
	 * Simulates exactly what the cron does: plan, write the claim, then run
	 * again with the same "now". The second plan must be a skip.
	 *
	 * The first two assertions matter as much as the third: without them the
	 * test would also pass if the claim were null (an empty next-payment date
	 * also makes the second run skip, but for the wrong reason — the schedule
	 * never advanced, so the subscription would be charged again on the next
	 * run that restores a date).
	 */
	public function test_second_run_in_same_window_does_not_charge_again(): void {
		$now   = '2026-09-01 00:00:00';
		$entry = $this->due_subscription( array( 'chip_sub_next_payment' => $now ) );

		$first = GF_Chip_Renewals::plan_renewal( $entry, $now, 1, 'month', 0 );
		$this->assertSame( 'charge', $first['action'], 'first run must charge' );
		$this->assertNotNull( $first['claim'], 'first run must claim a next-payment date' );
		$this->assertGreaterThan(
			0,
			GF_Chip_Renewals::compare_datetime( $first['claim'], $now ),
			'the claim must be a FUTURE date — otherwise the schedule has not advanced'
		);

		// The cron writes the claim before charging.
		$entry['chip_sub_next_payment'] = $first['claim'];

		$second = GF_Chip_Renewals::plan_renewal( $entry, $now, 1, 'month', 0 );
		$this->assertSame( 'skip', $second['action'], 'second run in the same window must NOT charge' );
		$this->assertNull( $second['claim'] );
	}

	/**
	 * A third run is also a skip — the guard is stable, not just two-shot.
	 */
	public function test_third_run_also_does_not_charge_again(): void {
		$now   = '2026-09-01 00:00:00';
		$entry = $this->due_subscription( array( 'chip_sub_next_payment' => $now ) );

		$claim = GF_Chip_Renewals::plan_renewal( $entry, $now, 1, 'month', 0 )['claim'];
		$this->assertNotNull( $claim, 'the schedule must advance, or this test proves nothing' );

		$entry['chip_sub_next_payment'] = $claim;

		for ( $i = 0; $i < 3; $i++ ) {
			$plan = GF_Chip_Renewals::plan_renewal( $entry, $now, 1, 'month', 0 );
			$this->assertSame( 'skip', $plan['action'] );
		}
	}

	/**
	 * The claim advances by the billing cycle, not by a fixed month.
	 */
	public function test_plan_claim_respects_the_billing_cycle(): void {
		$weekly = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription( array( 'chip_sub_next_payment' => '2026-09-01 00:00:00' ) ),
			'2026-09-01 00:00:00',
			2,
			'week',
			0
		);
		$this->assertSame( '2026-09-15 00:00:00', $weekly['claim'] );

		$yearly = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription( array( 'chip_sub_next_payment' => '2026-09-01 00:00:00' ) ),
			'2026-09-01 00:00:00',
			1,
			'year',
			0
		);
		$this->assertSame( '2027-09-01 00:00:00', $yearly['claim'] );
	}

	/**
	 * A late cron run anchors on the due date, so the billing day does not
	 * drift forward over time.
	 */
	public function test_late_run_does_not_drift_the_billing_day(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription( array( 'chip_sub_next_payment' => '2026-09-01 00:00:00' ) ),
			'2026-09-04 18:30:00', // four days late
			1,
			'month',
			0
		);

		$this->assertSame( '2026-10-01 00:00:00', $plan['claim'], 'must anchor on the due date, not on now' );
	}

	/**
	 * A finite plan decrements its remaining count on each charge.
	 */
	public function test_plan_decrements_remaining_count(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription(),
			'2026-09-01 00:00:00',
			1,
			'month',
			3
		);

		$this->assertSame( 'charge', $plan['action'] );
		$this->assertSame( 2, $plan['remaining'] );
	}

	/**
	 * The final instalment charges but claims no next date, so the caller
	 * expires the subscription instead of scheduling another cycle.
	 */
	public function test_final_instalment_charges_without_a_next_date(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription(),
			'2026-09-01 00:00:00',
			1,
			'month',
			1
		);

		$this->assertSame( 'charge', $plan['action'] );
		$this->assertNull( $plan['claim'] );
		$this->assertSame( 0, $plan['remaining'] );
	}

	/**
	 * A non-due subscription plans no claim, so nothing is written.
	 */
	public function test_plan_skips_without_claim_when_not_due(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription(),
			'2026-08-01 00:00:00', // before the due date
			1,
			'month',
			0
		);

		$this->assertSame( 'skip', $plan['action'] );
		$this->assertNull( $plan['claim'] );
	}

	/**
	 * A cancelled subscription plans nothing at all.
	 */
	public function test_plan_skips_cancelled_subscription(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription( array( 'chip_sub_status' => 'cancelled' ) ),
			'2026-09-12 00:00:00',
			1,
			'month',
			0
		);

		$this->assertSame( 'skip', $plan['action'] );
		$this->assertNull( $plan['claim'] );
	}

	/**
	 * An unparseable next-payment date plans a skip rather than guessing.
	 */
	public function test_plan_skips_on_unparseable_next_date(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription( array( 'chip_sub_next_payment' => 'garbage' ) ),
			'2026-09-12 00:00:00',
			1,
			'month',
			0
		);

		$this->assertSame( 'skip', $plan['action'] );
	}

	/**
	 * A month-end due date clamps the claim instead of overflowing, which
	 * would silently skip a month for a customer billed on the 31st.
	 */
	public function test_plan_clamps_month_end_claim(): void {
		$plan = GF_Chip_Renewals::plan_renewal(
			$this->due_subscription( array( 'chip_sub_next_payment' => '2026-01-31 00:00:00' ) ),
			'2026-01-31 00:00:00',
			1,
			'month',
			0
		);

		$this->assertSame( '2026-02-28 00:00:00', $plan['claim'] );
	}
}
