<?php
/**
 * Standalone result processor — called by daemons via wp eval-file.
 *
 * Usage: wp eval-file process-result.php -- <result_file_path>
 *
 * Reads a result JSON from completed/ or failed/, logs to DB, applies
 * import/verify results, queues follow-up jobs. Called directly by the
 * daemon after each job — no cron dependency.
 */

if ( PHP_SAPI !== 'cli' ) {
	echo "CLI only\n";
	exit( 1 );
}

$argv_copy = array_values( array_filter( $argv, fn( $a ) => '--' !== $a ) );
$result_file = '';
foreach ( $argv_copy as $arg ) {
	if ( is_string( $arg ) && str_ends_with( $arg, '.json' ) ) {
		$result_file = $arg;
		break;
	}
}

if ( ! $result_file || ! file_exists( $result_file ) ) {
	fwrite( STDERR, "Usage: process-result.php -- <result_file_path>\n" );
	exit( 1 );
}

$raw = file_get_contents( $result_file );
$job = $raw ? json_decode( $raw, true ) : null;

if ( ! is_array( $job ) ) {
	$backup = $result_file . '.unparseable';
	rename( $result_file, $backup );
	error_log( sprintf( '[Metamanager] Unparseable result file renamed: %s', $backup ) );
	exit( 1 );
}

// Determine status from directory.
$status = ( false !== strpos( $result_file, '/failed/' ) ) ? 'failed' : 'completed';
$job['status'] = $status;

// Log to DB — fail loudly, no retry.
if ( ! MM_DB::log_job( $job ) ) {
	$failed_log_dir = MM_JOB_ROOT . '/failed-log/';
	if ( ! is_dir( $failed_log_dir ) ) {
		mkdir( $failed_log_dir, 0755, true );
	}
	rename( $result_file, $failed_log_dir . basename( $result_file ) );
	error_log( sprintf(
		'[Metamanager] ERROR: DB insert failed for %s. Moved to failed-log.',
		basename( $result_file )
	) );
	exit( 1 );
}

// Import result: apply embedded tags to WP post meta, queue write-back.
if ( 'import' === ( $job['job_type'] ?? '' ) && 'completed' === $status ) {
	$att_id  = (int) ( $job['attachment_id'] ?? 0 );
	$tags    = is_array( $job['embedded_tags'] ?? null ) ? $job['embedded_tags'] : [];
	$trigger = (string) ( $job['trigger'] ?? '' );
	if ( $att_id > 0 ) {
		if ( 'verify' === $trigger ) {
			MM_Metadata::apply_verify_result( $att_id, $tags );
		} else {
			MM_Metadata::apply_import_result( $att_id, $tags );
			$file = get_attached_file( $att_id );
			$mime = (string) get_post_mime_type( $att_id );
			if ( $file && file_exists( $file ) && MM_Metadata::can_write_meta( $mime ) ) {
				MM_Job_Queue::write_job( 'metadata', $att_id, $file, 'full', [ 'trigger' => 'import' ] );
			}
			if ( wp_attachment_is_image( $att_id ) ) {
				$meta = wp_get_attachment_metadata( $att_id );
				if ( is_array( $meta ) ) {
					MM_Job_Queue::enqueue_all_sizes( $att_id, $meta, 'metadata', [ 'trigger' => 'import' ] );
				}
			}
		}
	}
}

// Metadata write-back completed: queue verification read-back (full size only).
if ( 'metadata' === ( $job['job_type'] ?? '' )
	&& 'full' === ( $job['size'] ?? '' )
	&& 'completed' === $status ) {
	$att_id = (int) ( $job['attachment_id'] ?? 0 );
	$file   = $att_id > 0 ? get_attached_file( $att_id ) : '';
	if ( $att_id > 0 && $file && file_exists( $file ) && MM_Status::exiftool_available() ) {
		MM_Job_Queue::write_job( 'import', $att_id, (string) $file, 'full', [ 'trigger' => 'verify' ] );
	}
}

// Delete result file after successful DB write.
wp_delete_file( $result_file );

echo "Processed: {$result_file} ({$status})\n";
exit( 0 );
