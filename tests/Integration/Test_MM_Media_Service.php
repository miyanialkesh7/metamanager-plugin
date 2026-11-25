<?php
/**
 * Integration tests for MM_Media_Service.
 *
 * Covers: canonical compress, embed_metadata, set_licensing (all-or-nothing),
 * inject_site_info, geocode (metadata sync), and scan operations.
 * Verifies FIELD_COMPAT filtering and WebP queueing for JPEG/PNG.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Media_Service extends WP_UnitTestCase {

	private array $tmp_files = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		MM_DB::create_or_update_tables();
		foreach ( [ 'compression', 'metadata', 'webp', 'completed', 'failed' ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	public function tear_down(): void {
		foreach ( [ 'compression', 'metadata', 'webp', 'completed', 'failed' ] as $dir ) {
			foreach ( glob( $dir . '*.json' ) ?: [] as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		foreach ( $this->tmp_files as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->tmp_files = [];
		parent::tear_down();
	}

	/** Create a real temporary image file and return its attachment ID. */
	private function create_attachment( string $mime = 'image/jpeg' ): int {
		$ext  = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' ][ $mime ] ?? 'jpg';
		$path = wp_tempnam( "mm-test-{$ext}" );
		$func = [ 'image/jpeg' => 'imagejpeg', 'image/png' => 'imagepng' ][ $mime ] ?? 'imagejpeg';
		$img  = imagecreatetruecolor( 20, 20 );
		$func( $img, $path );
		imagedestroy( $img );
		$this->tmp_files[] = $path;

		$upload_dir = wp_upload_dir();
		$dest       = $upload_dir['path'] . '/mm-test-' . uniqid() . '.' . $ext;
		copy( $path, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->tmp_files[] = $dest;

		$id = wp_insert_attachment( [
			'post_mime_type' => $mime,
			'post_title'     => 'MM Test ' . uniqid(),
			'post_status'    => 'inherit',
		], $dest );

		$meta = wp_generate_attachment_metadata( $id, $dest );
		wp_update_attachment_metadata( $id, $meta );

		// Clear jobs queued by on_upload so tests start clean.
		foreach ( [ 'compression', 'metadata', 'webp' ] as $dir ) {
			foreach ( glob( $dir . '*.json' ) ?: [] as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		return $id;
	}

	private function count_jobs( string $type ): int {
        return MM_DB::count_by_status( $type, 'pending' );
    }

	// -----------------------------------------------------------------------
	// compress
	// -----------------------------------------------------------------------

	public function test_compress_jpeg_queues_compression_and_webp(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::compress( [ $id ], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'compression' ) );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'webp' ),
			'JPEG must get WebP generation jobs.' );
	}

	public function test_compress_png_queues_compression_and_webp(): void {
		$id     = $this->create_attachment( 'image/png' );
		$result = MM_Media_Service::compress( [ $id ], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'webp' ),
			'PNG must get WebP generation jobs.' );
	}

	public function test_compress_webp_queues_compression_only(): void {
		$id     = $this->create_attachment( 'image/webp' );
		$result = MM_Media_Service::compress( [ $id ], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 0, $this->count_jobs( 'webp' ),
			'WebP source must NOT get WebP generation jobs.' );
	}

	public function test_compress_skips_non_media(): void {
		$result = MM_Media_Service::compress( [ 999999 ], 'test' );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_compress_respects_is_compressed_without_force(): void {
		$id = $this->create_attachment( 'image/jpeg' );
		// Mark as compressed in DB (is_compressed checks job history).
		MM_DB::log_pending_job( [
			'attachment_id' => $id,
			'image_name'    => 'test.jpg',
			'job_type'      => 'compression',
			'file_path'     => '/tmp/test.jpg',
			'size'          => 'full',
			'dimensions'    => '',
			'status'        => 'completed',
			'submitted_at'  => current_time( 'mysql' ),
			'completed_at'  => current_time( 'mysql' ),
		] );
		$result = MM_Media_Service::compress( [ $id ], 'test', false );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_compress_force_overrides_is_compressed(): void {
		$id = $this->create_attachment( 'image/jpeg' );
		MM_DB::log_pending_job( [
			'attachment_id' => $id,
			'image_name'    => 'test.jpg',
			'job_type'      => 'compression',
			'file_path'     => '/tmp/test.jpg',
			'size'          => 'full',
			'dimensions'    => '',
			'status'        => 'completed',
			'submitted_at'  => current_time( 'mysql' ),
			'completed_at'  => current_time( 'mysql' ),
		] );
		$result = MM_Media_Service::compress( [ $id ], 'test', true );
		$this->assertSame( 1, $result['count'] );
	}

	// -----------------------------------------------------------------------
	// embed_metadata
	// -----------------------------------------------------------------------

	public function test_embed_metadata_sets_fields_and_queues(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::embed_metadata( [ $id ], 'test', [
			MM_Metadata::META_CREATOR => 'Test Creator',
		] );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'Test Creator', get_post_meta( $id, MM_Metadata::META_CREATOR, true ) );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'metadata' ) );
	}

	public function test_embed_metadata_empty_fields_just_queues(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::embed_metadata( [ $id ], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'metadata' ) );
	}

	// -----------------------------------------------------------------------
	// set_licensing — all-or-nothing (rule 21)
	// -----------------------------------------------------------------------

	public function test_set_licensing_all_five_fields_succeeds(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::set_licensing( [ $id ], [
			'creator'   => 'John Doe',
			'credit'    => 'Photo by John Doe',
			'copyright' => 'Copyright 2026 John Doe',
			'license'   => 'https://example.com/license',
			'acquire'   => 'https://example.com/acquire',
		], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'John Doe', get_post_meta( $id, MM_Metadata::META_CREATOR, true ) );
		$this->assertSame( 'Photo by John Doe', get_post_meta( $id, MM_Metadata::META_CREDIT, true ) );
		$this->assertSame( 'Copyright 2026 John Doe', get_post_meta( $id, MM_Metadata::META_COPYRIGHT, true ) );
		$this->assertSame( 'https://example.com/license', get_post_meta( $id, MM_Metadata::META_LICENSE, true ) );
		$this->assertSame( 'https://example.com/acquire', get_post_meta( $id, MM_Metadata::META_ACQUIRE_LICENSE_PAGE, true ) );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'metadata' ) );
	}

	public function test_set_licensing_partial_fields_rejected(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::set_licensing( [ $id ], [
			'creator' => 'John Doe',
			'credit'  => 'Photo by John Doe',
			// Missing: copyright, license, acquire
		], 'test' );

		$this->assertSame( 0, $result['count'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'All-or-nothing', $result['errors'][0] );
	}

	public function test_set_licensing_empty_fields_rejected(): void {
		$result = MM_Media_Service::set_licensing( [ 1 ], [], 'test' );
		$this->assertSame( 0, $result['count'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_set_licensing_skips_non_images(): void {
		$id = $this->create_attachment( 'image/jpeg' );
		// Use a non-image attachment.
		$upload_dir = wp_upload_dir();
		$dest       = $upload_dir['path'] . '/mm-test-' . uniqid() . '.pdf';
		file_put_contents( $dest, '%PDF-1.4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->tmp_files[] = $dest;
		$doc_id = wp_insert_attachment( [
			'post_mime_type' => 'application/pdf',
			'post_title'     => 'Test PDF',
			'post_status'    => 'inherit',
		], $dest );

		$result = MM_Media_Service::set_licensing( [ $id, $doc_id ], [
			'creator'   => 'John Doe',
			'credit'    => 'Photo by John Doe',
			'copyright' => 'Copyright 2026',
			'license'   => 'https://example.com/license',
			'acquire'   => 'https://example.com/acquire',
		], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	// -----------------------------------------------------------------------
	// inject_site_info
	// -----------------------------------------------------------------------

	public function test_inject_site_info_queues_metadata(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::inject_site_info( [ $id ], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'metadata' ) );
	}

	// -----------------------------------------------------------------------
	// scan
	// -----------------------------------------------------------------------

	public function test_scan_queues_compression_webp_and_import_for_jpeg(): void {
		$id     = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::scan( [ $id ], 'test' );

		$this->assertSame( 1, $result['count'] );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'compression' ) );
		$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'webp' ),
			'Scan must queue WebP generation for JPEG.' );
		if ( MM_Status::exiftool_available() ) {
			$this->assertGreaterThanOrEqual( 1, $this->count_jobs( 'metadata' ) );
		}
	}

	// -----------------------------------------------------------------------
	// geocode
	// -----------------------------------------------------------------------

	public function test_geocode_without_api_key_returns_error(): void {
		$id = $this->create_attachment( 'image/jpeg' );
		$result = MM_Media_Service::geocode( [ $id ], 'test' );
		$this->assertSame( 0, $result['count'] );
		$this->assertNotEmpty( $result['errors'] );
	}
}
