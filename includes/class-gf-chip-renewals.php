<?php
/**
 * Subscription renewal engine.
 *
 * Gravity Forms ships no renewal engine: GFPaymentAddOn::setup_cron()
 * schedules an hourly cron that calls check_status(), whose core body is
 * empty. This class is that body.
 *
 * Design notes that matter:
 *
 * - The due-selection and retry decisions live in pure static methods so
 *   idempotency can be unit tested. WordPress and API I/O sits in the thin
 *   methods around them.
 * - The schedule is advanced BEFORE the charge is attempted. A crash
 *   mid-charge must not leave the subscription permanently due, which would
 *   re-charge it on every subsequent cron run.
 * - Everything is UTC. Gravity Forms stores UTC, and a local-time comparison
 *   would shift the due window by the site's offset.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renewal engine for CHIP subscriptions.
 */
class GF_Chip_Renewals {

	/**
	 * Days after the due date to retry a failed renewal.
	 *
	 * Three attempts. Offsets are measured from the original due date, not
	 * from the previous attempt, so a slow cron cannot stretch the ladder.
	 *
	 * @var array
	 */
	const RETRY_OFFSETS_DAYS = array( 1, 3, 5 );

	/**
	 * Maximum charges attempted for one subscription inside the window.
	 *
	 * Visa and Mastercard cap retries per card per 30 days and levy penalty
	 * fees above the cap, so it is enforced here rather than left to the
	 * operator to remember.
	 *
	 * @var int
	 */
	const NETWORK_MAX_ATTEMPTS = 15;

	/**
	 * Rolling window for the card-network retry cap, in days.
	 *
	 * @var int
	 */
	const NETWORK_WINDOW_DAYS = 30;

	/**
	 * Subscriptions processed in one cron run.
	 *
	 * Bounded so a single run cannot exhaust the PHP time limit and leave
	 * later subscriptions unprocessed.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Seconds in one day.
	 *
	 * Declared locally rather than using WordPress' DAY_IN_SECONDS so this
	 * class stays free of WordPress constant dependencies and remains
	 * unit testable without loading WordPress.
	 *
	 * @var int
	 */
	const SECONDS_PER_DAY = 86400;

	/**
	 * The cron hook Gravity Forms schedules for us.
	 *
	 * Core builds this as "{$slug}_cron" in GFPaymentAddOn::setup_cron(),
	 * where the slug is the add-on's _slug. Ours is 'gravityformschip'.
	 * Getting it wrong means the runner is never invoked at all and renewals
	 * silently never happen, so a test asserts the exact value.
	 *
	 * @return string
	 */
	public static function cron_hook() {
		return 'gravityformschip_cron';
	}

	/**
	 * Charges one subscription.
	 *
	 * The scheduled action is created entirely by Gravity Forms core: its
	 * pre_init() calls setup_cron() when the add-on overrides check_status(),
	 * and setup_cron() schedules "{$slug}_cron" hourly, calling check_status().
	 * Nothing here schedules or clears a cron — doing so would create a
	 * second, competing schedule beside core's, and the schedule key would
	 * not match core's anyway.
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @return array Result with a status of charged|failed|skipped|expired.
	 */
	public static function charge( $entry ) {
		$chip = GF_Chip::get_instance();

		if ( null === $chip ) {
			return array(
				'status' => 'skipped',
				'note'   => '',
			);
		}

		return $chip->charge_renewal( $entry );
	}

	// -----------------------------------------------------------------
	// Pure decision logic — no I/O, exhaustively unit tested.
	// -----------------------------------------------------------------

	/**
	 * Whether a subscription is due to be charged.
	 *
	 * Pure: takes the entry's stored state and the current time.
	 *
	 * @param array  $entry Entry array with chip_sub_* meta flattened in.
	 * @param string $now   Current UTC time, 'Y-m-d H:i:s'.
	 * @return bool
	 */
	public static function is_due( $entry, $now ) {
		// A one-time payment is not a subscription. Checked explicitly rather
		// than relying on chip_sub_status, which a stray meta write could set.
		if ( ! GF_Chip::is_subscription_entry( $entry ) ) {
			return false;
		}

		// Only an active subscription is charged. Cancelled, expired,
		// on-hold and pending are all deliberately excluded.
		if ( 'active' !== GF_Chip::get_subscription_state( $entry ) ) {
			return false;
		}

		// Without a token there is nothing to charge. Treating this as "not
		// due" rather than charging and failing keeps the loop from spinning;
		// the missing token was already surfaced when it was first noticed.
		if ( empty( $entry['chip_recurring_token'] ) ) {
			return false;
		}

		$next = rgar( $entry, 'chip_sub_next_payment' );

		if ( empty( $next ) ) {
			return false;
		}

		return self::compare_datetime( $next, $now ) <= 0;
	}

	/**
	 * The next attempt time after a failed charge.
	 *
	 * Returns null when the retry ladder is exhausted, which the caller
	 * treats as "expire this subscription".
	 *
	 * @param string $due_date    Original due date, UTC 'Y-m-d H:i:s'.
	 * @param int    $retry_count Failed attempts so far, 0-based.
	 * @return string|null UTC datetime, or null when exhausted.
	 */
	public static function next_retry_at( $due_date, $retry_count ) {
		$retry_count = (int) $retry_count;

		if ( $retry_count >= count( self::RETRY_OFFSETS_DAYS ) ) {
			return null;
		}

		$offset = self::RETRY_OFFSETS_DAYS[ $retry_count ];
		$base   = self::to_timestamp( $due_date );

		if ( false === $base ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $base + ( $offset * self::SECONDS_PER_DAY ) );
	}

	/**
	 * Whether another charge would breach the card-network retry cap.
	 *
	 * @param int $attempts_in_window Charges already attempted in the window.
	 * @return bool True when charging must stop.
	 */
	public static function network_cap_reached( $attempts_in_window ) {
		return (int) $attempts_in_window >= self::NETWORK_MAX_ATTEMPTS;
	}

	/**
	 * Compares two 'Y-m-d H:i:s' strings.
	 *
	 * String comparison rather than strtotime(): both values are already
	 * normalised UTC in a sortable format, and parsing would reintroduce a
	 * timezone dependency where the default zone quietly applies.
	 *
	 * @param string $a Left-hand datetime.
	 * @param string $b Right-hand datetime.
	 * @return int Negative when $a is earlier, 0 when equal, positive when later.
	 */
	public static function compare_datetime( $a, $b ) {
		return strcmp( (string) $a, (string) $b );
	}

	/**
	 * Converts a UTC datetime string to a timestamp.
	 *
	 * @param string $datetime UTC 'Y-m-d H:i:s'.
	 * @return int|false
	 */
	private static function to_timestamp( $datetime ) {
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $datetime, new DateTimeZone( 'UTC' ) );

		return $parsed instanceof DateTimeImmutable ? $parsed->getTimestamp() : false;
	}

	/**
	 * Decides what a renewal run should do with one subscription.
	 *
	 * This is the idempotency guard, and it is the most important function in
	 * the feature: it decides whether money moves. It is pure, so the
	 * "charged twice" case can be tested directly rather than reasoned about.
	 *
	 * The returned `claim` is the next-payment date to write BEFORE charging.
	 * Writing it first is what makes a repeated run safe: the second run sees
	 * a date in the future and skips. A crash after the claim costs one cycle
	 * (recoverable from the entry notes) rather than double-charging a
	 * customer, which is the worse failure by a wide margin.
	 *
	 * @param array  $entry Entry with chip_sub_* meta flattened in.
	 * @param string $now   Current UTC datetime.
	 * @param int    $length Billing cycle length.
	 * @param string $unit   Billing cycle unit.
	 * @param int    $remaining Remaining cycles, 0 meaning infinite.
	 * @return array {
	 *     @type string      $action    charge|skip|expire.
	 *     @type string|null $claim     Next payment date to write before charging.
	 *     @type int         $remaining Remaining cycles after this one.
	 * }
	 */
	public static function plan_renewal( $entry, $now, $length, $unit, $remaining ) {
		if ( ! self::is_due( $entry, $now ) ) {
			return array(
				'action'    => 'skip',
				'claim'     => null,
				'remaining' => (int) $remaining,
			);
		}

		// Anchor the cycle on the date that was due, not on "now", so a late
		// cron run does not drift the billing day forward.
		$anchor = self::to_timestamp( rgar( $entry, 'chip_sub_next_payment' ) );
		if ( false === $anchor ) {
			return array(
				'action'    => 'skip',
				'claim'     => null,
				'remaining' => (int) $remaining,
			);
		}

		$from  = new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $anchor ), new DateTimeZone( 'UTC' ) );
		$cycle = GF_Chip_Schedule::apply_cycle( $from, $length, $unit, $remaining );

		if ( $cycle['expired'] ) {
			// This was the final instalment: charge it, then expire rather
			// than schedule another cycle.
			return array(
				'action'    => 'charge',
				'claim'     => null,
				'remaining' => 0,
			);
		}

		return array(
			'action'    => 'charge',
			'claim'     => $cycle['next']->format( 'Y-m-d H:i:s' ),
			'remaining' => (int) $cycle['remaining'],
		);
	}
}
