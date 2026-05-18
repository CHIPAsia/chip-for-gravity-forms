<?php
/**
 * Unit tests for GF_Chip.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @covers \GF_Chip
 */
class GF_ChipTest extends TestCase {

	/**
	 * Reset API singleton before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$ref  = new \ReflectionClass( \GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/**
	 * get_credentials_for_feed returns global settings when configuration type is global.
	 */
	public function test_get_credentials_for_feed_returns_global_settings(): void {
		$global_settings = array(
			'secret_key'         => 'global_sk',
			'brand_id'           => 'global_brand',
			'due_strict'         => '1',
			'due_strict_timing'  => '120',
			'enable_refund'      => '1',
		);

		WP_Mock::userFunction( 'get_option' )
			->with( 'gravityformsaddon_gravityformschip_settings' )
			->andReturn( $global_settings );

		$chip = new GF_Chip();
		$feed = array( 'meta' => array( 'chipConfigurationType' => 'global' ) );
		$creds = $chip->get_credentials_for_feed( $feed );

		$this->assertSame( 'global_sk', $creds['secret_key'] );
		$this->assertSame( 'global_brand', $creds['brand_id'] );
		$this->assertSame( '1', $creds['due_strict'] );
		$this->assertSame( '120', $creds['due_timing'] );
		$this->assertSame( '1', $creds['refund'] );
	}

	/**
	 * get_credentials_for_feed returns form settings when configuration type is form.
	 */
	public function test_get_credentials_for_feed_returns_form_settings(): void {
		$global_settings = array(
			'secret_key'         => 'global_sk',
			'brand_id'           => 'global_brand',
			'due_strict'         => '1',
			'due_strict_timing'  => '120',
			'enable_refund'      => '1',
		);

		WP_Mock::userFunction( 'get_option' )
			->with( 'gravityformsaddon_gravityformschip_settings' )
			->andReturn( $global_settings );

		$chip = new GF_Chip();
		$feed = array(
			'meta' => array(
				'chipConfigurationType' => 'form',
				'secret_key'            => 'form_sk',
				'brand_id'              => 'form_brand',
				'due_strict'            => '0',
				'due_strict_timing'     => '30',
				'enable_refund'         => '0',
			),
		);
		$creds = $chip->get_credentials_for_feed( $feed );

		$this->assertSame( 'form_sk', $creds['secret_key'] );
		$this->assertSame( 'form_brand', $creds['brand_id'] );
		$this->assertSame( '0', $creds['due_strict'] );
		$this->assertSame( '30', $creds['due_timing'] );
		$this->assertSame( '0', $creds['refund'] );
	}

	/**
	 * get_credentials_for_feed returns defaults when global settings are missing.
	 */
	public function test_get_credentials_for_feed_returns_defaults_when_empty(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'gravityformsaddon_gravityformschip_settings' )
			->andReturn( false );

		$chip = new GF_Chip();
		$feed = array( 'meta' => array( 'chipConfigurationType' => 'global' ) );
		$creds = $chip->get_credentials_for_feed( $feed );

		$this->assertSame( '', $creds['secret_key'] );
		$this->assertSame( '', $creds['brand_id'] );
		$this->assertSame( '', $creds['due_strict'] );
		$this->assertSame( 60, $creds['due_timing'] );
		$this->assertFalse( $creds['refund'] );
	}

	/**
	 * build_callback_action returns complete_payment for paid status.
	 */
	public function test_build_callback_action_returns_complete_for_paid(): void {
		$chip = new GF_Chip();
		$ref  = new \ReflectionMethod( $chip, 'build_callback_action' );
		$ref->setAccessible( true );
		$action = $ref->invoke( $chip, 'pay_1', 42, 'paid', 'fpx', 10000 );

		$this->assertSame( 'complete_payment', $action['type'] );
		$this->assertSame( 'pay_1', $action['transaction_id'] );
		$this->assertSame( 42, $action['entry_id'] );
		$this->assertSame( 'fpx', $action['payment_method'] );
		$this->assertSame( '100.00', $action['amount'] );
		$this->assertArrayNotHasKey( 'abort_callback', $action );
	}

	/**
	 * build_callback_action returns fail_payment for error status.
	 */
	public function test_build_callback_action_returns_fail_for_error(): void {
		$chip = new GF_Chip();
		$ref  = new \ReflectionMethod( $chip, 'build_callback_action' );
		$ref->setAccessible( true );
		$action = $ref->invoke( $chip, 'pay_2', 99, 'error', 'card', 5000 );

		$this->assertSame( 'fail_payment', $action['type'] );
		$this->assertSame( 'pay_2', $action['transaction_id'] );
		$this->assertSame( 99, $action['entry_id'] );
		$this->assertArrayNotHasKey( 'abort_callback', $action );
	}

	/**
	 * build_callback_action sets abort_callback for non-paid non-error statuses.
	 */
	public function test_build_callback_action_aborts_for_pending(): void {
		$chip = new GF_Chip();
		$ref  = new \ReflectionMethod( $chip, 'build_callback_action' );
		$ref->setAccessible( true );
		$action = $ref->invoke( $chip, 'pay_3', 1, 'pending', '', 0 );

		$this->assertSame( 'fail_payment', $action['type'] );
		$this->assertSame( 'true', $action['abort_callback'] );
	}

	/**
	 * get_timezone returns WordPress timezone when valid.
	 */
	public function test_get_timezone_returns_wp_timezone_when_valid(): void {
		WP_Mock::userFunction( 'wp_timezone_string' )
			->andReturn( 'Asia/Kuala_Lumpur' );

		$chip = new GF_Chip();
		$this->assertSame( 'Asia/Kuala_Lumpur', $chip->get_timezone() );
	}

	/**
	 * get_timezone falls back to UTC when WordPress timezone is invalid.
	 */
	public function test_get_timezone_fallback_to_utc(): void {
		WP_Mock::userFunction( 'wp_timezone_string' )
			->andReturn( '+08:00' );

		$chip = new GF_Chip();
		$this->assertSame( 'UTC', $chip->get_timezone() );
	}
}
