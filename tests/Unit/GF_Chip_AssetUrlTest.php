<?php
/**
 * Regression tests for the URLs of the plugin's own asset files.
 *
 * GFAddOn::plugins_url() — and core's get_base_url(), which wraps it —
 * resolve a relative path against the directory of the file they are given.
 * `includes/class-gf-chip.php` is in `includes/`, so the old
 * `plugins_url( 'assets/logo.svg', __FILE__ )` form resolved to
 * `.../chip-for-gravity-forms/includes/assets/logo.svg`.
 *
 * That directory does not exist. The plugin's assets ship at its root. The
 * icon therefore 404'd and the settings screenshot link was dead — on every
 * install, release zip included, not just on the server where it was first
 * noticed.
 *
 * The fix anchors both URLs on get_base_url(), the same call the feed
 * settings script already used. The tests below assert on the paths that
 * result, and that the files they point at exist.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::get_menu_icon
 *
 * @covers \GF_Chip::get_description
 */
class GF_Chip_AssetUrlTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();

		// Reproduce WordPress's own semantics for plugins_url(): a relative
		// path is resolved against the DIRECTORY OF THE FILE passed in.
		// Without this the tests could only observe that plugins_url() was
		// called at all; with it, re-introducing the old form produces the
		// same wrong URL the live site served.
		WP_Mock::userFunction( 'plugins_url' )->andReturnUsing(
			function ( $path = '', $plugin = '' ) {
				$dir = $plugin ? basename( dirname( $plugin ) ) : '';

				return 'https://example.com/wp-content/plugins/' . $dir . '/' . ltrim( $path, '/' );
			}
		);
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * The plugin's base URL, as core's get_base_url() reports it.
	 *
	 * @return string
	 */
	private function base_url() {
		return 'https://example.com/wp-content/plugins/chip-for-gravity-forms';
	}

	// ---------------------------------------------------------------------
	// The menu icon.
	// ---------------------------------------------------------------------

	/**
	 * The icon must point at the plugin root, not at includes/.
	 *
	 * This is the assertion that fails on the old code: the returned URL
	 * contained '/includes/assets/logo.svg'.
	 */
	public function test_menu_icon_points_at_the_plugin_root(): void {
		$icon = GF_Chip::get_instance()->get_menu_icon();

		$this->assertSame( $this->base_url() . '/assets/logo.svg', $icon );
	}

	/**
	 * The icon URL must not be anchored inside includes/.
	 */
	public function test_menu_icon_is_not_anchored_inside_includes(): void {
		$icon = GF_Chip::get_instance()->get_menu_icon();

		$this->assertStringNotContainsString(
			'/includes/',
			$icon,
			'assets/ lives at the plugin root; a path under includes/ 404s'
		);
	}

	/**
	 * The file the icon URL names must actually exist in the plugin.
	 *
	 * A URL that is merely well-formed is not enough — the old one was
	 * well-formed too, and pointed at nothing.
	 */
	public function test_the_icon_file_exists(): void {
		$this->assertFileExists(
			GF_CHIP_PLUGIN_PATH . 'assets/logo.svg',
			'The URL returned by get_menu_icon() must name a real file'
		);
	}

	// ---------------------------------------------------------------------
	// The settings screenshot.
	// ---------------------------------------------------------------------

	/**
	 * The screenshot link must point at the plugin root.
	 */
	public function test_description_links_the_screenshot_at_the_plugin_root(): void {
		$description = GF_Chip::get_instance()->get_description();

		$this->assertStringContainsString(
			$this->base_url() . '/assets/form-settings.png',
			$description
		);
	}

	/**
	 * The screenshot URL must not be anchored inside includes/.
	 */
	public function test_description_does_not_link_a_path_inside_includes(): void {
		$description = GF_Chip::get_instance()->get_description();

		$this->assertStringNotContainsString( '/includes/assets/', $description );
	}

	/**
	 * The file the screenshot link names must exist.
	 */
	public function test_the_screenshot_file_exists(): void {
		$this->assertFileExists( GF_CHIP_PLUGIN_PATH . 'assets/form-settings.png' );
	}

	// ---------------------------------------------------------------------
	// The whole class of bug: no asset URL anchored on a file in a subdir.
	// ---------------------------------------------------------------------

	/**
	 * No asset URL the class builds may resolve beneath includes/.
	 *
	 * get_menu_icon() and get_description() were two instances of one
	 * mistake, so this asserts the class as a whole rather than either
	 * method: a third asset URL added the same way would fail here.
	 */
	public function test_no_asset_url_resolves_beneath_includes(): void {
		$addon = GF_Chip::get_instance();

		$urls = array(
			'menu icon'  => $addon->get_menu_icon(),
			'description screenshot' => $addon->get_description(),
		);

		foreach ( $urls as $label => $value ) {
			$this->assertStringNotContainsString(
				'/includes/assets/',
				$value,
				"The {$label} URL resolves beneath includes/, where no assets exist"
			);
		}
	}
}
