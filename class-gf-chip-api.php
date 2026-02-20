<?php
/**
 * CHIP API client.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

// CHIP API URL endpoint as per documented in: https://docs.chip-in.asia.
define( 'GF_CHIP_ROOT_URL', 'https://gate.chip-in.asia' );

/**
 * CHIP API client class.
 */
class GF_CHIP_API {

	/**
	 * Singleton instance.
	 *
	 * @var GF_CHIP_API
	 */
	private static $instance;

	/**
	 * Secret key for API auth.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Brand ID.
	 *
	 * @var string
	 */
	private $brand_id;

	/**
	 * Gets the singleton instance.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $brand_id   Brand ID.
	 * @return GF_CHIP_API
	 */
	public static function get_instance( $secret_key, $brand_id ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $secret_key, $brand_id );
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $brand_id   Brand ID.
	 */
	public function __construct( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * Creates a payment (purchase).
	 *
	 * @param array $params Purchase params.
	 * @return array|null
	 */
	public function create_payment( $params ) {
		// time() is to force fresh instead of cache.
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	/**
	 * Fetches payment methods for currency and language.
	 *
	 * @param string $currency Currency code.
	 * @param string $language Language code.
	 * @return array|null
	 */
	public function payment_methods( $currency, $language ) {
		return $this->call(
			'GET',
			"/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
		);
	}

	/**
	 * Gets a single payment (purchase).
	 *
	 * @param string $payment_id Purchase ID.
	 * @return array|null
	 */
	public function get_payment( $payment_id ) {
		// time() is to force fresh instead of cache.
		return $this->call( 'GET', "/purchases/{$payment_id}/?time=" . time() );
	}

	/**
	 * Cancels a payment.
	 *
	 * @param string $payment_id Purchase ID.
	 * @return array|null
	 */
	public function cancel_payment( $payment_id ) {
		return $this->call( 'POST', "/purchases/{$payment_id}/cancel/" );
	}

	/**
	 * Checks if a payment was successful.
	 *
	 * @param string $payment_id Purchase ID.
	 * @return bool
	 */
	public function was_payment_successful( $payment_id ) {
		$result = $this->get_payment( $payment_id );
		return $result && 'paid' === $result['status'];
	}

	/**
	 * Gets the public key (validates credentials).
	 *
	 * @return array|string|null
	 */
	public function get_public_key() {
		return $this->call( 'GET', '/public_key/' );
	}

	/**
	 * Refunds a payment.
	 *
	 * @param string $payment_id Purchase ID.
	 * @param array  $params     Refund params.
	 * @return array|null
	 */
	public function refund_payment( $payment_id, $params ) {
		return $this->call( 'POST', "/purchases/{$payment_id}/refund/", $params );
	}

	/**
	 * Makes an API call.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route   API route.
	 * @param array  $params  Request body (encoded to JSON).
	 * @return array|null
	 */
	private function call( $method, $route, $params = array() ) {
		$secret_key = $this->secret_key;
		if ( ! empty( $params ) ) {
			$params = wp_json_encode( $params );
		}

		$response = $this->request(
			$method,
			sprintf( '%s/api/v1%s', GF_CHIP_ROOT_URL, $route ),
			$params,
			array(
				'Content-type'  => 'application/json',
				'Authorization' => 'Bearer ' . $secret_key,
			)
		);

		$result = json_decode( $response, true );
		if ( ! $result ) {
			return null;
		}

		if ( ! empty( $result['errors'] ) ) {
			return null;
		}

		return $result;
	}

	/**
	 * Sends an HTTP request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $params  Body.
	 * @param array  $headers Headers.
	 * @return string Response body.
	 */
	private function request( $method, $url, $params = array(), $headers = array() ) {
		$wp_request = wp_remote_request(
			$url,
			array(
				'method'    => $method,
				'sslverify' => apply_filters( 'gf_chip_sslverify', true ),
				'headers'   => $headers,
				'body'      => $params,
			)
		);

		$response = wp_remote_retrieve_body( $wp_request );

		return $response;
	}
}
