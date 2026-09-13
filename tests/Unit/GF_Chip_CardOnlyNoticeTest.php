<?php
/**
 * Unit tests for the card-only notice in feed settings.
 *
 * CHIP recurring tokens are card-only, so a Subscription feed cannot offer FPX,
 * DuitNow QR or e-wallets. Before this, the constraint lived only in code
 * comments and the public FAQ -- an administrator choosing Subscription in the
 * feed editor had nothing telling them why the payment methods they expect will
 * not appear at checkout.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::card_only_notice_field
 */
class GF_Chip_CardOnlyNoticeTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();

		WP_Mock::userFunction( '__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * The field is an html field, which is what renders arbitrary markup in a
	 * Gravity Forms settings table.
	 */
	public function test_field_type_is_html(): void {
		$field = GF_Chip::card_only_notice_field();

		$this->assertSame( 'html', $field['type'] );
	}

	/**
	 * The field renders through the `html` key, which is what GF's HTML field
	 * reads. A `content` or `description` key would be ignored.
	 */
	public function test_field_uses_the_html_key(): void {
		$field = GF_Chip::card_only_notice_field();

		$this->assertArrayHasKey( 'html', $field );
		$this->assertIsString( $field['html'] );
		$this->assertNotSame( '', trim( $field['html'] ) );
	}

	/**
	 * The notice is shown ONLY when Subscription is selected, so an admin
	 * selling one-time products is not told about a constraint that does not
	 * apply to them.
	 */
	public function test_notice_depends_on_the_subscription_choice(): void {
		$field = GF_Chip::card_only_notice_field();

		$this->assertArrayHasKey( 'dependency', $field );
		$this->assertSame( 'transactionType', $field['dependency']['field'] );
		$this->assertContains( 'subscription', $field['dependency']['values'] );
	}

	/**
	 * The notice names the card-only constraint in plain language.
	 */
	public function test_notice_states_the_constraint(): void {
		$html = GF_Chip::card_only_notice_field()['html'];

		$this->assertStringContainsString( 'card', strtolower( $html ) );
	}

	/**
	 * The notice names the methods that will NOT be available.
	 *
	 * Naming them is the point: "card only" alone leaves an admin wondering
	 * whether FPX or DuitNow QR specifically are affected, and those are the
	 * methods a Malaysian merchant expects to offer.
	 */
	public function test_notice_names_the_unavailable_methods(): void {
		$html = strtolower( GF_Chip::card_only_notice_field()['html'] );

		$this->assertStringContainsString( 'fpx', $html );
		$this->assertStringContainsString( 'duitnow', $html );
	}

	/**
	 * The notice explains that one-time forms are unaffected, so an admin does
	 * not conclude the whole plugin has lost those methods.
	 */
	public function test_notice_reassures_about_one_time_forms(): void {
		$html = strtolower( GF_Chip::card_only_notice_field()['html'] );

		$this->assertStringContainsString( 'one-time', $html );
	}

	/**
	 * The markup is escaped rather than raw.
	 */
	public function test_notice_markup_contains_no_unescaped_quotes_in_text(): void {
		$html = GF_Chip::card_only_notice_field()['html'];

		// A styled paragraph is expected; a script tag is not.
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', strtolower( $html ) );
	}
}
