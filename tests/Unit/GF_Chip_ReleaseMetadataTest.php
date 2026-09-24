<?php
/**
 * Release-metadata consistency tests.
 *
 * A plugin's declared compatibility lives in several files that nothing makes
 * agree. WordPress reads the plugin header's `Requires at least` / `Requires
 * PHP` to decide whether a site can install or update at all; WordPress.org
 * reads `readme.txt` — which is the copy a merchant actually sees on the
 * plugin page — and the GitHub visitor reads `README.md`. phpcs.xml decides
 * which APIs the sniffs are allowed to demand.
 *
 * Drift is invisible: every file is plausible when read alone, and the value
 * that reaches a user is usually not the one the installer checks. These
 * tests are consistency assertions, not behaviour tests, because there is no
 * runtime behaviour to observe — the failure mode is two files disagreeing.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the declared compatibility of the plugin.
 */
class GF_Chip_ReleaseMetadataTest extends TestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function root() {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Read a file in the plugin, failing loudly if it is missing.
	 *
	 * @param string $relative Path relative to the plugin root.
	 *
	 * @return string
	 */
	private function read( $relative ) {
		$path = $this->root() . '/' . $relative;

		$this->assertFileExists( $path, "{$relative} must exist to be checked" );

		return (string) file_get_contents( $path );
	}

	/**
	 * The WordPress floor from the plugin header.
	 *
	 * @return string
	 */
	private function header_requires_wp() {
		$php = $this->read( 'chip-for-gravity-forms.php' );

		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Requires at least:\s*([0-9.]+)\s*$/m',
			$php,
			'The plugin header must declare "Requires at least", because that is the value '
				. 'WordPress reads on install and update. readme.txt is a separate copy.'
		);

		preg_match( '/^\s*\*\s*Requires at least:\s*([0-9.]+)\s*$/m', $php, $m );

		return $m[1];
	}

	/**
	 * The WordPress floor from readme.txt's header field.
	 *
	 * @return string
	 */
	private function readme_requires_wp() {
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^Requires at least:\s*([0-9.]+)\s*$/m', $readme, $m );

		return isset( $m[1] ) ? $m[1] : '';
	}

	/**
	 * The PHP floor from the plugin header.
	 *
	 * @return string
	 */
	private function header_requires_php() {
		$php = $this->read( 'chip-for-gravity-forms.php' );

		preg_match( '/^\s*\*\s*Requires PHP:\s*([0-9.]+)\s*$/m', $php, $m );

		return isset( $m[1] ) ? $m[1] : '';
	}

	// ---------------------------------------------------------------------
	// The floor is declared in four places, and they must agree.
	// ---------------------------------------------------------------------

	/**
	 * The plugin header must carry the WordPress and PHP floors.
	 *
	 * Without these, WordPress cannot refuse an install on an unsupported
	 * site, and the plugin page shows no requirement at all.
	 */
	public function test_plugin_header_declares_the_floors(): void {
		$this->assertSame( '6.3', $this->header_requires_wp() );
		$this->assertSame( '7.4', $this->header_requires_php() );
	}

	/**
	 * The plugin header and readme.txt must declare the same WordPress floor.
	 *
	 * These are two independent copies; WordPress enforces the header and
	 * WordPress.org displays the readme.
	 */
	public function test_the_wordpress_floor_agrees_across_the_header_and_readme(): void {
		$header = $this->header_requires_wp();
		$readme = $this->readme_requires_wp();

		$this->assertNotSame( '', $readme, 'readme.txt must declare a WordPress floor' );
		$this->assertSame(
			$header,
			$readme,
			"The plugin header declares WordPress {$header} but readme.txt declares {$readme}"
		);
	}

	/**
	 * The readme's Minimum Requirements bullet must match its header field.
	 *
	 * The header field is parsed; the bullet is what the merchant reads.
	 */
	public function test_the_readme_requirements_bullet_matches_the_readme_field(): void {
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^Requires at least:\s*([0-9.]+)\s*$/m', $readme, $m );
		$field = isset( $m[1] ) ? $m[1] : '';

		$this->assertMatchesRegularExpression(
			'/^\*\s*WordPress\s+' . preg_quote( $field, '/' ) . '\s+or greater\s*$/m',
			$readme,
			"The Minimum Requirements bullet must name WordPress {$field}, the same as the header field above it"
		);
	}

	/**
	 * phpcs.xml must target the same WordPress floor.
	 *
	 * The sniffs use this to decide which APIs are permitted; a lower value
	 * silently allows calls the declared floor cannot run.
	 */
	public function test_phpcs_targets_the_same_wordpress_floor(): void {
		$phpcs = $this->read( 'phpcs.xml' );

		preg_match( '/minimum_supported_wp_version"\s+value="([0-9.]+)"/', $phpcs, $m );

		$this->assertSame(
			$this->header_requires_wp(),
			isset( $m[1] ) ? $m[1] : '',
			'phpcs.xml minimum_supported_wp_version must match the declared WordPress floor'
		);
	}

	/**
	 * README.md must state the same floor a GitHub visitor is told.
	 */
	public function test_readme_md_states_the_same_floor(): void {
		$md = $this->read( 'README.md' );

		$this->assertMatchesRegularExpression(
			'/^-\s*WordPress\s+' . preg_quote( $this->header_requires_wp(), '/' ) . '\s+or greater\s*$/m',
			$md,
			'README.md must name the same WordPress floor as the plugin header'
		);
	}

	/**
	 * README.md must state the same PHP floor.
	 */
	public function test_readme_md_states_the_same_php_floor(): void {
		$md = $this->read( 'README.md' );

		$this->assertMatchesRegularExpression(
			'/^-\s*PHP\s+' . preg_quote( $this->header_requires_php(), '/' ) . '\s+or greater/m',
			$md,
			'README.md must name the same PHP floor as the plugin header'
		);
	}

	// ---------------------------------------------------------------------
	// Tested up to, and the version a release will ship.
	// ---------------------------------------------------------------------

	/**
	 * `Tested up to` must be MAJOR.MINOR.
	 *
	 * The plugin-check validator rejects a patch component, and rejects any
	 * value above WordPress's current stable release.
	 */
	public function test_tested_up_to_is_a_major_minor_version(): void {
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^Tested up to:\s*(\S+)\s*$/m', $readme, $m );
		$value = isset( $m[1] ) ? $m[1] : '';

		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+$/',
			$value,
			"Tested up to must be MAJOR.MINOR (plugin-check rejects a patch component); got '{$value}'"
		);
	}

	/**
	 * The version declaration and the version constant must agree.
	 *
	 * A tag pushed while these disagree ships a plugin that reports one
	 * version and claims another.
	 */
	public function test_the_header_version_matches_the_version_constant(): void {
		$php = $this->read( 'chip-for-gravity-forms.php' );

		preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $php, $header );
		preg_match( "/GF_CHIP_MODULE_VERSION',\s*'v(\S+)'\s*\)/", $php, $constant );

		$header_version   = isset( $header[1] ) ? $header[1] : '';
		$constant_version = isset( $constant[1] ) ? $constant[1] : '';

		$this->assertNotSame( '', $header_version, 'The plugin header must declare a Version' );
		$this->assertSame(
			$header_version,
			$constant_version,
			"The header declares {$header_version} but GF_CHIP_MODULE_VERSION is v{$constant_version}"
		);
	}

	/**
	 * Stable tag and the plugin header Version must agree.
	 *
	 * WordPress.org serves whichever `Stable tag` names, so a mismatch
	 * publishes a version nobody tagged.
	 */
	public function test_stable_tag_matches_the_header_version(): void {
		$php    = $this->read( 'chip-for-gravity-forms.php' );
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $php, $h );
		preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $s );

		$this->assertSame(
			isset( $h[1] ) ? $h[1] : '',
			isset( $s[1] ) ? $s[1] : '',
			'Stable tag must match the plugin header Version, or WordPress.org serves the wrong build'
		);
	}

	// ---------------------------------------------------------------------
	// The readme's changelog and screenshots must be truthful.
	// ---------------------------------------------------------------------

	/**
	 * readme.txt must carry exactly one changelog entry.
	 *
	 * WordPress.org renders the changelog from readme.txt; the full history
	 * belongs in changelog.txt. Two entries here means the readme has become
	 * a second history rather than the current release.
	 */
	public function test_readme_carries_exactly_one_changelog_entry(): void {
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^== Changelog ==$(.*?)^\[See changelog/mis', $readme, $m );
		$section = isset( $m[1] ) ? $m[1] : '';

		$this->assertNotSame( '', $section, 'readme.txt must have a Changelog section' );

		$count = preg_match_all( '/^=\s*[0-9]+\.[0-9]+\.[0-9]+.*=$/m', $section );

		$this->assertSame(
			1,
			$count,
			"readme.txt carries only the current release; found {$count} changelog entries"
		);
	}

	/**
	 * That one entry must be the version about to ship.
	 */
	public function test_the_readme_changelog_entry_is_the_current_version(): void {
		$php    = $this->read( 'chip-for-gravity-forms.php' );
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $php, $h );
		$version = isset( $h[1] ) ? $h[1] : '';

		$this->assertMatchesRegularExpression(
			'/^=\s*' . preg_quote( $version, '/' ) . '\s+[0-9]{4}-[0-9]{2}-[0-9]{2}\s*=$/m',
			$readme,
			"readme.txt must carry a changelog entry for the shipping version {$version}"
		);
	}

	/**
	 * Every screenshot the readme names must exist as a file.
	 *
	 * readme.txt declared a sixth screenshot whose PNG was never committed.
	 * WordPress.org links the entry to
	 * /assets/screenshot-6.png, which 404s — a broken image on the plugin
	 * page, on every install, that nothing else would catch.
	 */
	public function test_every_declared_screenshot_has_a_file(): void {
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^== Screenshots ==$(.*?)^== /mis', $readme, $m );
		$section = isset( $m[1] ) ? $m[1] : '';

		$this->assertNotSame( '', $section, 'readme.txt must have a Screenshots section' );

		$declared = array();
		foreach ( explode( "\n", $section ) as $line ) {
			if ( preg_match( '/^\s*([0-9]+)\.\s+\S/', $line, $entry ) ) {
				$declared[] = (int) $entry[1];
			}
		}

		$this->assertNotEmpty( $declared, 'The Screenshots section must list at least one entry' );

		foreach ( $declared as $number ) {
			$file = $this->root() . '/.wordpress-org/screenshot-' . $number . '.png';

			$this->assertFileExists(
				$file,
				"readme.txt declares screenshot {$number}, but .wordpress-org/screenshot-{$number}.png "
					. 'does not exist — WordPress.org will serve a 404 for it'
			);
		}
	}

	/**
	 * The screenshot numbers must be contiguous from 1.
	 *
	 * WordPress.org serves the assets directory by name; a gap means a
	 * numbered image exists that no entry describes.
	 */
	public function test_declared_screenshots_are_contiguous(): void {
		$readme = $this->read( 'readme.txt' );

		preg_match( '/^== Screenshots ==$(.*?)^== /mis', $readme, $m );
		$section = isset( $m[1] ) ? $m[1] : '';

		$declared = array();
		foreach ( explode( "\n", $section ) as $line ) {
			if ( preg_match( '/^\s*([0-9]+)\.\s+\S/', $line, $entry ) ) {
				$declared[] = (int) $entry[1];
			}
		}

		sort( $declared );

		$this->assertSame(
			range( 1, count( $declared ) ),
			$declared,
			'Screenshot numbers must run 1..N with no gaps: ' . implode( ',', $declared )
		);
	}
}
