<?php
/**
 * Unit tests for GF_Chip subscription token acquisition at checkout.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::get_checkout_token_params
 *
 * @covers \GF_Chip::get_recurring_payment_method_whitelist
 *
 * @covers \GF_Chip::extract_recurring_token
 */
class GF_Chip_TokenCheckoutTest extends TestCase {

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

	// ---------------------------------------------------------------------
	// Token params: only a subscription feed may request a token.
	// ---------------------------------------------------------------------

	/**
	 * A subscription feed must request a recurring token.
	 */
	public function test_subscription_feed_requests_force_recurring(): void {
		$params = GF_Chip::get_checkout_token_params( array( 'meta' => array( 'transactionType' => 'subscription' ) ) );

		$this->assertArrayHasKey( 'force_recurring', $params );
		$this->assertTrue( $params['force_recurring'] );
	}

	/**
	 * A one-time (product) feed must NOT request a token.
	 *
	 * This is the critical guard: an unconditional force_recurring would
	 * tokenise one-time payments and change behaviour for every existing user.
	 */
	public function test_product_feed_does_not_request_a_token(): void {
		$params = GF_Chip::get_checkout_token_params( array( 'meta' => array( 'transactionType' => 'product' ) ) );

		$this->assertSame( array(), $params );
	}

	/**
	 * A feed with no transaction type must not request a token.
	 */
	public function test_feed_without_transaction_type_does_not_request_a_token(): void {
		$params = GF_Chip::get_checkout_token_params( array( 'meta' => array() ) );

		$this->assertSame( array(), $params );
	}

	/**
	 * A feed with no meta at all must not request a token and must not warn.
	 */
	public function test_feed_without_meta_does_not_request_a_token(): void {
		$params = GF_Chip::get_checkout_token_params( array() );

		$this->assertSame( array(), $params );
	}

	// ---------------------------------------------------------------------
	// Card-only whitelist.
	// ---------------------------------------------------------------------

	/**
	 * A subscription checkout is restricted to card payment methods.
	 *
	 * CHIP recurring tokens are card-only, so offering FPX or DuitNow QR on a
	 * subscription would produce a payment that cannot be renewed.
	 */
	public function test_subscription_whitelist_is_card_only(): void {
		$whitelist = GF_Chip::get_recurring_payment_method_whitelist();

		$this->assertIsArray( $whitelist );
		$this->assertNotEmpty( $whitelist );

		// No non-card method may appear.
		foreach ( array( 'fpx', 'duitnow_qr', 'razer_grabpay', 'razer_tng', 'shopee_pay' ) as $non_card ) {
			$this->assertNotContains(
				$non_card,
				$whitelist,
				"Non-card method '{$non_card}' must not be allowed for recurring"
			);
		}
	}

	/**
	 * Every value sent as payment_method_whitelist must be one CHIP accepts.
	 *
	 * This is the regression test for a real defect: the whitelist used to
	 * include the generic UI key 'card', and CHIP rejected the whole purchase
	 * with HTTP 400 `"card" is not a valid choice`, so no subscription could
	 * ever reach checkout. The old assertion was on the plugin's own constant
	 * output, which cannot detect a wrong value inside it.
	 *
	 * The list below is the set of card identifiers CHIP accepts, cross-checked
	 * against GET /payment_methods/ on the live API.
	 */
	public function test_whitelist_contains_only_identifiers_chip_accepts(): void {
		$whitelist = GF_Chip::get_recurring_payment_method_whitelist();

		$this->assertSame( array( 'visa', 'mastercard', 'maestro' ), array_values( $whitelist ) );

		// The specific defect: the UI group key must never be sent to the API.
		$this->assertNotContains( 'card', $whitelist, "'card' is a UI key; CHIP rejects it with invalid_choice" );
	}

	/**
	 * The whitelist is 0-indexed, because CHIP rejects an associative array
	 * with "Expected a list of items but got type dict".
	 */
	public function test_whitelist_is_a_zero_indexed_list(): void {
		$whitelist = GF_Chip::get_recurring_payment_method_whitelist();

		$this->assertSame( range( 0, count( $whitelist ) - 1 ), array_keys( $whitelist ) );
	}

	/**
	 * The whitelist is filterable so a brand can extend it.
	 */
	public function test_whitelist_is_filterable(): void {
		WP_Mock::onFilter( 'gf_chip_recurring_payment_method_whitelist' )
			->with( GF_Chip::get_recurring_payment_method_whitelist() )
			->reply( array( 'visa' ) );

		$this->assertSame( array( 'visa' ), GF_Chip::get_recurring_payment_method_whitelist() );
	}

	// ---------------------------------------------------------------------
	// Token extraction from the CHIP purchase object.
	// ---------------------------------------------------------------------

	/**
	 * When is_recurring_token is true, the purchase id IS the token.
	 *
	 * CHIP documents two shapes. Looking for a separate recurring_token field
	 * in this case would store null and produce a subscription that can never
	 * be renewed.
	 */
	public function test_token_is_purchase_id_when_is_recurring_token_true(): void {
		$purchase = array(
			'id'                 => 'pur_abc123',
			'is_recurring_token' => true,
		);

		$this->assertSame( 'pur_abc123', GF_Chip::extract_recurring_token( $purchase ) );
	}

	/**
	 * When is_recurring_token is false, the recurring_token field is used.
	 */
	public function test_token_is_read_from_recurring_token_field(): void {
		$purchase = array(
			'id'                 => 'pur_abc123',
			'is_recurring_token' => false,
			'recurring_token'    => 'tok_xyz789',
		);

		$this->assertSame( 'tok_xyz789', GF_Chip::extract_recurring_token( $purchase ) );
	}

	/**
	 * A purchase with no token data yields null rather than a misleading value
	 * such as the raw purchase id.
	 */
	public function test_token_is_null_when_absent(): void {
		$purchase = array( 'id' => 'pur_abc123' );

		$this->assertNull( GF_Chip::extract_recurring_token( $purchase ) );
	}

	/**
	 * An explicit null recurring_token yields null.
	 */
	public function test_token_is_null_when_recurring_token_field_is_null(): void {
		$purchase = array(
			'id'                 => 'pur_abc123',
			'is_recurring_token' => false,
			'recurring_token'    => null,
		);

		$this->assertNull( GF_Chip::extract_recurring_token( $purchase ) );
	}

	/**
	 * An empty purchase yields null and does not warn.
	 */
	public function test_token_is_null_for_empty_purchase(): void {
		$this->assertNull( GF_Chip::extract_recurring_token( array() ) );
	}

	/**
	 * A non-array purchase yields null and does not warn.
	 */
	public function test_token_is_null_for_non_array_input(): void {
		$this->assertNull( GF_Chip::extract_recurring_token( null ) );
		$this->assertNull( GF_Chip::extract_recurring_token( false ) );
	}

	// ---------------------------------------------------------------------
	// Persisting the token on a completed subscription payment.
	//
	// NOTE: gform_update_meta() is *defined* in tests/bootstrap.php as a
	// no-op, so Patchwork cannot intercept it and WP_Mock::userFunction()
	// has no effect on it. These tests therefore assert the return value of
	// persist_subscription_token() — the contract the caller depends on —
	// rather than trying to capture the meta write.
	// ---------------------------------------------------------------------

	/**
	 * A purchase whose token is the purchase id persists successfully.
	 */
	public function test_persist_succeeds_when_is_recurring_token_true(): void {
		$purchase = array(
			'id'                 => 'pur_sub_1',
			'status'             => 'paid',
			'is_recurring_token' => true,
		);

		$this->assertTrue( GF_Chip::persist_subscription_token( 42, 7, $purchase ) );
	}

	/**
	 * A purchase whose token is in the recurring_token field persists.
	 */
	public function test_persist_succeeds_from_recurring_token_field(): void {
		$purchase = array(
			'id'                 => 'pur_sub_2',
			'status'             => 'paid',
			'is_recurring_token' => false,
			'recurring_token'    => 'tok_field_999',
		);

		$this->assertTrue( GF_Chip::persist_subscription_token( 42, 7, $purchase ) );
	}

	/**
	 * A purchase with no token reports failure so the caller can surface it.
	 *
	 * A subscription that silently stores no token looks active but can never
	 * renew — the false return is what lets complete_payment() add an error
	 * note instead of a success one.
	 */
	public function test_persist_fails_when_no_token_present(): void {
		$purchase = array(
			'id'     => 'pur_no_token',
			'status' => 'paid',
		);

		$this->assertFalse( GF_Chip::persist_subscription_token( 42, 7, $purchase ) );
	}

	/**
	 * A non-array purchase reports failure rather than warning.
	 */
	public function test_persist_fails_for_non_array_purchase(): void {
		$this->assertFalse( GF_Chip::persist_subscription_token( 42, 7, null ) );
		$this->assertFalse( GF_Chip::persist_subscription_token( 42, 7, false ) );
	}

	/**
	 * An empty-string token is treated as absent, not stored.
	 */
	public function test_persist_fails_for_empty_string_token(): void {
		$purchase = array(
			'id'                 => 'pur_empty',
			'is_recurring_token' => false,
			'recurring_token'    => '',
		);

		$this->assertFalse( GF_Chip::persist_subscription_token( 42, 7, $purchase ) );
	}

	// ---------------------------------------------------------------------
	// Wiring: the token params must actually reach the purchase payload.
	//
	// The builder being correct is worthless if redirect_url() never applies
	// it. These exercise merge_checkout_token_params(), which is the exact
	// call redirect_url() makes.
	// ---------------------------------------------------------------------

	/**
	 * A subscription payload gains force_recurring and the card whitelist.
	 */
	public function test_merge_adds_token_params_for_subscription(): void {
		$base = array( 'brand_id' => 'b1', 'purchase' => array( 'currency' => 'MYR' ) );

		$result = GF_Chip::merge_checkout_token_params(
			$base,
			array( 'meta' => array( 'transactionType' => 'subscription' ) )
		);

		$this->assertTrue( $result['force_recurring'] );
		$this->assertSame( GF_Chip::get_recurring_payment_method_whitelist(), $result['payment_method_whitelist'] );
		// Existing keys survive the merge.
		$this->assertSame( 'b1', $result['brand_id'] );
		$this->assertSame( array( 'currency' => 'MYR' ), $result['purchase'] );
	}

	/**
	 * A one-time payload is returned completely untouched.
	 *
	 * This is the regression guard for the whole gating decision: a
	 * subscription must not be able to change an existing user's one-time
	 * checkout payload in any way.
	 */
	public function test_merge_leaves_product_payload_untouched(): void {
		$base = array( 'brand_id' => 'b1', 'purchase' => array( 'currency' => 'MYR' ) );

		$result = GF_Chip::merge_checkout_token_params(
			$base,
			array( 'meta' => array( 'transactionType' => 'product' ) )
		);

		$this->assertSame( $base, $result );
		$this->assertArrayNotHasKey( 'force_recurring', $result );
		$this->assertArrayNotHasKey( 'payment_method_whitelist', $result );
	}

	/**
	 * A one-time payload is untouched when the feed has no meta at all.
	 */
	public function test_merge_leaves_payload_untouched_without_meta(): void {
		$base = array( 'brand_id' => 'b1' );

		$this->assertSame( $base, GF_Chip::merge_checkout_token_params( $base, array() ) );
	}

	/**
	 * The token and the subscription id are distinct concepts.
	 *
	 * When is_recurring_token is true they are the same value; when the token
	 * comes from the recurring_token field the purchase id is still the
	 * subscription identifier. extract_recurring_token() returning the token
	 * (not the id) in that case is what keeps the two apart.
	 */
	public function test_token_and_purchase_id_are_distinguishable(): void {
		$purchase = array(
			'id'                 => 'pur_identity',
			'is_recurring_token' => false,
			'recurring_token'    => 'tok_separate',
		);

		$this->assertSame( 'tok_separate', GF_Chip::extract_recurring_token( $purchase ) );
		$this->assertNotSame( $purchase['id'], GF_Chip::extract_recurring_token( $purchase ) );
	}
}
