<?php
/**
 * Unit tests for GF_Chip subscription lifecycle hooks.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The subscription state vocabulary this plugin owns.
 *
 * @covers \GF_Chip::get_subscription_state
 *
 * @covers \GF_Chip::is_subscription_entry
 *
 * @covers \GF_Chip::should_render_refund_ui
 */
class GF_Chip_SubscriptionLifecycleTest extends TestCase {

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
	// Subscription entry detection.
	// ---------------------------------------------------------------------

	/**
	 * transaction_type 2 marks a subscription entry.
	 */
	public function test_transaction_type_two_is_a_subscription(): void {
		$this->assertTrue( GF_Chip::is_subscription_entry( array( 'transaction_type' => '2' ) ) );
	}

	/**
	 * transaction_type 1 is a one-time payment, not a subscription.
	 */
	public function test_transaction_type_one_is_not_a_subscription(): void {
		$this->assertFalse( GF_Chip::is_subscription_entry( array( 'transaction_type' => '1' ) ) );
	}

	/**
	 * A missing transaction type is not a subscription.
	 */
	public function test_missing_transaction_type_is_not_a_subscription(): void {
		$this->assertFalse( GF_Chip::is_subscription_entry( array() ) );
	}

	/**
	 * A non-array entry is not a subscription and does not warn.
	 */
	public function test_non_array_entry_is_not_a_subscription(): void {
		$this->assertFalse( GF_Chip::is_subscription_entry( null ) );
	}

	// ---------------------------------------------------------------------
	// Refund UI gating.
	//
	// The previous implementation gated the refund button on
	// transaction_type === '1', which hid it for every subscription entry.
	// ---------------------------------------------------------------------

	/**
	 * A paid one-time payment shows the refund UI.
	 */
	public function test_paid_one_time_payment_shows_refund_ui(): void {
		$entry = array(
			'transaction_id'  => 'pur_1',
			'payment_method'  => 'fpx',
			'payment_status'  => 'Paid',
			'transaction_type' => '1',
		);

		$this->assertTrue( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * A paid subscription ALSO shows the refund UI.
	 *
	 * This is the regression guard: refunds are meaningful for subscription
	 * payments too, and the old check excluded them entirely.
	 */
	public function test_paid_subscription_shows_refund_ui(): void {
		$entry = array(
			'transaction_id'  => 'pur_sub_1',
			'payment_method'  => 'visa',
			'payment_status'  => 'Paid',
			'transaction_type' => '2',
		);

		$this->assertTrue( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * An unpaid entry shows no refund UI.
	 */
	public function test_unpaid_entry_hides_refund_ui(): void {
		$entry = array(
			'transaction_id'  => 'pur_1',
			'payment_method'  => 'fpx',
			'payment_status'  => 'Pending',
			'transaction_type' => '1',
		);

		$this->assertFalse( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * An entry with no transaction id shows no refund UI — nothing to refund.
	 */
	public function test_entry_without_transaction_id_hides_refund_ui(): void {
		$entry = array(
			'payment_method'  => 'fpx',
			'payment_status'  => 'Paid',
			'transaction_type' => '1',
		);

		$this->assertFalse( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * An entry with no payment method shows no refund UI.
	 */
	public function test_entry_without_payment_method_hides_refund_ui(): void {
		$entry = array(
			'transaction_id'  => 'pur_1',
			'payment_status'  => 'Paid',
			'transaction_type' => '1',
		);

		$this->assertFalse( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * A refunded (not Paid) entry hides the refund UI.
	 */
	public function test_refunded_entry_hides_refund_ui(): void {
		$entry = array(
			'transaction_id'  => 'pur_1',
			'payment_method'  => 'fpx',
			'payment_status'  => 'Refunded',
			'transaction_type' => '1',
		);

		$this->assertFalse( GF_Chip::should_render_refund_ui( $entry ) );
	}

	// ---------------------------------------------------------------------
	// Subscription state vocabulary.
	// ---------------------------------------------------------------------

	/**
	 * A subscription with no stored state yet reads as pending.
	 */
	public function test_missing_state_reads_as_pending(): void {
		$this->assertSame( 'pending', GF_Chip::get_subscription_state( array() ) );
	}

	/**
	 * Each known state is returned as-is.
	 */
	public function test_known_states_pass_through(): void {
		foreach ( array( 'active', 'on-hold', 'cancelled', 'expired', 'failed' ) as $state ) {
			$this->assertSame( $state, GF_Chip::get_subscription_state( array( 'chip_sub_status' => $state ) ) );
		}
	}

	/**
	 * An unrecognised state falls back to pending rather than passing through,
	 * so a typo cannot silently disable the renewal engine's filters.
	 */
	public function test_unknown_state_falls_back_to_pending(): void {
		$this->assertSame( 'pending', GF_Chip::get_subscription_state( array( 'chip_sub_status' => 'wat' ) ) );
	}

	// ---------------------------------------------------------------------
	// Core's cancel button depends on both conditions being true.
	// ---------------------------------------------------------------------

	/**
	 * The cancel button requires an overridden cancel() AND a cancellable
	 * subscription entry — mirroring core's own gate so the plugin can assert
	 * the precondition rather than discovering it in the admin UI.
	 */
	public function test_cancel_is_permitted_for_active_subscription(): void {
		$entry = array(
			'transaction_type' => '2',
			'payment_status'   => 'Active',
		);

		$this->assertTrue( GF_Chip::can_cancel_subscription( $entry ) );
	}

	/**
	 * An already-cancelled subscription cannot be cancelled again.
	 */
	public function test_cancel_is_refused_when_already_cancelled(): void {
		$entry = array(
			'transaction_type' => '2',
			'payment_status'   => 'Cancelled',
		);

		$this->assertFalse( GF_Chip::can_cancel_subscription( $entry ) );
	}

	/**
	 * A failed subscription cannot be cancelled — core refuses it too.
	 */
	public function test_cancel_is_refused_when_failed(): void {
		$entry = array(
			'transaction_type' => '2',
			'payment_status'   => 'Failed',
		);

		$this->assertFalse( GF_Chip::can_cancel_subscription( $entry ) );
	}

	/**
	 * A one-time payment is not a subscription and cannot be cancelled.
	 */
	public function test_cancel_is_refused_for_one_time_payment(): void {
		$entry = array(
			'transaction_type' => '1',
			'payment_status'   => 'Paid',
		);

		$this->assertFalse( GF_Chip::can_cancel_subscription( $entry ) );
	}
}
