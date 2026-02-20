<?php
/**
 * Plugin Name: CHIP for Gravity Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-gravity-forms/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.2.0
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 *
 * Copyright: © 2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Gravity Forms tested up to: 2.9.27
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || die();

define( 'GF_CHIP_MODULE_VERSION', 'v1.2.0' );
define( 'GF_CHIP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_CHIP_PLUGIN_FILE', __FILE__ );

require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip-bootstrap.php';

add_action( 'gform_loaded', array( 'GF_CHIP_Bootstrap', 'load_addon' ), 5 );
