<?php
/**
 * Unit tests for GF_CHIP_Bootstrap.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_CHIP_Bootstrap;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @covers \GF_CHIP_Bootstrap
 */
class GF_CHIP_BootstrapTest extends TestCase {

	/**
	 * gf_chip_setting_link adds a Settings link with correct URL and text.
	 */
	public function test_gf_chip_setting_link_adds_settings_link(): void {
		$settings_url = 'http://example.com/wp-admin/admin.php?page=gf_settings&subview=gravityformschip';

		WP_Mock::userFunction( 'admin_url' )
			->once()
			->with( 'admin.php?page=gf_settings&subview=gravityformschip' )
			->andReturn( $settings_url );

		WP_Mock::userFunction( 'esc_html__' )
			->once()
			->with( 'Settings', 'chip-for-gravity-forms' )
			->andReturn( 'Settings' );

		$links = array();
		$result = GF_CHIP_Bootstrap::gf_chip_setting_link( $links );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertStringContainsString( $settings_url, $result['settings'] );
		$this->assertStringContainsString( 'Settings', $result['settings'] );
		$this->assertStringStartsWith( '<a href="', $result['settings'] );
	}

	/**
	 * gf_chip_setting_link merges new link with existing plugin action links.
	 */
	public function test_gf_chip_setting_link_merges_with_existing_links(): void {
		WP_Mock::userFunction( 'admin_url' )
			->once()
			->andReturn( 'http://example.com/wp-admin/admin.php?page=gf_settings&subview=gravityformschip' );

		WP_Mock::userFunction( 'esc_html__' )
			->once()
			->with( 'Settings', 'chip-for-gravity-forms' )
			->andReturn( 'Settings' );

		$existing_links = array(
			'deactivate' => '<a href="#">Deactivate</a>',
		);
		$result = GF_CHIP_Bootstrap::gf_chip_setting_link( $existing_links );

		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'deactivate', $result );
		$this->assertSame( '<a href="#">Deactivate</a>', $result['deactivate'] );
		$this->assertCount( 2, $result );
	}

	/**
	 * gf_chip_setting_link places settings link first (array_merge order).
	 */
	public function test_gf_chip_setting_link_settings_appears_first(): void {
		WP_Mock::userFunction( 'admin_url' )
			->once()
			->andReturn( 'http://example.com/settings' );

		WP_Mock::userFunction( 'esc_html__' )
			->once()
			->andReturn( 'Settings' );

		$links = array( 'other' => 'Other' );
		$result = GF_CHIP_Bootstrap::gf_chip_setting_link( $links );

		$keys = array_keys( $result );
		$this->assertSame( 'settings', $keys[0] );
	}
}
