<?php

/*
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

define("GF_CHIP_ROOT_URL", "https://gate.chip-in.asia");

class GFChipAPI
{
    private static $_instance;
    public static function get_instance($private_key, $brand_id) {

		if ( self::$_instance == null ) {
			self::$_instance = new GFChipAPI($private_key, $brand_id);
		}

		return self::$_instance;

	}

    public function __construct($private_key, $brand_id)
    {
        $this->private_key = $private_key;
        $this->brand_id = $brand_id;
    }

    public function create_payment($params)
    {
        return $this->call('POST', '/purchases/', $params);
    }

    public function payment_methods($currency, $language)
    {
        return $this->call(
            'GET',
            "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
        );
    }

    public function get_payment($payment_id)
    {
      $result = $this->call('GET', "/purchases/{$payment_id}/");
      return $result;
    }

    public function was_payment_successful($payment_id)
    {
      $result = $this->get_payment($payment_id);
      return $result && $result['status'] == 'paid';
    }

    public function get_public_key()
    {
        return $this->call('GET', '/public_key/');
    }

    public function refund_payment($payment_id, $params)
    {
        $result = $this->call('POST', "/purchases/{$payment_id}/refund/", $params);

        return $result;
    }

    private function call($method, $route, $params = [])
    {
        $private_key = $this->private_key;
        if (!empty($params)) {
            $params = json_encode($params);
        }

        $response = $this->request(
            $method,
            sprintf("%s/api/v1%s", GF_CHIP_ROOT_URL, $route),
            $params,
            [
                'Content-type' => 'application/json',
                'Authorization' => 'Bearer ' . $private_key,
            ]
        );
        $result = json_decode($response, true);
        if (!$result) {
            return null;
        }

        if (!empty($result['errors'])) {
            return null;
        }

        return $result;
    }

    private function request($method, $url, $params = [], $headers = [])
    {
        $wp_request = wp_remote_request( $url, array(
            'method' => $method,
            'sslverify' => apply_filters( 'gf_chip_sslverify', true),
            'headers' => $headers,
            'body' => $params,
        ));

        $response = wp_remote_retrieve_body($wp_request);

        switch ($code = wp_remote_retrieve_response_code($wp_request)) {
            case 200:
            case 201:
                break;
            default:
        }

        return $response;
    }
}
