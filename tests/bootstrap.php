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

// Load plugin classes under test (API does not depend on Gravity Forms).
require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip-api.php';
