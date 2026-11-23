<?php
/**
 * Standalone WebP registration — called by the compress daemon via wp eval-file.
 *
 * Usage: wp eval-file register-webp.php -- <attachment_id> <webp_path>
 *
 * Registers the generated .webp as a WP attachment, copies metadata from the
 * original, and generates WP metadata (sizes). Called directly by
 * process_webp_job after cwebp completes — no cron dependency.
 */

if ( PHP_SAPI !== 'cli' ) {
	echo "CLI only\n";
	exit( 1 );
}

// Parse args after "--"
$argv_copy = array_values( array_filter( $argv, fn( $a ) => '--' !== $a ) );
$original_id = 0;
$webp_path   = '';

foreach ( $argv_copy as $i => $arg ) {
	if ( 0 === strpos( $arg, '--' ) ) continue;
	if ( preg_match( '/^\d+$/', $arg ) && ! $original_id ) {
		$original_id = (int) $arg;
	} elseif ( is_string( $arg ) && str_ends_with( $arg, '.webp' ) ) {
		$webp_path = $arg;
	}
}

if ( ! $original_id || ! $webp_path ) {
	fwrite( STDERR, "Usage: register-webp.php -- <attachment_id> <webp_path>\n" );
	exit( 1 );
}

if ( ! file_exists( $webp_path ) ) {
	fwrite( STDERR, "WebP not found: {$webp_path}\n" );
	exit( 1 );
}

if ( ! function_exists( 'mm_register_webp_attachment' ) ) {
	fwrite( STDERR, "mm_register_webp_attachment not available — is MetaManager active?\n" );
	exit( 1 );
}

mm_register_webp_attachment( $original_id, $webp_path );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only script.
echo "Registered WebP: {$webp_path} (parent: {$original_id})\n";
exit( 0 );
