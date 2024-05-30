<?php

/**
 * Plugin Name: CHIP for Gravity Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-woocommerce/
 * Description: CHIP - Better Payment & Business Solutions
 * Version: 1.0.7
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 * 
 * Copyright: © 2024 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Gravity Forms tested up to: 2.8
 */

defined( 'ABSPATH' ) || die();

define( 'GF_CHIP_MODULE_VERSION', 'v1.0.7');
define( 'GF_CHIP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

add_action( 'gform_loaded', array( 'GF_CHIP_Bootstrap', 'load_addon' ), 5 );

class GF_CHIP_Bootstrap {

  public static function load_addon() {

    require_once GF_CHIP_PLUGIN_PATH . '/api.php';
    require_once GF_CHIP_PLUGIN_PATH . '/class-gf-chip.php';

    GFAddOn::register( 'GF_Chip' );

    add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array('GF_CHIP_Bootstrap','gf_chip_setting_link'));
    
  }

  public static function gf_chip_setting_link($links) {
    $new_links = array(
      'settings' => sprintf(
        '<a href="%1$s">%2$s</a>', admin_url('admin.php?page=gf_settings&subview=gravityformschip'), esc_html__('Settings', 'gravityformschip')
      )
    );

    return array_merge($new_links, $links);
  }

}
