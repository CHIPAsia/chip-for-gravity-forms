<?php
/**
 * Tests for the admin "Retry now" action.
 *
 * OQ7: a manual retry COUNTS AS AN ATTEMPT, so it cannot be used to bypass the
 * dunning ladder by pressing it repeatedly.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use GF_Chip_Renewal_Notifications;
use GF_Chip_Subscriptions_Page;
use GF_Chip_Test_Feed;
use GF_CHIP_API;
use GF_Chip_Test_Meta;
use GFAPI;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Renewal_Notifications::admin_retry_url
 * @covers \GF_Chip_Renewal_Notifications::handle_admin_retry
 */
class GF_Chip_AdminRetryTest extends TestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();
		GFAPI::reset();

		$ref  = new \ReflectionClass( GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
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
	 * The action is registered, so the button's URL is reachable.
	 */
	public function test_retry_action_is_registered(): void {
		$src = file_get_contents( GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip.php' );

		$this->assertStringContainsString(
			"add_action( 'admin_post_chip_retry_renewal', array( 'GF_Chip_Renewal_Notifications', 'handle_admin_retry' ) )",
			$src,
			'the retry action must be registered or the button 404s'
		);
	}

	/**
	 * The URL is nonce-protected and points at admin-post.
	 */
	public function test_retry_url_is_nonce_protected(): void {
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturnUsing(
			function ( $url, $action ) {
				return $url . '&_wpnonce=' . $action;
			}
		);
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		WP_Mock::userFunction( 'admin_url' )->andReturn( 'https://example.com/wp-admin/admin-post.php' );

		$url = GF_Chip_Renewal_Notifications::admin_retry_url( 42 );

		$this->assertStringContainsString( 'action=chip_retry_renewal', $url );
		$this->assertStringContainsString( 'entry_id=42', $url );
		$this->assertStringContainsString(
			'chip_retry_renewal_42',
			$url,
			'the nonce action must be bound to this entry'
		);
	}

	/**
	 * The subscriptions page renders a real link, not a disabled button.
	 */
	public function test_page_renders_a_link_not_a_disabled_button(): void {
		$src = file_get_contents( GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-subscriptions-page.php' );

		$this->assertStringNotContainsString(
			'Retry on demand is not enabled in this release.',
			$src,
			'the placeholder must be gone'
		);
		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::admin_retry_url',
			$src,
			'the button must point at the real action'
		);
	}

	/**
	 * A retry counts as an attempt (OQ7): the charge failure increments the
	 * counter, so pressing the button cannot bypass the ladder.
	 */
	public function test_retry_counts_as_an_attempt(): void {
		$entry_id = 61;
		$due      = '2026-09-01 00:00:00';

		gform_update_meta( $entry_id, 'chip_sub_status', 'on-hold' );
		gform_update_meta( $entry_id, 'chip_recurring_token', 'tok_live' );
		gform_update_meta( $entry_id, 'chip_sub_next_payment', $due );
		gform_update_meta( $entry_id, 'chip_sub_remaining', '3' );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', '0' );
		gform_update_meta( $entry_id, 'chip_payment_id', 'pay_x' );
		// A renewal is skipped without an agreed recurring amount, so the
		// fixture carries one the way a real subscription entry does.
		gform_update_meta( $entry_id, 'chip_sub_amount', '5000' );

		$entry = array(
			'id'                    => $entry_id,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'currency'              => 'MYR',
			'chip_sub_status'       => 'on-hold',
			'chip_recurring_token'  => 'tok_live',
			'chip_sub_next_payment' => $due,
			'chip_sub_amount'       => '5000',
		);

		GFAPI::set_form( array( 'id' => 1, 'title' => 'F' ) );
		GFAPI::set_entry( $entry );
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

		// Forced, exactly as handle_admin_retry() calls it.
		$addon->charge_renewal( $entry, true );

		$this->assertSame(
			'1',
			(string) gform_get_meta( $entry_id, 'chip_sub_retry_count' ),
			'a manual retry must consume a ladder slot'
		);
	}

	/**
	 * can_retry() refuses a cancelled or expired subscription, so a retry
	 * cannot resurrect one.
	 */
	public function test_can_retry_refuses_dead_subscriptions(): void {
		$base = array(
			'id'               => 61,
			'form_id'          => 1,
			'transaction_type' => '2',
			'chip_recurring_token' => 'tok_live',
		);

		foreach ( array( 'cancelled', 'expired', 'pending' ) as $state ) {
			$entry          = $base;
			$entry['chip_sub_status'] = $state;

			$this->assertFalse(
				GF_Chip_Subscriptions_Page::can_retry( $entry ),
				"a {$state} subscription must not be retryable"
			);
		}

		// Live states with a token are retryable.
		foreach ( array( 'active', 'on-hold' ) as $state ) {
			$entry          = $base;
			$entry['chip_sub_status'] = $state;

			$this->assertTrue(
				GF_Chip_Subscriptions_Page::can_retry( $entry ),
				"a {$state} subscription must be retryable"
			);
		}
	}

	/**
	 * Without a token there is nothing to charge, so no retry.
	 */
	public function test_can_retry_requires_a_token(): void {
		$entry = array(
			'id'               => 61,
			'form_id'          => 1,
			'transaction_type' => '2',
			'chip_sub_status'  => 'active',
			'chip_recurring_token' => '',
		);

		$this->assertFalse( GF_Chip_Subscriptions_Page::can_retry( $entry ) );
	}

	/**
	 * The cron must NOT charge an on-hold subscription.
	 *
	 * The force flag belongs only to the operator retry. If the cron ever
	 * adopted it, every run would attempt an on-hold subscription and the
	 * dunning ladder would be bypassed.
	 */
	public function test_cron_refuses_on_hold_without_force(): void {
		$entry = array(
			'id'                    => 61,
			'form_id'               => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'on-hold',
			'chip_recurring_token'  => 'tok_live',
			'chip_sub_next_payment' => '2020-01-01 00:00:00',
		);

		$this->assertFalse(
			\GF_Chip_Renewals::is_due( $entry, '2026-09-13 00:00:00' ),
			'the cron must not charge on-hold'
		);

		$this->assertTrue(
			\GF_Chip_Renewals::is_due( $entry, '2026-09-13 00:00:00', true ),
			'an operator retry may attempt on-hold'
		);
	}

	/**
	 * Force must not resurrect a cancelled or expired subscription.
	 */
	public function test_force_does_not_revive_dead_states(): void {
		foreach ( array( 'cancelled', 'expired', 'pending' ) as $state ) {
			$entry = array(
				'id'                    => 61,
				'form_id'               => 1,
				'transaction_type'      => '2',
				'chip_sub_status'       => $state,
				'chip_recurring_token'  => 'tok_live',
				'chip_sub_next_payment' => '2020-01-01 00:00:00',
			);

			$this->assertFalse(
				\GF_Chip_Renewals::is_due( $entry, '2026-09-13 00:00:00', true ),
				"force must not charge a {$state} subscription"
			);
		}
	}
}
