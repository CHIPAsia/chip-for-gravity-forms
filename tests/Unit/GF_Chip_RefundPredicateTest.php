<?php
/**
 * The Refund button must stay available on an active subscription entry.
 *
 * Core records a subscription's payment_status as 'Active', not 'Paid'. The
 * refund predicate required 'Paid', so the entries this predicate was
 * widened to support were exactly the ones it still refused -- a subscription
 * payment is a payment, and it is refundable.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Covers the refund-UI predicate against Gravity Forms' own vocabularies.
 *
 * @covers \GF_Chip::should_render_refund_ui
 */
class GF_Chip_RefundPredicateTest extends TestCase {

	/**
	 * WP_Mock.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * A live subscription entry offers a refund.
	 *
	 * This is the regression: core sets payment_status to 'Active' for a
	 * subscription, so requiring 'Paid' hid the button on exactly the entries
	 * the predicate had been widened to cover.
	 */
	public function test_active_subscription_entry_offers_a_refund(): void {
		$entry = array(
			'transaction_id'   => 'pur_sub_1',
			'payment_method'   => 'visa',
			'payment_status'   => 'Active',
			'transaction_type' => '2',
		);

		$this->assertTrue( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * A one-time payment that was paid still offers a refund.
	 */
	public function test_paid_one_time_payment_offers_a_refund(): void {
		$entry = array(
			'transaction_id'   => 'pur_1',
			'payment_method'   => 'fpx',
			'payment_status'   => 'Paid',
			'transaction_type' => '1',
		);

		$this->assertTrue( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * The widened predicate must stay narrow in the direction that matters:
	 * a subscription that is not live has nothing to refund.
	 *
	 * @dataProvider provide_non_refundable_subscription_states
	 *
	 * @param string $status Gravity Forms payment status.
	 */
	public function test_non_live_subscription_states_offer_no_refund( $status ): void {
		$entry = array(
			'transaction_id'   => 'pur_sub_1',
			'payment_method'   => 'visa',
			'payment_status'   => $status,
			'transaction_type' => '2',
		);

		$this->assertFalse(
			GF_Chip::should_render_refund_ui( $entry ),
			"a '{$status}' subscription must not offer a refund"
		);
	}

	/**
	 * Statuses where the refund button must not render.
	 *
	 * @return array[]
	 */
	public function provide_non_refundable_subscription_states() {
		return array(
			'pending before the first payment settled' => array( 'Pending' ),
			'processing, outcome not yet known'        => array( 'Processing' ),
			'cancelled'                                => array( 'Cancelled' ),
			'failed'                                   => array( 'Failed' ),
			'already refunded'                         => array( 'Refunded' ),
		);
	}

	/**
	 * No transaction id means nothing to refund, whatever the status.
	 */
	public function test_entry_without_transaction_id_offers_no_refund(): void {
		$entry = array(
			'payment_method'   => 'visa',
			'payment_status'   => 'Active',
			'transaction_type' => '2',
		);

		$this->assertFalse( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * No payment method means this entry is not usable for a refund request.
	 */
	public function test_entry_without_payment_method_offers_no_refund(): void {
		$entry = array(
			'transaction_id'   => 'pur_sub_1',
			'payment_status'   => 'Active',
			'transaction_type' => '2',
		);

		$this->assertFalse( GF_Chip::should_render_refund_ui( $entry ) );
	}

	/**
	 * A non-array (a WP_Error from a bad lookup, say) must not render a button.
	 */
	public function test_non_array_entry_offers_no_refund(): void {
		$this->assertFalse( GF_Chip::should_render_refund_ui( null ) );
		$this->assertFalse( GF_Chip::should_render_refund_ui( 'Active' ) );
	}
}
