<?php
/**
 * Subscription billing cycle maths.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure billing-cycle arithmetic for subscriptions.
 *
 * Kept free of WordPress and Gravity Forms calls so it can be unit tested
 * exhaustively — a mistake here silently bills a customer on the wrong day.
 */
class GF_Chip_Schedule {

	/**
	 * Advances a date by one billing cycle.
	 *
	 * Month and year arithmetic intentionally does NOT use
	 * `modify( '+N month' )`: PHP overflows rather than clamps, so
	 * 31 Jan + 1 month yields 3 Mar instead of the 28 Feb a customer on a
	 * monthly plan expects. Instead the target month is entered on its first
	 * day and the original day-of-month is clamped to that month's length.
	 *
	 * @param DateTimeImmutable $from   Start date. Time of day and timezone are preserved.
	 * @param int               $length Number of units. Values below 1 are clamped to 1.
	 * @param string            $unit   One of day, week, month, year. Anything else falls back to month.
	 * @return DateTimeImmutable The next payment date.
	 */
	public static function next_payment_date( DateTimeImmutable $from, $length, $unit ) {
		$length = (int) $length;
		if ( $length < 1 ) {
			$length = 1;
		}

		switch ( strtolower( trim( (string) $unit ) ) ) {
			case 'day':
				return $from->modify( "+{$length} day" );
			case 'week':
				return $from->modify( "+{$length} week" );
			case 'year':
				// Years are twelve clamped months: 29 Feb + 1 year must be
				// 28 Feb, not the 1 Mar that modify( '+1 year' ) produces.
				return self::add_months_clamped( $from, $length * 12 );
			case 'month':
			default:
				return self::add_months_clamped( $from, $length );
		}
	}

	/**
	 * Computes the next payment date and whether the subscription has ended.
	 *
	 * @param DateTimeImmutable $from      Start date.
	 * @param int               $length    Number of units per cycle.
	 * @param string            $unit      One of day, week, month, year.
	 * @param int               $remaining Cycles left, 0 meaning infinite.
	 * @return array{next: DateTimeImmutable|null, remaining: int, expired: bool}
	 *               `next` is null when the subscription has expired.
	 */
	public static function apply_cycle( DateTimeImmutable $from, $length, $unit, $remaining ) {
		$remaining = (int) $remaining;

		if ( 0 === $remaining ) {
			return array(
				'next'      => self::next_payment_date( $from, $length, $unit ),
				'remaining' => 0,
				'expired'   => false,
			);
		}

		// A finite plan with one cycle left has just paid its last one.
		// A negative count is treated the same way rather than scheduling.
		if ( $remaining <= 1 ) {
			return array(
				'next'      => null,
				'remaining' => 0,
				'expired'   => true,
			);
		}

		return array(
			'next'      => self::next_payment_date( $from, $length, $unit ),
			'remaining' => $remaining - 1,
			'expired'   => false,
		);
	}

	/**
	 * Adds whole months, clamping the day-of-month to the target month.
	 *
	 * The date is moved to the first of the target month before the day is
	 * applied, so the intermediate value can never overflow into a third
	 * month. `setDate()` normalises an out-of-range month, so 12 + 1 becomes
	 * January of the following year.
	 *
	 * @param DateTimeImmutable $from   Start date.
	 * @param int               $months Months to add. May exceed 12.
	 * @return DateTimeImmutable
	 */
	private static function add_months_clamped( DateTimeImmutable $from, $months ) {
		$day = (int) $from->format( 'j' );

		$first_of_target = $from->setDate(
			(int) $from->format( 'Y' ),
			(int) $from->format( 'n' ) + (int) $months,
			1
		);

		$last_day_of_target = (int) $first_of_target->format( 't' );

		return $first_of_target->setDate(
			(int) $first_of_target->format( 'Y' ),
			(int) $first_of_target->format( 'n' ),
			min( $day, $last_day_of_target )
		);
	}
}
