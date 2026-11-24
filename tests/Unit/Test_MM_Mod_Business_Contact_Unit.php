<?php
/**
 * Unit tests for MM_Mod_Business_Contact style defaults and schema building.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Business_Contact_Unit extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// style_defaults()
	// ------------------------------------------------------------------

	public function test_style_defaults_returns_array(): void {
		$defaults = MM_Mod_Business_Contact::style_defaults();
		$this->assertIsArray( $defaults );
		$this->assertNotEmpty( $defaults );
	}

	public function test_style_defaults_has_required_keys(): void {
		$defaults = MM_Mod_Business_Contact::style_defaults();
		// Visibility toggles live in business defaults (mm_meta_business), not style.
		$style_keys = [ 'show_phone', 'show_email', 'show_vcard', 'show_json', 'show_csv' ];
		foreach ( $style_keys as $key ) {
			$this->assertArrayNotHasKey( $key, $defaults, "Style default should NOT have key: {$key} (it lives in business defaults)" );
		}
		// Appearance keys should be present.
		$this->assertArrayHasKey( 'card_bg', $defaults );
	}

	// ------------------------------------------------------------------
	// get_style_settings()
	// ------------------------------------------------------------------

	public function test_get_style_settings_returns_array(): void {
		delete_option( MM_Mod_Business_Contact::OPT_STYLE );
		$settings = MM_Mod_Business_Contact::get_style_settings();
		$this->assertIsArray( $settings );
	}

	public function test_get_style_settings_merges_defaults(): void {
		delete_option( MM_Mod_Business_Contact::OPT_STYLE );
		$settings = MM_Mod_Business_Contact::get_style_settings();
		$defaults = MM_Mod_Business_Contact::style_defaults();

		// Should have all default keys.
		foreach ( $defaults as $key => $value ) {
			$this->assertArrayHasKey( $key, $settings );
		}
	}

	public function test_get_style_settings_saved_values_override_defaults(): void {
		update_option( MM_Mod_Business_Contact::OPT_STYLE, [ 'card_bg' => '#000000' ] );
		$settings = MM_Mod_Business_Contact::get_style_settings();

		$this->assertSame( '#000000', $settings['card_bg'] );

		// Other defaults should still be present.
		$defaults = MM_Mod_Business_Contact::style_defaults();
		$this->assertSame( $defaults['card_text'], $settings['card_text'] );

		// Cleanup.
		delete_option( MM_Mod_Business_Contact::OPT_STYLE );
	}

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	public function test_opt_style_constant(): void {
		$this->assertSame( 'mm_meta_contact_style', MM_Mod_Business_Contact::OPT_STYLE );
	}

	public function test_opt_group_constant(): void {
		$this->assertSame( 'mm_meta_contact_group', MM_Mod_Business_Contact::OPT_GROUP );
	}
}
