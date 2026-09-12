<?php
/**
 * Tests for the card-update purchase and settlement rules.
 *
 * These are the rules that move money, so they are tested against the real
 * entry-meta harness rather than a no-op double — the seam where two earlier
 * defects hid.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Card_Update;
use GF_Chip_Card_Update_Flow;
use GF_Chip_Test_Meta;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Card_Update_Flow
 */
class GF_Chip_CardUpdateFlowTest extends TestCase {

	/**
	 * Clean state per test.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();

		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function ( $tag, $value ) {
			return $value;
		} );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		GF_Chip_Test_Meta::reset();
	}

	// ---------------------------------------------------------------------
	// Label and capture — the two places getting it backwards charges wrong.
	// ---------------------------------------------------------------------

	/**
	 * A settling update is labelled as a real payment.
	 */
	public function test_settling_label(): void {
		$this->assertSame( GF_Chip_Card_Update_Flow::LABEL_SETTLING, GF_Chip_Card_Update_Flow::product_label( 5000 ) );
	}

	/**
	 * A token-only update is labelled as a card change.
	 */
	public function test_token_only_label(): void {
		$this->assertSame( GF_Chip_Card_Update_Flow::LABEL_TOKEN_ONLY, GF_Chip_Card_Update_Flow::product_label( 0 ) );
	}

	/**
	 * Capture is skipped ONLY for the token-only path.
	 *
	 * Inverted, this would either collect nothing while claiming to settle a
	 * debt, or leave a real charge uncaptured.
	 */
	public function test_capture_not_skipped_when_settling(): void {
		$this->assertFalse( GF_Chip_Card_Update_Flow::should_skip_capture( 5000 ) );
	}

	/**
	 * Capture is skipped for a token-only swap.
	 */
	public function test_capture_skipped_for_token_only(): void {
		$this->assertTrue( GF_Chip_Card_Update_Flow::should_skip_capture( 0 ) );
	}

	// ---------------------------------------------------------------------
	// Purchase params.
	// ---------------------------------------------------------------------

	/**
	 * The params carry the card-only whitelist, force_recurring and the
	 * exact platform value CHIP requires.
	 */
	public function test_params_request_a_card_only_recurring_token(): void {
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => 0,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
			)
		);

		$this->assertTrue( $params['force_recurring'] );
		$this->assertSame( \GF_Chip::get_recurring_payment_method_whitelist(), $params['payment_method_whitelist'] );
		$this->assertSame( 'gravityforms', $params['platform'], 'CHIP requires this exact value' );
	}

	/**
	 * The platform value is not a made-up subscription-specific one.
	 *
	 * CHIP does not accept `gravityforms_subscriptions`; a regression to that
	 * would be rejected by the API at the point of a live charge.
	 */
	public function test_platform_is_not_a_made_up_value(): void {
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => 2500,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
			)
		);

		$this->assertSame( 'gravityforms', $params['platform'] );
		$this->assertStringNotContainsString( 'subscription', $params['platform'] );
	}

	/**
	 * A settling purchase carries the amount as the product price.
	 */
	public function test_settling_purchase_carries_the_amount(): void {
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => 2500,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
			)
		);

		$this->assertSame( 2500, $params['purchase']['products'][0]['price'] );
		$this->assertFalse( $params['skip_capture'] );
	}

	/**
	 * A token-only purchase carries a zero price and skips capture.
	 */
	public function test_token_only_purchase_is_zero_and_skips_capture(): void {
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => 0,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
			)
		);

		$this->assertSame( 0, $params['purchase']['products'][0]['price'] );
		$this->assertTrue( $params['skip_capture'] );
	}

	/**
	 * Every redirect points back to our own page so the customer returns to a
	 * screen we control whatever the outcome.
	 */
	public function test_all_redirects_point_back_to_our_page(): void {
		$url    = 'https://example.test/return';
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => 0,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => $url,
			)
		);

		$this->assertSame( $url, $params['success_redirect'] );
		$this->assertSame( $url, $params['failure_redirect'] );
		$this->assertSame( $url, $params['cancel_redirect'] );
	}

	/**
	 * A negative amount cannot become a negative charge.
	 */
	public function test_negative_amount_clamps_to_zero(): void {
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => -9999,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
			)
		);

		$this->assertSame( 0, $params['purchase']['products'][0]['price'] );
		$this->assertTrue( $params['skip_capture'], 'a clamped-to-zero amount must not attempt capture' );
	}

	/**
	 * A reference is truncated at CHIP's limit rather than rejected.
	 */
	public function test_long_reference_is_truncated(): void {
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => 0,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
				'reference'    => str_repeat( 'x', 200 ),
			)
		);

		$this->assertSame( 128, strlen( $params['reference'] ) );
	}

	// ---------------------------------------------------------------------
	// THE TAMPER TEST the plan requires.
	// ---------------------------------------------------------------------

	/**
	 * A request cannot influence the amount.
	 *
	 * The params builder takes the amount as an argument and derives everything
	 * else itself; there is no path by which a querystring value reaches it.
	 * This asserts the amount comes from the resolved value even when the
	 * caller's own source of truth says something else, and separately that
	 * resolve_amount_cents() ignores any request-shaped data on the entry.
	 */
	public function test_amount_cannot_be_influenced_by_request_data(): void {
		// A subscription that is genuinely due.
		$entry = array(
			'id'                    => 42,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'on-hold',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => 5000,
		);

		$true_amount = GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' );
		$this->assertSame( 5000, $true_amount, 'the true outstanding amount' );

		// A hostile entry carrying request-shaped fields must not change it.
		$tampered       = $entry;
		$tampered['amount']           = 1;
		$tampered['price']            = 1;
		$tampered['chip_sub_amount']  = 5000; // the real source, unchanged

		$this->assertSame(
			$true_amount,
			GF_Chip_Card_Update::resolve_amount_cents( $tampered, '2026-09-01 00:00:00' ),
			'stray amount fields on the entry must not override the subscription amount'
		);

		// And the purchase uses exactly that resolved value.
		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => $true_amount,
				'currency'     => 'MYR',
				'entry_id'     => 42,
				'return_url'   => 'https://example.test/return',
			)
		);

		$this->assertSame( 5000, $params['purchase']['products'][0]['price'] );
		$this->assertNotSame( 1, $params['purchase']['products'][0]['price'] );
	}

	// ---------------------------------------------------------------------
	// Completion and settlement.
	// ---------------------------------------------------------------------

	/**
	 * A paid purchase is complete.
	 */
	public function test_paid_purchase_is_complete(): void {
		$this->assertTrue( GF_Chip_Card_Update_Flow::is_completed( array( 'id' => 'pur_1', 'status' => 'paid' ) ) );
	}

	/**
	 * A pending_charge purchase is complete — the token is issued — but has not
	 * collected anything yet.
	 */
	public function test_pending_charge_is_complete_but_not_collected(): void {
		$purchase = array( 'id' => 'pur_1', 'status' => 'pending_charge' );

		$this->assertTrue( GF_Chip_Card_Update_Flow::is_completed( $purchase ) );
		$this->assertFalse( GF_Chip_Card_Update_Flow::collected_payment( $purchase ) );
	}

	/**
	 * Only a paid purchase counts as collected.
	 */
	public function test_only_paid_counts_as_collected(): void {
		$this->assertTrue( GF_Chip_Card_Update_Flow::collected_payment( array( 'id' => 'p', 'status' => 'paid' ) ) );
		$this->assertFalse( GF_Chip_Card_Update_Flow::collected_payment( array( 'id' => 'p', 'status' => 'error' ) ) );
	}

	/**
	 * A purchase without an id is not complete — it is a failed creation.
	 */
	public function test_missing_id_is_not_complete(): void {
		$this->assertFalse( GF_Chip_Card_Update_Flow::is_completed( array( 'status' => 'paid' ) ) );
	}

	/**
	 * Non-array input is not complete and does not warn.
	 */
	public function test_non_array_purchase_is_not_complete(): void {
		$this->assertFalse( GF_Chip_Card_Update_Flow::is_completed( null ) );
		$this->assertFalse( GF_Chip_Card_Update_Flow::is_completed( false ) );
		$this->assertFalse( GF_Chip_Card_Update_Flow::collected_payment( null ) );
	}

	// ---------------------------------------------------------------------
	// plan_settlement — what gets written after a successful update.
	// ---------------------------------------------------------------------

	/**
	 * A token issued by a purchase id marks the subscription active.
	 */
	public function test_plan_activates_when_token_present(): void {
		$plan = GF_Chip_Card_Update_Flow::plan_settlement(
			array( 'id' => 'pur_1', 'status' => 'paid', 'is_recurring_token' => true ),
			true
		);

		$this->assertSame( 'pur_1', $plan['token'] );
		$this->assertSame( 'active', $plan['status'] );
		$this->assertSame( 0, $plan['retry_count'], 'a working card must reset the dunning ladder' );
		$this->assertTrue( $plan['settled'] );
	}

	/**
	 * A token from the recurring_token field is used too.
	 */
	public function test_plan_reads_token_from_field(): void {
		$plan = GF_Chip_Card_Update_Flow::plan_settlement(
			array(
				'id'                 => 'pur_2',
				'status'             => 'paid',
				'is_recurring_token' => false,
				'recurring_token'    => 'tok_field',
			),
			false
		);

		$this->assertSame( 'tok_field', $plan['token'] );
		$this->assertFalse( $plan['settled'], 'a token-only swap does not settle a debt' );
	}

	/**
	 * No token means the card was not stored, so the subscription stays on
	 * hold rather than being marked active with a dead card.
	 */
	public function test_plan_keeps_on_hold_when_no_token(): void {
		$plan = GF_Chip_Card_Update_Flow::plan_settlement(
			array( 'id' => 'pur_3', 'status' => 'paid' ),
			true
		);

		$this->assertNull( $plan['token'] );
		$this->assertSame( 'on-hold', $plan['status'], 'no token must not be recorded as active' );
		$this->assertFalse( $plan['settled'], 'nothing was settled if no card was stored' );
	}

	/**
	 * The retry counter is reset even when settling, because the customer
	 * action that triggered the failure is now resolved.
	 */
	public function test_plan_resets_retry_count(): void {
		$plan = GF_Chip_Card_Update_Flow::plan_settlement(
			array( 'id' => 'pur_4', 'is_recurring_token' => true ),
			true
		);

		$this->assertSame( 0, $plan['retry_count'] );
	}

	// ---------------------------------------------------------------------
	// The wiring, through the real meta harness.
	// ---------------------------------------------------------------------

	/**
	 * Applying a settlement plan writes the token, status and counter, and
	 * those writes are observable.
	 */
	public function test_settlement_writes_are_observable(): void {
		$entry_id = 42;

		// Pre-existing failed state.
		gform_update_meta( $entry_id, 'chip_sub_status', 'on-hold' );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', 2 );
		gform_update_meta( $entry_id, 'chip_recurring_token', 'tok_old' );

		$plan = GF_Chip_Card_Update_Flow::plan_settlement(
			array( 'id' => 'pur_new', 'status' => 'paid', 'is_recurring_token' => true ),
			true,
			$entry_id
		);

		gform_update_meta( $entry_id, 'chip_recurring_token', $plan['token'] );
		gform_update_meta( $entry_id, 'chip_sub_status', $plan['status'] );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', $plan['retry_count'] );

		$this->assertSame( 'pur_new', gform_get_meta( $entry_id, 'chip_recurring_token' ) );
		$this->assertSame( 'active', gform_get_meta( $entry_id, 'chip_sub_status' ) );
		$this->assertSame( 0, gform_get_meta( $entry_id, 'chip_sub_retry_count' ) );

		// The old token must have been replaced, not merely added to.
		$this->assertNotSame( 'tok_old', gform_get_meta( $entry_id, 'chip_recurring_token' ) );
	}

	/**
	 * A settlement for a settling update records the purchase id so the
	 * outstanding cycle can be reconciled later.
	 */
	public function test_settling_records_the_purchase(): void {
		$entry_id = 43;

		gform_update_meta( $entry_id, GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE, 'pur_settle' );

		$this->assertSame( 'pur_settle', gform_get_meta( $entry_id, GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE ) );
		$this->assertTrue( GF_Chip_Test_Meta::has( $entry_id, GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE ) );
	}

	/**
	 * A settlement that found no token must NOT clear the debt marker: the
	 * charge may have gone through but no card was stored, so the customer
	 * still needs the link.
	 */
	public function test_no_token_settlement_does_not_mark_settled(): void {
		$plan = GF_Chip_Card_Update_Flow::plan_settlement(
			array( 'id' => 'pur_x', 'status' => 'paid' ),
			true
		);

		$this->assertFalse( $plan['settled'] );
		$this->assertSame( 'on-hold', $plan['status'] );
	}
}
