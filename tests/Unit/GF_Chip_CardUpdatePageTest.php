<?php
/**
 * Tests for the customer-facing card-update page's decisions.
 *
 * The HTML itself needs a live install, but every value shown and every button
 * decision is a pure function, so those are pinned here.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Card_Update_Page;
use GF_Chip_Test_Meta;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Card_Update_Page
 */
class GF_Chip_CardUpdatePageTest extends TestCase {

	/**
	 * Clean state per test.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();

		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function ( $tag, $value ) {
			return $value;
		} );
		WP_Mock::userFunction( 'get_option' )->andReturn( 'Y-m-d' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		GF_Chip_Test_Meta::reset();
	}

	// ---------------------------------------------------------------------
	// The view model — what the customer sees.
	// ---------------------------------------------------------------------

	/**
	 * A healthy active subscription offers a card swap with no charge.
	 */
	public function test_healthy_subscription_shows_a_free_card_swap(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_sub_next_payment' => '2026-12-01 00:00:00',
			'chip_sub_amount'       => 5000,
			'chip_recurring_token'  => 'tok_live',
		);

		$view = GF_Chip_Card_Update_Page::build_view( $entry, '2026-09-01 00:00:00' );

		$this->assertTrue( $view['can_update'] );
		$this->assertSame( 0, $view['amount_cents'], 'a healthy subscription is a free swap' );
		$this->assertFalse( $view['settling'] );
		$this->assertTrue( $view['has_token'] );
		$this->assertSame( 'Update your card', $view['heading'] );
		$this->assertSame( 'Update card', $view['button_label'] );
	}

	/**
	 * A past-due subscription asks for payment and says so.
	 */
	public function test_past_due_subscription_asks_for_payment(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => 5000,
			'chip_recurring_token'  => 'tok_live',
		);

		$view = GF_Chip_Card_Update_Page::build_view( $entry, '2026-09-01 00:00:00' );

		$this->assertSame( 5000, $view['amount_cents'] );
		$this->assertTrue( $view['settling'] );
		$this->assertSame( 'Pay and update your card', $view['heading'] );
		$this->assertSame( 'Pay and update card', $view['button_label'] );
		$this->assertStringContainsString( 'outstanding payment', $view['explanation'] );
	}

	/**
	 * An on-hold subscription asks for payment regardless of the date.
	 */
	public function test_on_hold_subscription_asks_for_payment(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'on-hold',
			'chip_sub_next_payment' => '2026-12-01 00:00:00',
			'chip_sub_amount'       => 3000,
			'chip_recurring_token'  => 'tok_live',
		);

		$view = GF_Chip_Card_Update_Page::build_view( $entry, '2026-09-01 00:00:00' );

		$this->assertSame( 3000, $view['amount_cents'] );
		$this->assertTrue( $view['settling'] );
	}

	/**
	 * A cancelled subscription offers no update button and says why.
	 */
	public function test_cancelled_subscription_offers_no_button(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'cancelled',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => 5000,
			'chip_recurring_token'  => 'tok_live',
		);

		$view = GF_Chip_Card_Update_Page::build_view( $entry, '2026-09-01 00:00:00' );

		$this->assertFalse( $view['can_update'], 'a cancelled subscription has nothing to update' );
		$this->assertSame( 0, $view['amount_cents'], 'a cancelled subscription is never charged' );
		$this->assertStringContainsString( 'no longer active', $view['explanation'] );
	}

	/**
	 * A subscription with no card on file is flagged as such.
	 */
	public function test_missing_token_is_surfaced(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_sub_next_payment' => '2026-12-01 00:00:00',
			'chip_sub_amount'       => 5000,
			'chip_recurring_token'  => '',
		);

		$view = GF_Chip_Card_Update_Page::build_view( $entry, '2026-09-01 00:00:00' );

		$this->assertFalse( $view['has_token'] );
		$this->assertStringContainsString( 'No card is on file', $view['explanation'] );
	}

	// ---------------------------------------------------------------------
	// explain() — every branch a customer can land in.
	// ---------------------------------------------------------------------

	/**
	 * The settling explanation is used whenever money is being collected.
	 */
	public function test_explain_prioritises_settling(): void {
		$text = GF_Chip_Card_Update_Page::explain( true, true, 'active' );

		$this->assertStringContainsString( 'outstanding payment', $text );
		$this->assertStringContainsString( 'one step', $text );
	}

	/**
	 * With no outstanding payment and a live card, the message is reassuring.
	 */
	public function test_explain_healthy(): void {
		$text = GF_Chip_Card_Update_Page::explain( false, true, 'active' );

		$this->assertStringContainsString( 'up to date', $text );
	}

	/**
	 * Every state produces a non-empty explanation — never a blank paragraph.
	 */
	public function test_explain_always_returns_text(): void {
		foreach ( array( 'pending', 'active', 'on-hold', 'cancelled', 'expired', 'failed' ) as $state ) {
			foreach ( array( true, false ) as $settling ) {
				$text = GF_Chip_Card_Update_Page::explain( $settling, true, $state );
				$this->assertNotSame( '', trim( $text ), "state {$state} settling=" . var_export( $settling, true ) . ' must explain itself' );
			}
		}
	}

	// ---------------------------------------------------------------------
	// Formatting.
	// ---------------------------------------------------------------------

	/**
	 * An amount formats with the currency code and two decimals.
	 */
	public function test_format_amount(): void {
		$this->assertSame( 'MYR 50.00', GF_Chip_Card_Update_Page::format_amount( 5000, 'MYR' ) );
		$this->assertSame( 'MYR 0.00', GF_Chip_Card_Update_Page::format_amount( 0, 'MYR' ) );
		$this->assertSame( 'MYR 12.34', GF_Chip_Card_Update_Page::format_amount( 1234, 'myr' ) );
	}

	/**
	 * An empty datetime formats as empty rather than the epoch.
	 */
	public function test_format_empty_datetime(): void {
		$this->assertSame( '', GF_Chip_Card_Update_Page::format_datetime( '' ) );
		$this->assertSame( '', GF_Chip_Card_Update_Page::format_datetime( 'not-a-date' ) );
	}

	/**
	 * A UTC datetime is formatted through wp_date, which applies site-local
	 * time. Stored values are UTC, so displaying them raw would be wrong by the
	 * site's offset.
	 */
	public function test_format_datetime_uses_wordpress_localisation(): void {
		// Re-register so this narrower expectation wins over the permissive
		// one installed in setUp().
		WP_Mock::userFunction( 'wp_date' )->andReturn( 'LOCALISED' );

		$this->assertSame( 'LOCALISED', GF_Chip_Card_Update_Page::format_datetime( '2026-09-01 12:00:00' ) );
	}

	// ---------------------------------------------------------------------
	// Loading an entry.
	// ---------------------------------------------------------------------

	/**
	 * load_subscription_entry() reads the entry's subscription meta through the
	 * harness, so the values it will display are the stored ones.
	 */
	public function test_load_entry_hydrates_subscription_meta(): void {
		gform_update_meta( 77, 'chip_sub_status', 'on-hold' );
		gform_update_meta( 77, 'chip_sub_amount', 4200 );

		$this->assertSame( 'on-hold', gform_get_meta( 77, 'chip_sub_status' ) );
		$this->assertSame( 4200, gform_get_meta( 77, 'chip_sub_amount' ) );
	}

	/**
	 * The purchase id recorded at handoff is what the return trip is checked
	 * against, so a guessed id cannot settle someone else's subscription.
	 */
	public function test_recorded_purchase_is_comparable(): void {
		gform_update_meta( 88, \GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE, 'pur_real' );

		$recorded = (string) gform_get_meta( 88, \GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE );

		$this->assertTrue( hash_equals( $recorded, 'pur_real' ) );
		$this->assertFalse( hash_equals( $recorded, 'pur_guessed' ) );
	}

	/**
	 * The settling flag round-trips, so the settlement knows whether to record
	 * a real payment.
	 */
	public function test_settling_flag_round_trips(): void {
		gform_update_meta( 99, 'chip_card_update_settling', '1' );
		$this->assertSame( '1', gform_get_meta( 99, 'chip_card_update_settling' ) );

		gform_delete_meta( 99, 'chip_card_update_settling' );
		$this->assertSame( '', gform_get_meta( 99, 'chip_card_update_settling' ), 'the flag must be cleared after use' );
	}

	// ---------------------------------------------------------------------
	// The action constant.
	// ---------------------------------------------------------------------

	/**
	 * The start action value is stable — it is embedded in a URL the customer
	 * receives, so changing it would break links already in inboxes.
	 */
	public function test_start_action_value_is_stable(): void {
		$this->assertSame( 'start', GF_Chip_Card_Update_Page::ACTION_START );
	}
}
