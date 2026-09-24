<?php
/**
 * Regression tests for the amount collected on a subscription's first charge.
 *
 * Gravity Forms reports three separate numbers and never folds them together:
 * `payment_amount` (the form total / recurring value), `trial` (what to
 * collect first when a trial is configured) and `setup_fee` (collected on top
 * of whichever applies). Reading `payment_amount` alone therefore:
 *
 *  - charges full price during a trial, and
 *  - never collects a setup fee.
 *
 * Two rules govern what is sent to CHIP, and the second one is the subtle one:
 *
 *  1. the first charge is `trial + setup_fee` for a subscription, and the
 *     form total for a one-time feed;
 *  2. `skip_capture` is set ONLY when that total is zero. `skip_capture`
 *     means "authorise without taking the money", so setting it while an
 *     amount is owed reserves funds on the customer's card and leaves the
 *     merchant unpaid. A trial WITH a setup fee is exactly that case: the
 *     trial amount is zero but the fee is payable now, so capture must
 *     proceed.
 *
 * Rule 2 also protects the reverse: a genuinely free trial must NOT be
 * captured, or the customer is charged a full cycle they were promised free.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::resolve_first_charge_cents
 *
 * @covers \GF_Chip::should_skip_first_capture
 *
 * @covers \GF_Chip::resolve_first_charge_label
 */
class GF_Chip_FirstChargeTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();

		WP_Mock::userFunction( '__' )->andReturnUsing(
			function ( $text, $domain = null ) {
				return $text;
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
	 * Submission data for a subscription feed.
	 *
	 * Mirrors what core's get_order_data() returns for a subscription: all
	 * three keys are always present (core returns an array literal), so a
	 * zero trial is indistinguishable from an absent one by value alone.
	 *
	 * @param float $payment_amount Form total.
	 * @param float $trial          Trial amount, 0 when no trial is configured.
	 * @param float $setup_fee      Setup fee, 0 when none is configured.
	 * @return array
	 */
	private function submission( $payment_amount, $trial = 0, $setup_fee = 0, $has_trial = false ) {
		return array(
			'is_subscription' => true,
			'payment_amount'  => $payment_amount,
			'trial'           => $trial,
			'setup_fee'       => $setup_fee,
			'has_trial'       => $has_trial,
			'line_items'      => array(),
		);
	}

	/**
	 * Resolve using the same feed-derived flag production passes.
	 *
	 * @param array $submission Submission data.
	 * @return int
	 */
	private function resolve( $submission ) {
		return GF_Chip::resolve_first_charge_cents( $submission, (bool) rgar( $submission, 'has_trial' ) );
	}

	/**
	 * Resolve the product label the same way production does, flag included.
	 *
	 * @param array  $submission      Submission data.
	 * @param string $recurring_label The subscription's own label.
	 * @return string
	 */
	private function label( $submission, $recurring_label = 'Gold Plan' ) {
		return GF_Chip::resolve_first_charge_label(
			$submission,
			$recurring_label,
			self::resolve( $submission ),
			(bool) rgar( $submission, 'has_trial' )
		);
	}

	// ---------------------------------------------------------------------
	// Resolving the first charge.
	// ---------------------------------------------------------------------

	/**
	 * With neither a trial nor a setup fee, the first charge is the form
	 * total. This is the path every existing subscription already takes and
	 * it must not move.
	 */
	public function test_plain_subscription_charges_the_form_total(): void {
		$this->assertSame( 1000, self::resolve( $this->submission( 10.0 ) ) );
	}

	/**
	 * A free trial collects nothing.
	 */
	public function test_free_trial_charges_nothing(): void {
		$this->assertSame( 0, self::resolve( $this->submission( 10.0, 0.0, 0, true ) ) );
	}

	/**
	 * A trial with an amount collects that amount, not the form total.
	 *
	 * The defect: the form total was charged, so a customer promised a RM2
	 * trial was billed RM10.
	 */
	public function test_trial_amount_replaces_the_form_total(): void {
		$this->assertSame( 200, self::resolve( $this->submission( 10.0, 2.0, 0, true ) ) );
	}

	/**
	 * A setup fee is collected on top of the form total.
	 *
	 * The second half of the defect: setup_fee was never read at all, so the
	 * merchant was simply not paid it.
	 */
	public function test_setup_fee_is_charged_on_top_of_the_form_total(): void {
		$this->assertSame( 1500, self::resolve( $this->submission( 10.0, 0, 5.0 ) ) );
	}

	/**
	 * A setup fee is collected on top of a paid trial.
	 */
	public function test_setup_fee_is_charged_on_top_of_a_paid_trial(): void {
		$this->assertSame( 700, self::resolve( $this->submission( 10.0, 2.0, 5.0, true ) ) );
	}

	/**
	 * A free trial with a setup fee collects the setup fee.
	 *
	 * This is the case the whole design turns on: the trial is free but money
	 * is owed now, so the first charge is the fee — NOT zero.
	 */
	public function test_free_trial_with_setup_fee_charges_the_fee(): void {
		$this->assertSame( 500, self::resolve( $this->submission( 10.0, 0.0, 5.0, true ) ) );
	}

	/**
	 * The amount is in the smallest currency unit.
	 *
	 * RM 12.34 must travel as 1234; sending 12 would bill 12 sen.
	 */
	public function test_amount_is_converted_to_the_smallest_unit(): void {
		$this->assertSame( 1234, self::resolve( $this->submission( 12.34 ) ) );
		$this->assertSame( 1234, self::resolve( $this->submission( 10.0, 7.34, 5.0, true ) ) );
	}

	// ---------------------------------------------------------------------
	// One-time feeds must be untouched.
	// ---------------------------------------------------------------------

	/**
	 * A one-time feed charges its form total and ignores trial/setup keys.
	 */
	public function test_one_time_feed_charges_the_form_total(): void {
		$this->assertSame(
			1000,
			GF_Chip::resolve_first_charge_cents(
				array(
					'payment_amount' => 10.0,
					'trial'          => 5.0,
					'setup_fee'      => 5.0,
				)
			)
		);
	}

	/**
	 * A one-time feed with no trial/setup keys still resolves its total.
	 */
	public function test_one_time_feed_without_trial_or_fee_keys(): void {
		$this->assertSame( 2500, GF_Chip::resolve_first_charge_cents( array( 'payment_amount' => 25.0 ) ) );
	}

	// ---------------------------------------------------------------------
	// skip_capture: only for a genuinely free first charge.
	// ---------------------------------------------------------------------

	/**
	 * A free trial skips capture so the card is only authorised.
	 */
	public function test_free_trial_skips_capture(): void {
		$cents = self::resolve( $this->submission( 10.0, 0.0, 0, true ) );

		$this->assertTrue( GF_Chip::should_skip_first_capture( $cents ) );
	}

	/**
	 * A trial WITH a setup fee must NOT skip capture.
	 *
	 * The merchant is owed the fee. Skipping capture would reserve it on the
	 * customer's card and leave the merchant unpaid.
	 */
	public function test_free_trial_with_setup_fee_does_not_skip_capture(): void {
		$cents = self::resolve( $this->submission( 10.0, 0.0, 5.0, true ) );

		$this->assertSame( 500, $cents );
		$this->assertFalse(
			GF_Chip::should_skip_first_capture( $cents ),
			'A setup fee is payable now, so capture must proceed'
		);
	}

	/**
	 * An ordinary subscription does not skip capture.
	 */
	public function test_plain_subscription_does_not_skip_capture(): void {
		$cents = self::resolve( $this->submission( 10.0 ) );

		$this->assertFalse( GF_Chip::should_skip_first_capture( $cents ) );
	}

	/**
	 * A paid trial does not skip capture.
	 */
	public function test_paid_trial_does_not_skip_capture(): void {
		$cents = self::resolve( $this->submission( 10.0, 2.0, 0, true ) );

		$this->assertFalse( GF_Chip::should_skip_first_capture( $cents ) );
	}

	/**
	 * The capture decision follows the money, never the presence of a trial.
	 *
	 * Asserted as a property across every shape: skip_capture must be true
	 * exactly when the resolved charge is zero. Any other rule would either
	 * hold funds for a free trial or leave a payable first charge uncollected.
	 */
	public function test_capture_is_skipped_exactly_when_nothing_is_owed(): void {
		$shapes = array(
			'no trial, no fee'           => array( 10.0, 0, 0, false ),
			'free trial'                 => array( 10.0, 0.0, 0, true ),
			'free trial + fee'           => array( 10.0, 0.0, 5.0, true ),
			'paid trial'                 => array( 10.0, 2.0, 0, true ),
			'paid trial + fee'           => array( 10.0, 2.0, 5.0, true ),
			'fee only, zero-priced plan' => array( 0.0, 0.0, 5.0, true ),
			'zero-priced, nothing else'  => array( 0.0, 0.0, 0, true ),
		);

		foreach ( $shapes as $label => $values ) {
			$cents = self::resolve( $this->submission( $values[0], $values[1], $values[2], $values[3] ) );

			$this->assertSame(
				$cents <= 0,
				GF_Chip::should_skip_first_capture( $cents ),
				"Shape '{$label}' decided capture inconsistently with its amount ({$cents} cents)"
			);
		}
	}

	// ---------------------------------------------------------------------
	// The product name sent with the charge.
	// ---------------------------------------------------------------------

	/**
	 * An ordinary subscription keeps its own name.
	 */
	public function test_ordinary_subscription_keeps_the_recurring_label(): void {
		$sub = $this->submission( 10.0 );

		$this->assertSame(
			'Gold Plan',
			self::label( $sub )
		);
	}

	/**
	 * A setup fee is the charge, so it is what the customer sees named.
	 */
	public function test_setup_fee_charges_are_labelled_as_such(): void {
		$sub = $this->submission( 10.0, 0.0, 5.0, true );

		$this->assertSame(
			'Setup fee',
			self::label( $sub )
		);
	}

	/**
	 * A free trial still needs a product name — a blank one is rejected by
	 * CHIP with HTTP 400.
	 */
	public function test_free_trial_is_labelled_and_never_blank(): void {
		$sub   = $this->submission( 10.0, 0.0, 0, true );
		$label = self::label( $sub );

		$this->assertSame( 'Free trial', $label );
		$this->assertNotSame( '', $label );
	}

	/**
	 * A paid trial keeps the subscription's name: the customer is paying for
	 * the subscription, at a trial price.
	 */
	public function test_paid_trial_keeps_the_recurring_label(): void {
		$sub = $this->submission( 10.0, 2.0, 0, true );

		$this->assertSame(
			'Gold Plan',
			self::label( $sub )
		);
	}

	/**
	 * No shape may ever produce a blank product name.
	 *
	 * CHIP rejects a blank name with HTTP 400, which is what made the
	 * subscription flow fail entirely before the amount field was fixed.
	 */
	public function test_no_shape_produces_a_blank_label(): void {
		$shapes = array(
			array( 10.0, 0, 0, false ),
			array( 10.0, 0.0, 0, true ),
			array( 10.0, 0.0, 5.0, true ),
			array( 10.0, 2.0, 0, true ),
			array( 10.0, 2.0, 5.0, true ),
			array( 0.0, 0.0, 0, true ),
		);

		foreach ( $shapes as $values ) {
			$sub   = $this->submission( $values[0], $values[1], $values[2], $values[3] );
			$label = self::label( $sub );

			$this->assertNotSame( '', $label, 'A blank product name is rejected by CHIP with HTTP 400' );
		}
	}
}
