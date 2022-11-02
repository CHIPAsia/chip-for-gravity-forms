<?php

/**
 * Plugin Name: CHIP for Gravity Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-woocommerce/
 * Description: Cash, Card and Coin Handling Integrated Platform
 * Version: 1.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 * 
 * Copyright: © 2022 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

defined( 'ABSPATH' ) || die();

define( 'GF_CHIP_MODULE_VERSION', 'v1.0.0');
define( 'GF_CHIP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

add_action( 'gform_loaded', array( 'GF_CHIP_Bootstrap', 'load_addon' ), 5 );

class GF_CHIP_Bootstrap {

	public static function load_addon() {

    require_once GF_CHIP_PLUGIN_PATH . '/api.php';
		require_once GF_CHIP_PLUGIN_PATH . '/class-gf-chip.php';

		GFAddOn::register( 'GF_Chip' );
		
	}

}