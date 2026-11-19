<?php
/**
 * Metamanager WP-CLI Commands
 *
 * Provides CLI access to Metamanager operations:
 *
 *   wp metamanager compress <id|all>  — queue lossless compression (images + video remux)
 *   wp metamanager embed   <id|all>   — queue metadata embedding: write WP fields back into files
 *   wp metamanager import  <id|all>   — import embedded metadata into WP fields (all supported types)
 *   wp metamanager queue   status     — show live queue statistics
 *   wp metamanager scan               — batch-import + queue daemon jobs for un-synced library
 *   wp metamanager stats              — show compression savings statistics
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage Metamanager media processing jobs.
 */
class MM_CLI extends \WP_CLI_Command {

	// -----------------------------------------------------------------------
	// compress
	// -----------------------------------------------------------------------

	/**
	 * Queue lossless compression for one or all compressible attachments.
	 *
	 * Images (JPEG, PNG, WebP, GIF, TIFF) are recompressed losslessly.
	 * Video files are remuxed losslessly via ffmpeg.
	 * Audio files and PDFs do not have a compression step and are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to compress.  Omit (or pass "all") to process everything compressible.
	 *
	 * [--force]
	 * : Re-queue even if the file has already been compressed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager compress 42
	 *     wp metamanager compress all
	 *     wp metamanager compress all --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function compress( array $args, array $assoc_args ): void {
		$force  = isset( $assoc_args['force'] );
		$target = $args[0] ?? 'all';

		if ( 'all' === strtolower( $target ) ) {
			// Images + video are compressible; audio and PDF have no compression step.
			$compressible_mimes = array_merge(
				[ 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/tiff' ],
				MM_Metadata::VIDEO_MIME_TYPES
			);
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => $compressible_mimes,
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No compressible files found.' );
				return;
			}
		} else {
			$ids  = [ (int) $target ];
			$mime = (string) get_post_mime_type( $ids[0] );
			if ( ! wp_attachment_is_image( $ids[0] ) && ! MM_Metadata::is_video_mime( $mime ) ) {
				$reason = MM_Metadata::is_audio_mime( $mime ) || MM_Metadata::is_pdf_mime( $mime )
					? 'Audio files and PDFs have no compression step. Use `wp metamanager import` for metadata.'
					: 'Attachment is not a compressible file type.';
				\WP_CLI::error( $reason );
			}
		}

		$count    = 0;
		$skipped  = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Queuing compression', count( $ids ) );

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );
			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( ! $force && MM_Status::is_compressed( $id, 'full' ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( MM_Metadata::is_video_mime( $mime ) ) {
				// Video: single lossless remux job.
				MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'cli' ] );
			} else {
				// Image: queue all registered sizes.
				$meta = wp_get_attachment_metadata( $id ) ?: [];
				MM_Job_Queue::enqueue_all_sizes( $id, $meta, 'compression', [ 'trigger' => 'cli' ] );
			}

			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Queued compression for {$count} file(s). Skipped: {$skipped}." );
	}

	// -----------------------------------------------------------------------
	// import
	// -----------------------------------------------------------------------

	/**
	 * Import embedded metadata into WordPress fields.
	 *
	 * Reads embedded tags from the file using ExifTool and populates empty
	 * WordPress fields.  Works for images (EXIF/IPTC/XMP), video (QuickTime
	 * atoms, XMP), audio (ID3, Vorbis comments, XMP), and PDF (XMP).
	 * Existing user-set values are never overwritten.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to process.  Omit (or pass "all") to process all supported files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager import 42
	 *     wp metamanager import all
	 *
	 * @param array $args Positional arguments.
	 */
	public function import( array $args ): void {
		$target = $args[0] ?? 'all';

		if ( 'all' === strtolower( $target ) ) {
			$supported_mimes = array_merge(
				[ 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/tiff' ],
				MM_Metadata::VIDEO_MIME_TYPES,
				MM_Metadata::AUDIO_MIME_TYPES,
				MM_Metadata::PDF_MIME_TYPES
			);
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => $supported_mimes,
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No supported files found.' );
				return;
			}
		} else {
			$ids  = [ (int) $target ];
			$mime = (string) get_post_mime_type( $ids[0] );
			if (
				! wp_attachment_is_image( $ids[0] ) &&
				! MM_Metadata::is_av_mime( $mime ) &&
				! MM_Metadata::is_pdf_mime( $mime )
			) {
				\WP_CLI::error( "Attachment {$ids[0]} is not a supported file type for metadata import." );
			}
		}

		$count    = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing metadata', count( $ids ) );

		foreach ( $ids as $id ) {
			MM_Metadata::enqueue_import_job( (int) $id );
			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Queued metadata import for {$count} file(s). The daemon will read embedded tags and populate WordPress fields." );
	}

	// -----------------------------------------------------------------------
	// queue
	// -----------------------------------------------------------------------

	/**
	 * Show live job queue status.
	 *
	 * ## SUBCOMMANDS
	 *
	 *   status   Print pending job counts by type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager queue status
	 *
	 * @param array $args Positional arguments (first: subcommand).
	 */
	public function queue( array $args, array $assoc_args = [] ): void {
		$sub = $args[0] ?? 'status';

		switch ( $sub ) {
			case 'status':
				$pending = MM_Job_Queue::get_pending_jobs();
				$comp    = count( $pending['compression'] );
				$meta    = count( $pending['metadata'] );
				$total   = $comp + $meta;

				\WP_CLI\Utils\format_items(
					'table',
					[
						[ 'Type' => 'Compression', 'Pending' => $comp ],
						[ 'Type' => 'Metadata',    'Pending' => $meta ],
						[ 'Type' => 'Total',       'Pending' => $total ],
					],
					[ 'Type', 'Pending' ]
				);

				$compress_running = MM_Status::compress_daemon_running();
				$meta_running     = MM_Status::meta_daemon_running();
				\WP_CLI::line( 'Compress daemon: ' . ( $compress_running ? "\033[0;32mrunning\033[0m" : "\033[0;31mstopped\033[0m" ) );
				\WP_CLI::line( 'Metadata daemon: ' . ( $meta_running     ? "\033[0;32mrunning\033[0m" : "\033[0;31mstopped\033[0m" ) );
				break;

			case 'list':
				$result = MM_DB::get_jobs( [
					'search'   => (string) ( $assoc_args['search'] ?? '' ),
					'status'   => (string) ( $assoc_args['status'] ?? '' ),
					'orderby'  => (string) ( $assoc_args['orderby'] ?? 'id' ),
					'order'    => (string) ( $assoc_args['order'] ?? 'DESC' ),
					'per_page' => (int) ( $assoc_args['per_page'] ?? 20 ),
					'paged'    => (int) ( $assoc_args['paged'] ?? 1 ),
				] );
				$rows = [];
				foreach ( $result['jobs'] as $job ) {
					$rows[] = [
						'ID'          => $job->id,
						'Type'        => $job->job_type,
					'Image'       => $job->image_name,
					'Size'        => $job->size,
						'Status'      => $job->status,
						'Submitted'   => $job->submitted_at,
						'Completed'   => $job->completed_at ?? '',
						'Before'      => $job->bytes_before ? size_format( (int) $job->bytes_before ) : '',
						'After'       => $job->bytes_after ? size_format( (int) $job->bytes_after ) : '',
					];
				}
				\WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'Type', 'Image', 'Size', 'Status', 'Submitted', 'Completed', 'Before', 'After' ] );
				\WP_CLI::line( sprintf( 'Showing %d of %d jobs.', count( $rows ), $result['total'] ) );
				break;

			case 'get':
				$job_id = (int) ( $args[1] ?? 0 );
				if ( ! $job_id ) {
					\WP_CLI::error( 'Usage: wp metamanager queue get <job_id>' );
				}
				$job = MM_DB::get_job( $job_id );
				if ( ! $job ) {
					\WP_CLI::error( "Job {$job_id} not found." );
				}
				\WP_CLI\Utils\format_items( 'table', [ (array) $job ], array_keys( (array) $job ) );
				break;

			case 'requeue':
				$job_id = (int) ( $args[1] ?? 0 );
				if ( ! $job_id ) {
					\WP_CLI::error( 'Usage: wp metamanager queue requeue <job_id>' );
				}
				$job = MM_DB::get_job( $job_id );
				if ( ! $job ) {
					\WP_CLI::error( "Job {$job_id} not found." );
				}
				if ( 'failed' !== $job->status ) {
					\WP_CLI::error( "Job {$job_id} is not failed (status: {$job->status}). Only failed jobs can be requeued." );
				}
				if ( MM_Job_Queue::requeue( $job_id ) ) {
					\WP_CLI::success( "Job {$job_id} requeued." );
				} else {
					\WP_CLI::error( "Failed to requeue job {$job_id}." );
				}
				break;

			case 'clear':
				if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes' ) ) {
					\WP_CLI::error( 'Add --yes to confirm clearing all job history.' );
				}
				global $wpdb;
				$table = MM_DB::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
				\WP_CLI::success( 'Job history cleared. Media files are not affected.' );
				break;

			default:
				\WP_CLI::error( "Unknown subcommand: {$sub}. Valid: status, list, get, requeue, clear" );
		}
	}

	// -----------------------------------------------------------------------
	// scan
	// -----------------------------------------------------------------------

	/**
	 * Import metadata for every library file not yet processed by Metamanager.
	 *
	 * Scans all supported attachments (images, video, audio, PDF) that have
	 * never been synced: imports their embedded metadata into WordPress fields
	 * and queues daemon jobs to write WP fields back into the files.
	 * Already-synced files are skipped automatically.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager scan
	 *
	 * @param array $args Positional arguments (unused).
	 */
	public function scan( array $args ): void {
		$supported_mimes = array_merge(
			[ 'image' ],
			MM_Metadata::VIDEO_MIME_TYPES,
			MM_Metadata::AUDIO_MIME_TYPES,
			MM_Metadata::PDF_MIME_TYPES
		);

		$all_ids  = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => $supported_mimes,
			'post_status'    => 'inherit',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );
		$done_ids = MM_DB::get_ids_with_completed_job( 'metadata' );
		$ids      = array_values( array_diff( array_map( 'intval', $all_ids ), $done_ids ) );

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'All supported files already scanned — nothing to do.' );
			return;
		}

		$count    = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning library', count( $ids ) );

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );

			// Queue an import job so the daemon reads embedded tags and reports
			// back. The metadata write-back job is triggered by the result handler.
			MM_Metadata::enqueue_import_job( $id );

			// Queue compression separately — does not depend on metadata import.
			if ( wp_attachment_is_image( $id ) ) {
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'compression', [ 'trigger' => 'scan' ] );
			} elseif ( MM_Metadata::is_video_mime( $mime ) ) {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) ) {
					MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'scan' ] );
				}
			}

			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Scanned {$count} file(s): queued daemon import jobs. WordPress fields will be populated once the daemon processes each file." );
	}

	// -----------------------------------------------------------------------
	// embed
	// -----------------------------------------------------------------------

	/**
	 * Queue metadata embedding for one or all supported attachments.
	 *
	 * Queues daemon jobs to write the current WordPress field values back into
	 * the media files via ExifTool.  Works for images, video, audio, and PDF.
	 * Use this after editing fields in WordPress to push changes into files.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to embed.  Omit (or pass "all") to process all supported files.
	 *
	 * [--force]
	 * : Re-queue even if the file already has a completed metadata job.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager embed 42
	 *     wp metamanager embed all
	 *     wp metamanager embed all --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function embed( array $args, array $assoc_args = [] ): void {
		$force  = isset( $assoc_args['force'] );
		$target = $args[0] ?? 'all';

		if ( 'all' === strtolower( $target ) ) {
			$supported_mimes = array_merge(
				[ 'image' ],
				MM_Metadata::VIDEO_MIME_TYPES,
				MM_Metadata::AUDIO_MIME_TYPES,
				MM_Metadata::PDF_MIME_TYPES
			);
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => $supported_mimes,
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No supported files found.' );
				return;
			}
		} else {
			$ids  = [ (int) $target ];
			$mime = (string) get_post_mime_type( $ids[0] );
			if (
				! wp_attachment_is_image( $ids[0] ) &&
				! MM_Metadata::is_av_mime( $mime ) &&
				! MM_Metadata::is_pdf_mime( $mime )
			) {
				\WP_CLI::error( "Attachment {$ids[0]} is not a supported file type for metadata embedding." );
			}
		}

		$done_ids = $force ? [] : MM_DB::get_ids_with_completed_job( 'metadata' );

		$count    = 0;
		$skipped  = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Queuing embed jobs', count( $ids ) );

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );

			if ( ! $force && in_array( $id, $done_ids, true ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( wp_attachment_is_image( $id ) ) {
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => 'cli' ] );
				++$count;
			} elseif ( MM_Metadata::is_av_mime( $mime ) || MM_Metadata::is_pdf_mime( $mime ) ) {
				if ( MM_Metadata::can_write_meta( $mime ) ) {
					$file = get_attached_file( $id );
					if ( $file && file_exists( $file ) ) {
						MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => 'cli' ] );
						++$count;
					} else {
						++$skipped;
					}
				} else {
					++$skipped;
				}
			} else {
				++$skipped;
			}

			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Queued embed jobs for {$count} file(s). Skipped: {$skipped}." );
	}

	// -----------------------------------------------------------------------
	// stats
	// -----------------------------------------------------------------------

	/**
	 * Show compression savings statistics from the job history.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager stats
	 *
	 * @param array $args Positional arguments (unused).
	 */
	public function stats( array $args ): void {
		$stats = MM_DB::get_stats();

		$bytes_saved    = (int) ( $stats['bytes_saved']    ?? 0 );
		$bytes_original = (int) ( $stats['bytes_original'] ?? 0 );
		$pct            = $bytes_original > 0
			? round( $bytes_saved / $bytes_original * 100, 1 )
			: 0.0;

		\WP_CLI\Utils\format_items(
			'table',
			[
				[ 'Metric' => 'Total jobs',          'Value' => number_format( (int) $stats['total_jobs'] ) ],
				[ 'Metric' => 'Completed',           'Value' => number_format( (int) $stats['completed'] ) ],
				[ 'Metric' => 'Failed',              'Value' => number_format( (int) $stats['failed'] ) ],
				[ 'Metric' => 'Unique attachments',  'Value' => number_format( (int) $stats['unique_attachments'] ) ],
				[ 'Metric' => 'Bytes saved',         'Value' => size_format( $bytes_saved ) . " ({$pct}%)" ],
			],
			[ 'Metric', 'Value' ]
		);
	}

	// -----------------------------------------------------------------------
	// set-licensing
	// -----------------------------------------------------------------------

	/**
	 * Set licensing metadata (license, creditText, acquireLicensePage) on images.
	 *
	 * Mirrors the "Set Image Licensing" bulk action. Values are specified by
	 * the caller — nothing is auto-derived. The caller is the judgment loop.
	 *
	 * ## OPTIONS
	 *
	 * <ids>
	 * : Comma-separated attachment IDs, or "all" for every image in the library.
	 *
	 * [--license=<url>]
	 * : License URL (maps to schema license).
	 *
	 * [--credit=<text>]
	 * : Credit text / photographer name (maps to schema creditText).
	 *
	 * [--acquire=<url>]
	 * : Acquire-license page URL (maps to schema acquireLicensePage).
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager set-licensing 42,43 --license=https://creativecommons.org/licenses/by/4.0/ --credit="Jane Photographer" --acquire=https://example.com/contact/
	 *     wp metamanager set-licensing all --license=https://example.com/terms/ --credit="Acme Studio"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function set_licensing( array $args, array $assoc_args ): void {
		$license = (string) ( $assoc_args['license'] ?? '' );
		$credit  = (string) ( $assoc_args['credit'] ?? '' );
		$acquire = (string) ( $assoc_args['acquire'] ?? '' );

		if ( '' === $license && '' === $credit && '' === $acquire ) {
			\WP_CLI::error( 'Provide at least one of --license, --credit, or --acquire.' );
		}

		$target = $args[0] ?? '';
		if ( 'all' === strtolower( $target ) ) {
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
		} else {
			$ids = array_filter( array_map( 'intval', explode( ',', $target ) ) );
		}

		if ( empty( $ids ) ) {
			\WP_CLI::error( 'No matching images found.' );
		}

		$count = 0;
		foreach ( $ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			if ( '' !== $license ) {
				update_post_meta( $id, MM_Metadata::META_LICENSE, $license );
			}
			if ( '' !== $credit ) {
				update_post_meta( $id, MM_Metadata::META_CREDIT, $credit );
			}
			if ( '' !== $acquire ) {
				update_post_meta( $id, MM_Metadata::META_ACQUIRE_LICENSE_PAGE, $acquire );
			}
			++$count;
		}

		\WP_CLI::success( sprintf( 'Licensing applied to %d images.', $count ) );
	}

	// -----------------------------------------------------------------------
	// meta — per-object metadata fields
	// -----------------------------------------------------------------------

	/**
	 * Get or set metadata fields on attachments (Creator, Copyright, Owner, Headline, etc.).
	 *
	 * Mirrors the Batch Metadata page and per-image attachment editor fields.
	 * Only the provided fields are updated; blanks are skipped. Queue the
	 * daemon embed afterward with `wp metamanager embed <id>` to write fields
	 * back into the file.
	 *
	 * ## OPTIONS
	 *
	 * <get|set|history>
	 * : Subcommand: get, set, or history.
	 *
	 * [<ids>]
	 * : For get/history: attachment ID. For set: comma-separated IDs or "all".
	 *
	 * [--creator=<text>]
	 * [--copyright=<text>]
	 * [--owner=<text>]
	 * [--headline=<text>]
	 * [--credit=<text>]
	 * [--keywords=<text>]
	 * [--date=<YYYY-MM-DD>]
	 * [--city=<text>]
	 * [--state=<text>]
	 * [--country=<text>]
	 * [--rating=<0-5>]
	 * : For set: field value(s) to set.
	 *
	 * [--limit=<n>]
	 * : For history: number of entries (default 10).
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager meta get 42
	 *     wp metamanager meta set 42,43 --creator="Jane Doe" --copyright="2026 Acme"
	 *     wp metamanager meta set all --keywords="landscape; sunset"
	 *     wp metamanager meta history 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function meta( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? '';
		switch ( $sub ) {
			case 'set':
				$this->meta_set( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'get':
				$this->meta_get( array_slice( $args, 1 ) );
				break;
			case 'history':
				$this->meta_history( array_slice( $args, 1 ), $assoc_args );
				break;
			default:
				\WP_CLI::error( "Unknown subcommand: {$sub}. Valid: get, set, history" );
		}
	}

	/**
	 * Set metadata fields on attachments.
	 *
	 * @param array $args       Positional arguments (ids).
	 * @param array $assoc_args Named arguments (field values).
	 */
	private function meta_set( array $args, array $assoc_args ): void {
		$field_map = [
			'creator'   => MM_Metadata::META_CREATOR,
			'copyright' => MM_Metadata::META_COPYRIGHT,
			'owner'     => MM_Metadata::META_OWNER,
			'headline'  => MM_Metadata::META_HEADLINE,
			'credit'    => MM_Metadata::META_CREDIT,
			'keywords'  => MM_Metadata::META_KEYWORDS,
			'date'      => MM_Metadata::META_DATE,
			'city'      => MM_Metadata::META_CITY,
			'state'     => MM_Metadata::META_STATE,
			'country'   => MM_Metadata::META_COUNTRY,
			'rating'    => MM_Metadata::META_RATING,
		];

		$updates = [];
		foreach ( $field_map as $cli_key => $meta_key ) {
			if ( isset( $assoc_args[ $cli_key ] ) && '' !== $assoc_args[ $cli_key ] ) {
				$updates[ $meta_key ] = (string) $assoc_args[ $cli_key ];
			}
		}
		if ( empty( $updates ) ) {
			\WP_CLI::error( 'Provide at least one field (e.g. --creator, --copyright, --headline, ...).' );
		}

		$target = $args[0] ?? '';
		if ( 'all' === strtolower( $target ) ) {
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
		} else {
			$ids = array_filter( array_map( 'intval', explode( ',', $target ) ) );
		}
		if ( empty( $ids ) ) {
			\WP_CLI::error( 'No matching attachments found.' );
		}

		$count = 0;
		foreach ( $ids as $id ) {
			foreach ( $updates as $meta_key => $value ) {
				update_post_meta( $id, $meta_key, $value );
			}
			++$count;
		}
		\WP_CLI::success( sprintf( 'Metadata applied to %d attachments.', $count ) );
	}

	/**
	 * Read all Metamanager metadata fields for one attachment.
	 *
	 * @param array $args Positional arguments (id).
	 */
	private function meta_get( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			\WP_CLI::error( 'Usage: wp metamanager meta get <attachment_id>' );
		}
		if ( ! get_post( $id ) || 'attachment' !== get_post_type( $id ) ) {
			\WP_CLI::error( "Attachment {$id} not found." );
		}

		$meta_keys = [
			'Creator'          => MM_Metadata::META_CREATOR,
			'Copyright'        => MM_Metadata::META_COPYRIGHT,
			'Owner'            => MM_Metadata::META_OWNER,
			'Headline'         => MM_Metadata::META_HEADLINE,
			'Credit'           => MM_Metadata::META_CREDIT,
			'Keywords'         => MM_Metadata::META_KEYWORDS,
			'Date'             => MM_Metadata::META_DATE,
			'City'             => MM_Metadata::META_CITY,
			'State'            => MM_Metadata::META_STATE,
			'Country'          => MM_Metadata::META_COUNTRY,
			'Rating'           => MM_Metadata::META_RATING,
			'License'          => MM_Metadata::META_LICENSE,
			'AcquireLicense'   => MM_Metadata::META_ACQUIRE_LICENSE_PAGE,
			'GPS Latitude'     => MM_Metadata::META_GPS_LAT,
			'GPS Longitude'    => MM_Metadata::META_GPS_LON,
		];

		$rows = [];
		foreach ( $meta_keys as $label => $key ) {
			$rows[] = [ 'Field' => $label, 'Value' => (string) get_post_meta( $id, $key, true ) ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'Field', 'Value' ] );
	}

	/**
	 * Show metadata version history for an attachment.
	 *
	 * @param array $args       Positional arguments (id).
	 * @param array $assoc_args Named arguments (limit).
	 */
	private function meta_history( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			\WP_CLI::error( 'Usage: wp metamanager meta history <attachment_id>' );
		}
		if ( ! class_exists( 'MM_Metadata_History' ) ) {
			\WP_CLI::error( 'MM_Metadata_History is not available.' );
		}

		$limit = (int) ( $assoc_args['limit'] ?? 10 );
		$rows  = MM_Metadata_History::get_history( $id );
		if ( empty( $rows ) ) {
			\WP_CLI::line( 'No history entries found.' );
			return;
		}
		$rows = array_slice( $rows, 0, $limit );

		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'Version'      => $row['version'],
				'Created'      => $row['created_at'],
				'Source'       => $row['change_source'],
				'Meta values'  => wp_json_encode( $row['meta_values'] ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $items, [ 'Version', 'Created', 'Source', 'Meta values' ] );
	}

	// -----------------------------------------------------------------------
	// attachment — per-attachment status
	// -----------------------------------------------------------------------

	/**
	 * Show compression and metadata-sync status for one attachment.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager attachment 42
	 *
	 * @param array $args Positional arguments.
	 */
	public function attachment( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			\WP_CLI::error( 'Usage: wp metamanager attachment <attachment_id>' );
		}
		if ( ! get_post( $id ) || 'attachment' !== get_post_type( $id ) ) {
			\WP_CLI::error( "Attachment {$id} not found." );
		}

		$mime       = (string) get_post_mime_type( $id );
		$compressed = MM_DB::has_completed_compression( $id, 'full' );
		$synced     = MM_DB::has_any_completed_metadata( $id );
		$pending    = MM_DB::get_pending_job_types( $id );

		$rows = [
			[ 'Field' => 'ID',              'Value' => $id ],
			[ 'Field' => 'File',            'Value' => (string) get_attached_file( $id ) ],
			[ 'Field' => 'MIME',            'Value' => $mime ],
			[ 'Field' => 'Compressed',      'Value' => $compressed ? 'yes' : 'no' ],
			[ 'Field' => 'Meta synced',     'Value' => $synced ? 'yes' : 'no' ],
			[ 'Field' => 'Pending jobs',    'Value' => $pending ? implode( ', ', $pending ) : 'none' ],
		];
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'Field', 'Value' ] );
	}

	// -----------------------------------------------------------------------
	// status — full system status
	// -----------------------------------------------------------------------

	/**
	 * Output the full Metamanager status JSON (daemons, tools, queues, cron).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table or json (default json).
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager status
	 *     wp metamanager status --format=table
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'json' );
		$status = MM_Status::system_status();

		if ( 'table' === $format ) {
			$rows = [];
			foreach ( $status as $key => $value ) {
				$rows[] = [ 'Key' => $key, 'Value' => is_scalar( $value ) ? (string) $value : json_encode( $value ) ];
			}
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'Key', 'Value' ] );
		} else {
			\WP_CLI::line( json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}
}

\WP_CLI::add_command( 'metamanager', 'MM_CLI' );
