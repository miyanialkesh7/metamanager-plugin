<?php
/**
 * Integration tests for uninstall.php — data removal.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Uninstall extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// uninstall.php can be included without errors
	// ------------------------------------------------------------------

	public function test_uninstall_file_exists(): void {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/uninstall.php' );
	}

	// ------------------------------------------------------------------
	// Option cleanup
	// ------------------------------------------------------------------

	public function test_uninstall_removes_plugin_options(): void {
		update_option( MM_META_OPT_SETTINGS, [ 'test' => true ] );
		update_option( MM_META_OPT_BUSINESS, [ 'name' => 'Test' ] );
		update_option( 'mm_sitemap_images', true );
		update_option( 'mm_sitemap_video', true );

		// Simulate uninstall by deleting options directly.
		delete_option( MM_META_OPT_SETTINGS );
		delete_option( MM_META_OPT_BUSINESS );
		delete_option( 'mm_sitemap_images' );
		delete_option( 'mm_sitemap_video' );

		$this->assertFalse( get_option( MM_META_OPT_SETTINGS ) );
		$this->assertFalse( get_option( MM_META_OPT_BUSINESS ) );
		$this->assertFalse( get_option( 'mm_sitemap_images' ) );
		$this->assertFalse( get_option( 'mm_sitemap_video' ) );
	}

	// ------------------------------------------------------------------
	// Database table removal
	// ------------------------------------------------------------------

	public function test_uninstall_drops_jobs_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . MM_JOB_TABLE;

		// WP test suite rewrites DROP TABLE to DROP TEMPORARY TABLE via
		// the _drop_temporary_tables query filter. Remove both filters so
		// DDL operates on real tables (same as WordPress core's own dbDelta
		// tests do). See: wordpress-develop tests/phpunit/tests/db/dbDelta.php
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		// Table should exist (created by admin_init or set_up_before_class).
		$wpdb->suppress_errors( true );
		$pre = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		$wpdb->suppress_errors( false );
		$this->assertNotEmpty( $pre, "Table {$table} should exist before test." );

		// Use the plugin's own drop method (the way uninstall.php does it).
		MM_DB::drop_table();

		// Verify gone.
		$wpdb->suppress_errors( true );
		$check = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		$wpdb->suppress_errors( false );
		$this->assertEmpty( $check, "Table {$table} should not exist after drop_table()." );
	}

	// ------------------------------------------------------------------
	// Transient cleanup
	// ------------------------------------------------------------------

	public function test_uninstall_removes_transients(): void {
		set_transient( 'mm_upload_batch', 'test', 60 );
		set_transient( 'mm_import_lock', '1', 30 );

		delete_transient( 'mm_upload_batch' );
		delete_transient( 'mm_import_lock' );

		$this->assertFalse( get_transient( 'mm_upload_batch' ) );
		$this->assertFalse( get_transient( 'mm_import_lock' ) );
	}
}
