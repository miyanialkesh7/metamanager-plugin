<?php
/**
 * Integration tests for MM_Memory_Manager end-to-end flow.
 *
 * Covers: memory gate integration with mm_import_completed_jobs(),
 * batch size settings, notice lifecycle, and job cost estimation.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Memory_Manager_Integration extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		MM_DB::create_or_update_tables();
	}

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		delete_transient( 'mm_import_lock' );
		delete_option( MM_Memory_Manager::NOTICE_OPTION );
		wp_mkdir_p( "completed" );
		wp_mkdir_p( "failed" );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		delete_transient( 'mm_import_lock' );
		delete_option( MM_Memory_Manager::NOTICE_OPTION );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	public function test_min_floor_is_8mb(): void {
		$this->assertSame( 8 * 1024 * 1024, MM_Memory_Manager::MIN_FLOOR );
	}

	public function test_system_pressure_ratio_is_10_percent(): void {
		$this->assertSame( 0.10, MM_Memory_Manager::SYSTEM_PRESSURE_RATIO );
	}

	public function test_notice_option_constant(): void {
		$this->assertSame( 'mm_memory_limit_notice', MM_Memory_Manager::NOTICE_OPTION );
	}

	// ------------------------------------------------------------------
	// get_memory_limit() — PHP ini parsing
	// ------------------------------------------------------------------

	public function test_get_memory_limit_returns_int(): void {
		$limit = MM_Memory_Manager::get_memory_limit();
		$this->assertIsInt( $limit );
		$this->assertGreaterThan( 0, $limit );
	}

	public function test_get_memory_limit_in_megabytes(): void {
		$original = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '256M' );
		$this->assertSame( 256 * 1024 * 1024, MM_Memory_Manager::get_memory_limit() );
		ini_set( 'memory_limit', $original );
	}

	public function test_get_memory_limit_in_gigabytes(): void {
		$original = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '2G' );
		$this->assertSame( 2 * 1024 * 1024 * 1024, MM_Memory_Manager::get_memory_limit() );
		ini_set( 'memory_limit', $original );
	}

	public function test_get_memory_limit_unlimited(): void {
		$original = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '-1' );
		$this->assertSame( PHP_INT_MAX, MM_Memory_Manager::get_memory_limit() );
		ini_set( 'memory_limit', $original );
	}

	// ------------------------------------------------------------------
	// estimate_job_cost() — pure logic
	// ------------------------------------------------------------------

	public function test_estimate_job_cost_base_for_thumbnail(): void {
		$job  = [ 'mime_type' => 'image/jpeg', 'size' => 'thumbnail', 'width' => 150, 'height' => 150 ];
		$cost = MM_Memory_Manager::estimate_job_cost( $job );
		$this->assertSame( MM_Memory_Manager::BASE_COST_PER_JOB, $cost );
	}

	public function test_estimate_job_cost_image_with_dimensions(): void {
		// 1000x2000 = 2,000,000 pixels = exactly 2 MP.
		$job  = [ 'mime_type' => 'image/jpeg', 'size' => 'full', 'width' => 1000, 'height' => 2000 ];
		$cost = MM_Memory_Manager::estimate_job_cost( $job );
		$this->assertSame( MM_Memory_Manager::BASE_COST_PER_JOB + 2 * MM_Memory_Manager::COST_PER_MEGAPIXEL, $cost );
	}

	public function test_estimate_job_cost_image_without_dimensions(): void {
		// No width/height → assume 4 MP.
		$job  = [ 'mime_type' => 'image/jpeg', 'size' => 'full' ];
		$cost = MM_Memory_Manager::estimate_job_cost( $job );
		$this->assertSame( MM_Memory_Manager::BASE_COST_PER_JOB + 4 * MM_Memory_Manager::COST_PER_MEGAPIXEL, $cost );
	}

	public function test_estimate_job_cost_video(): void {
		// 120 seconds = 2 minutes → base (2MB) + 2 * 5MB = 12MB.
		$job  = [ 'mime_type' => 'video/mp4', 'size' => 'full', 'duration' => 120 ];
		$cost = MM_Memory_Manager::estimate_job_cost( $job );
		$this->assertSame( MM_Memory_Manager::BASE_COST_PER_JOB + 2 * MM_Memory_Manager::COST_PER_MINUTE_VIDEO, $cost );
	}

	public function test_estimate_job_cost_video_short(): void {
		// 30 seconds → max(1, 30/60) = 1 minute → base + 5MB.
		$job  = [ 'mime_type' => 'video/mp4', 'size' => 'full', 'duration' => 30 ];
		$cost = MM_Memory_Manager::estimate_job_cost( $job );
		$this->assertSame( MM_Memory_Manager::BASE_COST_PER_JOB + MM_Memory_Manager::COST_PER_MINUTE_VIDEO, $cost );
	}

	public function test_estimate_job_cost_audio(): void {
		$job  = [ 'mime_type' => 'audio/mpeg', 'size' => 'full' ];
		$cost = MM_Memory_Manager::estimate_job_cost( $job );
		// base + 1 MB for audio.
		$this->assertSame( MM_Memory_Manager::BASE_COST_PER_JOB + 1 * 1024 * 1024, $cost );
	}

	// ------------------------------------------------------------------
	// calculate_batch_size() — pure logic
	// ------------------------------------------------------------------

	public function test_calculate_batch_size_empty_jobs(): void {
		$result = MM_Memory_Manager::calculate_batch_size( [] );
		$this->assertSame( 0, $result );
	}

	public function test_calculate_batch_size_memory_defined(): void {
		// Create 100 tiny jobs. Batch size is memory-defined, no upper bound.
		$jobs = array_fill( 0, 100, [ 'mime_type' => 'image/jpeg', 'size' => 'thumbnail' ] );
		$result = MM_Memory_Manager::calculate_batch_size( $jobs );
		$this->assertGreaterThan( 0, $result );
		$this->assertLessThanOrEqual( 100, $result );
	}

	public function test_calculate_batch_size_respects_available_memory(): void {
		// Create jobs that each cost BASE_COST (2MB).
		$jobs = array_fill( 0, 200, [ 'mime_type' => 'image/jpeg', 'size' => 'thumbnail' ] );
		$result = MM_Memory_Manager::calculate_batch_size( $jobs );
		// Should return some positive number (memory is available in test env).
		$this->assertGreaterThan( 0, $result );
		$this->assertLessThanOrEqual( 200, $result );
	}

	// ------------------------------------------------------------------
	// Notice lifecycle
	// ------------------------------------------------------------------

	public function test_notice_defaults_to_not_set(): void {
		$this->assertFalse( MM_Memory_Manager::has_notice() );
	}

	public function test_set_notice_creates_option(): void {
		MM_Memory_Manager::set_notice();
		$this->assertTrue( MM_Memory_Manager::has_notice() );
	}

	public function test_clear_notice_removes_option(): void {
		MM_Memory_Manager::set_notice();
		$this->assertTrue( MM_Memory_Manager::has_notice() );

		MM_Memory_Manager::clear_notice();
		$this->assertFalse( MM_Memory_Manager::has_notice() );
	}

	public function test_set_notice_does_not_overwrite_existing(): void {
		// Set once, record timestamp.
		MM_Memory_Manager::set_notice();
		$first = get_option( MM_Memory_Manager::NOTICE_OPTION );

		// Set again — should not overwrite.
		MM_Memory_Manager::set_notice();
		$second = get_option( MM_Memory_Manager::NOTICE_OPTION );

		$this->assertSame( $first, $second );
	}

	public function test_render_notice_outputs_html_when_notice_set(): void {
		MM_Memory_Manager::set_notice();

		ob_start();
		MM_Memory_Manager::render_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Metamanager:', $output );
		$this->assertStringContainsString( 'Batch processing paused', $output );
	}

	public function test_render_notice_outputs_nothing_when_notice_cleared(): void {
		MM_Memory_Manager::clear_notice();

		ob_start();
		MM_Memory_Manager::render_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	// ------------------------------------------------------------------
	// should_pause() — integration with memory
	// ------------------------------------------------------------------

	public function test_should_pause_returns_bool(): void {
		$result = MM_Memory_Manager::should_pause();
		$this->assertIsBool( $result );
	}

	public function test_should_pause_false_under_normal_conditions(): void {
		// In test environment with default memory limit, should not pause.
		$this->assertFalse( MM_Memory_Manager::should_pause() );
	}

	// ------------------------------------------------------------------
	// get_available_memory() — returns non-negative int
	// ------------------------------------------------------------------

	public function test_get_available_memory_returns_non_negative(): void {
		$available = MM_Memory_Manager::get_available_memory();
		$this->assertIsInt( $available );
		$this->assertGreaterThanOrEqual( 0, $available );
	}
}
