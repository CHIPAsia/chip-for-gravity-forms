<?php
/**
 * Tests for the entry-meta test harness itself.
 *
 * The harness replaced empty no-ops, so its own behaviour has to be pinned
 * down. If the double lies — returns null where Gravity Forms returns '',
 * keeps a value it was told to delete, or loses a 0 — every test built on it
 * inherits that lie silently.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Test_Meta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Test_Meta
 */
class GF_Chip_Test_MetaTest extends TestCase {

	/**
	 * Clean slate per test.
	 */
	public function setUp(): void {
		GF_Chip_Test_Meta::reset();
	}

	// ---------------------------------------------------------------------
	// Read/write round-trip.
	// ---------------------------------------------------------------------

	/**
	 * A written value reads back identically.
	 */
	public function test_write_then_read_round_trips(): void {
		gform_update_meta( 42, 'chip_test_key', 'value' );

		$this->assertSame( 'value', gform_get_meta( 42, 'chip_test_key' ) );
	}

	/**
	 * A missing key returns '' — Gravity Forms' actual behaviour, not null.
	 *
	 * Code that checks `=== ''` would break against null, so this distinction
	 * matters.
	 */
	public function test_missing_key_returns_empty_string_not_null(): void {
		$value = gform_get_meta( 42, 'never_set' );

		$this->assertSame( '', $value );
		$this->assertNull( null === $value ? null : null );
		$this->assertFalse( is_null( $value ) );
	}

	/**
	 * Meta is scoped per entry id.
	 */
	public function test_meta_is_scoped_per_entry(): void {
		gform_update_meta( 1, 'chip_test_key', 'entry-one' );
		gform_update_meta( 2, 'chip_test_key', 'entry-two' );

		$this->assertSame( 'entry-one', gform_get_meta( 1, 'chip_test_key' ) );
		$this->assertSame( 'entry-two', gform_get_meta( 2, 'chip_test_key' ) );
		$this->assertSame( '', gform_get_meta( 3, 'chip_test_key' ) );
	}

	/**
	 * Meta is scoped per key.
	 */
	public function test_meta_is_scoped_per_key(): void {
		gform_update_meta( 42, 'key_a', 'a' );
		gform_update_meta( 42, 'key_b', 'b' );

		$this->assertSame( 'a', gform_get_meta( 42, 'key_a' ) );
		$this->assertSame( 'b', gform_get_meta( 42, 'key_b' ) );
	}

	/**
	 * A later write replaces the earlier value.
	 */
	public function test_write_overwrites(): void {
		gform_update_meta( 42, 'k', 'first' );
		gform_update_meta( 42, 'k', 'second' );

		$this->assertSame( 'second', gform_get_meta( 42, 'k' ) );
	}

	// ---------------------------------------------------------------------
	// Falsy values — the classic place a double lies.
	// ---------------------------------------------------------------------

	/**
	 * A stored `0` reads back as `0`, not as ''.
	 *
	 * This is load-bearing: an installment counter of 0 means "finished", and
	 * a double that turned 0 into '' would make every finite plan look
	 * unlimited in tests.
	 */
	public function test_stored_zero_is_not_lost(): void {
		gform_update_meta( 42, 'counter', 0 );

		$this->assertSame( 0, gform_get_meta( 42, 'counter' ) );
		$this->assertNotSame( '', gform_get_meta( 42, 'counter' ) );
		$this->assertTrue( GF_Chip_Test_Meta::has( 42, 'counter' ) );
	}

	/**
	 * A stored string '0' reads back as '0'.
	 */
	public function test_stored_string_zero_is_not_lost(): void {
		gform_update_meta( 42, 'counter', '0' );

		$this->assertSame( '0', gform_get_meta( 42, 'counter' ) );
	}

	/**
	 * A stored false reads back as false.
	 */
	public function test_stored_false_is_not_lost(): void {
		gform_update_meta( 42, 'flag', false );

		$this->assertFalse( gform_get_meta( 42, 'flag' ) );
		$this->assertTrue( GF_Chip_Test_Meta::has( 42, 'flag' ) );
	}

	/**
	 * has() distinguishes a stored '' from an absent key, which get() cannot
	 * because Gravity Forms collapses both to ''.
	 */
	public function test_has_distinguishes_empty_from_absent(): void {
		gform_update_meta( 42, 'empty', '' );

		$this->assertTrue( GF_Chip_Test_Meta::has( 42, 'empty' ), 'stored empty string must count as present' );
		$this->assertFalse( GF_Chip_Test_Meta::has( 42, 'absent' ) );
		$this->assertSame( '', gform_get_meta( 42, 'empty' ) );
		$this->assertSame( '', gform_get_meta( 42, 'absent' ) );
	}

	// ---------------------------------------------------------------------
	// Deletion.
	// ---------------------------------------------------------------------

	/**
	 * A deleted key reads back as ''.
	 */
	public function test_delete_removes_the_value(): void {
		gform_update_meta( 42, 'k', 'value' );
		gform_delete_meta( 42, 'k' );

		$this->assertSame( '', gform_get_meta( 42, 'k' ) );
		$this->assertFalse( GF_Chip_Test_Meta::has( 42, 'k' ) );
	}

	/**
	 * Deleting one key leaves others intact.
	 */
	public function test_delete_is_scoped(): void {
		gform_update_meta( 42, 'keep', 'kept' );
		gform_update_meta( 42, 'drop', 'dropped' );

		gform_delete_meta( 42, 'drop' );

		$this->assertSame( 'kept', gform_get_meta( 42, 'keep' ) );
		$this->assertSame( '', gform_get_meta( 42, 'drop' ) );
	}

	// ---------------------------------------------------------------------
	// Call recording — what makes write/delete behaviour assertable.
	// ---------------------------------------------------------------------

	/**
	 * Writes are recorded in order with their values.
	 */
	public function test_writes_are_recorded(): void {
		gform_update_meta( 42, 'a', 1 );
		gform_update_meta( 42, 'b', 2 );

		$writes = GF_Chip_Test_Meta::writes();

		$this->assertCount( 2, $writes );
		$this->assertSame( 'a', $writes[0]['key'] );
		$this->assertSame( 1, $writes[0]['value'] );
		$this->assertSame( 2, $writes[1]['value'] );
	}

	/**
	 * Writes can be filtered by key, so a test can assert how many times one
	 * specific value was written.
	 */
	public function test_writes_can_be_filtered_by_key(): void {
		gform_update_meta( 42, 'counter', 4 );
		gform_update_meta( 42, 'other', 'x' );
		gform_update_meta( 42, 'counter', 3 );

		$counter_writes = GF_Chip_Test_Meta::writes_for( 'counter' );

		$this->assertCount( 2, $counter_writes );
		$this->assertSame( 4, $counter_writes[0]['value'] );
		$this->assertSame( 3, $counter_writes[1]['value'] );
	}

	/**
	 * A delete is recorded, so "was this key cleared" is assertable — this is
	 * exactly what the consume_link() gap needed.
	 */
	public function test_deletes_are_recorded(): void {
		gform_delete_meta( 42, 'chip_card_update_nonce' );

		$this->assertTrue( GF_Chip_Test_Meta::was_deleted( 'chip_card_update_nonce' ) );
		$this->assertFalse( GF_Chip_Test_Meta::was_deleted( 'something_else' ) );
	}

	/**
	 * reset() clears values, writes and deletes.
	 */
	public function test_reset_clears_all_state(): void {
		gform_update_meta( 42, 'k', 'v' );
		gform_delete_meta( 42, 'k' );

		GF_Chip_Test_Meta::reset();

		$this->assertSame( '', gform_get_meta( 42, 'k' ) );
		$this->assertCount( 0, GF_Chip_Test_Meta::writes() );
		$this->assertCount( 0, GF_Chip_Test_Meta::deletes() );
		$this->assertFalse( GF_Chip_Test_Meta::has( 42, 'k' ) );
	}

	// ---------------------------------------------------------------------
	// The proof the harness actually unblocks something.
	// ---------------------------------------------------------------------

	/**
	 * THE POINT OF THIS HARNESS: a write followed by a read now observes the
	 * written value, where before the no-op read always returned ''.
	 *
	 * This mirrors the exact shape of the installment-counter defect: the code
	 * wrote chip_sub_remaining and then read it back, and the old no-op double
	 * made that sequence look like it worked.
	 */
	public function test_write_read_matches_production_shape(): void {
		gform_update_meta( 99, 'chip_sub_remaining', 11 );

		$read_back = gform_get_meta( 99, 'chip_sub_remaining' );

		$this->assertSame( 11, $read_back, 'a written counter must read back — this is the defect the harness closes' );
		$this->assertNotSame( '', $read_back );
	}
}
