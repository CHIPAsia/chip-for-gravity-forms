<?php
/**
 * Regression tests for the feed field a payment amount is resolved from.
 *
 * Gravity Forms stores the amount field under a key that depends on the
 * feed's transaction type: `recurringAmount` for a subscription, and
 * `paymentAmount` for a one-time product or donation. The Subscription
 * Settings group in the feed editor is gated on `transactionType`, so a
 * subscription feed never has a `paymentAmount` key at all.
 *
 * The defect these tests pin: redirect_url() read `meta/paymentAmount`
 * unconditionally. On a subscription feed that resolved to an empty
 * string, no line item matched it, and the purchase was sent to CHIP with
 * an empty product name and a zero price. CHIP rejected it with HTTP 400
 * (`name: This field may not be blank.`, `quantity: This field may not be
 * null.`), no `chip_payment_id` was stored, and the customer saw the
 * form's default confirmation instead of the payment page — so a
 * subscription could never be paid for at all.
 *
 * These tests therefore drive the real redirect_url() and assert on the
 * JSON body that would go to CHIP. Asserting the resolver's return value
 * alone would be worthless here: get_payment_field() belongs to Gravity
 * Forms core, so a unit test of it tests the framework, not this plugin.
 * The payload is the only place the defect and the fix differ.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_CHIP_API;
use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::redirect_url
 */
class GF_Chip_SubscriptionAmountFieldTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * A feed shaped like the subscription feed found on a live install.
	 *
	 * @return array
	 */
	private function subscription_feed() {
		return array(
			'meta' => array(
				'feedName'                => 'Subscription',
				'transactionType'         => 'subscription',
				// What the feed editor actually saves for a subscription.
				'recurringAmount'         => 'form_total',
				// GF never writes paymentAmount for this transaction type.
				'paymentAmount'           => '',
				'chipConfigurationType'   => 'global',
				'clientInformation_email' => '3',
				'purchaseInformation_notes' => '7',
			),
		);
	}

	/**
	 * A feed shaped like a one-time product feed using the form total.
	 *
	 * @return array
	 */
	private function product_feed() {
		return array(
			'meta' => array(
				'feedName'                => 'Product',
				'transactionType'         => 'product',
				'paymentAmount'           => 'form_total',
				'chipConfigurationType'   => 'global',
				'clientInformation_email' => '3',
				'purchaseInformation_notes' => '7',
			),
		);
	}

	/**
	 * Stub every WordPress surface redirect_url() touches, capture the
	 * request body CHIP would receive, and return that capture by reference.
	 *
	 * @param array $captured Filled with the decoded request body.
	 * @return void
	 */
	private function mock_wordpress( &$captured ) {
		$captured = array();

		$response_body = wp_json_encode(
			array(
				'id'           => 'pur_captured',
				'status'       => 'created',
				'checkout_url' => 'https://payments.example.com/p/pur_captured/',
				'is_test'      => false,
			)
		);

		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				'secret_key' => 'sk_test',
				'brand_id'   => 'brand_test',
			)
		);

		// Passthrough for every filter: gf_chip_sslverify, the purchase
		// timezone, the recurring whitelist and the payload filter itself.
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $hook, $value = null ) {
				return $value;
			}
		);

		WP_Mock::userFunction( 'wp_remote_request' )->andReturnUsing(
			function ( $url, $args ) use ( $response_body, &$captured ) {
				$captured = json_decode( $args['body'], true );
				return array( 'body' => $response_body );
			}
		);

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '';
			}
		);

		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 201 );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_timezone_string' )->andReturn( 'Asia/Kuala_Lumpur' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'https://example.com/?callback=gravityformschip&entry_id=9' );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com/' );
		WP_Mock::userFunction( 'absint' )->andReturnUsing(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing(
			function ( $text, $domain = null ) {
				return $text;
			}
		);
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing(
			function ( $url ) {
				return $url;
			}
		);

		// Fresh API client so a captured request cannot be served from a
		// singleton left over from another test.
		$ref  = new \ReflectionClass( GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/**
	 * The product line CHIP receives for a form-total feed, and the
	 * redirect_url() result, for a given feed.
	 *
	 * @param array $feed The feed to process.
	 * @return array {
	 *     @type array       $product  The first product in the request body.
	 *     @type string|bool $redirect The redirect_url() return value.
	 * }
	 */
	private function capture_checkout( $feed ) {
		$captured = array();
		$this->mock_wordpress( $captured );

		$submission_data = array(
			'payment_amount' => 10.0,
			'line_items'     => array(),
		);

		$form = array(
			'id'     => 2,
			'title'  => 'Gravity Test 6.8 - Test',
			'fields' => array(),
		);

		$entry = array(
			'id'        => 9,
			'currency'  => 'MYR',
			'3'         => 'buyer@example.com',
			'7'         => 'Order 42',
			'payment_amount' => '10.00',
		);

		$redirect = GF_Chip::get_instance()->redirect_url( $feed, $submission_data, $form, $entry );

		return array(
			'product'  => rgar( rgar( $captured, 'purchase' ), 'products' )[0],
			'body'     => $captured,
			'redirect' => $redirect,
		);
	}

	// ---------------------------------------------------------------------
	// The defect: a subscription must send a real product, not a blank one.
	// ---------------------------------------------------------------------

	/**
	 * A subscription checkout must send CHIP a named, priced product.
	 *
	 * This is the test that fails against the old code: reading
	 * `meta/paymentAmount` yields '', no line item matches, and the request
	 * goes out as `{name: "", price: 0, quantity: null}` — which CHIP
	 * rejects with HTTP 400, so the customer is never redirected.
	 */
	public function test_subscription_checkout_sends_a_named_priced_product(): void {
		$result  = $this->capture_checkout( $this->subscription_feed() );
		$product = $result['product'];

		$this->assertNotSame( '', rgar( $product, 'name' ), 'CHIP rejects a blank product name with HTTP 400' );
		$this->assertGreaterThan( 0, rgar( $product, 'price' ), 'CHIP rejects a zero price for a paid subscription' );
		$this->assertNotNull( rgar( $product, 'quantity' ), 'CHIP rejects a null quantity with HTTP 400' );
	}

	/**
	 * A subscription using the form total sends the form title and the
	 * form total, converted to the smallest currency unit.
	 *
	 * RM 10.00 must travel as 1000 — sending 10 would bill 10 sen.
	 */
	public function test_subscription_form_total_uses_form_title_and_cents(): void {
		$result  = $this->capture_checkout( $this->subscription_feed() );
		$product = $result['product'];

		$this->assertSame( 'Gravity Test 6.8 - Test', rgar( $product, 'name' ) );
		$this->assertSame( 1000, (int) rgar( $product, 'price' ) );
		$this->assertSame( '1', (string) rgar( $product, 'quantity' ) );
	}

	/**
	 * A subscription checkout still reaches CHIP rather than returning false.
	 *
	 * redirect_url() returning false is exactly what makes Gravity Forms
	 * fall back to the form's default confirmation, which is what the
	 * customer saw.
	 */
	public function test_subscription_checkout_returns_the_checkout_url(): void {
		$result = $this->capture_checkout( $this->subscription_feed() );

		$this->assertSame( 'https://payments.example.com/p/pur_captured/', $result['redirect'] );
	}

	/**
	 * A subscription checkout still requests a recurring token.
	 *
	 * The amount fix must not displace the token params: a subscription
	 * that pays once and stores no token cannot renew.
	 */
	public function test_subscription_checkout_requests_a_recurring_token(): void {
		$result = $this->capture_checkout( $this->subscription_feed() );

		$this->assertTrue( rgar( $result['body'], 'force_recurring' ) );
		$this->assertNotEmpty( rgar( $result['body'], 'payment_method_whitelist' ) );
	}

	// ---------------------------------------------------------------------
	// One-time checkouts must be untouched by the fix.
	// ---------------------------------------------------------------------

	/**
	 * A one-time feed using the form total keeps its existing behaviour:
	 * the form title at the submission amount.
	 */
	public function test_product_checkout_still_sends_the_form_total(): void {
		$result  = $this->capture_checkout( $this->product_feed() );
		$product = $result['product'];

		$this->assertSame( 'Gravity Test 6.8 - Test', rgar( $product, 'name' ) );
		$this->assertSame( 1000, (int) rgar( $product, 'price' ) );
		$this->assertArrayNotHasKey( 'force_recurring', $result['body'], 'A one-time feed must not be tokenised' );
	}

	/**
	 * A one-time feed bound to a specific product field still picks that
	 * line item out of the submission data.
	 *
	 * This covers the else-branch, which the subscription fix must leave
	 * reachable.
	 */
	public function test_product_checkout_bound_to_a_field_uses_that_line_item(): void {
		$feed = $this->product_feed();
		$feed['meta']['paymentAmount'] = '4';
		// Bind notes to the product so every mapped substring has a value,
		// mirroring a real feed rather than leaving nulls to be deprecated.
		$feed['meta']['purchaseInformation_notes'] = '4';

		$captured = array();
		$this->mock_wordpress( $captured );

		$submission_data = array(
			'payment_amount' => 50.0,
			'line_items'     => array(
				array(
					'id'         => '4',
					'name'       => 'Widget',
					'unit_price' => 25.0,
					'quantity'   => 2,
				),
			),
		);

		$form  = array(
			'id'     => 2,
			'title'  => 'Gravity Test 6.8 - Test',
			'fields' => array(),
		);
		$entry = array(
			'id'       => 9,
			'currency' => 'MYR',
			'3'        => 'buyer@example.com',
			'4'        => 'Widget',
		);

		GF_Chip::get_instance()->redirect_url( $feed, $submission_data, $form, $entry );

		$product = rgar( rgar( $captured, 'purchase' ), 'products' )[0];

		$this->assertSame( 'Widget', rgar( $product, 'name' ) );
		$this->assertSame( 2500, (int) rgar( $product, 'price' ) );
		$this->assertSame( '2', (string) rgar( $product, 'quantity' ) );
	}
}
