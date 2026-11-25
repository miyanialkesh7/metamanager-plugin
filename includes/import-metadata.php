<?php
/**
 * Import embedded metadata from a media file into WordPress (file → WP direction).
 *
 * Called by the meta daemon via wp eval-file:
 *   wp eval-file import-metadata.php -- <attachment_id> <trigger>
 *   (embedded tags JSON passed via STDIN)
 *
 * Merge semantics: only fills EMPTY WordPress fields. Existing user-set
 * values are never overwritten. First non-empty file value wins.
 *
 * @package Metamanager
 */

if ( PHP_SAPI !== 'cli' ) {
	echo "CLI only\n";
	exit( 1 );
}

// Parse args.
$attachment_id = 0;
$trigger       = 'import';
if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $arg ) {
		if ( is_numeric( $arg ) && ! $attachment_id ) {
			$attachment_id = (int) $arg;
		} elseif ( is_string( $arg ) && '' !== $arg ) {
			$trigger = sanitize_key( $arg );
		}
	}
}

if ( ! $attachment_id ) {
	fwrite( STDERR, "Usage: import-metadata.php -- <attachment_id> [trigger]\n" );
	exit( 1 );
}

// Read embedded tags JSON from STDIN.
$stdin = stream_get_contents( STDIN );
$tags  = $stdin ? json_decode( $stdin, true ) : null;
if ( ! is_array( $tags ) ) {
	fwrite( STDERR, "No embedded tags JSON on STDIN\n" );
	exit( 1 );
}

if ( ! class_exists( 'MM_Metadata' ) ) {
	fwrite( STDERR, "MM_Metadata not loaded\n" );
	exit( 1 );
}

// Apply import result to WP meta.
// 'verify' trigger → comparison only (no WP changes).
// Other triggers → fill empty fields only.
if ( 'verify' === $trigger ) {
	MM_Metadata::apply_verify_result( $attachment_id, $tags );
	echo "Verified {$attachment_id}\n";
	exit( 0 );
}

MM_Metadata::apply_import_result( $attachment_id, $tags );

// Queue metadata write-back (WP→file) to sync any newly populated fields.
$file = get_attached_file( $attachment_id );
$mime = (string) get_post_mime_type( $attachment_id );
if ( $file && file_exists( $file ) && MM_Metadata::can_write_meta( $mime ) ) {
	MM_Job_Queue::write_job( 'metadata', $attachment_id, $file, 'full', [ 'trigger' => 'import', 'job_subtype' => 'import' ] );
}

// Queue all image sizes for metadata sync.
if ( wp_attachment_is_image( $attachment_id ) ) {
	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( is_array( $meta ) ) {
		MM_Job_Queue::enqueue_all_sizes( $attachment_id, $meta, 'metadata', [ 'trigger' => 'import', 'job_subtype' => 'import' ] );
	}
}

echo "Imported tags into WP meta for attachment {$attachment_id}\n";
exit( 0 );
