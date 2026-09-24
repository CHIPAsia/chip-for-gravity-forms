<?php
/**
 * Wiring tests for the FIRST payment on a subscription feed.
 *
 * These exist because of a specific defect: the plugin never activated a
 * subscription after the first payment through its redirect flow. Gravity
 * Forms only calls process_subscription()/start_subscription() when an
 * authorization was performed, and a hosted-redirect gateway performs none —
 * so $this->authorization stays empty, that branch is skipped, and core's
 * complete_payment() writes transaction_type='1' unconditionally.
 *
 * The result was an entry that IS a subscription feed's purchase but is
 * recorded as a one-time payment, with no chip_sub_status and no
 * chip_sub_next_payment. The renewal cron selects on chip_sub_next_payment,
 * so it could never charge it; and is_subscription_entry() returned false, so
 * the admin page, the Cancel button and the card-update link were all
 * unreachable.
 *
 * Every test below FAILS against the code before the fix. That is the point:
 * the existing suite exercised extract_recurring_token() and
 * persist_subscription_token() in isolation and never drove complete_payment()
 * end to end, which is why a green suite shipped this.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use GF_CHIP_API;
use GFAPI;
use GF_Chip_Test_Feed;
use GF_Chip_Test_Meta;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Covers activation of a subscription on its first paid callback.
 *
 * @covers \GF_Chip::complete_payment
 * @covers \GF_Chip::activate_subscription_on_first_payment
 */
class GF_Chip_SubscriptionActivationTest extends TestCase {

	/**
	 * Entry id used throughout.
	 *
	 * @var int
	 */
	private $entry_id = 501;

	/**
	 * WP_Mock and a clean meta store per test.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();
		GFAPI::reset();

		// Fresh API singleton so the mocked HTTP layer is reached.
		$ref  = new \ReflectionClass( GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		WP_Mock::userFunction( '__' )->andReturnUsing(
			function ( $t, $d = null ) {
				return $t;
			}
		);
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing(
			function ( $t, $d = null ) {
				return $t;
			}
		);
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $tag, $value ) {
				return $value;
			}
		);
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			function ( $r ) {
				return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();
		GFAPI::reset();
		parent::tearDown();
	}

	/**
	 * Stages the CHIP purchase response the callback fetches.
	 *
	 * The purchase is what carries the recurring token, so it must be the
	 * shape the plugin's own extractor understands.
	 *
	 * @param bool $with_token Whether CHIP issued a recurring token.
	 * @return void
	 */
	private function stage_chip_purchase( $with_token = true ) {
		$purchase = array(
			'id'     => 'pay_first',
			'status' => 'paid',
		);

		if ( $with_token ) {
			$purchase['is_recurring_token'] = true;
		}

		WP_Mock::userFunction( 'wp_remote_request' )->andReturn(
			array( 'body' => wp_json_encode( $purchase ) )
		);
	}

	/**
	 * A GF_Chip whose credentials are supplied so no real settings are read.
	 *
	 * @return GF_Chip
	 */
	private function make_addon() {
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

		return $addon;
	}

	/**
	 * Stage the state a subscription entry is in when the first payment
	 * callback arrives.
	 *
	 * Mirrors what core's entry_post_save() leaves for a redirect gateway:
	 * transaction_type 2 and payment_status Processing, with the payment id
	 * stored by redirect_url().
	 *
	 * @param int $remaining Feed's recurringTimes, 0 for unlimited.
	 * @return array The entry array.
	 */
	private function stage_first_payment( $remaining = 3 ) {
		$entry_id = $this->entry_id;

		gform_update_meta( $entry_id, 'chip_payment_id', 'pay_first', 1 );

		GF_Chip_Test_Feed::set(
			array(
				'id'    => 1,
				'form_id' => 1,
				'meta'  => array(
					'transactionType'     => 'subscription',
					'billingCycle_length' => '1',
					'billingCycle_unit'   => 'month',
					'recurringTimes'      => (string) $remaining,
					'recurringAmount'     => 'form_total',
				),
			)
		);

		return array(
			'id'               => $entry_id,
			'form_id'          => 1,
			'transaction_type' => '2',
			'payment_status'   => 'Processing',
			'currency'         => 'MYR',
			'payment_amount'   => '2.00',
		);
	}

	/**
	 * The regression that matters most: after the first paid callback the entry
	 * must still be a subscription and must have a renewal scheduled.
	 *
	 * Before the fix: transaction_type was rewritten to '1' by core's
	 * complete_payment(), chip_sub_status was empty and chip_sub_next_payment
	 * was empty — so the cron could never pick this subscription up.
	 */
	public function test_first_paid_callback_activates_the_subscription(): void {
		$entry = $this->stage_first_payment();

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$this->assertSame(
			'active',
			gform_get_meta( $this->entry_id, 'chip_sub_status' ),
			'a paid subscription must be marked active, or the cron will not charge it'
		);

		$next = gform_get_meta( $this->entry_id, 'chip_sub_next_payment' );

		$this->assertNotSame(
			'',
			(string) $next,
			'the first renewal must be scheduled — the cron selects on this field'
		);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			(string) $next,
			'the scheduled date must be a real UTC datetime'
		);
	}

	/**
	 * The scheduled date must be one billing cycle ahead, not "now".
	 *
	 * A subscription billed monthly must next be due about a month out. Writing
	 * `now` would make it immediately due and charge the customer again on the
	 * next cron run.
	 */
	public function test_first_renewal_is_one_cycle_ahead_not_immediately_due(): void {
		$entry = $this->stage_first_payment();

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$next = (string) gform_get_meta( $this->entry_id, 'chip_sub_next_payment' );

		$next_ts = strtotime( $next . ' UTC' );
		$in_20d  = strtotime( '+20 days' );
		$in_45d  = strtotime( '+45 days' );

		$this->assertGreaterThan( $in_20d, $next_ts, 'a monthly plan must not be due again this week' );
		$this->assertLessThan( $in_45d, $next_ts, 'a monthly plan must not be scheduled months out' );
	}

	/**
	 * A subscription is only chargeable if the cron considers it a
	 * subscription. Core's complete_payment() rewrites transaction_type to
	 * '1', and start_subscription() must put it back to '2'.
	 *
	 * Asserted against the stored entry, not the caller's array: core
	 * updates the entry table and does not propagate the array back through
	 * post_payment_action(), so the DB is what the admin UI and the cron
	 * actually read.
	 */
	public function test_entry_stays_a_subscription_after_the_callback(): void {
		$entry = $this->stage_first_payment();

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$stored = GFAPI::get_entry( $this->entry_id );

		$this->assertSame(
			'2',
			(string) rgar( $stored, 'transaction_type' ),
			'a subscription feed purchase must be stored as transaction_type 2'
		);
		$this->assertSame(
			'Active',
			(string) rgar( $stored, 'payment_status' ),
			'core gates the Cancel Subscription button on payment_status Active'
		);
		$this->assertSame(
			'pay_first',
			(string) rgar( $stored, 'transaction_id' ),
			'transaction_id must stay the CHIP purchase id, which the refund path needs'
		);
	}

	/**
	 * The amount the subscription was set up with must be stored, because the
	 * card-update flow reads it to settle an outstanding cycle.
	 *
	 * Before the fix nothing ever WROTE chip_sub_amount, so
	 * resolve_amount_cents() always fell through to its 0 fallback and a
	 * settling card update would have charged the customer nothing.
	 */
	public function test_the_subscription_amount_is_stored_for_later_settlement(): void {
		$entry = $this->stage_first_payment();

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$stored = gform_get_meta( $this->entry_id, 'chip_sub_amount' );

		$this->assertNotSame(
			'',
			(string) $stored,
			'the recurring amount must be stored, or a settling card update charges 0'
		);
		$this->assertSame(
			200,
			(int) $stored,
			'the amount is stored in the smallest currency unit'
		);
	}

	/**
	 * A one-time payment must be left completely alone.
	 *
	 * The activation must not fire for a product feed — no subscription meta,
	 * no schedule. This is the guard that keeps the fix from changing
	 * one-time behaviour.
	 */
	public function test_one_time_payment_is_not_turned_into_a_subscription(): void {
		$entry_id = $this->entry_id;

		gform_update_meta( $entry_id, 'chip_payment_id', 'pay_once', 1 );

		GF_Chip_Test_Feed::set(
			array(
				'id'      => 1,
				'form_id' => 1,
				'meta'    => array(
					'transactionType' => 'product',
					'paymentAmount'   => 'form_total',
				),
			)
		);

		$entry = array(
			'id'               => $entry_id,
			'form_id'          => 1,
			'transaction_type' => '1',
			'payment_status'   => 'Processing',
			'currency'         => 'MYR',
		);

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_once',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_once',
			'entry_id'       => $entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$this->assertSame( '', (string) gform_get_meta( $entry_id, 'chip_sub_status' ), 'a product feed must not gain subscription state' );
		$this->assertSame( '', (string) gform_get_meta( $entry_id, 'chip_sub_next_payment' ), 'a product feed must not be scheduled' );
		$this->assertFalse( GF_Chip_Test_Meta::has( $entry_id, 'chip_sub_amount' ), 'a product feed has no recurring amount' );
	}

	/**
	 * A limited plan stores the remaining count so the plan can end.
	 *
	 * resolve_remaining() prefers the stored value; if the activation does not
	 * write it, the feed is the only source and a 3-installment plan would
	 * charge forever.
	 */
	public function test_a_limited_plan_records_its_remaining_instalments(): void {
		$entry = $this->stage_first_payment( 3 );

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$remaining = gform_get_meta( $this->entry_id, 'chip_sub_remaining' );

		$this->assertNotSame( '', (string) $remaining, 'a limited plan must store its counter' );
		$this->assertSame( 2, (int) $remaining, 'one of three instalments is consumed by the first payment' );
	}

	/**
	 * An unlimited plan must store 0, not '' — 0 is the "no end date" marker
	 * that resolve_remaining() keys on.
	 */
	public function test_an_unlimited_plan_stores_zero_not_empty(): void {
		$entry = $this->stage_first_payment( 0 );

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		$this->assertTrue(
			GF_Chip_Test_Meta::has( $this->entry_id, 'chip_sub_remaining' ),
			'an unlimited plan must store its marker explicitly'
		);
		$this->assertSame( 0, (int) gform_get_meta( $this->entry_id, 'chip_sub_remaining' ) );
	}

	/**
	 * The whole point: after activation, the renewal engine must find the
	 * subscription chargeable. This is the end-to-end assertion the old suite
	 * was missing.
	 */
	public function test_the_cron_would_charge_the_subscription_after_activation(): void {
		$entry = $this->stage_first_payment();

		$this->stage_chip_purchase();

		$addon = $this->make_addon();
		$addon->complete_payment( $entry, array(
			'id'             => 'pay_first',
			'type'           => 'complete_payment',
			'transaction_id' => 'pay_first',
			'entry_id'       => $this->entry_id,
			'payment_method' => 'visa',
			'amount'         => '2.00',
		) );

		// Simulate that CHIP issued the recurring token with the purchase.
		gform_update_meta( $this->entry_id, 'chip_recurring_token', 'tok_live', 1 );

		// Bring the schedule forward so the engine sees it as due.
		gform_update_meta( $this->entry_id, 'chip_sub_next_payment', gmdate( 'Y-m-d H:i:s', time() - 3600 ), 1 );

		$hydrated = array(
			'id'                    => $this->entry_id,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => gform_get_meta( $this->entry_id, 'chip_sub_status' ),
			'chip_sub_next_payment' => gform_get_meta( $this->entry_id, 'chip_sub_next_payment' ),
			'chip_recurring_token'  => gform_get_meta( $this->entry_id, 'chip_recurring_token' ),
		);

		$this->assertTrue(
			GF_Chip::is_subscription_entry( $hydrated ),
			'the entry must still be recognised as a subscription'
		);

		$this->assertTrue(
			\GF_Chip_Renewals::is_due( $hydrated, gmdate( 'Y-m-d H:i:s' ) ),
			'the renewal engine must consider an activated subscription chargeable'
		);
	}
}
