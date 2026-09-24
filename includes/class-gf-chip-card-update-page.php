<?php
/**
 * Customer-facing card-update page.
 *
 * Gravity Forms is anonymous-first, so there is no "My Account" surface for a
 * subscriber to change the card that will be charged. The signed link
 * (GF_Chip_Card_Update) brings them here; this renders the page and drives the
 * flow into CHIP's hosted checkout.
 *
 * Card data never touches this page — CHIP is hosted, so a customer typing a
 * card is between them and CHIP.
 *
 * The decisions are pure and unit tested; the WordPress calls are thin.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and drives the standalone card-update page.
 */
class GF_Chip_Card_Update_Page {

	/**
	 * Query var requesting the purchase to start.
	 *
	 * @var string
	 */
	const ARG_ACTION = 'chip_update_action';

	/**
	 * Value of ARG_ACTION that starts the CHIP handoff.
	 *
	 * @var string
	 */
	const ACTION_START = 'start';

	/**
	 * Query var carrying the CHIP purchase id on return.
	 *
	 * @var string
	 */
	const ARG_PURCHASE = 'chip_purchase';

	/**
	 * Entry meta recording that a return trip has been settled.
	 *
	 * The return carries no purchase id, so the recorded purchase is settled
	 * on the way back. This marker keeps a reload of that page from settling
	 * (and re-issuing a link) a second time.
	 *
	 * @var string
	 */
	const META_RETURN_SETTLED = 'chip_card_update_returned';

	/**
	 * Renders nothing and returns when the request is not ours.
	 *
	 * Hooked early on `wp`, alongside the existing confirmation handler.
	 *
	 * @return void
	 */
	public static function maybe_handle() {
		// filter_input() returns null when the parameter is absent, so this
		// doubles as the "is this request ours" guard without touching $_GET
		// directly.
		$entry_present = filter_input( INPUT_GET, GF_Chip_Card_Update::ARG_ENTRY, FILTER_UNSAFE_RAW );

		if ( null === $entry_present || false === $entry_present ) {
			return;
		}

		$request = self::read_request();

		$result = GF_Chip_Card_Update::validate( $request, time() );

		if ( empty( $result['valid'] ) ) {
			self::render_error( $result['reason'] );
			exit;
		}

		$entry_id = (int) $result['entry_id'];

		$action = isset( $request[ self::ARG_ACTION ] ) ? (string) $request[ self::ARG_ACTION ] : '';

		if ( self::ACTION_START === $action ) {
			self::start_purchase( $entry_id, $request );
			exit;
		}

		if ( isset( $request[ self::ARG_PURCHASE ] ) && '' !== $request[ self::ARG_PURCHASE ] ) {
			self::finish_purchase( $entry_id, (string) $request[ self::ARG_PURCHASE ] );
			exit;
		}

		// A returning customer arrives with no purchase id: CHIP does not
		// append one to success_redirect, so the `chip_purchase` branch above
		// only ever fires for a caller that supplies it. The purchase this
		// entry's link created was recorded before the handoff, and the link
		// is the authenticator, so settle that one — read from the entry, not
		// from the request, so it cannot be pointed at another purchase.
		//
		// Only a purchase CHIP actually reports as completed is settled: a
		// customer who opened the link and walked away must still see the
		// payment page, not an error.
		if ( self::settle_returned_purchase( $entry_id ) ) {
			exit;
		}

		self::render_page( $entry_id, $request );
		exit;
	}

	/**
	 * Settles the recorded card-update purchase if CHIP says it completed.
	 *
	 * @param int $entry_id Entry id.
	 * @return bool True when the return trip was settled.
	 */
	private static function settle_returned_purchase( $entry_id ) {
		$recorded = (string) gform_get_meta( $entry_id, GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE );

		if ( ! GF_Chip_Card_Update_Flow::should_settle_return( $recorded, gform_get_meta( $entry_id, self::META_RETURN_SETTLED ) ) ) {
			return false;
		}

		$entry = self::load_subscription_entry( $entry_id );

		if ( null === $entry ) {
			return false;
		}

		$feed = self::find_feed( $entry );

		if ( null === $feed ) {
			return false;
		}

		$credentials = GF_Chip::get_instance()->get_credentials_for_feed( $feed );
		$client      = GF_CHIP_API::get_instance( $credentials['secret_key'], $credentials['brand_id'] );
		$purchase    = $client->get_payment( $recorded );

		if ( ! GF_Chip_Card_Update_Flow::is_completed( $purchase ) ) {
			return false;
		}

		gform_update_meta( $entry_id, self::META_RETURN_SETTLED, '1' );

		self::finish_purchase( $entry_id, $recorded );

		return true;
	}

	/**
	 * Reads and sanitises the request parameters this page uses.
	 *
	 * Every value is unslashed and sanitised here, so nothing downstream has to
	 * trust raw input. The signed link is the authenticator for this page — a
	 * nonce is not applicable, because the URL arrives by email and a per-session
	 * nonce would make every emailed link fail — so the nonce sniff is
	 * deliberately satisfied rather than suppressed.
	 *
	 * @return array
	 */
	private static function read_request() {
		// Each value is read, unslashed and sanitised in one expression so the
		// sanitisation is visible at the point of read rather than implied by a
		// loop.
		//
		// The signed link IS the authenticator here: it arrives by email, so a
		// per-session nonce would make every emailed link fail. That is why the
		// nonce sniff is answered by this comment rather than by a nonce.
		//
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$entry_id = isset( $_GET[ GF_Chip_Card_Update::ARG_ENTRY ] )
			? sanitize_text_field( wp_unslash( $_GET[ GF_Chip_Card_Update::ARG_ENTRY ] ) )
			: '';

		$expiry = isset( $_GET[ GF_Chip_Card_Update::ARG_EXPIRY ] )
			? sanitize_text_field( wp_unslash( $_GET[ GF_Chip_Card_Update::ARG_EXPIRY ] ) )
			: '';

		$nonce = isset( $_GET[ GF_Chip_Card_Update::ARG_NONCE ] )
			? sanitize_text_field( wp_unslash( $_GET[ GF_Chip_Card_Update::ARG_NONCE ] ) )
			: '';

		$signature = isset( $_GET[ GF_Chip_Card_Update::ARG_SIGNATURE ] )
			? sanitize_text_field( wp_unslash( $_GET[ GF_Chip_Card_Update::ARG_SIGNATURE ] ) )
			: '';

		$action = isset( $_GET[ self::ARG_ACTION ] )
			? sanitize_key( wp_unslash( $_GET[ self::ARG_ACTION ] ) )
			: '';

		$purchase = isset( $_GET[ self::ARG_PURCHASE ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::ARG_PURCHASE ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			GF_Chip_Card_Update::ARG_ENTRY     => $entry_id,
			GF_Chip_Card_Update::ARG_EXPIRY    => $expiry,
			GF_Chip_Card_Update::ARG_NONCE     => $nonce,
			GF_Chip_Card_Update::ARG_SIGNATURE => $signature,
			self::ARG_ACTION                   => $action,
			self::ARG_PURCHASE                 => $purchase,
		);
	}

	/**
	 * Loads an entry with its subscription meta, or null.
	 *
	 * @param int $entry_id Entry id.
	 * @return array|null
	 */
	public static function load_subscription_entry( $entry_id ) {
		$entry = GFAPI::get_entry( $entry_id );

		if ( ! is_array( $entry ) || is_wp_error( $entry ) ) {
			return null;
		}

		$entry = GF_Chip_Card_Update::hydrate( $entry );

		if ( ! GF_Chip::is_subscription_entry( $entry ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * The view model the page renders from.
	 *
	 * Pure: takes the entry and the current time, returns what to show. Kept
	 * separate so every displayed value and every button decision is
	 * assertable without HTML.
	 *
	 * @param array  $entry Entry with chip_sub_* meta flattened in.
	 * @param string $now   Current UTC datetime.
	 * @return array {
	 *     @type string $status        Subscription state key.
	 *     @type bool   $can_update     Whether the Update card button shows.
	 *     @type int    $amount_cents   Amount to be charged, 0 if token-only.
	 *     @type bool   $settling       Whether this collects an outstanding cycle.
	 *     @type string $next_payment  Next payment datetime, or ''.
	 *     @type bool   $has_token      Whether a card is on file.
	 *     @type string $heading       Page heading.
	 *     @type string $button_label  Update card button text.
	 *     @type string $explanation   Human sentence about what will happen.
	 * }
	 */
	public static function build_view( $entry, $now ) {
		$state        = GF_Chip::get_subscription_state( $entry );
		$amount_cents = GF_Chip_Card_Update::resolve_amount_cents( $entry, $now );
		$settling     = $amount_cents > 0;
		$has_token    = '' !== (string) rgar( $entry, 'chip_recurring_token' );

		return array(
			'status'       => $state,
			'can_update'   => GF_Chip_Card_Update::can_offer_link( $entry ),
			'amount_cents' => $amount_cents,
			'settling'     => $settling,
			'next_payment' => (string) rgar( $entry, 'chip_sub_next_payment' ),
			'has_token'    => $has_token,
			'heading'      => $settling ? 'Pay and update your card' : 'Update your card',
			'button_label' => $settling ? 'Pay and update card' : 'Update card',
			'explanation'  => self::explain( $settling, $has_token, $state ),
		);
	}

	/**
	 * The sentence telling the customer what will happen.
	 *
	 * @param bool   $settling  Whether an outstanding cycle is collected.
	 * @param bool   $has_token Whether a card is on file.
	 * @param string $state     Subscription state.
	 * @return string
	 */
	public static function explain( $settling, $has_token, $state ) {
		if ( $settling ) {
			return 'Your subscription has an outstanding payment. Paying now clears it and saves your new card in one step.';
		}

		if ( 'cancelled' === $state || 'expired' === $state ) {
			return 'This subscription is no longer active, so there is nothing to update.';
		}

		if ( ! $has_token ) {
			return 'No card is on file for this subscription. Add one to keep it running.';
		}

		return 'Your subscription is up to date. You can replace the card that will be charged at the next renewal.';
	}

	/**
	 * Formats a UTC datetime for display in site-local time.
	 *
	 * @param string $utc_datetime UTC 'Y-m-d H:i:s'.
	 * @return string
	 */
	public static function format_datetime( $utc_datetime ) {
		if ( empty( $utc_datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $utc_datetime . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Formats an amount in the smallest currency unit.
	 *
	 * @param int    $amount_cents Amount.
	 * @param string $currency     Currency code.
	 * @return string
	 */
	public static function format_amount( $amount_cents, $currency ) {
		return sprintf( '%s %s', strtoupper( (string) $currency ), number_format( (int) $amount_cents / 100, 2 ) );
	}

	/**
	 * Renders the page.
	 *
	 * @param int   $entry_id Entry id.
	 * @param array $request  Validated link components.
	 * @return void
	 */
	private static function render_page( $entry_id, $request ) {
		$entry = self::load_subscription_entry( $entry_id );

		if ( null === $entry ) {
			self::render_error( 'entry' );
			return;
		}

		$view     = self::build_view( $entry, gmdate( 'Y-m-d H:i:s' ) );
		$currency = self::resolve_currency( $entry );

		$start_url = add_query_arg(
			array(
				GF_Chip_Card_Update::ARG_ENTRY     => $entry_id,
				GF_Chip_Card_Update::ARG_EXPIRY    => $request[ GF_Chip_Card_Update::ARG_EXPIRY ],
				GF_Chip_Card_Update::ARG_NONCE     => $request[ GF_Chip_Card_Update::ARG_NONCE ],
				GF_Chip_Card_Update::ARG_SIGNATURE => $request[ GF_Chip_Card_Update::ARG_SIGNATURE ],
				self::ARG_ACTION                   => self::ACTION_START,
			),
			home_url( '/' )
		);

		$rows = array(
			'Subscription' => ucfirst( str_replace( '-', ' ', $view['status'] ) ),
			'Card on file' => $view['has_token'] ? 'Yes' : 'No',
		);

		if ( '' !== $view['next_payment'] ) {
			$rows['Next payment'] = self::format_datetime( $view['next_payment'] );
		}

		if ( $view['settling'] ) {
			$rows['Amount due'] = self::format_amount( $view['amount_cents'], $currency );
		}

		self::render_html( $view, $rows, $view['can_update'] ? $start_url : '' );
	}

	/**
	 * Resolves the customer's email for the CHIP purchase.
	 *
	 * CHIP wants an email on the client block. The field is the one the FEED
	 * names in `clientInformation_email` — the same field the checkout path
	 * bills — so this cannot drift from what the customer actually entered. A
	 * hardcoded field id would silently produce an empty client on any form
	 * that numbers its fields differently.
	 *
	 * Read from the entry only.
	 *
	 * @param array $entry Entry.
	 * @param array $feed  Feed.
	 * @return string
	 */
	private static function resolve_customer_email( $entry, $feed ) {
		$location = (string) rgars( $feed, 'meta/clientInformation_email' );
		$email    = '' !== $location ? (string) rgar( $entry, $location ) : '';

		return trim( $email );
	}

	/**
	 * Resolves the customer's name for the CHIP purchase.
	 *
	 * CHIP wants a name on the client block. The field is the one the FEED
	 * names in `clientInformation_full_name` — the same field the checkout
	 * path reads — so this cannot drift from what the customer actually
	 * filled in. A hardcoded field id would silently produce an empty name on
	 * any form that numbers its fields differently.
	 *
	 * Read from the entry only.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	private static function resolve_customer_name( $entry ) {
		$feed = self::find_feed( $entry );

		if ( is_array( $feed ) ) {
			$location = (string) rgars( $feed, 'meta/clientInformation_full_name' );

			if ( '' !== $location ) {
				// Prefer the form's own input order, which is the order the
				// checkout path builds the name in.
				$order = array();

				$form = GFAPI::get_form( rgar( $entry, 'form_id' ) );

				if ( is_array( $form ) ) {
					foreach ( (array) rgar( $form, 'fields' ) as $field ) {
						if ( 'name' !== rgar( (array) $field, 'type' ) ) {
							continue;
						}

						$field_id = (string) rgar( (array) $field, 'id' );

						if ( $field_id !== (string) $location ) {
							continue;
						}

						foreach ( (array) rgar( (array) $field, 'inputs' ) as $input ) {
							$order[] = (string) rgar( (array) $input, 'id' );
						}
					}
				}

				// Fall back to whatever the entry holds, in key order.
				if ( empty( $order ) ) {
					foreach ( array_keys( $entry ) as $key ) {
						if ( 0 === strpos( (string) $key, $location . '.' ) ) {
							$order[] = (string) $key;
						}
					}

					sort( $order );
				}

				$name = GF_Chip_Card_Update_Flow::join_name_parts( $entry, $order, $location );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		$fallback = rgar( $entry, 'full_name' );

		return is_string( $fallback ) ? trim( $fallback ) : '';
	}

	/**
	 * Resolves the currency for an entry.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	private static function resolve_currency( $entry ) {
		$currency = rgar( $entry, 'currency' );

		if ( ! empty( $currency ) ) {
			return $currency;
		}

		$form = GFAPI::get_form( rgar( $entry, 'form_id' ) );

		return is_array( $form ) ? (string) rgar( $form, 'currency', 'MYR' ) : 'MYR';
	}

	/**
	 * Starts the CHIP handoff.
	 *
	 * @param int   $entry_id Entry id.
	 * @param array $request  Validated link components.
	 * @return void
	 */
	private static function start_purchase( $entry_id, $request ) {
		$entry = self::load_subscription_entry( $entry_id );

		if ( null === $entry ) {
			self::render_error( 'entry' );
			return;
		}

		$view = self::build_view( $entry, gmdate( 'Y-m-d H:i:s' ) );

		if ( ! $view['can_update'] ) {
			self::render_error( 'state' );
			return;
		}

		$feed = self::find_feed( $entry );

		if ( null === $feed ) {
			self::render_error( 'feed' );
			return;
		}

		$credentials = GF_Chip::get_instance()->get_credentials_for_feed( $feed );

		if ( empty( $credentials['secret_key'] ) || empty( $credentials['brand_id'] ) ) {
			self::render_error( 'credentials' );
			return;
		}

		// Read from the entry only, never from the request.
		$recipient = self::resolve_customer_email( $entry, $feed );

		$return_url = add_query_arg(
			array(
				GF_Chip_Card_Update::ARG_ENTRY     => $entry_id,
				GF_Chip_Card_Update::ARG_EXPIRY    => $request[ GF_Chip_Card_Update::ARG_EXPIRY ],
				GF_Chip_Card_Update::ARG_NONCE     => $request[ GF_Chip_Card_Update::ARG_NONCE ],
				GF_Chip_Card_Update::ARG_SIGNATURE => $request[ GF_Chip_Card_Update::ARG_SIGNATURE ],
			),
			home_url( '/' )
		);

		$params = GF_Chip_Card_Update_Flow::build_purchase_params(
			array(
				'amount_cents' => $view['amount_cents'],
				'currency'     => self::resolve_currency( $entry ),
				'entry_id'     => $entry_id,
				'return_url'   => $return_url,
				'brand_id'     => $credentials['brand_id'],
				// CHIP requires the customer on the create call. Both are
				// read from the entry, never from the request.
				'email'        => $recipient,
				'full_name'    => self::resolve_customer_name( $entry ),
			)
		);

		$client   = GF_Chip_API::get_instance( $credentials['secret_key'], $credentials['brand_id'] );
		$response = $client->create_payment( $params );

		if ( ! is_array( $response ) || empty( $response['checkout_url'] ) ) {
			GF_Chip::get_instance()->log_debug( __METHOD__ . '(): no checkout_url for entry #' . $entry_id );
			self::render_error( 'checkout' );
			return;
		}

		// Remember which entry and link this purchase belongs to, so the
		// return trip can be matched without trusting a request parameter.
		gform_update_meta( $entry_id, GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE, $response['id'] );
		gform_update_meta( $entry_id, 'chip_card_update_settling', $view['settling'] ? '1' : '0' );

		// The checkout is on CHIP's own host, which wp_safe_redirect() blocks by
		// default. Rather than reaching for wp_redirect() and losing the guard,
		// the gateway host is allowlisted for exactly this redirect.
		$checkout_host = wp_parse_url( $response['checkout_url'], PHP_URL_HOST );

		if ( is_string( $checkout_host ) && '' !== $checkout_host ) {
			$allow = function ( $hosts ) use ( $checkout_host ) {
				$hosts[] = $checkout_host;

				return $hosts;
			};

			add_filter( 'allowed_redirect_hosts', $allow );
			wp_safe_redirect( $response['checkout_url'] );
			remove_filter( 'allowed_redirect_hosts', $allow );
			exit;
		}

		self::render_error( 'checkout_host' );
	}

	/**
	 * Settles the return trip from CHIP.
	 *
	 * @param int    $entry_id    Entry id.
	 * @param string $purchase_id Purchase id from the redirect.
	 * @return void
	 */
	private static function finish_purchase( $entry_id, $purchase_id ) {
		$entry = self::load_subscription_entry( $entry_id );

		if ( null === $entry ) {
			self::render_error( 'entry' );
			return;
		}

		// The purchase id must be the one we recorded for this entry, so a
		// guessed id cannot be used to settle someone else's subscription.
		$recorded = (string) gform_get_meta( $entry_id, GF_Chip_Card_Update_Flow::META_UPDATE_PURCHASE );

		if ( '' === $recorded || ! hash_equals( $recorded, $purchase_id ) ) {
			GF_Chip::get_instance()->log_debug( __METHOD__ . '(): purchase mismatch for entry #' . $entry_id );
			self::render_error( 'purchase' );
			return;
		}

		$feed = self::find_feed( $entry );

		if ( null === $feed ) {
			self::render_error( 'feed' );
			return;
		}

		$credentials = GF_Chip::get_instance()->get_credentials_for_feed( $feed );
		$client      = GF_Chip_API::get_instance( $credentials['secret_key'], $credentials['brand_id'] );
		$purchase    = $client->get_payment( $purchase_id );

		if ( ! GF_Chip_Card_Update_Flow::is_completed( $purchase ) ) {
			self::render_error( 'incomplete' );
			return;
		}

		$was_settling = '1' === (string) gform_get_meta( $entry_id, 'chip_card_update_settling' );
		$plan         = GF_Chip_Card_Update_Flow::plan_settlement( $purchase, $was_settling );
		$form_id      = rgar( $entry, 'form_id' );

		// The old token must be revoked at CHIP before we drop our only
		// reference to it, or it stays live with nothing tracking it.
		$old_purchase = (string) rgar( $entry, 'chip_payment_id' );
		$old_token    = (string) rgar( $entry, 'chip_recurring_token' );

		if ( '' !== $old_purchase && '' !== $old_token && $old_token !== $plan['token'] ) {
			$client->delete_recurring_token( $old_purchase );
		}

		if ( null !== $plan['token'] ) {
			gform_update_meta( $entry_id, 'chip_recurring_token', $plan['token'], $form_id );
		}

		gform_update_meta( $entry_id, 'chip_sub_status', $plan['status'], $form_id );
		gform_update_meta( $entry_id, 'chip_sub_retry_count', $plan['retry_count'], $form_id );

		if ( $plan['settled'] && GF_Chip_Card_Update_Flow::collected_payment( $purchase ) ) {
			gform_update_meta( $entry_id, 'chip_sub_last_payment', gmdate( 'Y-m-d H:i:s' ), $form_id );
		}

		gform_delete_meta( $entry_id, 'chip_card_update_settling' );

		$app = GF_Chip::get_instance();
		$app->add_note(
			$entry_id,
			sprintf(
				'Card update completed. New token stored, subscription set to %s.%s',
				$plan['status'],
				$plan['settled'] ? ' Outstanding cycle settled.' : ''
			)
		);

		// The link has done its job.
		GF_Chip_Card_Update::consume_link( $entry_id );

		self::render_success( $plan );
	}

	/**
	 * Finds the feed that processed an entry.
	 *
	 * Uses Gravity Forms' own get_payment_feed(), which resolves the feed from
	 * the entry's stored feed id. An earlier draft read a `chip_feed_id` meta
	 * key that this plugin never writes, so it would always have returned null
	 * and the page would have failed silently at the CHIP handoff.
	 *
	 * @param array $entry Entry with chip_sub_* meta flattened in.
	 * @return array|null
	 */
	private static function find_feed( $entry ) {
		$addon = GF_Chip::get_instance();
		$feed  = $addon->get_payment_feed( $entry );

		if ( ! is_array( $feed ) || empty( $feed['id'] ) ) {
			return null;
		}

		return $feed;
	}

	/**
	 * Emits a minimal standalone HTML page.
	 *
	 * @param array  $view      View model.
	 * @param array  $rows      Label => value rows.
	 * @param string $action_url Update button target, or '' to omit it.
	 * @return void
	 */
	private static function render_html( $view, $rows, $action_url ) {
		$title = get_bloginfo( 'name' );

		nocache_headers();

		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow" />';
		echo '<title>' . esc_html( $view['heading'] ) . ' &ndash; ' . esc_html( $title ) . '</title></head><body>';
		echo '<main style="max-width:32rem;margin:4rem auto;font-family:system-ui,sans-serif;padding:0 1rem">';
		echo '<h1>' . esc_html( $view['heading'] ) . '</h1>';
		echo '<p>' . esc_html( $view['explanation'] ) . '</p>';
		echo '<table style="width:100%;border-collapse:collapse">';

		foreach ( $rows as $label => $value ) {
			echo '<tr>';
			echo '<th style="text-align:left;padding:.4rem 0;font-weight:600">' . esc_html( $label ) . '</th>';
			echo '<td style="text-align:right;padding:.4rem 0">' . esc_html( $value ) . '</td>';
			echo '</tr>';
		}

		echo '</table>';

		if ( '' !== $action_url ) {
			echo '<p style="margin-top:2rem">';
			echo '<a href="' . esc_url( $action_url ) . '" style="display:inline-block;padding:.75rem 1.25rem;background:#1f2937;color:#fff;text-decoration:none;border-radius:.375rem">';
			echo esc_html( $view['button_label'] );
			echo '</a></p>';
		}

		echo '</main></body></html>';
	}

	/**
	 * Emits the success screen.
	 *
	 * @param array $plan Settlement plan.
	 * @return void
	 */
	private static function render_success( $plan ) {
		$rows = array(
			'Card'         => null !== $plan['token'] ? 'Saved' : 'Not saved',
			'Subscription' => ucfirst( str_replace( '-', ' ', $plan['status'] ) ),
		);

		if ( $plan['settled'] ) {
			$rows['Payment'] = 'Received';
		}

		$view = array(
			'heading'      => $plan['settled'] ? 'Payment received and card updated' : 'Card updated',
			'explanation'  => null !== $plan['token']
				? 'Your new card is saved and will be used for future payments.'
				: 'We could not save a card. Please use the link again, or contact support.',
			'button_label' => '',
		);

		self::render_html( $view, $rows, '' );
	}

	/**
	 * Emits a generic failure page that never says which check failed.
	 *
	 * The reason is logged, not shown: telling a visitor that the signature is
	 * wrong versus expired versus already used would help someone probing
	 * links, and would not help a legitimate customer at all.
	 *
	 * @param string $reason Internal reason, for the log only.
	 * @return void
	 */
	private static function render_error( $reason ) {
		if ( class_exists( 'GF_Chip' ) ) {
			GF_Chip::get_instance()->log_debug( 'Card update page rejected: ' . $reason );
		}

		$view = array(
			'heading'      => 'Link not valid',
			'explanation'  => 'This link is no longer valid. It may have expired or already been used. Please contact the site owner for a new one.',
			'button_label' => '',
		);

		self::render_html( $view, array(), '' );
	}
}
