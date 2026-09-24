<?php
/**
 * Renewal notifications and the admin link-sending action.
 *
 * Two jobs, both about telling somebody their card needs attention:
 *
 *  1. A merge tag {chip_update_card_link} so an operator can build the email in
 *     Gravity Forms' own notification UI, plus a built-in fallback email so it
 *     works with no configuration at all.
 *  2. The admin action that issues a link and sends it, so support can re-send
 *     one without touching the database.
 *
 * Privacy rules, from the plan and non-negotiable:
 *  - the email goes ONLY to the address stored on the entry, never to an
 *    address from the request
 *  - an email is sent only for a subscription that can actually be updated
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dunning notifications and the admin resend action.
 */
class GF_Chip_Renewal_Notifications {

	/**
	 * The merge tag an operator can place in a notification.
	 *
	 * @var string
	 */
	const MERGE_TAG = 'chip_update_card_link';

	/**
	 * The braced form an operator actually types.
	 *
	 * Gravity Forms merge tags are written as {tag}, so both forms are handled:
	 * the bare name for a programmatic caller and the braced form for a
	 * notification body written in the UI.
	 *
	 * @var string
	 */
	const MERGE_TAG_BRACED = '{chip_update_card_link}';

	/**
	 * The action name Gravity Forms dispatches for a failed renewal.
	 *
	 * @var string
	 */
	const EVENT_FAILED = 'subscription_payment_failed';

	/**
	 * The action name for a renewal that succeeded.
	 *
	 * @var string
	 */
	const EVENT_RENEWED = 'subscription_renewed';

	/**
	 * The action name for a subscription that ran out of installments.
	 *
	 * @var string
	 */
	const EVENT_EXPIRED = 'subscription_expired';

	/**
	 * Entry meta marking that a fallback dunning email was already sent for
	 * the current failure, so a retry ladder does not email five times.
	 *
	 * @var string
	 */
	const META_DUNNED_ATTEMPT = 'chip_dunned_attempt';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'gform_replace_merge_tags', array( __CLASS__, 'replace_merge_tag' ), 10, 7 );
	}

	// -----------------------------------------------------------------
	// Pure decisions.
	// -----------------------------------------------------------------

	/**
	 * Whether a dunning email should be sent for this attempt.
	 *
	 * One email per failure position, not one per cron run: a subscription that
	 * fails and is retried three times should not generate three identical
	 * emails if the cron happens to run twice within a retry window.
	 *
	 * @param int $attempt_index Current attempt number, 0-based.
	 * @param int $last_dunned   Attempt index already emailed, or -1.
	 * @return bool
	 */
	public static function should_send_dunning( $attempt_index, $last_dunned ) {
		return (int) $attempt_index > (int) $last_dunned;
	}

	/**
	 * The subject line for the fallback dunning email.
	 *
	 * @param string $site_name Site name.
	 * @return string
	 */
	public static function dunning_subject( $site_name ) {
		return sprintf( 'Action needed: payment failed for your subscription at %s', $site_name );
	}

	/**
	 * The body for the fallback dunning email.
	 *
	 * Plain text on purpose: this is a transactional notice about money, and a
	 * text body cannot be broken by a theme, a template plugin, or a mail
	 * client's HTML handling.
	 *
	 * @param array $args {
	 *    Email parts.
	 *     @type string $site_name   Site name.
	 *     @type string $link        Signed update-card URL.
	 *     @type string $next_payment Next payment datetime, or ''.
	 *     @type string $amount      Formatted amount outstanding, or ''.
	 * }
	 * @return string
	 */
	public static function dunning_body( $args ) {
		$lines = array();

		$lines[] = sprintf( 'Hello,', 0 );
		$lines[] = '';
		$lines[] = sprintf(
			'We could not take the latest payment for your subscription at %s.',
			(string) rgar( $args, 'site_name' )
		);

		$amount = (string) rgar( $args, 'amount' );

		if ( '' !== $amount ) {
			$lines[] = '';
			$lines[] = sprintf( 'Amount outstanding: %s', $amount );
		}

		$lines[] = '';
		$lines[] = 'You can pay the outstanding amount and save a new card in one step by opening this link:';
		$lines[] = (string) rgar( $args, 'link' );
		$lines[] = '';
		$lines[] = 'If you have already sorted this out, you can ignore this message.';

		return implode( "\n", $lines );
	}

	/**
	 * Whether an entry has a usable email address for dunning.
	 *
	 * Reads ONLY from the entry. An address supplied in a request is never
	 * consulted, so a caller cannot redirect a dunning email to itself.
	 *
	 * The field holding the address is the one the FEED names in
	 * `clientInformation_email` — that is the field the customer's address was
	 * actually collected in, and it is what the checkout path already reads.
	 * A fixed field id cannot work here: the id is a property of the form, so
	 * dunning silently resolved to nothing on every form numbered differently
	 * from whichever one was tested, and no email was ever sent.
	 *
	 * The feed is resolved from the entry's own form, and only entry-owned
	 * values are read, so the request-safety property above is preserved.
	 *
	 * @param array $entry Entry.
	 * @return string Email, or '' when none.
	 */
	public static function resolve_recipient( $entry ) {
		$candidates = array();

		$form_id = absint( rgar( $entry, 'form_id' ) );
		if ( $form_id > 0 ) {
			$feed = GF_Chip::get_instance()->get_payment_feed( $entry );

			if ( is_array( $feed ) ) {
				$location = rgars( $feed, 'meta/clientInformation_email' );

				if ( ! empty( $location ) ) {
					$candidates[] = rgar( $entry, (string) $location );
				}
			}
		}

		// Fallbacks for an entry whose feed is gone (deleted feed, restored
		// entry) or a form that collects the address in a field literally
		// named "email".
		$candidates[] = rgar( $entry, 'email' );

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && is_email( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	// -----------------------------------------------------------------
	// Merge tag.
	// -----------------------------------------------------------------

	/**
	 * Replaces {chip_update_card_link} in a notification.
	 *
	 * Issuing a link here means the tag is safe to put in any notification: it
	 * mints a fresh single-use link at send time and retires the previous one,
	 * rather than embedding a URL that was generated when the email was written.
	 *
	 * @param string $text       Notification text.
	 * @param array  $form       Form.
	 * @param array  $entry      Entry.
	 * @param bool   $url_encode Whether to url-encode.
	 * @param bool   $esc_html   Whether to escape.
	 * @param bool   $nl2br      Whether to convert newlines.
	 * @param string $format     Format.
	 * @return string
	 */
	public static function replace_merge_tag( $text, $form, $entry, $url_encode = false, $esc_html = false, $nl2br = false, $format = '' ) {
		unset( $form, $nl2br, $format );

		if ( false === strpos( (string) $text, self::MERGE_TAG ) ) {
			return $text;
		}

		$entry_id = (int) rgar( $entry, 'id' );

		if ( $entry_id <= 0 ) {
			return self::strip_tag( $text );
		}

		$link = GF_Chip_Card_Update::issue_link( $entry_id );

		if ( false === $link ) {
			return self::strip_tag( $text );
		}

		if ( $esc_html ) {
			$link = esc_html( $link );
		}

		if ( $url_encode ) {
			// urlencode(), not rawurlencode(): Gravity Forms documents this
			// filter's third parameter as "Indicates if the urlencode function
			// should be applied", and every core merge tag uses urlencode()
			// for it. Matching core keeps our tag's encoding identical to
			// {entry_url} and friends.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- matching Gravity Forms core merge-tag behaviour.
			$link = urlencode( $link );
		}

		return self::inject_link( $text, $link );
	}

	/**
	 * Substitutes the link for the tag, in both the braced and bare forms.
	 *
	 * Extracted so the substitution itself is unit testable. Mutating this was
	 * previously invisible to the suite: the only tests exercised the
	 * unresolvable path, so dropping the braced form — the one an operator
	 * actually types in the notification UI — left every test green while the
	 * tag silently stopped resolving.
	 *
	 * @param string $text Notification text.
	 * @param string $link Link to inject.
	 * @return string
	 */
	public static function inject_link( $text, $link ) {
		return str_replace(
			array( self::MERGE_TAG_BRACED, self::MERGE_TAG ),
			(string) $link,
			(string) $text
		);
	}

	/**
	 * Removes an unresolvable tag so a customer never receives the literal
	 * placeholder text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_tag( $text ) {
		return str_replace(
			array( self::MERGE_TAG_BRACED, self::MERGE_TAG ),
			'',
			(string) $text
		);
	}

	// -----------------------------------------------------------------
	// Sending.
	// -----------------------------------------------------------------

	/**
	 * Sends the built-in fallback dunning email.
	 *
	 * Only ever sent to the entry's own address, and only for a subscription
	 * that can be updated — emailing a link to a cancelled subscription would
	 * send the customer somewhere that tells them there is nothing to do.
	 *
	 * @param int   $entry_id      Entry id.
	 * @param int   $attempt_index Current attempt index.
	 * @param bool  $force         Send even if already dunned for this attempt.
	 * @param array $extra         Optional currency/amount overrides.
	 * @return bool
	 */
	public static function maybe_send_dunning_email( $entry_id, $attempt_index, $force = false, $extra = array() ) {
		$entry = GFAPI::get_entry( $entry_id );

		if ( ! is_array( $entry ) || is_wp_error( $entry ) ) {
			return false;
		}

		$entry = GF_Chip_Card_Update::hydrate( $entry );

		if ( ! GF_Chip_Card_Update::can_offer_link( $entry ) ) {
			return false;
		}

		$recipient = self::resolve_recipient( $entry );

		if ( '' === $recipient ) {
			return false;
		}

		$last_dunned = gform_get_meta( $entry_id, self::META_DUNNED_ATTEMPT );

		if ( ! $force && ! self::should_send_dunning( $attempt_index, '' === $last_dunned ? -1 : (int) $last_dunned ) ) {
			return false;
		}

		$link = GF_Chip_Card_Update::issue_link( $entry_id );

		if ( false === $link ) {
			return false;
		}

		$now          = gmdate( 'Y-m-d H:i:s' );
		$amount_cents = GF_Chip_Card_Update::resolve_amount_cents( $entry, $now );
		$currency     = (string) rgar( $extra, 'currency', rgar( $entry, 'currency', 'MYR' ) );

		$sent = wp_mail(
			$recipient,
			self::dunning_subject( get_bloginfo( 'name' ) ),
			self::dunning_body(
				array(
					'site_name'    => get_bloginfo( 'name' ),
					'link'         => $link,
					'next_payment' => (string) rgar( $entry, 'chip_sub_next_payment' ),
					'amount'       => $amount_cents > 0
						? GF_Chip_Card_Update_Page::format_amount( $amount_cents, $currency )
						: '',
				)
			),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		if ( $sent ) {
			gform_update_meta( $entry_id, self::META_DUNNED_ATTEMPT, (int) $attempt_index );
		}

		return (bool) $sent;
	}

	/**
	 * Handles the admin "Send update-card link" action.
	 *
	 * Capability checked, nonce checked, recipient read from the entry only.
	 *
	 * @return void
	 */
	public static function handle_admin_send() {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;

		if ( $entry_id <= 0 ) {
			wp_die( esc_html__( 'Missing entry.', 'chip-for-gravity-forms' ) );
		}

		check_admin_referer( 'chip_send_card_update_' . $entry_id );

		if ( ! current_user_can( GF_Chip_Subscriptions_Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chip-for-gravity-forms' ) );
		}

		if ( ! self::maybe_send_dunning_email( $entry_id, 0, true ) ) {
			GF_Chip::get_instance()->log_debug( __METHOD__ . '(): could not send for entry #' . $entry_id );
		}

		$redirect = add_query_arg(
			array(
				'page' => GF_Chip_Subscriptions_Page::slug(),
				'sent' => $entry_id,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * The nonce-protected admin URL that sends a link for an entry.
	 *
	 * @param int $entry_id Entry id.
	 * @return string
	 */
	/**
	 * URL that triggers an on-demand renewal retry for one subscription.
	 *
	 * Nonce-protected and capability-guarded in the handler, mirroring
	 * admin_send_url().
	 *
	 * @param int $entry_id The subscription entry id.
	 * @return string
	 */
	public static function admin_retry_url( $entry_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'chip_retry_renewal',
					'entry_id' => (int) $entry_id,
				),
				admin_url( 'admin-post.php' )
			),
			'chip_retry_renewal_' . (int) $entry_id
		);
	}

	/**
	 * Handles an on-demand renewal retry.
	 *
	 * A retry COUNTS AS AN ATTEMPT (OQ7): it goes through the same charge path
	 * the cron uses, so chip_sub_retry_count advances and the dunning ladder
	 * cannot be bypassed by pressing this repeatedly. The charge path owns the
	 * increment, so there is exactly one place that counts.
	 *
	 * @return void
	 */
	public static function handle_admin_retry() {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;

		if ( $entry_id <= 0 ) {
			wp_die( esc_html__( 'Missing entry.', 'chip-for-gravity-forms' ) );
		}

		check_admin_referer( 'chip_retry_renewal_' . $entry_id );

		if ( ! current_user_can( GF_Chip_Subscriptions_Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chip-for-gravity-forms' ) );
		}

		$addon = GF_Chip::get_instance();
		$entry = GFAPI::get_entry( $entry_id );

		if ( ! is_array( $entry ) ) {
			wp_die( esc_html__( 'Entry not found.', 'chip-for-gravity-forms' ) );
		}

		$entry = GF_Chip_Card_Update::hydrate( $entry );

		// A retry must not resurrect a dead subscription.
		if ( ! GF_Chip_Subscriptions_Page::can_retry( $entry ) ) {
			$addon->log_debug( __METHOD__ . '(): entry #' . $entry_id . ' is not retryable.' );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => GF_Chip_Subscriptions_Page::slug(),
						'retry'    => 'refused',
						'entry_id' => $entry_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Force: an operator pressed Retry, so an on-hold subscription may be
		// attempted. The attempt is still counted by the charge path.
		$result = $addon->charge_renewal( $entry, true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => GF_Chip_Subscriptions_Page::slug(),
					'retry'    => is_array( $result ) ? $result['status'] : 'failed',
					'entry_id' => $entry_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The nonce-protected admin URL that sends a link for an entry.
	 *
	 * @param int $entry_id Entry id.
	 * @return string
	 */
	public static function admin_send_url( $entry_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'chip_send_card_update',
					'entry_id' => (int) $entry_id,
				),
				admin_url( 'admin-post.php' )
			),
			'chip_send_card_update_' . (int) $entry_id
		);
	}
}
