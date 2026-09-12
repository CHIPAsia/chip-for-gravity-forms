<?php
/**
 * Security tests for the card-update link.
 *
 * The link is a capability: whoever holds it can replace the card that will be
 * charged. These tests exist to prove each control holds, and each one is
 * paired with a sabotage mutation in the PR to show it bites.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Card_Update;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Card_Update
 */
class GF_Chip_CardUpdateTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();

		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function ( $tag, $value ) {
			return $value;
		} );
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * A valid request fixture matching a stored signature.
	 *
	 * @param int $entry_id Entry id.
	 * @param int $expiry   Expiry timestamp.
	 * @return array
	 */
	private function valid_request( $entry_id, $expiry ) {
		return array(
			GF_Chip_Card_Update::ARG_ENTRY     => $entry_id,
			GF_Chip_Card_Update::ARG_EXPIRY    => $expiry,
			GF_Chip_Card_Update::ARG_SIGNATURE => 'stored-signature',
		);
	}

	// ---------------------------------------------------------------------
	// The signed payload.
	// ---------------------------------------------------------------------

	/**
	 * The payload is deterministic — generation and validation agree.
	 */
	public function test_payload_is_deterministic(): void {
		$this->assertSame(
			GF_Chip_Card_Update::signature_payload( 42, 1000 ),
			GF_Chip_Card_Update::signature_payload( 42, 1000 )
		);
	}

	/**
	 * The payload is bound to the entry id.
	 *
	 * This is the control that stops a link for entry A being replayed at
	 * entry B: change the id, the signature no longer matches.
	 */
	public function test_payload_differs_per_entry(): void {
		$this->assertNotSame(
			GF_Chip_Card_Update::signature_payload( 42, 1000 ),
			GF_Chip_Card_Update::signature_payload( 43, 1000 )
		);
	}

	/**
	 * The payload is bound to the expiry, so it cannot be extended.
	 */
	public function test_payload_differs_per_expiry(): void {
		$this->assertNotSame(
			GF_Chip_Card_Update::signature_payload( 42, 1000 ),
			GF_Chip_Card_Update::signature_payload( 42, 2000 )
		);
	}

	// ---------------------------------------------------------------------
	// Expiry.
	// ---------------------------------------------------------------------

	/**
	 * A link inside its window is not expired.
	 */
	public function test_not_expired_within_window(): void {
		$this->assertFalse( GF_Chip_Card_Update::is_expired( 2000, 1000 ) );
	}

	/**
	 * A link past its expiry is expired.
	 */
	public function test_expired_after_window(): void {
		$this->assertTrue( GF_Chip_Card_Update::is_expired( 1000, 2000 ) );
	}

	/**
	 * Exactly at the expiry instant the link is still valid, so a link is
	 * usable for its full stated lifetime.
	 */
	public function test_valid_exactly_at_expiry(): void {
		$this->assertFalse( GF_Chip_Card_Update::is_expired( 1000, 1000 ) );
	}

	// ---------------------------------------------------------------------
	// Signature comparison.
	// ---------------------------------------------------------------------

	/**
	 * Matching signatures pass.
	 */
	public function test_signature_matches_identical(): void {
		$this->assertTrue( GF_Chip_Card_Update::signature_matches( 'abc123', 'abc123' ) );
	}

	/**
	 * A different signature fails.
	 */
	public function test_signature_rejects_different(): void {
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( 'abc123', 'abc124' ) );
	}

	/**
	 * A signature differing only in the FIRST character is rejected.
	 *
	 * The comparison must consider the whole string, not a prefix or a
	 * suffix. A comparison that only looked at the tail would pass a value
	 * sharing the last characters.
	 */
	public function test_signature_rejects_difference_at_first_character(): void {
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( 'Xbc123', 'abc123' ) );
	}

	/**
	 * A prefix of a valid signature is rejected.
	 */
	public function test_signature_rejects_prefix(): void {
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( 'abc123', 'abc' ) );
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( 'abc', 'abc123' ) );
	}

	/**
	 * An empty presented signature fails even against an empty record — an
	 * unset meta value must never authorise anything.
	 */
	public function test_signature_rejects_empty_presented(): void {
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( 'abc123', '' ) );
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( '', '' ) );
	}

	/**
	 * A missing stored signature fails.
	 */
	public function test_signature_rejects_empty_stored(): void {
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( '', 'abc123' ) );
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( null, 'abc123' ) );
	}

	/**
	 * Non-string input fails rather than coercing (a coerced array would
	 * otherwise become the string "Array").
	 */
	public function test_signature_rejects_non_string(): void {
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( array( 'abc' ), 'abc' ) );
		$this->assertFalse( GF_Chip_Card_Update::signature_matches( 'abc', array( 'abc' ) ) );
	}

	// ---------------------------------------------------------------------
	// Amount resolution — never from the request.
	// ---------------------------------------------------------------------

	/**
	 * A healthy subscription changing card is token-only (zero).
	 */
	public function test_healthy_subscription_is_token_only(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_sub_next_payment' => '2026-12-01 00:00:00',
			'chip_sub_amount'       => 5000,
		);

		$this->assertSame( 0, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' ) );
	}

	/**
	 * A past-due active subscription settles the outstanding cycle.
	 */
	public function test_past_due_active_settles_full_amount(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'active',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => 5000,
		);

		$this->assertSame( 5000, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' ) );
	}

	/**
	 * An on-hold subscription settles, regardless of the date.
	 */
	public function test_on_hold_settles_full_amount(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'on-hold',
			'chip_sub_next_payment' => '2026-12-01 00:00:00',
			'chip_sub_amount'       => 7000,
		);

		$this->assertSame( 7000, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' ) );
	}

	/**
	 * A settling subscription with no stored amount falls back rather than
	 * silently charging zero — charging nothing while claiming to settle the
	 * debt would leave the customer owing without telling them.
	 */
	public function test_settling_falls_back_when_no_stored_amount(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'on-hold',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => '',
		);

		$this->assertSame( 2500, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00', 2500 ) );
	}

	/**
	 * A cancelled subscription resolves to zero — it must never be charged.
	 */
	public function test_cancelled_resolves_to_zero(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'cancelled',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => 5000,
		);

		$this->assertSame( 0, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' ) );
	}

	/**
	 * A negative stored amount cannot produce a negative charge.
	 */
	public function test_negative_stored_amount_is_ignored(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '2',
			'chip_sub_status'       => 'on-hold',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => -5000,
		);

		$this->assertSame( 0, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' ) );
	}

	/**
	 * A one-time payment never settles as a subscription.
	 */
	public function test_one_time_payment_resolves_to_zero(): void {
		$entry = array(
			'id'                    => 1,
			'transaction_type'      => '1',
			'chip_sub_status'       => 'active',
			'chip_sub_next_payment' => '2026-08-01 00:00:00',
			'chip_sub_amount'       => 5000,
		);

		$this->assertSame( 0, GF_Chip_Card_Update::resolve_amount_cents( $entry, '2026-09-01 00:00:00' ) );
	}

	// ---------------------------------------------------------------------
	// is_settling.
	// ---------------------------------------------------------------------

	/**
	 * The settling decision by state and date.
	 */
	public function test_is_settling_matrix(): void {
		$this->assertTrue( GF_Chip_Card_Update::is_settling( 'on-hold', false ), 'on-hold always settles' );
		$this->assertTrue( GF_Chip_Card_Update::is_settling( 'active', true ), 'past-due active settles' );
		$this->assertFalse( GF_Chip_Card_Update::is_settling( 'active', false ), 'healthy active does not' );
		$this->assertFalse( GF_Chip_Card_Update::is_settling( 'cancelled', true ), 'cancelled never settles' );
		$this->assertFalse( GF_Chip_Card_Update::is_settling( 'expired', true ), 'expired never settles' );
		$this->assertFalse( GF_Chip_Card_Update::is_settling( 'pending', true ), 'pending does not settle' );
	}

	// ---------------------------------------------------------------------
	// Validation.
	// ---------------------------------------------------------------------

	/**
	 * A request missing any component is rejected.
	 */
	public function test_validate_rejects_incomplete_request(): void {
		$this->assertFalse( GF_Chip_Card_Update::validate( array(), 1000 )['valid'] );
		$this->assertFalse( GF_Chip_Card_Update::validate( array( GF_Chip_Card_Update::ARG_ENTRY => 1 ), 1000 )['valid'] );
		$this->assertSame(
			'incomplete',
			GF_Chip_Card_Update::validate( array( GF_Chip_Card_Update::ARG_ENTRY => 1 ), 1000 )['reason']
		);
	}

	/**
	 * A non-array request is rejected without warning.
	 */
	public function test_validate_rejects_non_array(): void {
		$this->assertFalse( GF_Chip_Card_Update::validate( null, 1000 )['valid'] );
		$this->assertFalse( GF_Chip_Card_Update::validate( 'nope', 1000 )['valid'] );
	}

	/**
	 * An expired link is rejected with a distinct reason.
	 */
	public function test_validate_rejects_expired(): void {
		$request = array(
			GF_Chip_Card_Update::ARG_ENTRY     => 42,
			GF_Chip_Card_Update::ARG_EXPIRY    => 1000,
			GF_Chip_Card_Update::ARG_SIGNATURE => 'sig',
		);

		$result = GF_Chip_Card_Update::validate( $request, 2000 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'expired', $result['reason'] );
	}

	/**
	 * Zero or negative identifiers are rejected as incomplete.
	 *
	 * An entry id of 0 would otherwise read meta for a non-entry.
	 */
	public function test_validate_rejects_zero_identifiers(): void {
		$this->assertSame(
			'incomplete',
			GF_Chip_Card_Update::validate(
				array(
					GF_Chip_Card_Update::ARG_ENTRY     => 0,
					GF_Chip_Card_Update::ARG_EXPIRY    => 1000,
					GF_Chip_Card_Update::ARG_SIGNATURE => 'sig',
				),
				500
			)['reason']
		);

		$this->assertSame(
			'incomplete',
			GF_Chip_Card_Update::validate(
				array(
					GF_Chip_Card_Update::ARG_ENTRY     => 42,
					GF_Chip_Card_Update::ARG_EXPIRY    => 0,
					GF_Chip_Card_Update::ARG_SIGNATURE => 'sig',
				),
				500
			)['reason']
		);
	}

	/**
	 * Reason strings are distinct per failure, so the log identifies which
	 * check rejected a request.
	 */
	public function test_validate_reasons_are_distinct(): void {
		$reasons = array(
			GF_Chip_Card_Update::validate( array(), 1000 )['reason'],
			GF_Chip_Card_Update::validate(
				array(
					GF_Chip_Card_Update::ARG_ENTRY     => 42,
					GF_Chip_Card_Update::ARG_EXPIRY    => 1000,
					GF_Chip_Card_Update::ARG_SIGNATURE => 'sig',
				),
				2000
			)['reason'],
		);

		$this->assertSame( $reasons, array_unique( $reasons ) );
	}

	// ---------------------------------------------------------------------
	// Eligibility.
	// ---------------------------------------------------------------------

	/**
	 * A live subscription can be offered a link.
	 */
	public function test_link_offered_for_active(): void {
		$this->assertTrue(
			GF_Chip_Card_Update::can_offer_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'active' )
			)
		);
	}

	/**
	 * An on-hold subscription can be offered a link — that is exactly when it
	 * is most needed.
	 */
	public function test_link_offered_for_on_hold(): void {
		$this->assertTrue(
			GF_Chip_Card_Update::can_offer_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'on-hold' )
			)
		);
	}

	/**
	 * A cancelled subscription is NOT offered a link — there is no future
	 * charge to redirect.
	 */
	public function test_link_refused_for_cancelled(): void {
		$this->assertFalse(
			GF_Chip_Card_Update::can_offer_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'cancelled' )
			)
		);
	}

	/**
	 * An expired subscription is NOT offered a link.
	 */
	public function test_link_refused_for_expired(): void {
		$this->assertFalse(
			GF_Chip_Card_Update::can_offer_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'expired' )
			)
		);
	}

	/**
	 * A one-time payment is never offered a card-update link.
	 */
	public function test_link_refused_for_one_time_payment(): void {
		$this->assertFalse(
			GF_Chip_Card_Update::can_offer_link(
				array( 'transaction_type' => '1', 'chip_sub_status' => 'active' )
			)
		);
	}
}
