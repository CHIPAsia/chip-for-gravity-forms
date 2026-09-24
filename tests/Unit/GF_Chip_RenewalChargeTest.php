<?php
/**
 * Regression tests for the renewal charge.
 *
 * The defect: the renewal engine charged the ORIGINAL purchase over and over.
 * CHIP only charges a purchase that can still be paid, and the original was
 * settled on the first cycle, so every renewal answered
 *
 *   400 purchase_charge_wrong_status
 *   "Only purchases that can be paid for can be charged."
 *
 * The entry went on-hold and the subscription could never collect again.
 * WooCommerce avoids this by creating a NEW purchase per cycle and charging
 * that one with the saved token, which is what these tests pin down.
 *
 * Every assertion here is on an observable the defect would change: which
 * purchase id the charge was addressed to, whether a purchase was created at
 * all, and whether the schedule was touched. A test that merely re-checked the
 * amount would pass on the broken code too.
 *
 * Dates are computed relative to now rather than written literally, so these
 * tests stay correct as time passes and cannot depend on a stale calendar.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use GF_Chip_Test_Feed;
use GF_Chip_Test_Meta;
use GF_Chip_Test_Submission;
use GF_Chip_Test_WPDB;
use GFAPI;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::charge_renewal
 * @covers \GF_Chip::build_renewal_purchase_params
 * @covers \GF_Chip::resolve_renewal_amount_cents
 */
class GF_Chip_RenewalChargeTest extends TestCase {

	/**
	 * Every HTTP request the code under test made, in order.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Set up WP_Mock and clear the staged stores.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();
		GF_Chip_Test_Submission::reset();
		GF_Chip_Test_WPDB::reset();
		$this->requests = array();
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * A renewal must address its charge at a NEW purchase, never the original.
	 *
	 * The recorded request URL is the observable: it carries the purchase id
	 * the charge was created against. With the defect the URL holds the
	 * original (paid) purchase id, which CHIP rejects.
	 */
	public function test_a_renewal_creates_a_purchase_and_charges_that_one(): void {
		$entry_id = 71;

		$this->stage_due_subscription( $entry_id );
		$this->mock_transport();

		$addon = $this->make_addon();
		$result = $addon->charge_renewal( $this->entry( $entry_id ) );

		$this->assertSame( 'charged', rgar( $result, 'status' ), 'the renewal must be reported as charged' );

		$charge_urls = $this->urls_matching( '/charge/' );

		$this->assertCount( 1, $charge_urls, 'exactly one charge attempt is expected' );

		// The distinguishing assertion. The original purchase was settled on
		// the first cycle; charging it is what CHIP rejects outright.
		$this->assertStringContainsString(
			'pay_new',
			$charge_urls[0],
			'the charge must be addressed at the purchase created for this cycle'
		);

		$this->assertStringNotContainsString(
			'pay_original',
			$charge_urls[0],
			'the original, already-settled purchase must never be charged again'
		);

		// It must also have created that purchase first — charging a purchase
		// that was never created would 404.
		$this->assertCount(
			1,
			$this->create_urls(),
			'a purchase must be created for the cycle before it is charged'
		);
	}

	/**
	 * The charged amount must be the agreed recurring amount.
	 *
	 * `chip_sub_amount` is what the subscription was set up with, and it wins
	 * over anything the form says now.
	 */
	public function test_the_renewal_charges_the_agreed_recurring_amount(): void {
		$entry_id = 72;

		$this->stage_due_subscription( $entry_id, true, '5000' );
		$this->mock_transport();

		$addon = $this->make_addon();
		$addon->charge_renewal( $this->entry( $entry_id ) );

		$created = $this->body_of_create_call();

		$this->assertNotNull( $created, 'a purchase must be created for the cycle' );

		$this->assertSame(
			5000,
			(int) rgar( $created['purchase']['products'][0], 'price' ),
			'the charge must use the amount the customer agreed to pay each cycle'
		);
	}

	/**
	 * A trial subscription has no stored first-charge amount.
	 *
	 * Its first cycle legitimately collected zero, so `chip_sub_amount` cannot
	 * have been written from it. The renewal must still charge the feed's
	 * recurring amount — falling back to the entry's last payment (zero) would
	 * skip every cycle forever.
	 */
	public function test_a_renewal_uses_the_feed_amount_when_nothing_was_stored(): void {
		$entry_id = 73;

		$this->stage_due_subscription( $entry_id, false );
		$this->mock_transport();

		$addon = $this->make_addon();
		$addon->charge_renewal( $this->entry( $entry_id ) );

		$created = $this->body_of_create_call();

		$this->assertNotNull( $created, 'a trial subscription must still renew' );

		$this->assertSame(
			5000,
			(int) rgar( $created['purchase']['products'][0], 'price' ),
			'a renewal with no stored amount must use the feed recurring amount, not the zero first charge'
		);
	}

	/**
	 * A subscription with no resolvable amount is skipped, not charged at zero.
	 *
	 * The schedule must also be left alone: burning an installment on a
	 * configuration problem would shorten the plan silently.
	 */
	public function test_a_renewal_with_no_amount_is_skipped_without_consuming_a_cycle(): void {
		$entry_id = 74;

		// No stored amount and a feed that resolves to nothing either.
		$this->stage_due_subscription( $entry_id, false );
		GF_Chip_Test_Submission::set( array() );
		$this->mock_transport();

		$addon = $this->make_addon();
		$result = $addon->charge_renewal( $this->entry( $entry_id ) );

		$this->assertSame( 'skipped', rgar( $result, 'status' ) );
		$this->assertSame(
			0,
			count( $this->create_urls() ),
			'nothing must be sent to CHIP when no amount can be resolved'
		);

		$this->assertSame(
			'3',
			(string) gform_get_meta( $entry_id, 'chip_sub_remaining' ),
			'a refusal to charge must not consume an installment'
		);
	}

	/**
	 * A collected renewal must repoint the entry at the purchase that holds
	 * the money, so the refund button targets the right one.
	 *
	 * `chip_payment_id` must NOT be repointed: it is the purchase the token
	 * was issued against, and the card-update flow deletes the old token with
	 * it.
	 */
	public function test_a_collected_renewal_moves_the_transaction_id_but_keeps_the_token_owner(): void {
		$entry_id = 75;

		$this->stage_due_subscription( $entry_id );
		$this->mock_transport();

		$addon = $this->make_addon();
		$addon->charge_renewal( $this->entry( $entry_id ) );

		$this->assertSame(
			'pay_new',
			rgar( GFAPI::get_entry( $entry_id ), 'transaction_id' ),
			'the refund target must move to the purchase that collected this cycle'
		);

		$this->assertSame(
			'pay_original',
			(string) gform_get_meta( $entry_id, 'chip_payment_id' ),
			'the token owner must not move, or the old token cannot be revoked'
		);
	}

	/**
	 * Charges that do not settle immediately must not consume a dunning slot.
	 *
	 * A pending charge is settled by its callback, so counting it as a failure
	 * would email the customer about a payment that is still in flight.
	 */
	public function test_a_pending_charge_does_not_consume_a_dunning_slot(): void {
		$entry_id = 76;

		$this->stage_due_subscription( $entry_id );
		$this->mock_transport( 'pending_charge' );

		$addon = $this->make_addon();
		$addon->charge_renewal( $this->entry( $entry_id ) );

		$this->assertSame(
			'0',
			(string) gform_get_meta( $entry_id, 'chip_sub_retry_count' ),
			'an in-flight charge must not count as a failed attempt'
		);
	}

	/**
	 * The cron's own wiring must carry the agreed amount into the charge.
	 *
	 * This drives `check_status()` — the real cron handler — rather than
	 * handing charge_renewal() a pre-built entry. The cron builds that entry
	 * itself by flattening entry meta, so a missing key there would silently
	 * reduce the charge to the feed fallback and a test that staged the array
	 * by hand could never notice.
	 */
	public function test_the_cron_flattens_the_agreed_amount_into_the_entry(): void {
		$entry_id = 77;

		$this->stage_due_subscription( $entry_id, true, '1250' );
		$this->mock_transport();

		GF_Chip_Test_WPDB::set_due_ids( array( $entry_id ) );

		$addon = $this->make_addon();
		$addon->check_status();

		$created = $this->body_of_create_call();

		$this->assertNotNull( $created, 'the cron must charge a due subscription' );

		// 1250 is what the subscription was set up with; the feed says 5000.
		// Only an entry carrying the stored amount can produce 1250 here.
		$this->assertSame(
			1250,
			(int) rgar( $created['purchase']['products'][0], 'price' ),
			'the cron must pass the agreed amount through, not fall back to the feed'
		);
	}

	// -----------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------

	/**
	 * Stages a due subscription entry as the cron path would see it.
	 *
	 * @param int         $entry_id      Entry id.
	 * @param bool        $store_amount  Whether the agreed amount was recorded.
	 * @param string|null $stored_amount The stored amount, when recorded.
	 * @return void
	 */
	private function stage_due_subscription( $entry_id, $store_amount = true, $stored_amount = '5000' ) {
		// A date in the past, computed rather than written literally.
		$due = gmdate( 'Y-m-d H:i:s', time() - 86400 );

		gform_update_meta( $entry_id, 'chip_sub_status', 'active' );
		gform_update_meta( $entry_id, 'chip_recurring_token', 'tok_live' );
		gform_update_meta( $entry_id, 'chip_sub_next_payment', $due );
		gform_update_meta( $entry_id, 'chip_sub_remaining', '3' );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', '0' );
		gform_update_meta( $entry_id, 'chip_payment_id', 'pay_original' );
		gform_update_meta( $entry_id, 'chip_subscription_id', 'pay_original' );

		if ( $store_amount ) {
			gform_update_meta( $entry_id, 'chip_sub_amount', $stored_amount );
		}

		GF_Chip_Test_Feed::set(
			array(
				'id'   => 7,
				'meta' => array(
					'transactionType'     => 'subscription',
					'billingCycle_length' => '1',
					'billingCycle_unit'   => 'month',
					'recurringTimes'      => '3',
					'recurringAmount'     => 'form_total',
				),
			)
		);

		GFAPI::set_form( array( 'id' => 1, 'title' => 'F' ) );

		// Core reports the RECURRING amount for a subscription feed: the trial
		// and setup fee are already excluded from it. This is the fallback
		// when chip_sub_amount is absent.
		GF_Chip_Test_Submission::set( array( 'payment_amount' => 50.0 ) );

		GFAPI::set_entry(
			array(
				'id'               => $entry_id,
				'form_id'          => 1,
				'transaction_id'   => 'pay_original',
				'transaction_type' => '2',
				'payment_status'   => 'Active',
				'payment_amount'   => '0.00',
				'currency'         => 'MYR',
			)
		);
	}

	/**
	 * The entry array as charge_renewal() receives it from the cron path.
	 *
	 * @param int $entry_id Entry id.
	 * @return array
	 */
	private function entry( $entry_id ) {
		$due = (string) gform_get_meta( $entry_id, 'chip_sub_next_payment' );

		return array(
			'id'                    => $entry_id,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'payment_status'        => 'Active',
			'currency'              => 'MYR',
			'chip_sub_status'       => 'active',
			'chip_recurring_token'  => 'tok_live',
			'chip_sub_next_payment' => $due,
		);
	}

	/**
	 * A GF_Chip instance with only the credential lookup replaced.
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
	 * Mocks the HTTP layer, recording every request on $this->requests.
	 *
	 * @param string $charge_status Status the charge call answers with.
	 * @return void
	 */
	private function mock_transport( $charge_status = 'paid' ) {
		$test = $this;

		WP_Mock::userFunction( 'wp_remote_request' )->andReturnUsing(
			function ( $url, $args = array() ) use ( $test, $charge_status ) {
				$test->record_request( $url, $args );

				if ( false !== strpos( $url, '/charge/' ) ) {
					return array( 'body' => wp_json_encode( array( 'id' => 'pay_new', 'status' => $charge_status ) ) );
				}

				return array( 'body' => wp_json_encode( array( 'id' => 'pay_new', 'status' => 'created' ) ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
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
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'wp_timezone_string' )->andReturn( 'Asia/Kuala_Lumpur' );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com/' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		WP_Mock::userFunction( 'absint' )->andReturnUsing(
			function ( $v ) {
				return abs( (int) $v );
			}
		);
	}

	/**
	 * Records one HTTP request. Public so the mocked transport can reach it.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request args.
	 * @return void
	 */
	public function record_request( $url, $args = array() ) {
		$this->requests[] = array(
			'url'  => (string) $url,
			'body' => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array(),
		);
	}

	/**
	 * Recorded request URLs containing a fragment.
	 *
	 * @param string $fragment Fragment to match.
	 * @return array
	 */
	private function urls_matching( $fragment ) {
		return array_values(
			array_map(
				function ( $request ) {
					return $request['url'];
				},
				array_filter(
					$this->requests,
					function ( $request ) use ( $fragment ) {
						return false !== strpos( $request['url'], $fragment );
					}
				)
			)
		);
	}

	/**
	 * Request URLs that create a purchase (as opposed to charging one).
	 *
	 * @return array
	 */
	private function create_urls() {
		return array_values(
			array_filter(
				$this->urls_matching( '/purchases/' ),
				function ( $url ) {
					return false === strpos( $url, '/charge/' );
				}
			)
		);
	}

	/**
	 * The decoded body of the purchase-creation call, or null.
	 *
	 * @return array|null
	 */
	private function body_of_create_call() {
		foreach ( $this->requests as $request ) {
			if ( false === strpos( $request['url'], '/charge/' ) && false !== strpos( $request['url'], '/purchases/' ) ) {
				return $request['body'];
			}
		}

		return null;
	}
}
