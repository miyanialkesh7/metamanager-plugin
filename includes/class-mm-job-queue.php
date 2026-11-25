<?php
/**
 * Metamanager Job Queue
 *
 * Single point of responsibility for writing, reading, and cleaning up job
 * files. PHP's only role in image processing is placing the job instruction
 * on the queue. The OS daemons do all image work.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Job_Queue
 */
class MM_Job_Queue {

	// -----------------------------------------------------------------------
	// Filesystem helper
	// -----------------------------------------------------------------------

	/**
	 * Return a direct-access filesystem instance for writing job queue files.
	 * Always uses WP_Filesystem_Direct regardless of the global FS method so
	 * that other plugins (e.g. those that set the global to FTP/SSH) cannot
	 * silently break job file writes.
	 *
	 * @return WP_Filesystem_Direct
	 */
	private static function get_filesystem(): WP_Filesystem_Direct {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		// Call WP_Filesystem() only to ensure FS_CHMOD_FILE / FS_CHMOD_DIR constants
		// are defined (they are set inside WP_Filesystem(), not at file-include time).
		// We ignore the global it sets — all actual I/O goes through WP_Filesystem_Direct.
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			WP_Filesystem();
		}
		return new WP_Filesystem_Direct( [] );
	}

	// -----------------------------------------------------------------------
	// Directory management
	// -----------------------------------------------------------------------

	/**
	 * Create all job queue directories if they do not exist.
	 * Called on plugin activation and defensively before writing.
	 */
	public static function ensure_dirs(): void {
		// DB-only mode: tables are created by MM_DB::create_or_update_tables()
	}

	// -----------------------------------------------------------------------
	// Writing a single job file
	// -----------------------------------------------------------------------

	/**
	 * Write one job JSON file for a single file.
	 *
	 * Deduplication behaviour:
	 *   Compression — if an unclaimed .json job already exists for this
	 *   attachment+size, the new write is suppressed entirely. Running lossless
	 *   compression twice on the same file is wasteful and produces an identical
	 *   result. A user-facing admin notice is queued via transient.
	 *
	 *   Metadata — if an unclaimed .json job already exists for this
	 *   attachment+size, the new job is written normally (they will run in
	 *   sequence). A user-facing admin notice is queued so the user knows their
	 *   update is behind an existing job and will follow it.
	 *
	 *   Only plain .json files are inspected — .processing files are already
	 *   owned by a running daemon subshell and are never touched here.
	 *
	 * Shell cross-reference: The daemon scripts (metamanager-meta-daemon.sh,
	 * metamanager-compress-daemon.sh) read the JSON files written by this
	 * method, process them, and write result JSON to MM_JOB_DONE or MM_JOB_FAILED.
	 * The mm_import_completed_jobs() cron handler then reads those results.
	 *
	 * @param string $type          'compression' or 'metadata'.
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $file_path     Absolute path to the file.
	 * @param string $size          WP size slug (e.g. 'full', 'thumbnail').
	 * @param array  $extra         Optional additional fields merged into the job.
	 *
	 * @return string 'written'  — new job queued, no duplicate detected.
	 *                'skipped'  — compression duplicate found; write suppressed.
	 *                'queued'   — metadata job written behind an existing pending job.
	 */
	public static function write_job(
		string $type,
		int $attachment_id,
		string $file_path,
		string $size = 'full',
		array $extra = []
	): string {
		// Note: no deduplication at write time. The daemon's per-file flock
		// handles concurrency safely. Suppressing writes at queue time drops
		// work without knowing if the pending job will succeed (rule 18:
		// no silent fallbacks — write the job and let the daemon decide).

		// Dimensions from file — only valid for images; skip for video/audio.
		$dimensions = '';
		if ( file_exists( $file_path ) ) {
			$info = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $info && isset( $info[0], $info[1] ) ) {
				$dimensions = $info[0] . 'x' . $info[1];
			}
		}

		$job = array_merge( [
			'attachment_id'  => $attachment_id,
			'image_name'     => pathinfo( get_attached_file( $attachment_id ) ?: '', PATHINFO_FILENAME ),
			'job_type'       => $type,
			'file_path'      => $file_path,
			'size'           => $size,
			'dimensions'     => $dimensions,
			'submitted_at'   => current_time( 'mysql' ),
			'optimize_level' => (int) get_option( 'mm_compress_level', 2 ),
			'metadata'       => MM_Metadata::get_fields_for_job( $attachment_id ),
		], $extra );

		// DB is the single source of truth. No file queues.
		MM_DB::insert_job( $job );

		return 'written';
	}

	// -----------------------------------------------------------------------
	// Enqueue for all image sizes
	// -----------------------------------------------------------------------

	/**
	 * Enqueue one or both job types for the original image and all registered sizes.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param array  $meta          Attachment metadata (from wp_get_attachment_metadata).
	 * @param string $types         'both' | 'compression' | 'metadata'
	 * @param array  $extra         Extra fields passed to each job.
	 */
	public static function enqueue_all_sizes(
		int $attachment_id,
		array $meta = [],
		string $types = 'both',
		array $extra = []
	): void {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		if ( ! $meta ) {
			$meta = wp_get_attachment_metadata( $attachment_id ) ?: [];
		}

		$queue_types = match ( $types ) {
			'compression' => [ 'compression' ],
			'metadata'    => [ 'metadata' ],
			'compress+webp' => [ 'compression', 'webp' ],
			default       => [ 'compression', 'metadata' ],
		};

		// WebP: one job per image (full-size only). The daemon generates the
		// WebP and registers it; WordPress generates all sizes via
		// wp_generate_attachment_metadata. The on_upload hook then queues
		// metadata for those new sizes.
		if ( in_array( 'webp', $queue_types, true ) ) {
			self::write_job( 'webp', $attachment_id, $file, 'full', $extra );
		}

		// Compression/metadata: one job per size (full + all generated sizes).
		$per_size_types = array_values( array_diff( $queue_types, [ 'webp' ] ) );
		if ( empty( $per_size_types ) ) {
			return;
		}

		foreach ( $per_size_types as $type ) {
			self::write_job( $type, $attachment_id, $file, 'full', $extra );
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( pathinfo( $file, PATHINFO_DIRNAME ) );
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) ) {
					continue;
				}
				$img_path = $dir . $info['file'];
				if ( ! file_exists( $img_path ) ) {
					continue;
				}
				foreach ( $per_size_types as $type ) {
					self::write_job( $type, $attachment_id, $img_path, $size, $extra );
				}
			}
		}
	}

	// -----------------------------------------------------------------------
	// Hook handlers (called from metamanager.php — no duplicate registrations)
	// -----------------------------------------------------------------------

	/**
	 * wp_generate_attachment_metadata hook: enqueue both job types on upload.
	 *
	 * Fresh upload (no completed jobs in DB): reads embedded metadata into WP,
	 * then queues both compression and metadata-embedding jobs.
	 * Thumbnail regeneration (completed jobs exist in DB): skips metadata import
	 * (preserves user edits) and queues only compression for changed sizes.
	 *
	 * @param array $metadata      WordPress-generated metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata (we are a passthrough filter).
	 */
	public static function on_upload( array $metadata, int $attachment_id ): array {
		$mime = (string) get_post_mime_type( $attachment_id );
		$is_image = wp_attachment_is_image( $attachment_id );
		$is_video = MM_Metadata::is_video_mime( $mime );
		$is_audio = MM_Metadata::is_audio_mime( $mime );
		$is_pdf   = MM_Metadata::is_pdf_mime( $mime );

		if ( ! $is_image && ! $is_video && ! $is_audio && ! $is_pdf ) {
			return $metadata;
		}

		$is_regeneration = '1' === (string) get_post_meta( $attachment_id, MM_Metadata::META_SYNCED, true );

		if ( $is_image ) {
			// Step 1 (JPEG/PNG): queue webp generation + compression.
			// Step 2: webp daemon generates optimal WebP (cwebp -q 80), registers as attachment.
			// Step 3 (WebP): cascade queues metadata only — WebP is already
			//   optimal from the daemon; running compression again would
			//   losslessly re-encode a lossy file (wasteful, potentially larger).
			$job_types = ( 'image/webp' === $mime ) ? 'metadata' : 'compress+webp';
			if ( $is_regeneration ) {
				self::enqueue_all_sizes( $attachment_id, $metadata, $job_types, [ 'trigger' => 'thumbnail_regen', 'job_subtype' => 'edit' ] );
			} else {
				MM_Metadata::enqueue_import_job( $attachment_id );
				self::enqueue_all_sizes( $attachment_id, $metadata, $job_types, [ 'trigger' => 'upload', 'job_subtype' => 'provenance' ] );
			}
			return $metadata;
		}

		// ---- Video / Audio / PDF: single-file handling ----
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		if ( ! $is_regeneration ) {
			// Queue an import job; metadata write-back is triggered by the result handler.
			MM_Metadata::enqueue_import_job( $attachment_id );
		} else {
			// Re-upload of an existing file: write current WP fields back.
			if ( MM_Metadata::can_write_meta( $mime ) ) {
				self::write_job( 'metadata', $attachment_id, $file, 'full', [ 'trigger' => 'upload', 'job_subtype' => 'provenance' ] );
			}
		}

		// Queue video remux (container repack, lossless) — audio and PDF have no remux.
		if ( $is_video ) {
			self::write_job( 'compression', $attachment_id, $file, 'full', [ 'trigger' => 'upload' ] );
		}

		return $metadata;
	}

	/**
	 * delete_attachment hook: remove queued job files and compression meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_delete_attachment( int $attachment_id ): void {
		MM_DB::delete_jobs_for_attachment( $attachment_id );

		// Remove all Metamanager custom metadata fields.
		foreach ( [
			MM_Metadata::META_CREATOR,
			MM_Metadata::META_COPYRIGHT,
			MM_Metadata::META_OWNER,
			MM_Metadata::META_HEADLINE,
			MM_Metadata::META_CREDIT,
			MM_Metadata::META_KEYWORDS,
			MM_Metadata::META_DATE,
			MM_Metadata::META_CITY,
			MM_Metadata::META_STATE,
			MM_Metadata::META_COUNTRY,
			MM_Metadata::META_RATING,
			MM_Metadata::META_GPS_LAT,
			MM_Metadata::META_GPS_LON,
			MM_Metadata::META_GPS_ALT,
		] as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}

	// -----------------------------------------------------------------------
	// Reading queued jobs (for dashboard display)
	// -----------------------------------------------------------------------

	/**
	 * Return all pending jobs from compress, meta, and webp directories.
	 *
	 * @return array[ 'compression' => [...], 'metadata' => [...], 'webp' => [...] ]
	 */
	public static function get_pending_jobs(): array {
		$result = [ 'compression' => [], 'metadata' => [], 'webp' => [] ];
		foreach ( [ 'compression', 'metadata', 'webp' ] as $type ) {
			$rows = MM_DB::get_jobs( [ 'job_type' => $type, 'status' => 'pending' ] );
			$result[$type] = $rows['jobs'] ?? [];
		}
		return $result;
	}

	// -----------------------------------------------------------------------
	// Re-queue from history
	// -----------------------------------------------------------------------

	/**
	 * Re-enqueue a job from the history table (e.g. to retry a failed job).
	 *
	 * @param int $job_id DB row ID.
	 * @return bool True if the job file was written.
	 */
	public static function requeue( int $job_id ): bool {
		$row = MM_DB::get_job( $job_id );
		if ( ! $row ) {
			return false;
		}

		if ( ! file_exists( $row->file_path ) ) {
			return false;
		}

		self::write_job(
			$row->job_type,
			(int) $row->attachment_id,
			$row->file_path,
			$row->size,
			[ 'trigger' => 'requeue', 'job_subtype' => 'requeue', 'requeue_source_id' => $job_id ]
		);

		return true;
	}
}
