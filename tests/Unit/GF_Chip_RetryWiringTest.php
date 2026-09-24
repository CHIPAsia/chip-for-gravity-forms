<?php
/**
 * Wiring test for the retry-ladder anchor.
 *
 * The helper's own behaviour is covered elsewhere. This drives the REAL
 * charge_renewal() failure path with the CHIP API mocked, so it fails if the
 * caller rebuilds the anchor from a re-read field instead of passing the plan.
 *
 * The distinguishing assertion: after a failed charge, the scheduled retry must
 * land one day after the MISS. The stored next-payment field holds the next
 * billing date (a month ahead). Only a caller using the plan's anchor produces
 * the correct value, so this test cannot pass with the defect present.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_CHIP_API;
use GF_Chip;
use GF_Chip_Test_Feed;
use GF_Chip_Test_Meta;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::charge_renewal
 */
class GF_Chip_RetryWiringTest extends TestCase {

	/**
	 * Set up WP_Mock and clear the meta store.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();

		// Fresh API singleton so the mocked HTTP layer is reached.
		$ref  = new \ReflectionClass( GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * A failed renewal must schedule its retry a day after the MISS, not a day
	 * after the next billing date.
	 */
	public function test_failed_renewal_schedules_retry_from_the_missed_date(): void {
		$entry_id = 55;

		$due   = '2026-09-01 00:00:00';
		$claim = '2026-10-01 00:00:00';

		gform_update_meta( $entry_id, 'chip_sub_status', 'active' );
		gform_update_meta( $entry_id, 'chip_recurring_token', 'tok_live' );
		gform_update_meta( $entry_id, 'chip_sub_next_payment', $due );
		gform_update_meta( $entry_id, 'chip_sub_remaining', '3' );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', '0' );
		gform_update_meta( $entry_id, 'chip_payment_id', 'pay_aaa' );
		// The agreed recurring amount. A renewal without one is skipped by
		// design, so the fixture has to carry it the way a real entry does.
		gform_update_meta( $entry_id, 'chip_sub_amount', '5000' );

		$entry = array(
			'id'                    => $entry_id,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'payment_status'        => 'Active',
			'currency'              => 'MYR',
			'chip_sub_status'       => 'active',
			'chip_recurring_token'  => 'tok_live',
			'chip_sub_next_payment' => $due,
			'chip_sub_amount'       => '5000',
		);

		// The charge is declined.
		WP_Mock::userFunction( 'wp_remote_request' )->andReturn(
			array( 'body' => wp_json_encode( array( 'status' => 'error' ) ) )
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			function ( $r ) {
				return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 400 );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $tag, $value ) {
				return $value;
			}
		);
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		// The renewal purchase carries a timezone and a success callback, so
		// the real get_timezone() and param builder are reached on this path.
		WP_Mock::userFunction( 'wp_timezone_string' )->andReturn( 'Asia/Kuala_Lumpur' );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com/' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'https://example.com/?callback=gravityformschip' );

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
		// Stage the subscription feed so the real charge path is reachable.
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

		$result = $addon->charge_renewal( $entry );

		$this->assertIsArray( $result );

		$scheduled = gform_get_meta( $entry_id, 'chip_sub_next_payment' );

		// The distinguishing assertion: anchored on the MISS (2026-09-01), the
		// first failure schedules the +3d rung. handle_renewal_failure()
		// increments the counter before consulting the ladder, so offset index
		// 1 applies for attempt 1.
		$this->assertSame(
			'2026-09-04 00:00:00',
			$scheduled,
			'the retry must be anchored on the missed date'
		);

		// The defect would have produced a date a whole billing cycle later.
		$this->assertNotSame(
			'2026-10-04 00:00:00',
			$scheduled,
			'anchoring on the advanced claim defers the retry a whole cycle'
		);

		// The attempt was counted, so the button cannot bypass the ladder.
		$this->assertSame( '1', (string) gform_get_meta( $entry_id, 'chip_sub_retry_count' ) );

		// The claim was advanced before the charge was attempted, as designed:
		// the first write to the schedule was the next cycle, and the ladder
		// then moved it to the retry. Assert the write order rather than a
		// fragile index.
		$written = array_column( GF_Chip_Test_Meta::writes_for( 'chip_sub_next_payment' ), 'value' );

		$this->assertContains(
			$claim,
			$written,
			'the claim must be advanced to the next cycle before the charge is attempted'
		);
	}
}
