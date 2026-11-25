<?php
/**
 * Write WordPress metadata into a media file (WP → file direction).
 *
 * Called by the meta daemon via wp eval-file:
 *   wp eval-file write-metadata.php -- <attachment_id> <file_path>
 *
 * Merge semantics: only fields present in the job payload are written.
 * Existing file tags not named in the payload are preserved. If a WP field
 * is empty it is NOT written, so clearing a field in WordPress does not
 * clear it in the file — the file retains its last non-empty value.
 *
 * Format categories and tag mappings are defined in JOB_QUEUE_SPEC.md.
 *
 * @package Metamanager
 */

if ( PHP_SAPI !== 'cli' ) {
	echo "CLI only\n";
	exit( 1 );
}

// Parse args — wp eval-file passes them as $args (not $argv).
$attachment_id = 0;
$file_path     = '';
if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $arg ) {
		if ( is_numeric( $arg ) && ! $attachment_id ) {
			$attachment_id = (int) $arg;
		} elseif ( is_string( $arg ) && file_exists( $arg ) ) {
			$file_path = $arg;
		}
	}
}

if ( ! $attachment_id || ! $file_path ) {
	fwrite( STDERR, "Usage: write-metadata.php -- <attachment_id> <file_path>\n" );
	exit( 1 );
}

// Load plugin classes if not already loaded (daemon may call this standalone).
if ( ! class_exists( 'MM_Metadata' ) ) {
	fwrite( STDERR, "MM_Metadata not loaded — is the plugin active?\n" );
	exit( 1 );
}

// Determine format category from extension.
$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
$category = match ( $ext ) {
	'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'tiff', 'tif' => 'image',
	'mp3'                                                     => 'mp3',
	'mp4', 'm4v', 'm4a', 'mov', '3gp', '3gpp', '3g2'          => 'quicktime',
	'ogg', 'oga', 'flac'                                       => 'vorbis',
	'avi', 'wav', 'wmv', 'wma', 'pdf'                          => 'xmp_only',
	'mkv', 'webm', 'ogv'                                       => 'read_only',
	default                                                    => 'image',
};

if ( 'read_only' === $category ) {
	echo "Read-only format — metadata embedding skipped\n";
	exit( 0 );
}

// Get the fields to write from the attachment's WP meta.
// Only non-empty values are written (merge semantics).
$fields = MM_Metadata::get_fields_for_job( $attachment_id );
if ( empty( $fields ) ) {
	echo "No metadata fields to embed — skipped\n";
	exit( 0 );
}

// Build ExifTool tag arguments per format category.
$args_list = [];

// Helper: add a tag=value pair if value is non-empty.
$add_tag = function( string $tag, string $value ) use ( &$args_list ): void {
	if ( '' !== $value ) {
		$args_list[] = "-{$tag}={$value}";
	}
};

// Helper: append keywords with += (merge with existing).
$add_keywords = function( string $tag, string $value ) use ( &$args_list ): void {
	if ( '' !== $value ) {
		// Split on "; " or ";" or space.
		$parts = preg_split( '/;\s*|\s+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $parts as $part ) {
			$args_list[] = "-{$tag}+={$part}";
		}
	}
};

switch ( $category ) {
	case 'image':
		$add_tag( 'Title', $fields['Title'] ?? '' );
		$add_tag( 'IPTC:ObjectName', $fields['Title'] ?? '' );
		$add_tag( 'XMP:Title', $fields['Title'] ?? '' );

		$add_tag( 'EXIF:ImageDescription', $fields['Description'] ?? '' );
		$add_tag( 'IPTC:Caption-Abstract', $fields['Description'] ?? '' );
		$add_tag( 'XMP:Description', $fields['Description'] ?? '' );

		if ( ! empty( $fields['Caption'] ) ) {
			$add_tag( 'IPTC:Caption-Abstract', $fields['Caption'] );
			$add_tag( 'XMP:Caption', $fields['Caption'] );
		}

		$add_tag( 'XMP:AltTextAccessibility', $fields['AltText'] ?? '' );

		$add_tag( 'EXIF:Artist', $fields['Creator'] ?? '' );
		$add_tag( 'IPTC:By-line', $fields['Creator'] ?? '' );
		$add_tag( 'XMP:Creator', $fields['Creator'] ?? '' );

		$add_tag( 'EXIF:Copyright', $fields['Copyright'] ?? '' );
		$add_tag( 'IPTC:CopyrightNotice', $fields['Copyright'] ?? '' );
		$add_tag( 'XMP:Rights', $fields['Copyright'] ?? '' );

		$add_tag( 'EXIF:OwnerName', $fields['Owner'] ?? '' );
		$add_tag( 'XMP:Owner', $fields['Owner'] ?? '' );

		$add_tag( 'XMP:Publisher', $fields['Publisher'] ?? '' );
		$add_tag( 'XMP:WebStatement', $fields['Website'] ?? '' );

		// IPTC:Source gets Website if present, else Publisher.
		$source = $fields['Website'] ?? ( $fields['Publisher'] ?? '' );
		$add_tag( 'IPTC:Source', $source );

		$add_tag( 'IPTC:Headline', $fields['Headline'] ?? '' );
		$add_tag( 'XMP:Headline', $fields['Headline'] ?? '' );

		$add_tag( 'IPTC:Credit', $fields['Credit'] ?? '' );
		$add_tag( 'XMP:Credit', $fields['Credit'] ?? '' );

		$add_keywords( 'IPTC:Keywords', $fields['Keywords'] ?? '' );
		$add_keywords( 'XMP:Subject', $fields['Keywords'] ?? '' );

		$add_tag( 'EXIF:DateTimeOriginal', $fields['DateCreated'] ?? '' );
		$add_tag( 'IPTC:DateCreated', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:DateCreated', $fields['DateCreated'] ?? '' );

		$add_tag( 'XMP:Rating', $fields['Rating'] ?? '' );

		$add_tag( 'IPTC:City', $fields['City'] ?? '' );
		$add_tag( 'XMP:City', $fields['City'] ?? '' );

		$add_tag( 'IPTC:Province-State', $fields['State'] ?? '' );
		$add_tag( 'XMP:State', $fields['State'] ?? '' );

		$add_tag( 'IPTC:Country-PrimaryLocationName', $fields['Country'] ?? '' );
		$add_tag( 'XMP:Country', $fields['Country'] ?? '' );
		break;

	case 'mp3':
		$add_tag( 'ID3:Title', $fields['Title'] ?? '' );
		$add_tag( 'XMP:Title', $fields['Title'] ?? '' );
		$add_tag( 'ID3:Artist', $fields['Creator'] ?? '' );
		$add_tag( 'XMP:Creator', $fields['Creator'] ?? '' );
		$add_tag( 'ID3:Copyright', $fields['Copyright'] ?? '' );
		$add_tag( 'XMP:Rights', $fields['Copyright'] ?? '' );
		$add_tag( 'ID3:Comment', $fields['Description'] ?? '' );
		$add_tag( 'XMP:Description', $fields['Description'] ?? '' );
		$add_tag( 'ID3:Band', $fields['Publisher'] ?? '' );
		$add_tag( 'XMP:Publisher', $fields['Publisher'] ?? '' );
		$add_tag( 'XMP:WebStatement', $fields['Website'] ?? '' );
		$add_tag( 'XMP:Headline', $fields['Headline'] ?? '' );
		$add_tag( 'XMP:Credit', $fields['Credit'] ?? '' );
		$add_tag( 'ID3:Year', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:DateCreated', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:Rating', $fields['Rating'] ?? '' );
		$add_keywords( 'ID3:Genre', $fields['Keywords'] ?? '' );
		$add_keywords( 'XMP:Subject', $fields['Keywords'] ?? '' );
		break;

	case 'quicktime':
		$add_tag( 'QuickTime:Title', $fields['Title'] ?? '' );
		$add_tag( 'XMP:Title', $fields['Title'] ?? '' );
		$add_tag( 'QuickTime:Author', $fields['Creator'] ?? '' );
		$add_tag( 'XMP:Creator', $fields['Creator'] ?? '' );
		$add_tag( 'QuickTime:Copyright', $fields['Copyright'] ?? '' );
		$add_tag( 'XMP:Rights', $fields['Copyright'] ?? '' );
		$add_tag( 'QuickTime:Description', $fields['Description'] ?? '' );
		$add_tag( 'XMP:Description', $fields['Description'] ?? '' );
		$add_tag( 'XMP:Publisher', $fields['Publisher'] ?? '' );
		$add_tag( 'XMP:WebStatement', $fields['Website'] ?? '' );
		$add_tag( 'XMP:Headline', $fields['Headline'] ?? '' );
		$add_tag( 'XMP:Credit', $fields['Credit'] ?? '' );
		$add_tag( 'QuickTime:CreateDate', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:DateCreated', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:Rating', $fields['Rating'] ?? '' );
		$add_tag( 'XMP:City', $fields['City'] ?? '' );
		$add_tag( 'XMP:State', $fields['State'] ?? '' );
		$add_tag( 'XMP:Country', $fields['Country'] ?? '' );
		$add_keywords( 'QuickTime:Keywords', $fields['Keywords'] ?? '' );
		$add_keywords( 'XMP:Subject', $fields['Keywords'] ?? '' );
		break;

	case 'vorbis':
		$add_tag( 'Title', $fields['Title'] ?? '' );
		$add_tag( 'Artist', $fields['Creator'] ?? '' );
		$add_tag( 'Copyright', $fields['Copyright'] ?? '' );
		$add_tag( 'Description', $fields['Description'] ?? '' );
		$add_tag( 'Organization', $fields['Publisher'] ?? '' );
		$add_tag( 'Date', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:Headline', $fields['Headline'] ?? '' );
		$add_tag( 'XMP:WebStatement', $fields['Website'] ?? '' );
		$add_keywords( 'Genre', $fields['Keywords'] ?? '' );
		$add_keywords( 'XMP:Subject', $fields['Keywords'] ?? '' );
		break;

	case 'xmp_only':
		$add_tag( 'XMP:Title', $fields['Title'] ?? '' );
		$add_tag( 'XMP:Creator', $fields['Creator'] ?? '' );
		$add_tag( 'XMP:Rights', $fields['Copyright'] ?? '' );
		$add_tag( 'XMP:Description', $fields['Description'] ?? '' );
		$add_tag( 'XMP:Publisher', $fields['Publisher'] ?? '' );
		$add_tag( 'XMP:WebStatement', $fields['Website'] ?? '' );
		$add_tag( 'XMP:Headline', $fields['Headline'] ?? '' );
		$add_tag( 'XMP:Credit', $fields['Credit'] ?? '' );
		$add_tag( 'XMP:DateCreated', $fields['DateCreated'] ?? '' );
		$add_tag( 'XMP:Rating', $fields['Rating'] ?? '' );
		$add_tag( 'XMP:City', $fields['City'] ?? '' );
		$add_tag( 'XMP:State', $fields['State'] ?? '' );
		$add_tag( 'XMP:Country', $fields['Country'] ?? '' );
		$add_keywords( 'XMP:Subject', $fields['Keywords'] ?? '' );
		break;
}

if ( empty( $args_list ) ) {
	echo "No metadata fields to embed — skipped\n";
	exit( 0 );
}

// Find exiftool.
$exiftool = '';
foreach ( [ '/usr/bin/exiftool', '/usr/local/bin/exiftool', '/opt/homebrew/bin/exiftool' ] as $path ) {
	if ( is_executable( $path ) ) {
		$exiftool = $path;
		break;
	}
}
if ( ! $exiftool ) {
	fwrite( STDERR, "exiftool not found\n" );
	exit( 1 );
}

// Build command.
$cmd = [ $exiftool, '-overwrite_original' ];
if ( 'image' === $category ) {
	$cmd[] = '-charset';
	$cmd[] = 'iptc=UTF8';
}
$cmd = array_merge( $cmd, $args_list, [ '--', $file_path ] );

// Run exiftool.
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- required for exiftool
$proc = proc_open( $cmd, [
	0 => [ 'pipe', 'r' ],
	1 => [ 'pipe', 'w' ],
	2 => [ 'pipe', 'w' ],
], $pipes );

if ( ! is_resource( $proc ) ) {
	fwrite( STDERR, "Failed to run exiftool\n" );
	exit( 1 );
}

fclose( $pipes[0] );
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$exit_code = proc_close( $proc );

if ( 0 !== $exit_code ) {
	fwrite( STDERR, "exiftool failed (exit {$exit_code}): {$stderr}\n" );
	exit( 1 );
}

// Sibling WebP sync: re-apply same tags to <file>.webp if it exists.
if ( in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) {
	$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );
	if ( $webp_path !== $file_path && file_exists( $webp_path ) ) {
		$webp_cmd = array_merge( [ $exiftool, '-overwrite_original' ], $args_list, [ '--', $webp_path ] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- required for exiftool
$webp_proc = proc_open( $webp_cmd, [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		], $webp_pipes );
		if ( is_resource( $webp_proc ) ) {
			fclose( $webp_pipes[0] );
			stream_get_contents( $webp_pipes[1] );
			stream_get_contents( $webp_pipes[2] );
			fclose( $webp_pipes[1] );
			fclose( $webp_pipes[2] );
			proc_close( $webp_proc );
		}
	}
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not HTML
	echo "Metadata written to {$file_path} (" . count( $args_list ) . " tags)\n";
exit( 0 );
