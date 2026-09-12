<?php
/**
 * Regression tests for two renewal-engine defects found by live integration
 * testing after PR #27 merged.
 *
 * Both concern finite subscriptions (recurringTimes > 0):
 *
 *  1. The installment counter was never read back. Every renewal re-read
 *     recurringTimes from the feed, so a 12-installment plan charged forever.
 *
 *  2. A failed charge consumed an installment, because the counter was
 *     decremented during the pre-charge advance and never restored on failure.
 *     The customer would have been billed fewer times than agreed.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Renewals;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Renewals::resolve_remaining
 *
 * @covers \GF_Chip_Renewals::restore_installment
 */
class GF_Chip_Renewals_RemainingTest extends TestCase {

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

	// ---------------------------------------------------------------------
	// Resolving the counter — the read that was missing.
	// ---------------------------------------------------------------------

	/**
	 * With no stored counter yet (first cycle), the feed value is used.
	 */
	public function test_first_cycle_uses_feed_value(): void {
		$this->assertSame( 12, GF_Chip_Renewals::resolve_remaining( '', 12 ) );
	}

	/**
	 * A stored counter OVERRIDES the feed value.
	 *
	 * This is the defect: without this, recurringTimes was re-read from the
	 * feed on every run and never decreased, so a finite plan never ended.
	 */
	public function test_stored_counter_overrides_feed_value(): void {
		$this->assertSame( 11, GF_Chip_Renewals::resolve_remaining( 11, 12 ) );
	}

	/**
	 * A stored zero means the plan is finished and is honoured, not treated
	 * as "unset".
	 */
	public function test_stored_zero_is_honoured(): void {
		$this->assertSame( 0, GF_Chip_Renewals::resolve_remaining( 0, 12 ) );
		$this->assertSame( 0, GF_Chip_Renewals::resolve_remaining( '0', 12 ) );
	}

	/**
	 * An unlimited plan (feed value 0) stays unlimited.
	 */
	public function test_unlimited_plan_stays_unlimited(): void {
		$this->assertSame( 0, GF_Chip_Renewals::resolve_remaining( '', 0 ) );
	}

	/**
	 * A stored value on an unlimited plan still wins, so an operator can cap
	 * a plan that was configured as unlimited.
	 */
	public function test_stored_value_applies_to_unlimited_plan(): void {
		$this->assertSame( 3, GF_Chip_Renewals::resolve_remaining( 3, 0 ) );
	}

	/**
	 * A negative stored value is clamped to zero rather than propagating.
	 */
	public function test_negative_stored_value_clamps_to_zero(): void {
		$this->assertSame( 0, GF_Chip_Renewals::resolve_remaining( -5, 12 ) );
	}

	/**
	 * A non-numeric stored value falls back to the feed value rather than
	 * silently ending a plan.
	 */
	public function test_non_numeric_stored_value_falls_back_to_feed(): void {
		$this->assertSame( 12, GF_Chip_Renewals::resolve_remaining( 'garbage', 12 ) );
	}

	// ---------------------------------------------------------------------
	// Restoring an installment after a failed charge.
	// ---------------------------------------------------------------------

	/**
	 * A failed charge gives the installment back.
	 */
	public function test_restore_returns_the_installment(): void {
		$this->assertSame( 5, GF_Chip_Renewals::restore_installment( 4 ) );
	}

	/**
	 * Restoring an unlimited plan keeps it unlimited — it must not become a
	 * finite plan with one installment.
	 */
	public function test_restore_keeps_unlimited_plan_unlimited(): void {
		$this->assertSame( 0, GF_Chip_Renewals::restore_installment( 0 ) );
	}

	/**
	 * Restoring never produces a negative counter.
	 */
	public function test_restore_never_goes_negative(): void {
		$this->assertSame( 1, GF_Chip_Renewals::restore_installment( -1 ) );
	}

	/**
	 * Restoring twice is not cumulative — a retry that fails again restores
	 * once per failure, never more.
	 */
	public function test_restore_is_not_cumulative_across_retries(): void {
		$after_first = GF_Chip_Renewals::restore_installment( 4 );
		$this->assertSame( 5, $after_first );

		// Second attempt: consumed again on advance, then restored again.
		$consumed_again = $after_first - 1;
		$this->assertSame( 5, GF_Chip_Renewals::restore_installment( $consumed_again ) );
	}

	/**
	 * A finite plan ends after exactly recurringTimes successful charges.
	 *
	 * Drives the counter through a full plan end-to-end: 3 installments of an
	 * offer configured for 3 must produce exactly 3 charges and then expire.
	 */
	public function test_finite_plan_charges_exactly_n_times(): void {
		$feed_value = 3;
		$stored     = '';

		$charges = 0;
		$expired = false;

		for ( $i = 0; $i < 10; $i++ ) {
			$remaining = GF_Chip_Renewals::resolve_remaining( $stored, $feed_value );

			if ( 0 === $remaining ) {
				$expired = true;
				break;
			}

			$plan = GF_Chip_Renewals::plan_renewal(
				array(
					'id'                    => 1,
					'transaction_type'      => '2',
					'chip_sub_status'       => 'active',
					'chip_recurring_token'  => 'tok',
					'chip_sub_next_payment' => '2026-09-01 00:00:00',
				),
				'2026-09-01 00:00:00',
				1,
				'month',
				$remaining
			);

			if ( null === $plan['claim'] ) {
				++$charges;
				$expired = true;
				break;
			}

			++$charges;
			$stored = $plan['remaining'];
		}

		$this->assertSame( 3, $charges, 'a 3-installment plan must charge exactly 3 times' );
		$this->assertTrue( $expired, 'the plan must expire after the final installment' );
	}

	/**
	 * A failed charge followed by a success still yields exactly N charges.
	 *
	 * Without restoring the installment on failure, this would yield N-1:
	 * the failed attempt would have consumed one of the plan's charges.
	 *
	 * The failure is injected on the FIRST attempt so the scenario is
	 * guaranteed to exercise the restore path within the plan's window.
	 */
	public function test_failures_do_not_reduce_total_charges(): void {
		$feed_value = 3;
		$stored     = '';

		$successful = 0;
		$failures   = 0;

		for ( $i = 0; $i < 12; $i++ ) {
			$remaining = GF_Chip_Renewals::resolve_remaining( $stored, $feed_value );

			if ( 0 === $remaining ) {
				break;
			}

			$plan = GF_Chip_Renewals::plan_renewal(
				array(
					'id'                    => 1,
					'transaction_type'      => '2',
					'chip_sub_status'       => 'active',
					'chip_recurring_token'  => 'tok',
					'chip_sub_next_payment' => '2026-09-01 00:00:00',
				),
				'2026-09-01 00:00:00',
				1,
				'month',
				$remaining
			);

			// The first attempt fails; the rest succeed.
			if ( 0 === $i ) {
				++$failures;
				$stored = GF_Chip_Renewals::restore_installment( $plan['remaining'] );
				continue;
			}

			++$successful;

			if ( null === $plan['claim'] ) {
				break;
			}

			$stored = $plan['remaining'];
		}

		$this->assertGreaterThan( 0, $failures, 'the scenario must actually exercise a failure' );
		$this->assertSame(
			3,
			$successful,
			'a 3-installment plan must still deliver 3 successful charges despite the failed attempt'
		);
	}
}
