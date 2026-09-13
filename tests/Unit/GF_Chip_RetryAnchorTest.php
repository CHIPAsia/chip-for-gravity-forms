<?php
/**
 * Regression tests for the retry-ladder anchor.
 *
 * The ladder must be measured from the date that was DUE. The code used to
 * re-read chip_sub_next_payment after the pre-charge advance had overwritten
 * it, so the ladder anchored one whole cycle ahead and a failed renewal was
 * retried on the next billing date instead of a day after the miss.
 *
 * It happened to work only because every caller passed an entry fetched before
 * the advance. These tests pin the contract so a caller that refreshes the
 * entry cannot silently reintroduce the drift.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Renewals;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Renewals::plan_renewal
 * @covers \GF_Chip_Renewals::next_retry_at
 */
class GF_Chip_RetryAnchorTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * A due subscription's plan must report the date it anchored on.
	 *
	 * Without this the failure path has nothing to anchor the ladder on, and
	 * would have to re-read a field the advance has already changed.
	 */
	public function test_plan_reports_the_due_anchor_on_a_charge(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );

		$entry = array(
			'id'                    => 1,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_recurring_token'  => 'tok',
			'chip_sub_next_payment' => '2026-09-01 00:00:00',
		);

		$plan = GF_Chip_Renewals::plan_renewal( $entry, '2026-09-01 00:00:01', 1, 'month', 3 );

		$this->assertSame( 'charge', $plan['action'] );
		$this->assertSame(
			'2026-09-01 00:00:00',
			$plan['due_anchor'],
			'the plan must report the date that was due, not the date it will claim'
		);

		// And the claim is the NEXT cycle, one month on.
		$this->assertNotSame(
			$plan['due_anchor'],
			$plan['claim'],
			'the claim must advance past the due date'
		);
	}

	/**
	 * The bug in numbers: the ladder computed from the plan's anchor must be
	 * one day after the MISS, not one day after the next billing date.
	 */
	public function test_ladder_from_the_anchor_lands_next_to_the_miss(): void {
		$due     = '2026-09-01 00:00:00';
		$claim   = '2026-10-01 00:00:00';   // what the advance writes

		$this->assertSame(
			'2026-09-02 00:00:00',
			GF_Chip_Renewals::next_retry_at( $due, 0 ),
			'the first retry must be the day after the missed payment'
		);

		// The drift this fix removes: anchoring on the claim defers the retry
		// by a whole billing cycle.
		$this->assertSame(
			'2026-10-02 00:00:00',
			GF_Chip_Renewals::next_retry_at( $claim, 0 ),
			'anchoring on the claim defers the retry a whole cycle -- the defect'
		);
	}

	/**
	 * The final-installment case still reports an anchor.
	 *
	 * plan_renewal returns claim=null there (nothing to schedule), but the
	 * ladder must still be anchored correctly if that charge fails.
	 */
	public function test_final_installment_plan_still_reports_an_anchor(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );

		$entry = array(
			'id'                    => 1,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_recurring_token'  => 'tok',
			'chip_sub_next_payment' => '2026-09-01 00:00:00',
		);

		// remaining = 1 -> this is the last installment; no next cycle.
		$plan = GF_Chip_Renewals::plan_renewal( $entry, '2026-09-01 00:00:01', 1, 'month', 1 );

		$this->assertSame( 'charge', $plan['action'] );
		$this->assertNull( $plan['claim'], 'no cycle to schedule after the last installment' );
		$this->assertSame(
			'2026-09-01 00:00:00',
			$plan['due_anchor'],
			'even the final installment must carry its anchor'
		);
	}

	/**
	 * A skip carries no anchor, so the failure path cannot invent one.
	 */
	public function test_a_skipped_plan_carries_no_anchor(): void {
		$entry = array(
			'id'                    => 1,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_recurring_token'  => 'tok',
			'chip_sub_next_payment' => '2027-01-01 00:00:00',   // not due
		);

		$plan = GF_Chip_Renewals::plan_renewal( $entry, '2026-09-01 00:00:00', 1, 'month', 3 );

		$this->assertSame( 'skip', $plan['action'] );
		$this->assertNull( $plan['due_anchor'] );
	}

	// ---------------------------------------------------------------------
	// next_attempt_from_plan(): the ladder decision, tested directly.
	//
	// This closes the gap where the wiring inside handle_renewal_failure was
	// unreachable without an API mock, so nothing protected the anchor.
	// ---------------------------------------------------------------------

	/**
	 * The ladder is computed from the plan's anchor, not from the advanced
	 * date. This is the money-relevant assertion: anchoring on the claim
	 * defers the retry a whole billing cycle.
	 */
	public function test_next_attempt_uses_the_due_anchor_not_the_claim(): void {
		$plan = array(
			'action'     => 'charge',
			'claim'      => '2026-10-01 00:00:00',
			'remaining'  => 2,
			'due_anchor' => '2026-09-01 00:00:00',
		);

		$this->assertSame(
			'2026-09-02 00:00:00',
			GF_Chip_Renewals::next_attempt_from_plan( $plan, 0 ),
			'the first retry must be a day after the missed payment'
		);
	}

	/**
	 * The ladder advances through 1 / 3 / 5 days, then exhausts.
	 */
	public function test_next_attempt_walks_the_ladder_then_exhausts(): void {
		$plan = array( 'due_anchor' => '2026-09-01 00:00:00' );

		$this->assertSame( '2026-09-02 00:00:00', GF_Chip_Renewals::next_attempt_from_plan( $plan, 0 ) );
		$this->assertSame( '2026-09-04 00:00:00', GF_Chip_Renewals::next_attempt_from_plan( $plan, 1 ) );
		$this->assertSame( '2026-09-06 00:00:00', GF_Chip_Renewals::next_attempt_from_plan( $plan, 2 ) );
		$this->assertNull( GF_Chip_Renewals::next_attempt_from_plan( $plan, 3 ), 'ladder exhausted after 3' );
	}

	/**
	 * No usable anchor must expire rather than schedule against a vague date.
	 */
	public function test_next_attempt_without_an_anchor_is_null(): void {
		$this->assertNull( GF_Chip_Renewals::next_attempt_from_plan( array( 'due_anchor' => null ), 0 ) );
		$this->assertNull( GF_Chip_Renewals::next_attempt_from_plan( array(), 0 ) );
	}

	/**
	 * A plan that reports a claim must never have the claim used as the anchor.
	 */
	public function test_claim_is_never_used_as_the_anchor(): void {
		$plan = array(
			'claim'      => '2026-10-01 00:00:00',
			'due_anchor' => '2026-09-01 00:00:00',
		);

		$this->assertNotSame(
			GF_Chip_Renewals::next_retry_at( $plan['claim'], 0 ),
			GF_Chip_Renewals::next_attempt_from_plan( $plan, 0 ),
			'the claim must not be used as the ladder anchor'
		);
	}
}
