<?php
/**
 * PHPUnit bootstrap for CHIP for Gravity Forms.
 *
 * @package GravityFormsCHIP
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../vendor/wordpress/wordpress/' );
}

if ( ! defined( 'GF_CHIP_PLUGIN_PATH' ) ) {
	define( 'GF_CHIP_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'GF_CHIP_MODULE_VERSION' ) ) {
	define( 'GF_CHIP_MODULE_VERSION', 'v1.2.0' );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	echo "Run composer install to install test dependencies.\n";
	exit( 1 );
}
require_once $autoload;

\WP_Mock::bootstrap();

// Stub WordPress functions used by the API but not provided by WP_Mock.
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data   Data to encode.
	 * @param int   $options Optional.
	 * @param int   $depth   Optional.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// Load plugin classes under test (API does not depend on Gravity Forms).
require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip-api.php';
