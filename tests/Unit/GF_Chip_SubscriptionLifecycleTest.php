<?php
/**
 * Unit tests for GF_Chip subscription lifecycle hooks.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_CHIP_API;
use GF_Chip;
use GF_Chip_Card_Update;
use GF_Chip_Test_Meta;
use GF_Chip_Subscriptions_Page;
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
	/**
	 * A cancelled subscription is never due, even with a stale date and token.
	 *
	 * The money guard, tested independently of mark_cancelled() clearing meta.
	 */
	/**
	 * cancel() must move the stored state to 'cancelled'.
	 *
	 * This is the regression test for a real defect, and it drives the real
	 * cancel() path rather than the helper it calls -- a test that exercises
	 * only the helper passes whether or not cancel() actually invokes it, and
	 * the wiring is exactly what broke.
	 *
	 * Before the fix, cancel() revoked the token and deleted the schedule but
	 * never wrote chip_sub_status. Every admin surface and gate reads
	 * chip_sub_status, so a cancelled subscription kept rendering as Active and
	 * kept offering the "Send update-card link" action.
	 */
	public function test_cancel_writes_the_cancelled_state(): void {
		GF_Chip_Test_Meta::reset();

		$entry_id = 42;

		// A live, cancellable subscription.
		gform_update_meta( $entry_id, 'chip_payment_id', 'pay_123' );
		gform_update_meta( $entry_id, 'chip_sub_status', 'active' );
		gform_update_meta( $entry_id, 'chip_recurring_token', 'tok_live' );
		gform_update_meta( $entry_id, 'chip_sub_next_payment', '2026-10-01 00:00:00' );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', '2' );

		$entry = array(
			'id'                    => $entry_id,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'payment_status'        => 'Active',
			'chip_sub_status'       => 'active',
			'chip_recurring_token'  => 'tok_live',
			'chip_sub_next_payment' => '2026-10-01 00:00:00',
		);

		// CHIP accepts the token deletion.
		WP_Mock::userFunction( 'wp_remote_request' )
			->andReturn( array( 'body' => wp_json_encode( array( 'ok' => true ) ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing(
				function ( $r ) {
					return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
				}
			);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $tag, $value ) {
				return $value;
			}
		);

		// Reset the API singleton so the mock reaches a fresh instance.
		$ref  = new \ReflectionClass( GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$addon = $this->getMockBuilder( GF_Chip::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_credentials_for_feed' ) )
			->getMock();

		$addon->method( 'get_credentials_for_feed' )->willReturn(
			array(
				'secret_key' => 'sk',
				'brand_id'   => 'br',
			)
		);

		$result = $addon->cancel( $entry, array( 'meta' => array() ) );

		$this->assertTrue( $result, 'cancel() should succeed' );
		$this->assertSame(
			'cancelled',
			gform_get_meta( $entry_id, 'chip_sub_status' ),
			'cancel() must write chip_sub_status = cancelled'
		);
		$this->assertFalse( GF_Chip_Test_Meta::has( $entry_id, 'chip_recurring_token' ) );
		$this->assertFalse( GF_Chip_Test_Meta::has( $entry_id, 'chip_sub_next_payment' ) );
	}

	public function test_cancelled_subscription_is_never_due_for_renewal(): void {
		$entry = array(
			'id'                    => 42,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'payment_status'        => 'Cancelled',
			'chip_sub_status'       => 'cancelled',
			'chip_recurring_token'  => 'tok_still_there',
			'chip_sub_next_payment' => '2020-01-01 00:00:00',
		);

		$this->assertFalse(
			\GF_Chip_Renewals::is_due( $entry, '2026-09-13 00:00:00' ),
			'a cancelled subscription must never be due, even with a stale date and token'
		);
	}

	/**
	 * A cancelled subscription must not be offered the update-card link.
	 *
	 * The link is a capability: it replaces the card that would be charged.
	 * There is nothing to update once the subscription is cancelled.
	 */
	public function test_cancelled_subscription_is_not_offered_a_link(): void {
		$entry = array(
			'id'               => 42,
			'form_id'          => 1,
			'transaction_type' => '2',
			'payment_status'   => 'Cancelled',
			'chip_sub_status'  => 'cancelled',
		);

		$this->assertFalse(
			GF_Chip_Card_Update::can_offer_link( $entry ),
			'a cancelled subscription must not be offered a card-update link'
		);
	}

	public function test_cancel_is_refused_for_one_time_payment(): void {
		$entry = array(
			'transaction_type' => '1',
			'payment_status'   => 'Paid',
		);

		$this->assertFalse( GF_Chip::can_cancel_subscription( $entry ) );
	}
}
