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
	 * @param bool   $force Attempt a charge even when the subscription is
	 *                      on-hold. Used only by an operator-initiated retry;
	 *                      the cron must never set this, or it would bypass the
	 *                      dunning ladder on every run.
	 * @return bool
	 */
	public static function is_due( $entry, $now, $force = false ) {
		// A one-time payment is not a subscription. Checked explicitly rather
		// than relying on chip_sub_status, which a stray meta write could set.
		if ( ! GF_Chip::is_subscription_entry( $entry ) ) {
			return false;
		}

		// Only an active subscription is charged by the cron. Cancelled,
		// expired, pending and on-hold are all deliberately excluded -- on-hold
		// specifically because the dunning ladder owns it and a cron that
		// ignored that would fire a charge on every run.
		//
		// $force is the operator override: a human pressed Retry, so an
		// on-hold subscription may be attempted. It still consumes a ladder
		// slot, so it cannot be used to spam charges.
		$state = GF_Chip::get_subscription_state( $entry );

		if ( 'active' !== $state && ! ( $force && 'on-hold' === $state ) ) {
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
	 * The next attempt time after a failed charge, anchored on the plan.
	 *
	 * The ladder must be measured from the date that was DUE. Reading
	 * chip_sub_next_payment here would return the date the pre-charge advance
	 * already wrote -- one whole cycle ahead -- so the retry would fire on the
	 * next billing date instead of a day after the miss, giving the customer a
	 * free cycle. The plan carries the value it actually anchored on.
	 *
	 * Extracted so the wiring is testable: the caller path needs a live CHIP
	 * API, this decision does not.
	 *
	 * @param array $plan        A plan_renewal() result.
	 * @param int   $retry_count Failed attempts so far, including this one.
	 * @return string|null UTC datetime, or null when the ladder is exhausted.
	 */
	public static function next_attempt_from_plan( $plan, $retry_count ) {
		$anchor = rgar( (array) $plan, 'due_anchor' );

		if ( empty( $anchor ) ) {
			// No anchor means there is no safe retry time. Expire rather than
			// schedule an attempt against an unknown date.
			return null;
		}

		return self::next_retry_at( $anchor, $retry_count );
	}

	/**
	 * Resolves how many installments remain for a subscription.
	 *
	 * A finite plan must count down across cycles, so the stored counter wins
	 * over the feed value once it exists. Re-reading recurringTimes from the
	 * feed every run would mean a 12-installment plan charges forever: the
	 * counter would reset to 12 on every cycle and never reach zero.
	 *
	 * An empty/absent stored value means the plan has not started counting
	 * yet, so the feed value applies. A stored `0` is a real value and is
	 * honoured — it means the plan is finished.
	 *
	 * @param mixed $stored_value chip_sub_remaining as read back.
	 * @param int   $feed_value   recurringTimes from the feed; 0 means unlimited.
	 * @return int Installments remaining; 0 means none left or unlimited.
	 */
	public static function resolve_remaining( $stored_value, $feed_value ) {
		$feed_value = max( 0, (int) $feed_value );

		if ( '' === $stored_value || null === $stored_value ) {
			return $feed_value;
		}

		// A non-numeric stored value must not silently end or reset a plan.
		if ( ! is_numeric( $stored_value ) ) {
			return $feed_value;
		}

		return max( 0, (int) $stored_value );
	}

	/**
	 * Returns an installment consumed by a charge attempt that failed.
	 *
	 * The counter is decremented during the pre-charge advance so a crash
	 * cannot double-charge. If the charge then fails, the installment must be
	 * given back — otherwise the customer is billed fewer times than agreed
	 * (a 5-installment plan delivering only 4).
	 *
	 * Unlimited plans stay unlimited: restoring 0 must not turn one into a
	 * finite plan with a single installment.
	 *
	 * @param int $remaining Counter value after the failed attempt.
	 * @return int Counter value to store.
	 */
	public static function restore_installment( $remaining ) {
		$remaining = (int) $remaining;

		if ( 0 === $remaining ) {
			return 0;
		}

		return max( 1, $remaining + 1 );
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
	 * @param bool   $force     Attempt a charge even when on-hold. Only the
	 *                          operator-initiated retry sets this; the cron
	 *                          must not, or it would bypass the ladder.
	 * @return array {
	 *     @type string      $action    charge|skip|expire.
	 *     @type string|null $claim     Next payment date to write before charging.
	 *     @type string|null $due_anchor The due date this plan was anchored on.
	 *     @type int         $remaining Remaining cycles after this one.
	 * }
	 */
	public static function plan_renewal( $entry, $now, $length, $unit, $remaining, $force = false ) {
		if ( ! self::is_due( $entry, $now, $force ) ) {
			return array(
				'action'     => 'skip',
				'claim'      => null,
				'remaining'  => (int) $remaining,
				'due_anchor' => null,
			);
		}

		// Anchor the cycle on the date that was due, not on "now", so a late
		// cron run does not drift the billing day forward.
		$due_date = (string) rgar( $entry, 'chip_sub_next_payment' );
		$anchor   = self::to_timestamp( $due_date );

		if ( false === $anchor ) {
			return array(
				'action'     => 'skip',
				'claim'      => null,
				'remaining'  => (int) $remaining,
				'due_anchor' => null,
			);
		}

		$from  = new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $anchor ), new DateTimeZone( 'UTC' ) );
		$cycle = GF_Chip_Schedule::apply_cycle( $from, $length, $unit, $remaining );

		if ( $cycle['expired'] ) {
			// This was the final instalment: charge it, then expire rather
			// than schedule another cycle.
			return array(
				'action'     => 'charge',
				'claim'      => null,
				'remaining'  => 0,
				'due_anchor' => $due_date,
			);
		}

		return array(
			'action'     => 'charge',
			'claim'      => $cycle['next']->format( 'Y-m-d H:i:s' ),
			'remaining'  => (int) $cycle['remaining'],
			'due_anchor' => $due_date,
		);
	}
}
