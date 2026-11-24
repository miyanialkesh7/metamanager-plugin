<?php
/**
 * Integration tests for mm_register_webp_attachment.
 *
 * Tests the daemon's registration path: generate .webp alongside JPEG/PNG,
 * register as WP attachment, copy metadata, generate WP metadata.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Register_WebP extends WP_UnitTestCase {

	private array $tmp_files = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		MM_DB::create_or_update_table();
	}

	public function tear_down(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->tmp_files = [];
		parent::tear_down();
	}

	/** Create a real JPEG attachment with metadata and a WebP sibling on disk. */
	private function create_jpeg_with_webp(): array {
		// Create a real JPEG.
		$jpg_path = sys_get_temp_dir() . '/mm_test_' . uniqid() . '.jpg';
		$img = imagecreatetruecolor( 20, 20 );
		imagejpeg( $img, $jpg_path );
		imagedestroy( $img );
		$this->tmp_files[] = $jpg_path;

		// Create the WebP sibling (what the daemon would generate).
		$webp_path = preg_replace( '/\.jpg$/i', '.webp', $jpg_path );
		$img2 = imagecreatetruecolor( 20, 20 );
		imagewebp( $img2, $webp_path );
		imagedestroy( $img2 );
		$this->tmp_files[] = $webp_path;

		// Create the JPEG attachment with metadata.
		$att_id = $this->factory->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		update_post_meta( $att_id, '_wp_attached_file', $jpg_path );
		update_post_meta( $att_id, MM_Metadata::META_CREATOR, 'Test Creator' );
		update_post_meta( $att_id, MM_Metadata::META_COPYRIGHT, 'Test Copyright' );

		return [ $att_id, $jpg_path, $webp_path ];
	}

	public function test_register_webp_creates_attachment(): void {
		[ $orig_id, $jpg_path, $webp_path ] = $this->create_jpeg_with_webp();

		mm_register_webp_attachment( $orig_id, $webp_path );

		// Find the WebP child.
		$children = get_children( [
			'post_parent'    => $orig_id,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/webp',
		] );
		$this->assertNotEmpty( $children, 'WebP attachment must be created' );

		$webp_id = array_keys( $children )[0];
		$webp = get_post( $webp_id );
		$this->assertSame( 'image/webp', $webp->post_mime_type );
		$this->assertSame( $orig_id, (int) $webp->post_parent );
	}

	public function test_register_webp_copies_metadata(): void {
		[ $orig_id, $jpg_path, $webp_path ] = $this->create_jpeg_with_webp();

		mm_register_webp_attachment( $orig_id, $webp_path );

		$children = get_children( [
			'post_parent'    => $orig_id,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/webp',
		] );
		$webp_id = array_keys( $children )[0];

		$this->assertSame( 'Test Creator', get_post_meta( $webp_id, MM_Metadata::META_CREATOR, true ) );
		$this->assertSame( 'Test Copyright', get_post_meta( $webp_id, MM_Metadata::META_COPYRIGHT, true ) );
	}

	public function test_register_webp_idempotent(): void {
		[ $orig_id, $jpg_path, $webp_path ] = $this->create_jpeg_with_webp();

		mm_register_webp_attachment( $orig_id, $webp_path );
		mm_register_webp_attachment( $orig_id, $webp_path ); // Second call — no duplicate.

		$children = get_children( [
			'post_parent'    => $orig_id,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/webp',
		] );
		$this->assertCount( 1, $children, 'Must not create duplicate WebP attachments' );
	}

	public function test_register_webp_missing_original_returns(): void {
		$webp_path = sys_get_temp_dir() . '/mm_test_' . uniqid() . '.webp';
		$img = imagecreatetruecolor( 20, 20 );
		imagewebp( $img, $webp_path );
		imagedestroy( $img );
		$this->tmp_files[] = $webp_path;

		// Original ID 999999 doesn't exist — function should return without error.
		mm_register_webp_attachment( 999999, $webp_path );

		$children = get_children( [
			'post_parent'    => 999999,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/webp',
		] );
		$this->assertEmpty( $children, 'No attachment should be created for missing original' );
	}

	public function test_register_webp_generates_wp_metadata(): void {
		[ $orig_id, $jpg_path, $webp_path ] = $this->create_jpeg_with_webp();

		mm_register_webp_attachment( $orig_id, $webp_path );

		$children = get_children( [
			'post_parent'    => $orig_id,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/webp',
		] );
		$webp_id = array_keys( $children )[0];

		$meta = wp_get_attachment_metadata( $webp_id );
		$this->assertIsArray( $meta, 'WP metadata must be generated for WebP' );
		$this->assertArrayHasKey( 'width', $meta );
		$this->assertArrayHasKey( 'height', $meta );
	}
}
