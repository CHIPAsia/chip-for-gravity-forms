<?php
/**
 * Unit tests for GF_Chip feed settings — transaction type choices.
 *
 * @package GravityFormsCHIP
 */

namespace GravityFormsCHIP\Tests\Unit;

use GF_Chip;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GF_Chip::feed_settings_fields
 *
 * @covers \GF_Chip::get_transaction_type_choices
 */
class GF_Chip_FeedSettingsTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * Stub the WordPress functions feed_settings_fields() touches.
	 */
	private function stub_wp_functions() {
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function ( $text ) {
			return $text;
		} );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( function ( $data ) {
			return json_encode( $data );
		} );
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
	}

	/**
	 * Build the feed settings field list from a fresh addon instance.
	 *
	 * @return array
	 */
	private function fields() {
		$this->stub_wp_functions();
		$chip = new GF_Chip();
		return $chip->feed_settings_fields();
	}

	/**
	 * Find the transactionType field in the returned sections.
	 *
	 * @param array $sections Sections from feed_settings_fields().
	 * @return array|null
	 */
	private function transaction_type_field( $sections ) {
		foreach ( $sections as $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			foreach ( $section['fields'] as $field ) {
				if ( isset( $field['name'] ) && 'transactionType' === $field['name'] ) {
					return $field;
				}
			}
		}
		return null;
	}

	/**
	 * Collect the transaction type choice values.
	 *
	 * @param array $field The transactionType field.
	 * @return array
	 */
	private function choice_values( $field ) {
		$values = array();
		foreach ( $field['choices'] as $choice ) {
			$values[] = $choice['value'];
		}
		return $values;
	}

	// ---------------------------------------------------------------------
	// The Subscription choice must be offered.
	// ---------------------------------------------------------------------

	/**
	 * The Subscription choice is present — the whole point of this change.
	 */
	public function test_subscription_choice_is_present(): void {
		$field = $this->transaction_type_field( $this->fields() );

		$this->assertNotNull( $field, 'transactionType field not found in feed settings' );
		$this->assertContains( 'subscription', $this->choice_values( $field ) );
	}

	/**
	 * The Product choice is still present — restoring Subscription must not
	 * have removed the existing option.
	 */
	public function test_product_choice_is_still_present(): void {
		$field = $this->transaction_type_field( $this->fields() );

		$this->assertContains( 'product', $this->choice_values( $field ) );
	}

	/**
	 * Only the expected choices are offered — no leftover placeholder.
	 */
	public function test_choices_are_exactly_the_expected_set(): void {
		$field = $this->transaction_type_field( $this->fields() );

		$this->assertSame(
			array( '', 'product', 'subscription' ),
			$this->choice_values( $field )
		);
	}

	/**
	 * The transaction type field stays mandatory.
	 */
	public function test_transaction_type_remains_required(): void {
		$field = $this->transaction_type_field( $this->fields() );

		$this->assertTrue( $field['required'] );
	}

	// ---------------------------------------------------------------------
	// The Subscription Settings section must remain gated on subscription.
	// ---------------------------------------------------------------------

	/**
	 * Core's Subscription Settings section still depends on the subscription
	 * transaction type, so its fields only show for a subscription feed.
	 */
	public function test_subscription_settings_section_still_exists_and_is_gated(): void {
		$sections = $this->fields();

		$found = null;
		foreach ( $sections as $section ) {
			if ( isset( $section['title'] ) && 'Subscription Settings' === $section['title'] ) {
				$found = $section;
				break;
			}
		}

		$this->assertNotNull( $found, 'Subscription Settings section is missing' );
		$this->assertSame( 'transactionType', $found['dependency']['field'] );
		$this->assertSame( array( 'subscription' ), $found['dependency']['values'] );
	}

	// ---------------------------------------------------------------------
	// Reordering safety: the implementation must find the choice by value,
	// not by a fixed array index.
	// ---------------------------------------------------------------------

	/**
	 * When Subscription sits at a position other than index 2, withholding it
	 * must still remove the Subscription choice — not whatever happens to be
	 * at index 2.
	 *
	 * This is the regression guard for the previous implementation's
	 * `unset( choices[2] )` assumption: with Subscription moved to index 1,
	 * an index-based removal would drop "Products and Services" instead and
	 * leave Subscription selectable.
	 */
	public function test_withholding_removes_subscription_not_whatever_is_at_index_two(): void {
		$field = array(
			'choices' => array(
				array(
					'label' => 'Select a transaction type',
					'value' => '',
				),
				array(
					'label' => 'Subscription',
					'value' => 'subscription',
				),
				array(
					'label' => 'Products and Services',
					'value' => 'product',
				),
			),
		);

		$result  = GF_Chip::get_transaction_type_choices( $field, true );
		$values  = $this->choice_values( array( 'choices' => $result ) );

		$this->assertNotContains( 'subscription', $values, 'Subscription must be the choice removed' );
		$this->assertContains( 'product', $values, 'Products and Services must survive' );
		$this->assertContains( '', $values, 'The placeholder must survive' );
		$this->assertSame( array( '', 'product' ), $values );
	}

	/**
	 * Subscription is retained when it is not being withheld, wherever it sits.
	 */
	public function test_subscription_retained_wherever_it_sits(): void {
		$field = array(
			'choices' => array(
				array(
					'label' => 'Subscription',
					'value' => 'subscription',
				),
				array(
					'label' => 'Select a transaction type',
					'value' => '',
				),
				array(
					'label' => 'Products and Services',
					'value' => 'product',
				),
			),
		);

		$result = GF_Chip::get_transaction_type_choices( $field, false );

		$this->assertContains(
			'subscription',
			$this->choice_values( array( 'choices' => $result ) ),
			'Subscription must be retained regardless of its position'
		);
	}

	/**
	 * The capability gate is actually wired: when the brand cannot take cards
	 * the Subscription choice must be absent from the real feed settings.
	 *
	 * Exercises feed_settings_fields() rather than the helper, so a wiring
	 * mistake (gate ignored, hardcoded false) is caught.
	 */
	public function test_subscription_absent_from_feed_when_brand_cannot_take_cards(): void {
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function ( $text ) {
			return $text;
		} );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( function ( $data ) {
			return json_encode( $data );
		} );
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );

		// Filters use onFilter(), not userFunction() — apply_filters() is
		// implemented by WP_Mock as onFilter( $tag )->apply( $args ).
		WP_Mock::onFilter( 'gf_chip_brand_supports_cards' )
			->with( true )
			->reply( false );

		$chip  = new GF_Chip();
		$field = $this->transaction_type_field( $chip->feed_settings_fields() );

		$this->assertNotNull( $field );
		$this->assertNotContains(
			'subscription',
			$this->choice_values( $field ),
			'Subscription must be withheld when the brand cannot take cards'
		);
		$this->assertContains( 'product', $this->choice_values( $field ) );
	}

	/**
	 * When cards are supported (the default) Subscription is offered.
	 *
	 * The counterpart to the gate test — without this, an implementation that
	 * always withheld Subscription would pass the test above.
	 */
	public function test_subscription_present_in_feed_when_brand_supports_cards(): void {
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( function ( $text, $domain = null ) {
			return $text;
		} );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function ( $text ) {
			return $text;
		} );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( function ( $data ) {
			return json_encode( $data );
		} );
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );

		WP_Mock::onFilter( 'gf_chip_brand_supports_cards' )
			->with( true )
			->reply( true );

		$chip  = new GF_Chip();
		$field = $this->transaction_type_field( $chip->feed_settings_fields() );

		$this->assertNotNull( $field );
		$this->assertContains( 'subscription', $this->choice_values( $field ) );
	}

	/**
	 * When the brand cannot take cards, Subscription is withheld and the
	 * remaining choices are untouched.
	 */
	public function test_subscription_withheld_when_cards_unsupported(): void {
		$field = array(
			'choices' => array(
				array(
					'label' => 'Select a transaction type',
					'value' => '',
				),
				array(
					'label' => 'Products and Services',
					'value' => 'product',
				),
				array(
					'label' => 'Subscription',
					'value' => 'subscription',
				),
			),
		);

		$result = GF_Chip::get_transaction_type_choices( $field, true );

		$this->assertSame(
			array( '', 'product' ),
			$this->choice_values( array( 'choices' => $result ) )
		);
	}

	/**
	 * Withholding Subscription must not reindex the remaining choices — the
	 * select renders by value, but a gap would be a latent bug.
	 */
	public function test_withholding_subscription_leaves_no_gap(): void {
		$field = array(
			'choices' => array(
				array(
					'label' => 'Select a transaction type',
					'value' => '',
				),
				array(
					'label' => 'Products and Services',
					'value' => 'product',
				),
				array(
					'label' => 'Subscription',
					'value' => 'subscription',
				),
			),
		);

		$result = GF_Chip::get_transaction_type_choices( $field, true );

		$this->assertSame( array_keys( $result ), range( 0, count( $result ) - 1 ) );
	}
}
