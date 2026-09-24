<?php
/**
 * Plugin Name: CHIP for Gravity Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-gravity-forms/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.3.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 *
 * Copyright: © 2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires at least: 6.3
 * Requires PHP: 7.4
 *
 * Gravity Forms tested up to: 3.1
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || die();

define( 'GF_CHIP_MODULE_VERSION', 'v1.3.0' );
define( 'GF_CHIP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_CHIP_PLUGIN_FILE', __FILE__ );

require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-bootstrap.php';

add_action( 'gform_loaded', array( 'GF_CHIP_Bootstrap', 'load_addon' ), 5 );

// Gravity Forms schedules an hourly renewal cron for this add-on. Clearing it
// on deactivation stops WordPress firing a dead action forever; the event is
// rescheduled automatically the next time the plugin is active.
register_deactivation_hook( __FILE__, array( 'GF_CHIP_Bootstrap', 'clear_scheduled_events' ) );
