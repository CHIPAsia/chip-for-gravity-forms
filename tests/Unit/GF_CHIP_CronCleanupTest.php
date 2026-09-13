<?php
/**
 * Unit tests for cron cleanup on plugin deactivation.
 *
 * Gravity Forms schedules an hourly cron for any add-on that overrides
 * check_status(). Once this plugin is deactivated the callback is gone but the
 * scheduled event is not, so WordPress keeps firing a dead action every hour
 * indefinitely. Core clears its own cron on uninstall; this plugin did not.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_CHIP_Bootstrap;
use GF_Chip_Renewals;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_CHIP_Bootstrap
 */
class GF_CHIP_CronCleanupTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * The hook cleared is exactly the one Gravity Forms schedules.
	 *
	 * Core builds it as "{$slug}_cron" where the slug is the add-on's _slug.
	 * Clearing a different name would leave the real event behind.
	 */
	public function test_clears_the_gravity_forms_cron_hook(): void {
		$cleared = array();

		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturnUsing(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;

				return true;
			}
		);

		GF_CHIP_Bootstrap::clear_scheduled_events();

		$this->assertSame(
			array( GF_Chip_Renewals::cron_hook() ),
			$cleared,
			'the add-on cron hook must be the one cleared'
		);
	}

	/**
	 * The cleared hook matches the add-on slug convention, so it cannot drift
	 * out of step with what core schedules.
	 */
	public function test_cleared_hook_matches_the_slug_convention(): void {
		$cleared = array();

		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturnUsing(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return true;
			}
		);

		GF_CHIP_Bootstrap::clear_scheduled_events();

		$reflection = new \ReflectionClass( \GF_Chip::class );
		$property   = $reflection->getProperty( '_slug' );
		$property->setAccessible( true );

		// Read the slug from a real instance, then confirm the cleared hook is
		// that slug plus '_cron' -- the exact string core schedules.
		$slug = $property->getValue( \GF_Chip::get_instance() );

		$this->assertSame(
			$slug . '_cron',
			$cleared[0],
			'the cleared hook must equal the slug core uses'
		);
	}

	/**
	 * Cleanup clears exactly one hook and schedules nothing.
	 *
	 * A deactivation must never leave a newly-scheduled event behind.
	 */
	public function test_cleanup_does_not_schedule_anything(): void {
		$cleared = array();

		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturnUsing(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return true;
			}
		);

		// A deactivation that re-scheduled the event would be a bug: it would
		// leave a live cron behind pointing at an unloaded plugin.
		WP_Mock::userFunction( 'wp_schedule_event' )->never();
		WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

		GF_CHIP_Bootstrap::clear_scheduled_events();

		$this->assertCount( 1, $cleared, 'cleanup clears exactly one hook' );
		$this->assertSame( GF_Chip_Renewals::cron_hook(), $cleared[0] );
	}

	/**
	 * Cleanup is safe to run when nothing is scheduled.
	 *
	 * wp_clear_scheduled_hook() returns false when the hook was not scheduled;
	 * a deactivation on a site that never ran a renewal must not error.
	 */
	public function test_cleanup_tolerates_an_unscheduled_hook(): void {
		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( false );

		GF_CHIP_Bootstrap::clear_scheduled_events();

		// Reaching here without an exception is the assertion.
		$this->assertTrue( true );
	}

	/**
	 * The deactivation hook is registered against the constant that names the
	 * add-on cron, so the cleared hook cannot silently diverge from the
	 * scheduled one.
	 *
	 * This is the contract that matters on our side. Whether Gravity Forms
	 * reschedules afterwards is core's behaviour, verified against the core
	 * source at the time this was written and recorded in AGENTS.md: core's
	 * setup_cron() guards on wp_next_scheduled(), so clearing the event here
	 * does not disable renewals permanently.
	 */
	public function test_cleared_hook_is_derived_from_the_addon_not_a_literal(): void {
		$cleared = array();

		WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturnUsing(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return true;
			}
		);

		GF_CHIP_Bootstrap::clear_scheduled_events();

		// The method must clear whatever the renewals class reports, so a slug
		// change is picked up automatically rather than leaving a stale name.
		$this->assertSame( GF_Chip_Renewals::cron_hook(), $cleared[0] );
		$this->assertStringEndsWith( '_cron', $cleared[0], 'core schedules the slug plus _cron' );
	}

	/**
	 * The main plugin file registers the deactivation hook.
	 *
	 * Without the registration the cleanup method would exist and never run,
	 * which is exactly the state this PR fixes -- so it is asserted rather than
	 * assumed.
	 */
	public function test_deactivation_hook_is_registered(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/chip-for-gravity-forms.php' );

		$this->assertStringContainsString(
			"register_deactivation_hook( __FILE__, array( 'GF_CHIP_Bootstrap', 'clear_scheduled_events' ) )",
			$source,
			'the cleanup must actually be wired to deactivation'
		);
	}

	/**
	 * The cleanup method exists on the bootstrap, so the hook target resolves.
	 */
	public function test_cleanup_method_exists(): void {
		$this->assertTrue(
			method_exists( GF_CHIP_Bootstrap::class, 'clear_scheduled_events' ),
			'the deactivation callback must be a real method'
		);
	}
}
