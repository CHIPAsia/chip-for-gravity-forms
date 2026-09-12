<?php
/**
 * Tests for the two WRITE paths that the old no-op meta double could not
 * observe.
 *
 * These exist because of a specific history: two defects shipped in PR #27
 * that a green suite did not catch, because gform_get_meta() always returned
 * '' and gform_update_meta() discarded its argument. The wiring was wrong and
 * unobservable.
 *
 * This file is the proof the harness closes that class of gap: each test below
 * fails against the old no-op double and passes against the real store.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Test_Meta;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Covers the counter wiring inside the renewal path.
 */
class GF_Chip_CounterWiringTest extends TestCase {

	/**
	 * Clean meta state and WP_Mock per test.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();

		WP_Mock::userFunction( '__' )->andReturnUsing( function ( $t, $d = null ) {
			return $t;
		} );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $t, $d = null ) {
			return $t;
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
	// The read side: resolve_remaining must consume the STORED value.
	//
	// Reading recurringTimes from the feed instead would reset a finite plan
	// every cycle. With the old no-op double the stored read always returned
	// '', so the fallback path was taken and this was invisible.
	// ---------------------------------------------------------------------

	/**
	 * After a cycle writes the counter, resolving it again returns the STORED
	 * value, not the feed's configured value.
	 */
	public function test_resolved_counter_comes_from_stored_meta_not_the_feed(): void {
		$entry_id = 42;
		$feed_value = 12;

		// Cycle 1: nothing stored yet, so the feed value applies.
		$first = \GF_Chip_Renewals::resolve_remaining(
			gform_get_meta( $entry_id, 'chip_sub_remaining' ),
			$feed_value
		);
		$this->assertSame( 12, $first, 'first cycle uses the feed value' );

		// The charge path writes the decremented counter back.
		gform_update_meta( $entry_id, 'chip_sub_remaining', $first - 1 );

		// Cycle 2: the STORED value must win.
		$second = \GF_Chip_Renewals::resolve_remaining(
			gform_get_meta( $entry_id, 'chip_sub_remaining' ),
			$feed_value
		);

		$this->assertSame( 11, $second, 'the stored counter must override the feed, or a finite plan never ends' );
		$this->assertNotSame( 12, $second, 're-reading the feed would reset the plan every cycle' );
	}

	/**
	 * A stored 0 survives the round trip and still means "finished".
	 *
	 * With the old double, `gform_update_meta( ..., 0 )` was discarded and the
	 * read returned '', so resolve_remaining took the feed fallback and a
	 * finished plan looked like an unlimited one.
	 */
	public function test_stored_zero_still_means_finished_after_round_trip(): void {
		$entry_id = 42;

		gform_update_meta( $entry_id, 'chip_sub_remaining', 0 );

		$resolved = \GF_Chip_Renewals::resolve_remaining(
			gform_get_meta( $entry_id, 'chip_sub_remaining' ),
			12
		);

		$this->assertSame( 0, $resolved, 'a finished plan must stay finished' );
	}

	/**
	 * The counter decrements monotonically across several cycles.
	 */
	public function test_counter_counts_down_across_cycles(): void {
		$entry_id   = 7;
		$feed_value = 3;

		$seen = array();

		for ( $cycle = 0; $cycle < 3; $cycle++ ) {
			$remaining = \GF_Chip_Renewals::resolve_remaining(
				gform_get_meta( $entry_id, 'chip_sub_remaining' ),
				$feed_value
			);

			if ( 0 === $remaining ) {
				break;
			}

			$seen[] = $remaining;
			gform_update_meta( $entry_id, 'chip_sub_remaining', $remaining - 1 );
		}

		$this->assertSame( array( 3, 2, 1 ), $seen, 'a 3-installment plan must count 3, 2, 1 and then stop' );
		$this->assertSame( 0, gform_get_meta( $entry_id, 'chip_sub_remaining' ) );
	}

	// ---------------------------------------------------------------------
	// The write side: a failed charge must RETURN the installment.
	//
	// handle_renewal_failure() restores the counter. With the old double that
	// write was discarded, so this could not be asserted.
	// ---------------------------------------------------------------------

	/**
	 * The restore write is observable, and the value is the pre-consume one.
	 */
	public function test_failed_charge_restores_the_installment_visibly(): void {
		$entry_id = 55;

		// Pre-charge state.
		gform_update_meta( $entry_id, 'chip_sub_remaining', 5 );

		// The pre-charge advance consumes one.
		$consumed = \GF_Chip_Renewals::resolve_remaining( gform_get_meta( $entry_id, 'chip_sub_remaining' ), 0 ) - 1;
		gform_update_meta( $entry_id, 'chip_sub_remaining', $consumed );
		$this->assertSame( 4, gform_get_meta( $entry_id, 'chip_sub_remaining' ) );

		// The charge fails; the failure handler restores.
		$restored = \GF_Chip_Renewals::restore_installment( gform_get_meta( $entry_id, 'chip_sub_remaining' ) );
		gform_update_meta( $entry_id, 'chip_sub_remaining', $restored );

		$this->assertSame( 5, gform_get_meta( $entry_id, 'chip_sub_remaining' ), 'a failed charge must not consume an installment' );
	}

	/**
	 * The restore is observable in the write log, so a test can assert it
	 * happened rather than only asserting the end state.
	 */
	public function test_restore_is_recorded_as_a_write(): void {
		$entry_id = 56;

		gform_update_meta( $entry_id, 'chip_sub_remaining', 4 );
		$restored = \GF_Chip_Renewals::restore_installment( gform_get_meta( $entry_id, 'chip_sub_remaining' ) );
		gform_update_meta( $entry_id, 'chip_sub_remaining', $restored );

		$writes = GF_Chip_Test_Meta::writes_for( 'chip_sub_remaining' );

		$this->assertCount( 2, $writes );
		$this->assertSame( 4, $writes[0]['value'] );
		$this->assertSame( 5, $writes[1]['value'], 'the restore must be written back' );
	}

	/**
	 * An unlimited plan stays unlimited through a failure — the restore must
	 * not turn 0 into 1.
	 */
	public function test_unlimited_plan_survives_a_failure_as_unlimited(): void {
		$entry_id = 57;

		gform_update_meta( $entry_id, 'chip_sub_remaining', 0 );

		$restored = \GF_Chip_Renewals::restore_installment( gform_get_meta( $entry_id, 'chip_sub_remaining' ) );
		gform_update_meta( $entry_id, 'chip_sub_remaining', $restored );

		$this->assertSame( 0, gform_get_meta( $entry_id, 'chip_sub_remaining' ) );
	}

	// ---------------------------------------------------------------------
	// The nonce gap from the card-update work.
	// ---------------------------------------------------------------------

	/**
	 * A stored nonce is readable, so the comparison inside validate() is now
	 * reachable by a test rather than only by live verification.
	 */
	public function test_stored_nonce_round_trips_and_compares(): void {
		$entry_id = 70;
		$nonce    = 'abc123def456';

		gform_update_meta( $entry_id, \GF_Chip_Card_Update::META_LINK_NONCE, $nonce );

		$this->assertTrue(
			\GF_Chip_Card_Update::nonce_matches( gform_get_meta( $entry_id, \GF_Chip_Card_Update::META_LINK_NONCE ), $nonce )
		);
		$this->assertFalse(
			\GF_Chip_Card_Update::nonce_matches( gform_get_meta( $entry_id, \GF_Chip_Card_Update::META_LINK_NONCE ), 'wrong' )
		);
	}

	/**
	 * consume_link() actually clears every component — assertable now because
	 * the deletes are recorded.
	 */
	public function test_consume_link_clears_every_component_observably(): void {
		$entry_id = 71;

		foreach ( \GF_Chip_Card_Update::link_meta_keys() as $key ) {
			gform_update_meta( $entry_id, $key, 'something' );
		}

		\GF_Chip_Card_Update::consume_link( $entry_id );

		foreach ( \GF_Chip_Card_Update::link_meta_keys() as $key ) {
			$this->assertTrue(
				GF_Chip_Test_Meta::was_deleted( $key ),
				"{$key} must be deleted by consume_link()"
			);
			$this->assertFalse(
				GF_Chip_Test_Meta::has( $entry_id, $key ),
				"{$key} must be gone after consume_link()"
			);
		}
	}
}
