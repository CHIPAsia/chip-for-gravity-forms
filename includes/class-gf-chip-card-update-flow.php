<?php
/**
 * Customer-facing card-update flow.
 *
 * Sits on top of GF_Chip_Card_Update (which owns link issuing and validation)
 * and does the part that moves money: resolve the amount, build the CHIP
 * purchase, and settle the result.
 *
 * Split so the decisions are pure and unit testable, with the WordPress and
 * API calls in thin methods around them. That split is deliberate: the two
 * defects that shipped in the renewal engine lived in the seam between
 * testable logic and entry-meta I/O, so the meta reads and writes here are all
 * routed through the same keys the test harness can fake.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card-update purchase and settlement.
 */
class GF_Chip_Card_Update_Flow {

	/**
	 * Entry meta key recording the charge made by a settling card update.
	 *
	 * @var string
	 */
	const META_UPDATE_PURCHASE = 'chip_card_update_purchase';

	/**
	 * Product name shown at CHIP when settling an outstanding cycle.
	 *
	 * @var string
	 */
	const LABEL_SETTLING = 'Outstanding payment';

	/**
	 * Product name shown at CHIP when only replacing the card.
	 *
	 * @var string
	 */
	const LABEL_TOKEN_ONLY = 'Update payment method';

	// -----------------------------------------------------------------
	// Pure decisions.
	// -----------------------------------------------------------------

	/**
	 * Product label for the purchase.
	 *
	 * @param int $amount_cents Resolved amount.
	 * @return string
	 */
	public static function product_label( $amount_cents ) {
		return (int) $amount_cents > 0 ? self::LABEL_SETTLING : self::LABEL_TOKEN_ONLY;
	}

	/**
	 * Whether the purchase should skip capture.
	 *
	 * A settling update must actually collect, so capture is skipped ONLY for
	 * the token-only path. Getting this backwards would either charge nothing
	 * on a debt or leave an authorisation uncaptured on a swap.
	 *
	 * @param int $amount_cents Resolved amount.
	 * @return bool
	 */
	public static function should_skip_capture( $amount_cents ) {
		return (int) $amount_cents <= 0;
	}

	/**
	 * Builds the CHIP purchase params for a card update.
	 *
	 * The amount is passed in, never read from a request. The caller obtains it
	 * from GF_Chip_Card_Update::resolve_amount_cents(), which derives it from
	 * subscription state alone.
	 *
	 * @param array $args {
	 *    Purchase arguments.
	 *     @type int    $amount_cents Resolved amount, 0 for token-only.
	 *     @type string $currency     Currency code.
	 *     @type int    $entry_id     Entry id.
	 *     @type string $return_url   Where CHIP sends the customer back.
	 *     @type string $reference    Optional reference override.
	 * }
	 * @return array
	 */
	public static function build_purchase_params( $args ) {
		$amount_cents = max( 0, (int) rgar( $args, 'amount_cents' ) );
		$currency     = (string) rgar( $args, 'currency' );
		$entry_id     = (int) rgar( $args, 'entry_id' );
		$return_url   = (string) rgar( $args, 'return_url' );
		$reference    = rgar( $args, 'reference' );

		return array(
			'force_recurring'          => true,
			'payment_method_whitelist' => GF_Chip::get_recurring_payment_method_whitelist(),
			// CHIP requires this exact value for Gravity Forms purchases.
			'platform'                 => 'gravityforms',
			'reference'                => empty( $reference ) ? (string) $entry_id : substr( (string) $reference, 0, 128 ),
			'skip_capture'             => self::should_skip_capture( $amount_cents ),
			'send_receipt'             => false,
			'success_redirect'         => $return_url,
			'failure_redirect'         => $return_url,
			'cancel_redirect'          => $return_url,
			'purchase'                 => array(
				'currency' => $currency,
				'products' => array(
					array(
						'name'     => substr( self::product_label( $amount_cents ), 0, 256 ),
						'price'    => $amount_cents,
						'quantity' => 1,
					),
				),
			),
		);
	}

	/**
	 * Decides what to store after a card update completes.
	 *
	 * Pure: takes the purchase response and the state before, returns the meta
	 * writes to perform. Keeping it pure is what makes the settlement rules
	 * assertable rather than only observable live.
	 *
	 * @param mixed $purchase     Decoded CHIP purchase response.
	 * @param bool  $was_settling Whether this update collected an outstanding cycle.
	 * @return array {
	 *     @type string|null $token        New recurring token, or null if absent.
	 *     @type string      $status       Subscription state to store.
	 *     @type int         $retry_count  Retry counter to store.
	 *     @type bool        $settled      Whether a real charge was recorded.
	 * }
	 */
	public static function plan_settlement( $purchase, $was_settling ) {
		$token = GF_Chip::extract_recurring_token( $purchase );

		return array(
			'token'       => $token,
			'status'      => null === $token ? 'on-hold' : 'active',
			'retry_count' => 0,
			'settled'     => (bool) $was_settling && null !== $token,
		);
	}

	/**
	 * Whether a purchase response represents a completed card update.
	 *
	 * CHIP can return pending_charge while the acquirer works. That is a
	 * success for our purposes — the token is issued — but it is NOT settled,
	 * so the caller must not clear the debt on it.
	 *
	 * @param mixed $purchase Decoded CHIP purchase response.
	 * @return bool
	 */
	public static function is_completed( $purchase ) {
		if ( ! is_array( $purchase ) ) {
			return false;
		}

		if ( empty( $purchase['id'] ) ) {
			return false;
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';

		return in_array( $status, array( 'paid', 'pending_charge', 'pending' ), true );
	}

	/**
	 * Whether a completed update actually collected money.
	 *
	 * Only a `paid` status means the outstanding cycle is cleared. A pending
	 * charge leaves the debt outstanding until the callback settles it, so
	 * treating it as settled would mark a subscription active while the
	 * customer still owes.
	 *
	 * @param mixed $purchase Decoded CHIP purchase response.
	 * @return bool
	 */
	public static function collected_payment( $purchase ) {
		if ( ! self::is_completed( $purchase ) ) {
			return false;
		}

		return 'paid' === ( isset( $purchase['status'] ) ? (string) $purchase['status'] : '' );
	}
}
