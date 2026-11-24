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

// Parse args — wp eval-file passes them as $args (not $argv).
error_log( '[register-webp] START: args=' . json_encode( $args ?? 'NOT SET' ) . ' argv=' . json_encode( $argv ?? 'NOT SET' ) );

$original_id = 0;
$webp_path   = '';

if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $arg ) {
		if ( is_string( $arg ) && preg_match( '/^\d+$/', $arg ) && ! $original_id ) {
			$original_id = (int) $arg;
		} elseif ( is_string( $arg ) && str_ends_with( $arg, '.webp' ) ) {
			$webp_path = $arg;
		}
	}
}

error_log( "[register-webp] PARSED: original_id={$original_id} webp_path={$webp_path}" );

if ( ! $original_id || ! $webp_path ) {
	error_log( '[register-webp] FAIL: missing args' );
	fwrite( STDERR, "Usage: register-webp.php -- <attachment_id> <webp_path>\n" );
	exit( 1 );
}

if ( ! file_exists( $webp_path ) ) {
	error_log( "[register-webp] FAIL: webp not found: {$webp_path}" );
	fwrite( STDERR, "WebP not found: {$webp_path}\n" );
	exit( 1 );
}

if ( ! function_exists( 'mm_register_webp_attachment' ) ) {
	error_log( '[register-webp] FAIL: mm_register_webp_attachment not available' );
	fwrite( STDERR, "mm_register_webp_attachment not available — is MetaManager active?\n" );
	exit( 1 );
}

error_log( "[register-webp] CALLING mm_register_webp_attachment({$original_id}, {$webp_path})" );
mm_register_webp_attachment( $original_id, $webp_path );
error_log( "[register-webp] RETURNED from mm_register_webp_attachment" );

// Verify attachment was created.
global $wpdb;
$check = $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/webp' AND post_parent = %d",
	$original_id
) );
error_log( "[register-webp] VERIFY: attachment_id={$check}" );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only script.
echo "Registered WebP: {$webp_path} (parent: {$original_id}, attachment: {$check})\n";
exit( 0 );
