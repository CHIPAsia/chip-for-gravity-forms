<?php
/**
 * Unit tests for GF_CHIP_API.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_CHIP_API;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Purchase status values match CHIP API PurchaseStatus enum:
 * https://docs.chip-in.asia/chip-collect/api-reference/purchases/create
 *
 * @covers \GF_CHIP_API
 */
class GF_CHIP_APITest extends TestCase {

	/**
	 * Reset API singleton before each test so mocks apply to a fresh instance.
	 */
	public function setUp(): void {
		parent::setUp();
		$ref  = new \ReflectionClass( GF_CHIP_API::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * get_public_key returns decoded string when API returns JSON string body.
	 */
	public function test_get_public_key_returns_string_from_json_body(): void {
		$response_body = json_encode( 'simple-key-string' );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$key = $api->get_public_key();

		$this->assertIsString( $key );
		$this->assertSame( 'simple-key-string', $key );
	}

	/**
	 * get_public_key normalizes literal \n to real newlines in PEM string.
	 */
	public function test_get_public_key_normalizes_newlines(): void {
		$pem_with_slash_n = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA\n-----END PUBLIC KEY-----";
		// Simulate API returning PEM with literal backslash-n (two chars).
		$pem_literal_backslash_n = str_replace( "\n", '\n', $pem_with_slash_n );
		$response_body           = json_encode( $pem_literal_backslash_n );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$key = $api->get_public_key();

		$this->assertIsString( $key );
		$this->assertStringContainsString( "\n", $key );
		$this->assertStringNotContainsString( '\n', $key );
		$this->assertSame( $pem_with_slash_n, $key );
	}

	/**
	 * get_public_key returns null when API returns invalid JSON.
	 */
	public function test_get_public_key_returns_null_for_invalid_json(): void {
		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => 'invalid json' ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$this->assertNull( $api->get_public_key() );
	}

	/**
	 * get_public_key returns null when API response has errors key.
	 */
	public function test_get_public_key_returns_null_when_response_has_errors(): void {
		$response_body = json_encode( array( 'errors' => array( 'Something went wrong' ) ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api  = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$key  = $api->get_public_key();
		$this->assertNull( $key );
	}

	/**
	 * get_public_key returns array unchanged (no newline normalization).
	 */
	public function test_get_public_key_returns_array_unchanged(): void {
		$response_body = json_encode( array( 'key' => 'value' ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api   = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$result = $api->get_public_key();
		$this->assertIsArray( $result );
		$this->assertSame( array( 'key' => 'value' ), $result );
	}

	/**
	 * get_company_uid returns company_uid from GET results.
	 */
	public function test_get_company_uid_returns_from_get_results(): void {
		$response_body = json_encode( array(
			'results' => array(
				array( 'company_uid' => 'comp_123' ),
			),
		) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api  = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$uid  = $api->get_company_uid();
		$this->assertSame( 'comp_123', $uid );
	}

	/**
	 * get_company_uid returns company_uid from POST when GET results are empty.
	 */
	public function test_get_company_uid_returns_from_post_when_get_empty(): void {
		$get_body  = json_encode( array( 'results' => array() ) );
		$post_body = json_encode( array( 'company_uid' => 'comp_456' ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->twice()
			->andReturnValues( array(
				array( 'body' => $get_body ),
				array( 'body' => $post_body ),
			) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$uid = $api->get_company_uid();
		$this->assertSame( 'comp_456', $uid );
	}

	/**
	 * get_company_uid returns null when GET has no results and POST has no company_uid.
	 */
	public function test_get_company_uid_returns_null_when_get_empty_and_post_fails(): void {
		$get_body  = json_encode( array( 'results' => array() ) );
		$post_body = json_encode( array( 'errors' => array( 'Failed' ) ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->twice()
			->andReturnValues( array(
				array( 'body' => $get_body ),
				array( 'body' => $post_body ),
			) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$this->assertNull( $api->get_company_uid() );
	}

	/**
	 * get_company_uid returns null when first result has no company_uid.
	 */
	public function test_get_company_uid_returns_null_when_first_result_has_no_company_uid(): void {
		$response_body = json_encode( array(
			'results' => array(
				array( 'id' => 1 ),
			),
		) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$this->assertNull( $api->get_company_uid() );
	}

	/**
	 * get_payment returns decoded array when API returns valid JSON.
	 * Status 'paid' per CHIP PurchaseStatus (purchase successfully paid for).
	 */
	public function test_get_payment_returns_array_for_valid_response(): void {
		$response_body = json_encode( array( 'id' => 'pay_1', 'status' => 'paid' ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api    = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$result = $api->get_payment( 'pay_1' );
		$this->assertIsArray( $result );
		$this->assertSame( 'pay_1', $result['id'] );
		$this->assertSame( 'paid', $result['status'] );
	}

	/**
	 * get_payment returns null when API returns invalid JSON.
	 */
	public function test_get_payment_returns_null_for_invalid_json(): void {
		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => 'not json' ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$this->assertNull( $api->get_payment( 'pay_1' ) );
	}

	/**
	 * get_payment returns null when response has errors.
	 */
	public function test_get_payment_returns_null_when_response_has_errors(): void {
		$response_body = json_encode( array( 'errors' => array( 'Not found' ) ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$this->assertNull( $api->get_payment( 'pay_1' ) );
	}

	/**
	 * create_payment sends POST and returns decoded response.
	 * Status 'created' per CHIP PurchaseStatus (purchase created via POST /purchases/).
	 */
	public function test_create_payment_returns_decoded_response(): void {
		$response_body = json_encode( array(
			'id'           => 'pay_new',
			'status'      => 'created',
			'checkout_url' => 'https://example.com/checkout',
		) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api    = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$params = array( 'purchase' => array( 'products' => array() ) );
		$result = $api->create_payment( $params );
		$this->assertIsArray( $result );
		$this->assertSame( 'pay_new', $result['id'] );
		$this->assertSame( 'created', $result['status'] );
		$this->assertSame( 'https://example.com/checkout', $result['checkout_url'] );
	}

	/**
	 * cancel_payment sends POST to cancel route and returns response.
	 * Status 'cancelled' per CHIP PurchaseStatus (POST /purchases/{id}/cancel/).
	 */
	public function test_cancel_payment_returns_response(): void {
		$response_body = json_encode( array( 'status' => 'cancelled' ) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api    = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$result = $api->cancel_payment( 'pay_123' );
		$this->assertIsArray( $result );
		$this->assertSame( 'cancelled', $result['status'] );
	}

	/**
	 * refund_payment sends POST with params and returns response.
	 * Status 'pending_refund' per CHIP PurchaseStatus (refund in processing).
	 */
	public function test_refund_payment_returns_response(): void {
		$response_body = json_encode( array(
			'refund_id' => 'ref_1',
			'status'    => 'pending_refund',
		) );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array( 'body' => $response_body ) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'gf_chip_sslverify', true )
			->andReturn( true );

		$api    = GF_CHIP_API::get_instance( 'test_secret', 'test_brand' );
		$params = array( 'amount' => 1000 );
		$result = $api->refund_payment( 'pay_123', $params );
		$this->assertIsArray( $result );
		$this->assertSame( 'ref_1', $result['refund_id'] );
		$this->assertSame( 'pending_refund', $result['status'] );
	}

	/**
	 * get_instance returns same instance for same (or different) args (singleton).
	 */
	public function test_get_instance_is_singleton(): void {
		WP_Mock::userFunction( 'wp_remote_request' )->andReturn( array( 'body' => 'null' ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( 'null' );
		WP_Mock::userFunction( 'apply_filters' )->andReturn( true );

		$a = GF_CHIP_API::get_instance( 'sk1', 'brand1' );
		$b = GF_CHIP_API::get_instance( 'sk2', 'brand2' );

		$this->assertSame( $a, $b );
	}
}
