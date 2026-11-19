<?php
/**
 * Unit tests for serviceType visibility in details table.
 *
 * serviceType is machine-reading only (schema JSON-LD) and must never
 * appear in the human-visible details table on single/archive pages.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Service_Type_Hidden_Unit extends WP_UnitTestCase {

	public function test_service_type_not_in_details_table(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_service',
			'post_title'  => 'Test Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'service_type' => 'Pontoon Charter',
			'service_price' => '$150',
			'description' => 'A test service',
		] );

		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Pontoon Charter', $output, 'service_type must not appear in visible details' );
		$this->assertStringNotContainsString( 'Service Type', $output, 'service_type label must not appear in visible details' );
		$this->assertStringContainsString( '$150', $output, 'other fields should still render' );
		$this->assertStringContainsString( 'A test service', $output, 'description should still render' );
	}

	public function test_service_type_still_in_jsonld(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_service',
			'post_title'  => 'Test Service',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'service_type' => 'Pontoon Charter',
		] );

		$fields = MM_Schema_Types::get_fields_by_type();
		$service_fields = $fields['Service'] ?? [];
		$has_service_type = false;
		foreach ( $service_fields as $f ) {
			if ( ( $f['key'] ?? '' ) === 'service_type' ) {
				$has_service_type = true;
				break;
			}
		}
		$this->assertTrue( $has_service_type, 'service_type must remain in schema field definitions for JSON-LD' );
	}
}
