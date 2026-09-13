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

		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-api.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-schedule.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-renewals.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-subscriptions-page.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update-flow.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-card-update-page.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip-renewal-notifications.php';
		require_once GF_CHIP_PLUGIN_PATH . 'includes/class-gf-chip.php';

		GFAddOn::register( 'GF_Chip' );

		add_filter( 'plugin_action_links_' . plugin_basename( GF_CHIP_PLUGIN_FILE ), array( 'GF_CHIP_Bootstrap', 'gf_chip_setting_link' ) );
	}

	/**
	 * Clears the scheduled renewal cron.
	 *
	 * Gravity Forms schedules "{slug}_cron" hourly for any add-on that
	 * overrides check_status(). That event outlives the plugin: deactivating
	 * leaves it in WP-Cron with no callback attached, so WordPress fires a dead
	 * action every hour indefinitely. Gravity Forms core clears its own cron on
	 * uninstall; this does the same job for ours.
	 *
	 * Safe to run on every deactivation. Gravity Forms' setup_cron() guards on
	 * wp_next_scheduled(), so the next time the plugin is active the event is
	 * scheduled again — clearing here does not disable renewals permanently.
	 *
	 * @return void
	 */
	public static function clear_scheduled_events() {
		wp_clear_scheduled_hook( GF_Chip_Renewals::cron_hook() );
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
