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
