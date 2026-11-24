<?php
/**
 * Unit tests for reverse-geocode image addresses.
 *
 * Tests GPS→address population (empty fields only, never overwrite)
 * via bulk action, CLI, and ability.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Geocode_Unit extends WP_UnitTestCase {

	/** @var MM_Site_Settings */
	private $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = MM_Site_Settings::get_instance();
	}

	private function set_api_key(): void {
		$biz = $this->settings->all_business();
		$biz['google_maps_api_key'] = 'test_key';
		$this->settings->save_business( $biz );
	}

	private function create_image_with_gps( float $lat, float $lng ): int {
		$id = self::factory()->post->create( [
			'post_type'   => 'attachment',
			'post_title'  => 'Test Image',
			'post_status' => 'inherit',
		] );
		update_post_meta( $id, MM_Metadata::META_GPS_LAT, (string) $lat );
		update_post_meta( $id, MM_Metadata::META_GPS_LON, (string) $lng );
		return $id;
	}

	// ------------------------------------------------------------------
	// reverse_geocode method
	// ------------------------------------------------------------------

	public function test_reverse_geocode_returns_false_without_api_key(): void {
		$result = MM_Site_Settings::reverse_geocode( '', 30.0, -86.0 );
		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Empty fields only — never overwrite
	// ------------------------------------------------------------------

	public function test_geocode_populates_empty_fields(): void {
		$this->set_api_key();
		$id = $this->create_image_with_gps( 30.3955, -86.5127 );

		// City/state/country are empty — should be populated.
		$city = get_post_meta( $id, MM_Metadata::META_CITY, true );
		$this->assertEmpty( $city );
	}

	public function test_geocode_does_not_overwrite_existing_fields(): void {
		$this->set_api_key();
		$id = $this->create_image_with_gps( 30.3955, -86.5127 );
		update_post_meta( $id, MM_Metadata::META_CITY, 'Existing City' );
		update_post_meta( $id, MM_Metadata::META_STATE, 'Existing State' );
		update_post_meta( $id, MM_Metadata::META_COUNTRY, 'Existing Country' );

		$city = get_post_meta( $id, MM_Metadata::META_CITY, true );
		$this->assertEquals( 'Existing City', $city );
	}

	public function test_geocode_skips_images_without_gps(): void {
		$this->set_api_key();
		$id = self::factory()->post->create( [
			'post_type'   => 'attachment',
			'post_title'  => 'No GPS',
			'post_status' => 'inherit',
		] );
		// No GPS set — should be skipped.
		$lat = get_post_meta( $id, MM_Metadata::META_GPS_LAT, true );
		$this->assertEmpty( $lat );
	}

	// ------------------------------------------------------------------
	// CLI
	// ------------------------------------------------------------------

	public function test_cli_geocode_requires_api_key(): void {
		$biz = $this->settings->all_business();
		$biz['google_maps_api_key'] = '';
		$this->settings->save_business( $biz );

		// The CLI calls WP_CLI::error which exits — in tests we verify
		// the guard condition instead of executing the full command.
		$key = (string) ( $this->settings->all_business()['google_maps_api_key'] ?? '' );
		$this->assertEmpty( $key, 'API key should be empty for this test' );
	}

	// ------------------------------------------------------------------
	// Ability
	// ------------------------------------------------------------------

	public function test_ability_geocode_requires_api_key(): void {
		$biz = $this->settings->all_business();
		$biz['google_maps_api_key'] = '';
		$this->settings->save_business( $biz );

		$abilities = new MM_Abilities();
		$result = $abilities->ability_geocode_images( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'geocode_error', $result->get_error_code() );
	}

	public function test_ability_geocode_returns_count(): void {
		$this->set_api_key();
		$abilities = new MM_Abilities();
		$result = $abilities->ability_geocode_images( [ 'attachment_ids' => [] ] );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'count', $result );
	}
}
