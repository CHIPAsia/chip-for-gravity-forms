<?php
/**
 * Bootstrap class for CHIP for Gravity Forms.
 *
 * @package GravityFormsCHIP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap class for CHIP for Gravity Forms.
 */
class GF_CHIP_Bootstrap {

	/**
	 * Loads the addon and registers it with Gravity Forms.
	 */
	public static function load_addon() {

		require_once GF_CHIP_PLUGIN_PATH . 'class-gf-chip-api.php';
		require_once GF_CHIP_PLUGIN_PATH . '/class-gf-chip.php';

		GFAddOn::register( 'GF_Chip' );

		add_filter( 'plugin_action_links_' . plugin_basename( GF_CHIP_PLUGIN_FILE ), array( 'GF_CHIP_Bootstrap', 'gf_chip_setting_link' ) );
	}

	/**
	 * Adds the Settings link to the plugin action links.
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public static function gf_chip_setting_link( $links ) {
		$new_links = array(
			'settings' => sprintf(
				'<a href="%1$s">%2$s</a>',
				admin_url( 'admin.php?page=gf_settings&subview=gravityformschip' ),
				esc_html__( 'Settings', 'chip-for-gravity-forms' )
			),
		);

		return array_merge( $new_links, $links );
	}
}
