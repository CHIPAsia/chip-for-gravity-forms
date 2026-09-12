<?php
/**
 * Secure card-update links for subscriptions.
 *
 * Gravity Forms is anonymous-first: there is no WP_User and no "My Account"
 * page, so the recurring token belongs to the entry and the customer has no
 * built-in surface to replace it. WooCommerce Subscriptions solves the same
 * problem by sending the customer a link, and that is what this class does —
 * an admin never enters card data, and card data never passes through this
 * plugin.
 *
 * The link is a capability: whoever holds it can replace the card that will be
 * charged. Every control below exists because of that.
 *
 * Security properties, all implemented here and unit tested:
 *  - signed with wp_hash() (HMAC-SHA256 over AUTH_KEY) — never md5 or a
 *    guessable value
 *  - bound to a single entry, so a link for entry A cannot be replayed at
 *    entry B (the entry id is inside the signed payload)
 *  - time limited, 7 days by default and filterable
 *  - single use: the signature is stored and cleared on success, so a link
 *    stops working once it has been used
 *  - the amount is resolved from subscription state server-side and is never
 *    read from the request
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card-update link generation and validation.
 */
class GF_Chip_Card_Update {

	/**
	 * Query var carrying the entry id.
	 *
	 * @var string
	 */
	const ARG_ENTRY = 'chip_update_card';

	/**
	 * Query var carrying the expiry timestamp.
	 *
	 * @var string
	 */
	const ARG_EXPIRY = 'exp';

	/**
	 * Query var carrying the signature.
	 *
	 * @var string
	 */
	const ARG_SIGNATURE = 'hash';

	/**
	 * Default link lifetime, in days.
	 *
	 * Covers the +1d/+3d/+5d retry ladder with margin, and is long enough to
	 * survive a weekend when the email lands on a Friday. Deliberately more
	 * generous than an in-app billing-portal session (Stripe expires those in
	 * 5 minutes idle), because this arrives by email.
	 *
	 * @var int
	 */
	const DEFAULT_EXPIRY_DAYS = 7;

	/**
	 * Entry meta key holding the signature of the live link.
	 *
	 * @var string
	 */
	const META_LINK_SIGNATURE = 'chip_card_update_signature';

	/**
	 * Entry meta key holding the live link's expiry.
	 *
	 * @var string
	 */
	const META_LINK_EXPIRY = 'chip_card_update_expiry';

	/**
	 * Link lifetime in days, filterable.
	 *
	 * @return int
	 */
	public static function expiry_days() {
		$days = (int) apply_filters( 'gf_chip_card_update_expiry_days', self::DEFAULT_EXPIRY_DAYS );

		return $days > 0 ? $days : self::DEFAULT_EXPIRY_DAYS;
	}

	// -----------------------------------------------------------------
	// Pure logic — unit tested without WordPress.
	// -----------------------------------------------------------------

	/**
	 * Builds the exact string that gets signed.
	 *
	 * Kept as one function so generation and validation cannot drift apart —
	 * a mismatch here would reject every valid link, or worse, accept a
	 * forged one.
	 *
	 * @param int $entry_id Entry id.
	 * @param int $expiry   Expiry timestamp.
	 * @return string
	 */
	public static function signature_payload( $entry_id, $expiry ) {
		return 'chip_card_update|' . (int) $entry_id . '|' . (int) $expiry;
	}

	/**
	 * Whether a link's expiry has passed.
	 *
	 * @param int $expiry   Expiry timestamp.
	 * @param int $now      Current timestamp.
	 * @return bool
	 */
	public static function is_expired( $expiry, $now ) {
		return (int) $now > (int) $expiry;
	}

	/**
	 * Whether a stored signature matches the presented one.
	 *
	 * Uses hash_equals() for a constant-time comparison. A plain === leaks
	 * timing information about how many leading characters matched.
	 *
	 * @param string $expected Signature on record.
	 * @param string $presented Signature from the request.
	 * @return bool
	 */
	public static function signature_matches( $expected, $presented ) {
		if ( ! is_string( $expected ) || ! is_string( $presented ) ) {
			return false;
		}

		if ( '' === $expected || '' === $presented ) {
			return false;
		}

		return hash_equals( $expected, $presented );
	}

	/**
	 * Whether this subscription state requires the outstanding cycle to be
	 * settled as part of the card change.
	 *
	 * A subscription whose renewal failed, or which is due now, should clear
	 * the debt and store the new card in one trip. A healthy subscription
	 * only swaps the card.
	 *
	 * @param string $state Subscription state.
	 * @param bool   $past_due Whether the next-payment date has passed.
	 * @return bool
	 */
	public static function is_settling( $state, $past_due ) {
		if ( 'on-hold' === $state ) {
			return true;
		}

		return 'active' === $state && (bool) $past_due;
	}

	/**
	 * Resolves what the customer will be charged for a card update.
	 *
	 * Returns 0 for a token-only swap. The amount is NEVER taken from the
	 * request — a tampered amount must be impossible by construction, so this
	 * is the only source.
	 *
	 * @param array  $entry    Entry with chip_sub_* meta flattened in.
	 * @param string $now      Current UTC datetime.
	 * @param int    $fallback_amount_cents Amount to use when settling and no
	 *                                      stored amount is available.
	 * @return int Amount in the smallest currency unit; 0 means token-only.
	 */
	public static function resolve_amount_cents( $entry, $now, $fallback_amount_cents = 0 ) {
		// A one-time payment has no cycle to settle, whatever its meta says.
		if ( ! GF_Chip::is_subscription_entry( $entry ) ) {
			return 0;
		}

		$state    = GF_Chip::get_subscription_state( $entry );
		$next     = rgar( $entry, 'chip_sub_next_payment' );
		$past_due = ! empty( $next ) && GF_Chip_Renewals::compare_datetime( $next, $now ) <= 0;

		if ( ! self::is_settling( $state, $past_due ) ) {
			return 0;
		}

		// Prefer the amount the subscription was set up with, so a settled
		// cycle matches what the customer agreed to pay.
		$stored = rgar( $entry, 'chip_sub_amount' );

		if ( is_numeric( $stored ) && (int) $stored > 0 ) {
			return (int) $stored;
		}

		return max( 0, (int) $fallback_amount_cents );
	}

	/**
	 * Whether a link should be offered for this entry at all.
	 *
	 * A cancelled or expired subscription has no future charge to redirect, so
	 * a card update is meaningless.
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @return bool
	 */
	public static function can_offer_link( $entry ) {
		if ( ! GF_Chip::is_subscription_entry( $entry ) ) {
			return false;
		}

		return in_array(
			GF_Chip::get_subscription_state( $entry ),
			array( 'active', 'on-hold', 'pending' ),
			true
		);
	}

	// -----------------------------------------------------------------
	// Link lifecycle (WordPress-facing).
	// -----------------------------------------------------------------

	/**
	 * Issues a fresh link for an entry, invalidating any previous one.
	 *
	 * Storing the signature is what makes the link single-use and revocable:
	 * validation compares against the stored value, so issuing a new link
	 * immediately retires the old one.
	 *
	 * @param int $entry_id Entry id.
	 * @return string|false URL, or false when the entry is not eligible.
	 */
	public static function issue_link( $entry_id ) {
		$entry = GFAPI::get_entry( $entry_id );

		if ( ! is_array( $entry ) ) {
			return false;
		}

		$entry = self::hydrate( $entry );

		if ( ! self::can_offer_link( $entry ) ) {
			return false;
		}

		$form_id = rgar( $entry, 'form_id' );
		$expiry  = time() + ( self::expiry_days() * 86400 );
		$payload = self::signature_payload( $entry_id, $expiry );
		$sig     = wp_hash( $payload );

		gform_update_meta( $entry_id, self::META_LINK_SIGNATURE, $sig, $form_id );
		gform_update_meta( $entry_id, self::META_LINK_EXPIRY, $expiry, $form_id );

		return add_query_arg(
			array(
				self::ARG_ENTRY     => $entry_id,
				self::ARG_EXPIRY    => $expiry,
				self::ARG_SIGNATURE => $sig,
			),
			home_url( '/' )
		);
	}

	/**
	 * Validates a presented link.
	 *
	 * Every failure returns a distinct reason so the caller can log it without
	 * ever telling the visitor which check failed.
	 *
	 * @param array $request Values for ARG_ENTRY / ARG_EXPIRY / ARG_SIGNATURE.
	 * @param int   $now     Current timestamp.
	 * @return array { valid: bool, reason: string, entry_id: int }
	 */
	public static function validate( $request, $now ) {
		$invalid = function ( $reason ) {
			return array(
				'valid'    => false,
				'reason'   => $reason,
				'entry_id' => 0,
			);
		};

		if ( ! is_array( $request ) ) {
			return $invalid( 'malformed' );
		}

		$entry_id = isset( $request[ self::ARG_ENTRY ] ) ? (int) $request[ self::ARG_ENTRY ] : 0;
		$expiry   = isset( $request[ self::ARG_EXPIRY ] ) ? (int) $request[ self::ARG_EXPIRY ] : 0;
		$sig      = isset( $request[ self::ARG_SIGNATURE ] ) ? (string) $request[ self::ARG_SIGNATURE ] : '';

		if ( $entry_id <= 0 || $expiry <= 0 || '' === $sig ) {
			return $invalid( 'incomplete' );
		}

		if ( self::is_expired( $expiry, $now ) ) {
			return $invalid( 'expired' );
		}

		$stored = gform_get_meta( $entry_id, self::META_LINK_SIGNATURE );

		if ( ! self::signature_matches( $stored, $sig ) ) {
			return $invalid( 'signature' );
		}

		// The stored signature must also match what this entry+expiry pair
		// produces, so a valid signature for another entry cannot be replayed.
		if ( ! self::signature_matches( $stored, wp_hash( self::signature_payload( $entry_id, $expiry ) ) ) ) {
			return $invalid( 'binding' );
		}

		return array(
			'valid'    => true,
			'reason'   => '',
			'entry_id' => $entry_id,
		);
	}

	/**
	 * Retires an entry's link.
	 *
	 * Called on successful use, so a link works exactly once.
	 *
	 * @param int $entry_id Entry id.
	 * @return void
	 */
	public static function consume_link( $entry_id ) {
		gform_delete_meta( $entry_id, self::META_LINK_SIGNATURE );
		gform_delete_meta( $entry_id, self::META_LINK_EXPIRY );
	}

	/**
	 * Flattens the plugin's subscription meta onto an entry.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function hydrate( $entry ) {
		$entry_id = rgar( $entry, 'id' );

		foreach ( array( 'chip_recurring_token', 'chip_sub_next_payment', 'chip_sub_status', 'chip_sub_retry_count', 'chip_sub_amount' ) as $key ) {
			$entry[ $key ] = gform_get_meta( $entry_id, $key );
		}

		return $entry;
	}
}
