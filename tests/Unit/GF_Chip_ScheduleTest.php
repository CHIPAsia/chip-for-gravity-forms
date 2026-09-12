<?php
/**
 * Unit tests for GF_Chip_Schedule billing cycle maths.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Schedule;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \GF_Chip_Schedule
 */
class GF_Chip_ScheduleTest extends TestCase {

	/**
	 * UTC, matching how Gravity Forms stores dates.
	 *
	 * @var DateTimeZone
	 */
	private $utc;

	/**
	 * Set up the timezone fixture.
	 */
	public function setUp(): void {
		$this->utc = new DateTimeZone( 'UTC' );
	}

	/**
	 * Build a UTC date for a test case.
	 *
	 * @param string $date Date string.
	 * @return DateTimeImmutable
	 */
	private function d( $date ) {
		return new DateTimeImmutable( $date, $this->utc );
	}

	// ---------------------------------------------------------------------
	// Month arithmetic must clamp, not overflow.
	//
	// PHP's modify('+1 month') overflows: 31 Jan + 1 month yields 3 Mar.
	// A subscription billed on the 31st would silently jump months and bill
	// the customer on the wrong day. These are the cases that matter.
	// ---------------------------------------------------------------------

	/**
	 * 31 Jan + 1 month clamps to the last day of February (non-leap).
	 */
	public function test_one_month_from_jan_31_clamps_to_feb_28_non_leap(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-31' ), 1, 'month' );
		$this->assertSame( '2026-02-28', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 31 Jan + 1 month clamps to 29 Feb in a leap year.
	 */
	public function test_one_month_from_jan_31_clamps_to_feb_29_in_leap_year(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2024-01-31' ), 1, 'month' );
		$this->assertSame( '2024-02-29', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 31 Mar + 1 month clamps to 30 April, not 1 May.
	 */
	public function test_one_month_from_mar_31_clamps_to_apr_30(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-03-31' ), 1, 'month' );
		$this->assertSame( '2026-04-30', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 31 May + 1 month clamps to 30 June.
	 */
	public function test_one_month_from_may_31_clamps_to_jun_30(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-05-31' ), 1, 'month' );
		$this->assertSame( '2026-06-30', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 31 Aug + 1 month clamps to 30 September.
	 */
	public function test_one_month_from_aug_31_clamps_to_sep_30(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-08-31' ), 1, 'month' );
		$this->assertSame( '2026-09-30', $result->format( 'Y-m-d' ) );
	}

	/**
	 * A day that exists in both months is carried over unchanged.
	 */
	public function test_one_month_from_mid_month_is_unchanged_day(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-15' ), 1, 'month' );
		$this->assertSame( '2026-02-15', $result->format( 'Y-m-d' ) );
	}

	/**
	 * Month addition rolls the year over correctly.
	 */
	public function test_one_month_from_dec_31_rolls_into_next_year(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-12-31' ), 1, 'month' );
		$this->assertSame( '2027-01-31', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 31 Jan + 3 months clamps to 30 April.
	 */
	public function test_three_months_from_jan_31_clamps_to_apr_30(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-31' ), 3, 'month' );
		$this->assertSame( '2026-04-30', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 31 Aug + 6 months crosses into the next year and clamps to 28 Feb.
	 */
	public function test_six_months_from_aug_31_clamps_across_year_boundary(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-08-31' ), 6, 'month' );
		$this->assertSame( '2027-02-28', $result->format( 'Y-m-d' ) );
	}

	/**
	 * 29 Feb + 1 year clamps to 28 Feb, not 1 March.
	 */
	public function test_one_year_from_feb_29_clamps_to_feb_28(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2024-02-29' ), 1, 'year' );
		$this->assertSame( '2025-02-28', $result->format( 'Y-m-d' ) );
	}

	// ---------------------------------------------------------------------
	// Day and week units.
	// ---------------------------------------------------------------------

	/**
	 * Adding days is exact.
	 */
	public function test_add_days(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-31' ), 5, 'day' );
		$this->assertSame( '2026-02-05', $result->format( 'Y-m-d' ) );
	}

	/**
	 * Adding weeks is exact.
	 */
	public function test_add_weeks(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-01' ), 2, 'week' );
		$this->assertSame( '2026-01-15', $result->format( 'Y-m-d' ) );
	}

	// ---------------------------------------------------------------------
	// Guards and fallbacks — a misconfigured feed must not throw.
	// ---------------------------------------------------------------------

	/**
	 * An unrecognised unit falls back to months rather than throwing.
	 */
	public function test_unknown_unit_falls_back_to_month(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-15' ), 1, 'fortnight' );
		$this->assertSame( '2026-02-15', $result->format( 'Y-m-d' ) );
	}

	/**
	 * An empty unit falls back to months rather than throwing.
	 */
	public function test_empty_unit_falls_back_to_month(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-15' ), 1, '' );
		$this->assertSame( '2026-02-15', $result->format( 'Y-m-d' ) );
	}

	/**
	 * A zero length is clamped to one rather than producing a zero interval.
	 */
	public function test_zero_length_is_clamped_to_one(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-15' ), 0, 'month' );
		$this->assertSame( '2026-02-15', $result->format( 'Y-m-d' ) );
	}

	/**
	 * A negative length is clamped to one rather than moving backwards.
	 */
	public function test_negative_length_is_clamped_to_one(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-15' ), -5, 'month' );
		$this->assertSame( '2026-02-15', $result->format( 'Y-m-d' ) );
	}

	// ---------------------------------------------------------------------
	// Fidelity: time of day and timezone must survive.
	// ---------------------------------------------------------------------

	/**
	 * The time of day is preserved when adding months.
	 */
	public function test_time_of_day_is_preserved_across_month_addition(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-31 14:30:45' ), 1, 'month' );
		$this->assertSame( '2026-02-28 14:30:45', $result->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * The timezone is preserved.
	 */
	public function test_timezone_is_preserved(): void {
		$result = GF_Chip_Schedule::next_payment_date( $this->d( '2026-01-15 09:00:00' ), 1, 'month' );
		$this->assertSame( 'UTC', $result->getTimezone()->getName() );
	}

	/**
	 * Adding weeks preserves the local wall-clock time across a DST shift.
	 *
	 * Gravity Forms stores UTC so this is defensive, but a subscription
	 * scheduled at 09:00 local must not drift to 08:00 after a DST change.
	 */
	public function test_adding_weeks_preserves_local_time_across_dst(): void {
		$ny     = new DateTimeZone( 'America/New_York' );
		$from   = new DateTimeImmutable( '2026-03-05 09:00:00', $ny );
		$result = GF_Chip_Schedule::next_payment_date( $from, 1, 'week' );
		$this->assertSame( '2026-03-12 09:00:00', $result->format( 'Y-m-d H:i:s' ) );
	}

	// ---------------------------------------------------------------------
	// apply_cycle: remaining count and expiry.
	// ---------------------------------------------------------------------

	/**
	 * Remaining 0 means infinite: schedule advances, never expires.
	 */
	public function test_apply_cycle_infinite_advances_and_never_expires(): void {
		$result = GF_Chip_Schedule::apply_cycle( $this->d( '2026-01-15' ), 1, 'month', 0 );

		$this->assertFalse( $result['expired'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertInstanceOf( DateTimeImmutable::class, $result['next'] );
		$this->assertSame( '2026-02-15', $result['next']->format( 'Y-m-d' ) );
	}

	/**
	 * Remaining 3 advances the schedule and decrements to 2.
	 */
	public function test_apply_cycle_remaining_three_decrements(): void {
		$result = GF_Chip_Schedule::apply_cycle( $this->d( '2026-01-15' ), 1, 'month', 3 );

		$this->assertFalse( $result['expired'] );
		$this->assertSame( 2, $result['remaining'] );
		$this->assertSame( '2026-02-15', $result['next']->format( 'Y-m-d' ) );
	}

	/**
	 * Remaining 2 decrements to 1 and still schedules.
	 */
	public function test_apply_cycle_remaining_two_decrements_to_one(): void {
		$result = GF_Chip_Schedule::apply_cycle( $this->d( '2026-01-15' ), 1, 'month', 2 );

		$this->assertFalse( $result['expired'] );
		$this->assertSame( 1, $result['remaining'] );
		$this->assertSame( '2026-02-15', $result['next']->format( 'Y-m-d' ) );
	}

	/**
	 * Remaining 1 is the last cycle: expire, do not schedule another.
	 */
	public function test_apply_cycle_remaining_one_expires_without_next_date(): void {
		$result = GF_Chip_Schedule::apply_cycle( $this->d( '2026-01-15' ), 1, 'month', 1 );

		$this->assertTrue( $result['expired'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertNull( $result['next'] );
	}

	/**
	 * A negative remaining count expires rather than scheduling.
	 */
	public function test_apply_cycle_negative_remaining_expires(): void {
		$result = GF_Chip_Schedule::apply_cycle( $this->d( '2026-01-15' ), 1, 'month', -1 );

		$this->assertTrue( $result['expired'] );
		$this->assertNull( $result['next'] );
	}
}
