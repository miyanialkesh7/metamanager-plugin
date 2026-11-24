<?php
/**
 * Unit tests for machine-reading fields hidden from visible details.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Machine_Fields_Hidden_Unit extends WP_UnitTestCase {

	public function test_trip_machine_fields_hidden(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_trip',
			'post_title'  => 'Test Trip',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'trip_departure_name' => 'Marina',
			'trip_duration_iso'   => 'PT4H',
			'trip_currency'       => 'USD',
			'trip_price'          => '350',
		] );

		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Marina', $output, 'departure_name should show' );
		$this->assertStringContainsString( '350', $output, 'price should show' );
		$this->assertStringNotContainsString( 'PT4H', $output, 'trip_duration_iso hidden' );
		$this->assertStringNotContainsString( 'USD', $output, 'trip_currency hidden' );
	}

	public function test_event_machine_fields_hidden(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_event',
			'post_title'  => 'Test Event',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'event_start_date'       => '2026-12-01',
			'event_location_name'    => 'Convention Center',
			'event_status'           => 'EventScheduled',
			'event_attendance_mode'  => 'OfflineEventAttendanceMode',
			'event_currency'         => 'USD',
		] );

		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Convention Center', $output, 'location should show' );
		$this->assertStringNotContainsString( 'EventScheduled', $output, 'event_status hidden' );
		$this->assertStringNotContainsString( 'OfflineEventAttendanceMode', $output, 'event_attendance_mode hidden' );
		$this->assertStringNotContainsString( 'USD', $output, 'event_currency hidden' );
	}

	public function test_service_machine_fields_hidden(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_service',
			'post_title'  => 'Test Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'service_type'     => 'Pontoon Charter',
			'service_price'    => '350',
			'service_currency' => 'USD',
		] );

		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( '350', $output, 'price should show' );
		$this->assertStringNotContainsString( 'Pontoon Charter', $output, 'service_type hidden' );
		$this->assertStringNotContainsString( 'USD', $output, 'service_currency hidden' );
	}
}
