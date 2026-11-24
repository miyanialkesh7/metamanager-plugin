<?php
/**
 * MM_Media_Service — canonical media processing operations.
 *
 * Single source of truth for compress, metadata embed, licensing,
 * site-info injection, geocoding, and library scan. All surfaces call
 * this class: Media Processing page, WP-CLI, AI abilities, REST API,
 * and AJAX handlers. No surface implements its own logic.
 *
 * Every operation:
 * - Validates attachment IDs and MIME types
 * - Enforces FIELD_COMPAT per write-capability level
 * - Enforces all-or-nothing attribution (rule 21) for licensing
 * - Queues daemon jobs via MM_Job_Queue
 * - Returns a standard result array
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

class MM_Media_Service {

	/**
	 * Standard result shape returned by every operation.
	 *
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	private static function result( int $count = 0, int $skipped = 0, array $errors = [] ): array {
		return [ 'count' => $count, 'skipped' => $skipped, 'errors' => $errors ];
	}

	/**
	 * Queue compression + WebP generation for attachments.
	 *
	 * Images: enqueue_all_sizes with 'compress+webp' (JPEG/PNG) or
	 * 'compression' (WebP/AVIF/GIF/TIFF — already in target format or
	 * no WebP generation needed). Video: single remux job. Audio/PDF:
	 * skipped (no compression step).
	 *
	 * @param int[]  $ids     Attachment IDs.
	 * @param string $trigger Job trigger label.
	 * @param bool   $force   Re-queue even if already compressed.
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	public static function compress( array $ids, string $trigger = 'service', bool $force = false ): array {
		$count   = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $ids as $raw_id ) {
			$id   = (int) $raw_id;
			$mime = $id ? (string) get_post_mime_type( $id ) : '';

			if ( ! $id || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}

			$is_image = wp_attachment_is_image( $id );
			$is_video = MM_Metadata::is_video_mime( $mime );

			if ( ! $is_image && ! $is_video ) {
				++$skipped;
				continue;
			}

			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				$errors[] = "File not found for attachment {$id}";
				++$skipped;
				continue;
			}

			// Skip if already compressed (unless force).
			if ( ! $force && MM_Status::is_compressed( $id, 'full' ) ) {
				++$skipped;
				continue;
			}

			if ( $is_video ) {
				MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => $trigger ] );
				++$count;
				continue;
			}

			// Images: JPEG/PNG get compression + WebP generation.
			// Other formats (WebP, AVIF, GIF, TIFF) get compression only.
			$is_jpeg_png = in_array( $mime, [ 'image/jpeg', 'image/png' ], true );
			$types       = $is_jpeg_png ? 'compress+webp' : 'compression';

			$meta = wp_get_attachment_metadata( $id ) ?: [];
			MM_Job_Queue::enqueue_all_sizes( $id, $meta, $types, [ 'trigger' => $trigger ] );
			++$count;
		}

		return self::result( $count, $skipped, $errors );
	}

	/**
	 * Queue metadata embedding for attachments.
	 *
	 * If $fields is provided, sets the fields first (filtered by FIELD_COMPAT
	 * per MIME write-capability), then queues the embedding job. If $fields
	 * is empty, just queues embedding of existing WP meta values.
	 *
	 * @param int[]  $ids     Attachment IDs.
	 * @param string $trigger Job trigger label.
	 * @param array  $fields  Optional field map (meta_key => value).
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	public static function embed_metadata( array $ids, string $trigger = 'service', array $fields = [] ): array {
		$count   = 0;
		$skipped = 0;
		$errors  = [];

		// Build allowed fields map (field name => meta_key).
		$allowed = [
			MM_Metadata::META_CREATOR   => MM_Metadata::META_CREATOR,
			MM_Metadata::META_COPYRIGHT => MM_Metadata::META_COPYRIGHT,
			MM_Metadata::META_OWNER     => MM_Metadata::META_OWNER,
			MM_Metadata::META_HEADLINE  => MM_Metadata::META_HEADLINE,
			MM_Metadata::META_CREDIT    => MM_Metadata::META_CREDIT,
			MM_Metadata::META_KEYWORDS  => MM_Metadata::META_KEYWORDS,
			MM_Metadata::META_DATE      => MM_Metadata::META_DATE,
			MM_Metadata::META_CITY      => MM_Metadata::META_CITY,
			MM_Metadata::META_STATE     => MM_Metadata::META_STATE,
			MM_Metadata::META_COUNTRY   => MM_Metadata::META_COUNTRY,
			MM_Metadata::META_RATING    => MM_Metadata::META_RATING,
		];

		// Sanitize and filter fields.
		$clean_fields = [];
		foreach ( $allowed as $key ) {
			if ( isset( $fields[ $key ] ) && '' !== $fields[ $key ] ) {
				if ( MM_Metadata::META_RATING === $key ) {
					$clean_fields[ $key ] = min( 5, max( 0, (int) $fields[ $key ] ) );
				} else {
					$clean_fields[ $key ] = sanitize_text_field( (string) $fields[ $key ] );
				}
			}
		}

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( ! $id || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}

			$mime     = (string) get_post_mime_type( $id );
			$write_cap = MM_Metadata::write_capability( $mime );

			if ( 'read_only' === $write_cap ) {
				++$skipped;
				continue;
			}

			// Restrict fields to those writable for this capability level.
			$permitted = $clean_fields;
			if ( isset( MM_Metadata::FIELD_COMPAT[ $write_cap ] ) ) {
				$permitted = array_intersect_key( $permitted, array_flip( MM_Metadata::FIELD_COMPAT[ $write_cap ] ) );
			}

			// Set fields in WP meta.
			foreach ( $permitted as $key => $val ) {
				update_post_meta( $id, $key, $val );
			}

			// Queue embedding job.
			if ( wp_attachment_is_image( $id ) ) {
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => $trigger ] );
			} else {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) && MM_Metadata::can_write_meta( $mime ) ) {
					MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => $trigger ] );
				} else {
					++$skipped;
					continue;
				}
			}
			++$count;
		}

		return self::result( $count, $skipped, $errors );
	}

	/**
	 * Set attribution fields on images (all-or-nothing, rule 21).
	 *
	 * The five attribution fields (creator, creditText, copyrightNotice,
	 * license, acquireLicensePage) are a group: once one is set, Google
	 * requires the rest. This method enforces that all five are provided
	 * together or not at all.
	 *
	 * @param int[]  $ids    Attachment IDs.
	 * @param array  $fields Must contain all five keys: creator, credit,
	 *                       copyright, license, acquire. All non-empty.
	 * @param string $trigger Job trigger label.
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	public static function set_licensing( array $ids, array $fields, string $trigger = 'service' ): array {
		// All-or-nothing validation (rule 21).
		$required = [ 'creator', 'credit', 'copyright', 'license', 'acquire' ];
		$provided = [];
		foreach ( $required as $key ) {
			$val = trim( (string) ( $fields[ $key ] ?? '' ) );
			if ( '' !== $val ) {
				$provided[ $key ] = $val;
			}
		}

		if ( empty( $provided ) ) {
			return self::result( 0, 0, [ 'No attribution fields provided.' ] );
		}

		if ( count( $provided ) < 5 ) {
			$missing = array_diff( $required, array_keys( $provided ) );
			return self::result( 0, 0, [
				'All-or-nothing attribution: provide all five fields (creator, credit, copyright, license, acquire) or none. Missing: ' . implode( ', ', $missing ),
			] );
		}

		$count   = 0;
		$skipped = 0;
		$errors  = [];

		$meta_map = [
			'creator'   => MM_Metadata::META_CREATOR,
			'credit'    => MM_Metadata::META_CREDIT,
			'copyright' => MM_Metadata::META_COPYRIGHT,
			'license'   => MM_Metadata::META_LICENSE,
			'acquire'   => MM_Metadata::META_ACQUIRE_LICENSE_PAGE,
		];

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( ! $id || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}
			if ( ! wp_attachment_is_image( $id ) ) {
				++$skipped;
				continue;
			}

			foreach ( $meta_map as $field => $meta_key ) {
				update_post_meta( $id, $meta_key, $provided[ $field ] );
			}

			// Queue metadata sync (writes values to file via ExifTool).
			MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => $trigger ] );
			++$count;
		}

		return self::result( $count, $skipped, $errors );
	}

	/**
	 * Inject site provenance (Publisher + Website) into metadata.
	 *
	 * Neutral provenance — never sets Creator, Copyright, or Owner.
	 *
	 * @param int[]  $ids     Attachment IDs.
	 * @param string $trigger Job trigger label.
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	public static function inject_site_info( array $ids, string $trigger = 'service' ): array {
		$count   = 0;
		$skipped = 0;

		foreach ( $ids as $raw_id ) {
			$id   = (int) $raw_id;
			$mime = $id ? (string) get_post_mime_type( $id ) : '';

			if ( ! $id || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}

			$is_image = wp_attachment_is_image( $id );
			$is_av    = MM_Metadata::is_av_mime( $mime );
			$is_pdf   = MM_Metadata::is_pdf_mime( $mime );

			if ( ! $is_image && ! $is_av && ! $is_pdf ) {
				++$skipped;
				continue;
			}

			if ( $is_image ) {
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => $trigger ] );
			} else {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) && MM_Metadata::can_write_meta( $mime ) ) {
					MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => $trigger ] );
				} else {
					++$skipped;
					continue;
				}
			}
			++$count;
		}

		return self::result( $count, $skipped );
	}

	/**
	 * Reverse-geocode GPS coordinates to address fields.
	 *
	 * Only populates empty fields — never overwrites existing values.
	 * Queues metadata sync after updating WP meta (audit bug fix: values
	 * were written to WP meta but never synced to the file).
	 *
	 * @param int[]  $ids     Attachment IDs.
	 * @param string $trigger Job trigger label.
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	public static function geocode( array $ids, string $trigger = 'service' ): array {
		$biz = MM_Site_Settings::get_instance()->all_business();
		$key = (string) ( $biz['google_maps_api_key'] ?? '' );
		if ( '' === $key ) {
			return self::result( 0, 0, [ 'No Google Maps API key set in business profile.' ] );
		}

		$count   = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( ! $id || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}
			if ( ! wp_attachment_is_image( $id ) ) {
				++$skipped;
				continue;
			}

			$att_meta = wp_get_attachment_metadata( $id );
			$img_meta = $att_meta['image_meta'] ?? [];
			$lat      = (float) ( $img_meta['latitude'] ?? 0 );
			$lng      = (float) ( $img_meta['longitude'] ?? 0 );

			if ( ! $lat && ! $lng ) {
				++$skipped;
				continue;
			}

			$city    = trim( (string) get_post_meta( $id, MM_Metadata::META_CITY, true ) );
			$state   = trim( (string) get_post_meta( $id, MM_Metadata::META_STATE, true ) );
			$country = trim( (string) get_post_meta( $id, MM_Metadata::META_COUNTRY, true ) );

			// Only geocode if at least one address field is empty.
			if ( '' !== $city && '' !== $state && '' !== $country ) {
				++$skipped;
				continue;
			}

			$geo = MM_Site_Settings::reverse_geocode( $key, $lat, $lng );
			if ( is_wp_error( $geo ) ) {
				$errors[] = "Geocode failed for {$id}: " . $geo->get_error_message();
				++$skipped;
				continue;
			}

			$updated = false;
			if ( '' === $city && ! empty( $geo['city'] ) ) {
				update_post_meta( $id, MM_Metadata::META_CITY, $geo['city'] );
				$updated = true;
			}
			if ( '' === $state && ! empty( $geo['state'] ) ) {
				update_post_meta( $id, MM_Metadata::META_STATE, $geo['state'] );
				$updated = true;
			}
			if ( '' === $country && ! empty( $geo['country'] ) ) {
				update_post_meta( $id, MM_Metadata::META_COUNTRY, $geo['country'] );
				$updated = true;
			}

			if ( $updated ) {
				// Queue metadata sync — values must reach the file (audit bug fix).
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => $trigger ] );
				++$count;
			} else {
				++$skipped;
			}
		}

		return self::result( $count, $skipped, $errors );
	}

	/**
	 * Scan library: queue import + compression + WebP for unprocessed attachments.
	 *
	 * @param int[]  $ids     Attachment IDs (pre-filtered to unprocessed).
	 * @param string $trigger Job trigger label.
	 * @return array{count: int, skipped: int, errors: string[]}
	 */
	public static function scan( array $ids, string $trigger = 'service' ): array {
		$count   = 0;
		$skipped = 0;

		foreach ( $ids as $raw_id ) {
			$id   = (int) $raw_id;
			$mime = $id ? (string) get_post_mime_type( $id ) : '';

			if ( ! $id || ! get_post( $id ) ) {
				++$skipped;
				continue;
			}

			if ( wp_attachment_is_image( $id ) ) {
				// Images: compression + WebP for JPEG/PNG, compression for others.
				$is_jpeg_png = in_array( $mime, [ 'image/jpeg', 'image/png' ], true );
				$types       = $is_jpeg_png ? 'compress+webp' : 'compression';
				MM_Job_Queue::enqueue_all_sizes( $id, [], $types, [ 'trigger' => $trigger ] );
				MM_Metadata::enqueue_import_job( $id );
				++$count;
			} elseif ( MM_Metadata::is_video_mime( $mime ) ) {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) ) {
					MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => $trigger ] );
					MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => $trigger ] );
					++$count;
				} else {
					++$skipped;
				}
			} elseif ( MM_Metadata::is_audio_mime( $mime ) || MM_Metadata::is_pdf_mime( $mime ) ) {
				if ( MM_Metadata::can_write_meta( $mime ) ) {
					$file = get_attached_file( $id );
					if ( $file && file_exists( $file ) ) {
						MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => $trigger ] );
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
		}

		return self::result( $count, $skipped );
	}
}
