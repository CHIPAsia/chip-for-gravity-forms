<?php
/**
 * Tests for dunning notifications and the admin resend action.
 *
 * The security-relevant claims are that the recipient is read ONLY from the
 * entry, that only an updatable subscription is emailed, and that a retry
 * ladder does not spam the customer.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip_Renewal_Notifications;
use GF_Chip_Test_Feed;
use GF_Chip_Test_Meta;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip_Renewal_Notifications
 */
class GF_Chip_RenewalNotificationsTest extends TestCase {

	/**
	 * Clean state per test.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();

		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function ( $tag, $value ) {
			return $value;
		} );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
		GF_Chip_Test_Meta::reset();
		GF_Chip_Test_Feed::reset();
	}

	// ---------------------------------------------------------------------
	// Recipient resolution — the privacy guarantee.
	// ---------------------------------------------------------------------

	/**
	 * The field the FEED names as the email field is the one read.
	 *
	 * The field id belongs to the form, so a fixed id sends nothing on any
	 * form that numbers its fields differently — the defect this asserts
	 * against.
	 */
	public function test_recipient_follows_the_feed_email_field(): void {
		GF_Chip_Test_Feed::set(
			array(
				'id'   => 1,
				'meta' => array( 'clientInformation_email' => '2' ),
			)
		);

		$entry = array(
			'form_id' => 1,
			'2'       => 'configured@example.test',
			'7'       => 'wrongfield@example.test',
		);

		$this->assertSame(
			'configured@example.test',
			GF_Chip_Renewal_Notifications::resolve_recipient( $entry )
		);
	}

	/**
	 * A form whose email field is not the one that happened to be tested.
	 *
	 * This is the regression: the resolver previously read a hardcoded field
	 * id, so on this entry it found nothing and dunning was silently skipped.
	 */
	public function test_recipient_resolves_on_a_form_with_a_different_field_id(): void {
		GF_Chip_Test_Feed::set(
			array(
				'id'   => 2,
				'meta' => array( 'clientInformation_email' => '3' ),
			)
		);

		$entry = array(
			'form_id' => 4,
			'3'       => 'thirdfield@example.test',
		);

		$this->assertSame(
			'thirdfield@example.test',
			GF_Chip_Renewal_Notifications::resolve_recipient( $entry )
		);
	}

	/**
	 * A lowercase-keyed email field works too, as a fallback.
	 */
	public function test_recipient_from_named_field(): void {
		GF_Chip_Test_Feed::set( array() );

		$this->assertSame(
			'other@example.test',
			GF_Chip_Renewal_Notifications::resolve_recipient( array( 'email' => 'other@example.test' ) )
		);
	}

	/**
	 * An entry with no valid address resolves to '' — so no email is sent,
	 * rather than falling back to anything else.
	 */
	public function test_recipient_empty_when_absent(): void {
		GF_Chip_Test_Feed::set( array() );

		$this->assertSame( '', GF_Chip_Renewal_Notifications::resolve_recipient( array() ) );
		$this->assertSame( '', GF_Chip_Renewal_Notifications::resolve_recipient( array( '2' => '' ) ) );
	}

	/**
	 * A malformed address is rejected instead of being passed to wp_mail.
	 */
	public function test_recipient_rejects_invalid_address(): void {
		GF_Chip_Test_Feed::set(
			array( 'id' => 1, 'meta' => array( 'clientInformation_email' => '2' ) )
		);

		$this->assertSame( '', GF_Chip_Renewal_Notifications::resolve_recipient( array( '2' => 'not-an-email' ) ) );
		$this->assertSame( '', GF_Chip_Renewal_Notifications::resolve_recipient( array( '2' => 'a@b' ) ) );
	}

	/**
	 * A request-shaped address on the entry is not consulted.
	 *
	 * This is the control: the resolver reads the entry's own email fields, so a
	 * caller cannot point a dunning email at an address of their choosing.
	 */
	public function test_recipient_ignores_request_shaped_keys(): void {
		$entry = array(
			'to'      => 'attacker@example.test',
			'email'   => 'attacker@example.test',
			'user_email' => 'attacker@example.test',
			'recipient'  => 'attacker@example.test',
		);

		// 'email' IS a legitimate entry field, so it is honoured; the others
		// are not fields we read. The important part is that no non-entry field
		// is used, and with no entry-owned address, nothing is sent.
		$this->assertSame( 'attacker@example.test', GF_Chip_Renewal_Notifications::resolve_recipient( $entry ) );

		$no_owned = array(
			'to'         => 'attacker@example.test',
			'recipient'  => 'attacker@example.test',
			'user_email' => 'attacker@example.test',
		);

		$this->assertSame( '', GF_Chip_Renewal_Notifications::resolve_recipient( $no_owned ) );
	}

	// ---------------------------------------------------------------------
	// The retry ladder must not spam.
	// ---------------------------------------------------------------------

	/**
	 * The first failure emails.
	 */
	public function test_first_attempt_emails(): void {
		$this->assertTrue( GF_Chip_Renewal_Notifications::should_send_dunning( 0, -1 ) );
	}

	/**
	 * Re-running at the SAME attempt index does not email again.
	 *
	 * A cron that runs twice inside one retry window must not send two
	 * identical dunning emails.
	 */
	public function test_same_attempt_does_not_email_twice(): void {
		$this->assertFalse( GF_Chip_Renewal_Notifications::should_send_dunning( 1, 1 ) );
	}

	/**
	 * Each new attempt emails once.
	 */
	public function test_each_new_attempt_emails(): void {
		$this->assertTrue( GF_Chip_Renewal_Notifications::should_send_dunning( 1, 0 ) );
		$this->assertTrue( GF_Chip_Renewal_Notifications::should_send_dunning( 2, 1 ) );
	}

	/**
	 * An attempt index does not go backwards and re-email.
	 */
	public function test_no_email_for_a_lower_attempt(): void {
		$this->assertFalse( GF_Chip_Renewal_Notifications::should_send_dunning( 0, 2 ) );
	}

	// ---------------------------------------------------------------------
	// The built-in email is a FALLBACK, not a second opinion.
	// ---------------------------------------------------------------------

	/**
	 * A notification the merchant configured for the failure event suppresses
	 * the built-in email.
	 *
	 * Without this, one failed payment produces two messages: the merchant's
	 * designed notification AND the built-in fallback. Reproduced on a live
	 * install before the fix.
	 */
	public function test_configured_notification_suppresses_the_builtin_email(): void {
		$form = array(
			'notifications' => array(
				'merchant' => array(
					'event'    => GF_Chip_Renewal_Notifications::EVENT_FAILED,
					'isActive' => true,
				),
			),
		);

		$this->assertTrue(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				$form,
				array()
			)
		);
	}

	/**
	 * A notification for a DIFFERENT event does not suppress it.
	 *
	 * The check must be about the event being announced, not about the form
	 * having notifications at all — otherwise configuring a routine
	 * admin-submission notification would silence dunning for every customer.
	 */
	public function test_notification_for_another_event_does_not_suppress(): void {
		$form = array(
			'notifications' => array(
				'admin' => array(
					'event'    => 'form_submission',
					'isActive' => true,
				),
			),
		);

		$this->assertFalse(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				$form,
				array()
			)
		);
	}

	/**
	 * A notification the merchant switched OFF does not suppress it.
	 *
	 * `GFCommon::get_notifications()` does not filter on `isActive`, so a
	 * check built on that call alone would make the plugin silent for a
	 * customer whose merchant had merely parked their own notification —
	 * nobody would be told anything.
	 */
	public function test_inactive_notification_does_not_suppress(): void {
		$form = array(
			'notifications' => array(
				'merchant' => array(
					'event'    => GF_Chip_Renewal_Notifications::EVENT_FAILED,
					'isActive' => false,
				),
			),
		);

		$this->assertFalse(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				$form,
				array()
			)
		);
	}

	/**
	 * A notification whose conditional logic will not pass does not suppress it.
	 *
	 * The fallback exists so the customer is told when nothing else will. A
	 * notification that cannot fire for this entry is not "something else".
	 */
	public function test_unsatisfied_conditional_logic_does_not_suppress(): void {
		$form = array(
			'notifications' => array(
				'merchant' => array(
					'event'             => GF_Chip_Renewal_Notifications::EVENT_FAILED,
					'isActive'          => true,
					'conditionalLogic'  => array(
						'logicType' => 'all',
						'rules'     => array(
							array(
								'fieldId'  => '1',
								'operator' => 'is',
								'value'    => 'yes',
							),
						),
					),
				),
			),
		);

		$this->assertFalse(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				$form,
				array( '1' => 'no' )
			)
		);

		// And it DOES suppress the entry it was written for.
		$this->assertTrue(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				$form,
				array( '1' => 'yes' )
			)
		);
	}

	/**
	 * A form with no notifications at all leaves the fallback in charge.
	 */
	public function test_no_notifications_configured_does_not_suppress(): void {
		$this->assertFalse(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				array(),
				array()
			)
		);

		$this->assertFalse(
			GF_Chip_Renewal_Notifications::has_active_notification(
				GF_Chip_Renewal_Notifications::EVENT_FAILED,
				'not a form',
				array()
			)
		);
	}

	/**
	 * The send method actually consults the stand-down before mailing.
	 *
	 * The six tests above prove the predicate is correct; none of them proves
	 * anybody calls it. Removing the guard from `maybe_send_dunning_email()`
	 * left the whole suite green — which is exactly the double-send bug,
	 * restored. This test is the wiring, and it must fail if the guard goes.
	 */
	public function test_the_send_consults_the_stand_down_before_mailing(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip-renewal-notifications.php' );
		$source = str_replace( "\r\n", "\n", $source );

		$start = strpos( $source, 'public static function maybe_send_dunning_email' );
		$this->assertNotFalse( $start, 'the send method must exist' );

		$body = substr( $source, $start, 3000 );

		$guard = strpos( $body, 'has_active_notification' );
		$mail  = strpos( $body, 'wp_mail(' );

		$this->assertNotFalse( $guard, 'the send must consult the merchant notification stand-down' );
		$this->assertNotFalse( $mail, 'the send must still mail' );

		$this->assertLessThan(
			$mail,
			$guard,
			'the stand-down must be decided BEFORE wp_mail, or the email has already left'
		);

		$this->assertStringContainsString(
			'self::EVENT_FAILED',
			$body,
			'the stand-down must ask about the failure event, which is the one the fallback covers'
		);
	}

	/**
	 * An operator's explicit send ignores the stand-down.
	 *
	 * "Send update-card link" is a deliberate act by support, not the automated
	 * fallback. Suppressing it because the merchant happens to run their own
	 * notification would make the button appear broken.
	 */
	public function test_an_explicit_send_is_not_suppressed(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip-renewal-notifications.php' );
		$source = str_replace( "\r\n", "\n", $source );

		$start = strpos( $source, 'public static function maybe_send_dunning_email' );
		$body  = substr( $source, $start, 3000 );

		$guard_line = strpos( $body, 'has_active_notification' );
		$line_start = strrpos( substr( $body, 0, $guard_line ), "\n" );
		$guard_stmt = substr( $body, $line_start, 400 );

		$this->assertStringContainsString(
			'! $force',
			$guard_stmt,
			'the stand-down must be bypassed for an operator-initiated send'
		);
	}

	// ---------------------------------------------------------------------
	// Email content.
	// ---------------------------------------------------------------------

	/**
	 * The subject names the site so the customer knows who is writing.
	 */
	public function test_subject_names_the_site(): void {
		$subject = GF_Chip_Renewal_Notifications::dunning_subject( 'My Shop' );

		$this->assertStringContainsString( 'My Shop', $subject );
		$this->assertStringContainsString( 'failed', $subject );
	}

	/**
	 * The body contains the link, so the email is actually actionable.
	 */
	public function test_body_contains_the_link(): void {
		$body = GF_Chip_Renewal_Notifications::dunning_body(
			array(
				'site_name' => 'My Shop',
				'link'      => 'https://example.test/?chip_update_card=1',
				'amount'    => 'MYR 50.00',
			)
		);

		$this->assertStringContainsString( 'https://example.test/?chip_update_card=1', $body );
		$this->assertStringContainsString( 'MYR 50.00', $body );
		$this->assertStringContainsString( 'My Shop', $body );
	}

	/**
	 * With no amount outstanding the line is omitted, not shown as "0".
	 */
	public function test_body_omits_absent_amount(): void {
		$body = GF_Chip_Renewal_Notifications::dunning_body(
			array(
				'site_name' => 'My Shop',
				'link'      => 'https://example.test/x',
				'amount'    => '',
			)
		);

		$this->assertStringNotContainsString( 'Amount outstanding', $body );
		$this->assertStringContainsString( 'https://example.test/x', $body );
	}

	// ---------------------------------------------------------------------
	// The merge tag.
	// ---------------------------------------------------------------------

	/**
	 * Text without the tag is returned untouched.
	 */
	public function test_merge_tag_untouched_when_absent(): void {
		$text = 'A notification with no tag.';

		$this->assertSame(
			$text,
			GF_Chip_Renewal_Notifications::replace_merge_tag( $text, array(), array( 'id' => 1 ) )
		);
	}

	/**
	 * An entry with no id strips the tag rather than emitting the placeholder.
	 *
	 * Sending the literal text "{chip_update_card_link}" would be worse than
	 * sending nothing: the customer sees a broken instruction.
	 */
	public function test_merge_tag_stripped_without_an_entry(): void {
		$result = GF_Chip_Renewal_Notifications::replace_merge_tag(
			'Link: {chip_update_card_link}',
			array(),
			array()
		);

		$this->assertStringNotContainsString( '{chip_update_card_link}', $result );
		$this->assertSame( 'Link: ', $result );
	}

	// ---------------------------------------------------------------------
	// The admin action's URL is nonce-protected.
	// ---------------------------------------------------------------------

	/**
	 * The admin URL carries a nonce, so the action cannot be triggered by a
	 * crafted link.
	 */
	public function test_admin_send_url_is_nonce_protected(): void {
		WP_Mock::userFunction( 'admin_url' )->andReturnUsing( function ( $path = '' ) {
			return 'https://example.test/wp-admin/' . $path;
		} );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturnUsing( function ( $url, $action ) {
			return $url . '&_wpnonce=NONCE';
		} );

		$url = GF_Chip_Renewal_Notifications::admin_send_url( 42 );

		$this->assertStringContainsString( '_wpnonce=', $url );
		$this->assertStringContainsString( 'entry_id=42', $url );
		$this->assertStringContainsString( 'action=chip_send_card_update', $url );
	}

	// ---------------------------------------------------------------------
	// The dunned-attempt marker round-trips through the harness.
	// ---------------------------------------------------------------------

	/**
	 * The marker is written after a send, so the next run at the same attempt
	 * stays quiet.
	 */
	public function test_dunned_marker_round_trips(): void {
		$entry_id = 55;

		// Nothing dunned yet.
		$this->assertSame( '', gform_get_meta( $entry_id, GF_Chip_Renewal_Notifications::META_DUNNED_ATTEMPT ) );

		gform_update_meta( $entry_id, GF_Chip_Renewal_Notifications::META_DUNNED_ATTEMPT, 1 );

		$last = (int) gform_get_meta( $entry_id, GF_Chip_Renewal_Notifications::META_DUNNED_ATTEMPT );
		$this->assertSame( 1, $last );
		$this->assertFalse(
			GF_Chip_Renewal_Notifications::should_send_dunning( 1, $last ),
			'the same attempt must not email again'
		);
		$this->assertTrue(
			GF_Chip_Renewal_Notifications::should_send_dunning( 2, $last ),
			'the next attempt must email'
		);
	}

	/**
	 * A stored attempt of 0 is honoured as "attempt zero was dunned", which the
	 * no-op meta double could not have expressed because it returned '' for
	 * everything set.
	 */
	public function test_stored_zero_attempt_is_respected(): void {
		$entry_id = 56;

		gform_update_meta( $entry_id, GF_Chip_Renewal_Notifications::META_DUNNED_ATTEMPT, 0 );

		$this->assertSame( 0, gform_get_meta( $entry_id, GF_Chip_Renewal_Notifications::META_DUNNED_ATTEMPT ) );
		$this->assertTrue( GF_Chip_Test_Meta::has( $entry_id, GF_Chip_Renewal_Notifications::META_DUNNED_ATTEMPT ) );
	}

	// ---------------------------------------------------------------------
	// Event names.
	// ---------------------------------------------------------------------

	/**
	 * The event constants match the strings the notification UI expects, so a
	 * notification configured against them fires.
	 */
	public function test_event_constants(): void {
		$this->assertSame( 'subscription_payment_failed', GF_Chip_Renewal_Notifications::EVENT_FAILED );
		$this->assertSame( 'subscription_renewed', GF_Chip_Renewal_Notifications::EVENT_RENEWED );
		$this->assertSame( 'subscription_expired', GF_Chip_Renewal_Notifications::EVENT_EXPIRED );
	}

	// ---------------------------------------------------------------------
	// The admin button's visibility rule.
	// ---------------------------------------------------------------------

	/**
	 * The button appears for a subscription the send would accept.
	 */
	public function test_button_shown_for_active(): void {
		$this->assertTrue(
			\GF_Chip_Subscriptions_Page::can_send_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'active' )
			)
		);
	}

	/**
	 * The button appears for an on-hold subscription — the common case, since
	 * that is what a failed renewal produces.
	 */
	public function test_button_shown_for_on_hold(): void {
		$this->assertTrue(
			\GF_Chip_Subscriptions_Page::can_send_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'on-hold' )
			)
		);
	}

	/**
	 * The button is hidden for a cancelled subscription, matching what the
	 * send action would refuse. A button that does nothing is worse than no
	 * button.
	 */
	public function test_button_hidden_for_cancelled(): void {
		$this->assertFalse(
			\GF_Chip_Subscriptions_Page::can_send_link(
				array( 'transaction_type' => '2', 'chip_sub_status' => 'cancelled' )
			)
		);
	}

	/**
	 * The button is hidden for a one-time payment.
	 */
	public function test_button_hidden_for_one_time_payment(): void {
		$this->assertFalse(
			\GF_Chip_Subscriptions_Page::can_send_link(
				array( 'transaction_type' => '1', 'chip_sub_status' => 'active' )
			)
		);
	}

	// ---------------------------------------------------------------------
	// The eligibility gate inside the send itself.
	// ---------------------------------------------------------------------

	/**
	 * resolve_recipient is the only source of the address, and it reads the
	 * entry. A cancelled subscription is refused before the address is even
	 * considered, so no email is sent for a subscription with nothing to
	 * update.
	 */
	public function test_cancelled_subscription_is_not_emailed(): void {
		$entry = array(
			'transaction_type' => '2',
			'chip_sub_status'  => 'cancelled',
			'7'                => 'customer@example.test',
		);

		$this->assertFalse( \GF_Chip_Card_Update::can_offer_link( $entry ) );
		$this->assertFalse( \GF_Chip_Subscriptions_Page::can_send_link( $entry ) );
	}

	/**
	 * The merge tag is registered against the Gravity Forms filter name, so a
	 * notification configured with {chip_update_card_link} resolves.
	 */
	public function test_merge_tag_braced_form_is_stripped_when_unresolvable(): void {
		$result = GF_Chip_Renewal_Notifications::replace_merge_tag(
			'Update here: {chip_update_card_link}',
			array(),
			array()
		);

		$this->assertStringNotContainsString( '{chip_update_card_link}', $result );
		$this->assertSame( 'Update here: ', $result );
	}

	// ---------------------------------------------------------------------
	// Closing the gaps the sabotage pass exposed.
	// ---------------------------------------------------------------------

	/**
	 * The braced form — what an operator actually types — is substituted.
	 *
	 * Mutating inject_link to handle only the bare form previously left the
	 * suite green, because no test exercised a RESOLVED substitution.
	 */
	public function test_braced_tag_is_substituted(): void {
		$result = GF_Chip_Renewal_Notifications::inject_link(
			'Update here: {chip_update_card_link}',
			'https://example.test/link'
		);

		$this->assertSame( 'Update here: https://example.test/link', $result );
	}

	/**
	 * The bare form is substituted too, for a programmatic caller.
	 */
	public function test_bare_tag_is_substituted(): void {
		$result = GF_Chip_Renewal_Notifications::inject_link(
			'Update here: chip_update_card_link',
			'https://example.test/link'
		);

		$this->assertSame( 'Update here: https://example.test/link', $result );
	}

	/**
	 * Both forms in one body are replaced.
	 */
	public function test_both_forms_in_one_body(): void {
		$result = GF_Chip_Renewal_Notifications::inject_link(
			'{chip_update_card_link} or chip_update_card_link',
			'URL'
		);

		$this->assertSame( 'URL or URL', $result );
	}

	/**
	 * A request-shaped address is NOT a fallback when the entry has none.
	 *
	 * This is the privacy control: resolve_recipient is the only source, and it
	 * reads the entry. Nothing else, however it is supplied, may become the
	 * recipient.
	 */
	public function test_request_address_is_never_a_fallback(): void {
		$_REQUEST['email'] = 'attacker@example.test';

		try {
			$this->assertSame(
				'',
				GF_Chip_Renewal_Notifications::resolve_recipient(
					array( 'to' => 'attacker@example.test' )
				),
				'an address outside the entry must never be used'
			);
		} finally {
			unset( $_REQUEST['email'] );
		}
	}

	/**
	 * The subscription notification events are registered on the add-on, so a
	 * notification configured against them fires.
	 *
	 * Reflecting on the class rather than the instance: the registration is the
	 * claim, and removing the events previously left the suite green.
	 */
	public function test_subscription_events_are_in_supported_events(): void {
		$body = self::supported_notification_events_body();

		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::EVENT_RENEWED',
			$body,
			'the renewed event must be offered to the notification UI'
		);
		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::EVENT_FAILED',
			$body,
			'the failed event must be offered to the notification UI'
		);
		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::EVENT_EXPIRED',
			$body,
			'the expired event must be offered to the notification UI'
		);
	}

	/**
	 * Extracts the body of supported_notification_events().
	 *
	 * Scoped deliberately: the event constants also appear at their dispatch
	 * sites elsewhere in the file, so asserting against the whole file passed
	 * even after the events were removed from this method. Only the events
	 * OFFERED to the notification UI make them configurable, so only this
	 * method's body is the right thing to check.
	 *
	 * @return string
	 */
	private static function supported_notification_events_body() {
		$source = (string) file_get_contents( __DIR__ . '/../../includes/class-gf-chip.php' );
		$start  = strpos( $source, 'function supported_notification_events' );

		if ( false === $start ) {
			return '';
		}

		$end = strpos( $source, '}', $start );

		// Walk to the closing brace of the method by brace counting, so a
		// nested array literal does not end the slice early.
		$depth = 0;
		$len   = strlen( $source );

		for ( $i = $start; $i < $len; $i++ ) {
			if ( '{' === $source[ $i ] ) {
				$depth++;
			} elseif ( '}' === $source[ $i ] ) {
				$depth--;

				if ( 0 === $depth ) {
					return substr( $source, $start, $i - $start + 1 );
				}
			}
		}

		unset( $end );

		return substr( $source, $start );
	}

	/**
	 * The failure path actually invokes the dunning sender.
	 *
	 * Removing the call previously left the suite green, which is exactly the
	 * class of wiring gap the entry-meta harness was built to stop.
	 */
	public function test_failure_path_invokes_dunning(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip.php' );

		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::maybe_send_dunning_email( $entry_id, $retry_count )',
			$source,
			'the failure path must attempt a dunning email'
		);
	}

	/**
	 * The dunning email is attempted ONLY while retries remain, and a terminal
	 * failure announces ONE event rather than two.
	 *
	 * The failure and the expiry are the same moment, so firing both meant a
	 * merchant with a notification on each got two emails for one payment.
	 * The built-in "update your card" email is deliberately skipped on that
	 * path as well: the card-update link stops working the moment the
	 * subscription is expired, so mailing one would hand the customer a dead
	 * link. They were dunned on every earlier attempt, while action was still
	 * possible.
	 *
	 * Anchored on the marker comment rather than on `if ( null === $next )`,
	 * which appears twice in this method and would match the log_debug guard.
	 */
	public function test_terminal_failure_announces_the_expiry_only(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip.php' );
		$source = str_replace( "\r\n", "\n", $source );

		$start = strpos( $source, 'private function handle_renewal_failure' );
		$this->assertNotFalse( $start, 'handle_renewal_failure must exist' );

		$body = substr( $source, $start, 8000 );

		$exhausted_at = strpos( $body, 'The ladder is exhausted' );
		$this->assertNotFalse( $exhausted_at, 'the exhausted branch must be marked' );

		$send_at = strpos( $body, 'maybe_send_dunning_email( $entry_id, $retry_count )' );
		$this->assertNotFalse( $send_at, 'the dunning send must exist' );

		$this->assertLessThan(
			$send_at,
			$exhausted_at,
			'the terminal branch must be handled before the dunning send, so the send cannot run on it'
		);

		$exhausted = substr( $body, $exhausted_at, $send_at - $exhausted_at );

		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::EVENT_EXPIRED',
			$exhausted,
			'a terminal failure must announce the expiry'
		);

		$this->assertStringNotContainsString(
			'GF_Chip_Renewal_Notifications::EVENT_FAILED',
			$exhausted,
			'a terminal failure must not ALSO announce the failure — that is one email too many'
		);

		$this->assertStringNotContainsString(
			'maybe_send_dunning_email',
			$exhausted,
			'the built-in email must not be sent once the ladder is exhausted — the link is already dead'
		);

		// The retry path still does both, so the guards above cannot be
		// satisfied by deleting the work instead of gating it.
		$retry_path = substr( $body, $send_at );

		$this->assertStringContainsString(
			'GF_Chip_Renewal_Notifications::EVENT_FAILED',
			$retry_path,
			'a non-terminal failure must still announce the failure event'
		);
	}

	/**
	 * The success path dispatches the renewed event.
	 */
	public function test_success_path_dispatches_renewed_event(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip.php' );

		$this->assertStringContainsString(
			"'type'           => GF_Chip_Renewal_Notifications::EVENT_RENEWED",
			$source,
			'a successful renewal must dispatch its event'
		);
	}

	/**
	 * The failure path dispatches the failed event.
	 */
	public function test_failure_path_dispatches_failed_event(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip.php' );

		$this->assertStringContainsString(
			"'type'           => GF_Chip_Renewal_Notifications::EVENT_FAILED",
			$source,
			'a failed renewal must dispatch its event'
		);
	}

	/**
	 * The admin handler is registered on admin_post, so the button's link
	 * resolves to something that runs.
	 */
	public function test_admin_handler_is_registered(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip.php' );

		$this->assertStringContainsString(
			"add_action( 'admin_post_chip_send_card_update'",
			$source,
			'the admin send action must have a handler'
		);
	}

	/**
	 * The button must report what actually happened.
	 *
	 * The handler used to redirect with &sent=<id> unconditionally, so a
	 * refused recipient and a refused send both looked identical to a
	 * success. That is how "no email arrived" stayed invisible.
	 */
	public function test_send_handler_reports_the_real_outcome(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip-renewal-notifications.php' );

		// The return value must be captured, not discarded.
		$this->assertMatchesRegularExpression(
			'/\$sent\s*=\s*self::maybe_send_dunning_email\(/',
			$source,
			'the handler must capture whether the mail was sent'
		);

		// And the redirect must depend on it.
		$this->assertMatchesRegularExpression(
			"/'sent'\s*=>\s*\\\$sent\s*\?/",
			$source,
			'the sent flag must depend on the actual outcome'
		);

		// With a distinct failure flag, so the page can say so.
		$this->assertStringContainsString( "'sendfail'", $source );
	}

	/**
	 * And the page must render a failure notice, not a success one.
	 *
	 * Without this the handler reports the failure and the page drops it.
	 */
	public function test_page_renders_a_failure_notice(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip-subscriptions-page.php' );

		$this->assertStringContainsString( "\$_GET['sendfail']", $source );
		$this->assertMatchesRegularExpression(
			'/sendfail.*?notice-error/s',
			$source,
			'the failure flag must produce an error notice'
		);
	}

	/**
	 * The merge tag filter is registered.
	 */
	public function test_merge_tag_filter_is_registered(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-gf-chip-renewal-notifications.php' );

		$this->assertStringContainsString(
			"add_filter( 'gform_replace_merge_tags'",
			$source,
			'the merge tag must be registered with Gravity Forms'
		);
	}
}
