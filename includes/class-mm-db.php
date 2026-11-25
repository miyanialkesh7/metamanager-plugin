<?php
/**
 * Database layer for MetaManager job queues.
 *
 * Three separate tables, each tailored to its queue's data:
 *   wp_mm_webp_queue      — WebP generation jobs
 *   wp_mm_compress_queue  — Lossless compression jobs
 *   wp_mm_meta_queue      — Metadata read/write jobs
 *
 * DB is the single source of truth for job state. No file queues.
 * Pending → processing → completed/failed is one atomic UPDATE.
 */
class MM_DB {

	// ── Table names ──────────────────────────────────────────────────
	public static function table_webp(): string {
		global $wpdb;
		return $wpdb->prefix . 'mm_webp_queue';
	}
	public static function table_compress(): string {
		global $wpdb;
		return $wpdb->prefix . 'mm_compress_queue';
	}
	public static function table_meta(): string {
		global $wpdb;
		return $wpdb->prefix . 'mm_meta_queue';
	}

	/**
	 * Map a job_type string to its table name.
	 */
	public static function table_for( string $job_type ): string {
		return match ( $job_type ) {
			'webp'       => self::table_webp(),
			'compression' => self::table_compress(),
			default      => self::table_meta(),
		};
	}

	// ── Schema ───────────────────────────────────────────────────────

	public static function create_or_update_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// WebP queue — minimal: daemon generates webp from source file.
		$sql = "CREATE TABLE IF NOT EXISTS " . self::table_webp() . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			image_name VARCHAR(255) NOT NULL DEFAULT '',
			file_path TEXT NOT NULL,
			size VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			submitted_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			details LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_submitted (submitted_at)
		) {$charset};";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
		if ( $wpdb->last_error ) { error_log( '[MM_DB] webp table error: ' . $wpdb->last_error ); }

		// Compression queue — needs dimensions, optimize level, byte counts.
		$sql = "CREATE TABLE IF NOT EXISTS " . self::table_compress() . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			image_name VARCHAR(255) NOT NULL DEFAULT '',
			file_path TEXT NOT NULL,
			size VARCHAR(64) NOT NULL DEFAULT '',
			dimensions VARCHAR(32) NOT NULL DEFAULT '',
			optimize_level TINYINT NOT NULL DEFAULT 2,
			bytes_before BIGINT(20) UNSIGNED DEFAULT NULL,
			bytes_after BIGINT(20) UNSIGNED DEFAULT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			submitted_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			details LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_submitted (submitted_at)
		) {$charset};";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
		if ( $wpdb->last_error ) { error_log( '[MM_DB] compress table error: ' . $wpdb->last_error ); }

		// Metadata queue — needs the metadata payload to embed.
		$sql = "CREATE TABLE IF NOT EXISTS " . self::table_meta() . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			image_name VARCHAR(255) NOT NULL DEFAULT '',
			job_type VARCHAR(32) NOT NULL DEFAULT 'metadata',
			job_subtype VARCHAR(32) NOT NULL DEFAULT '',
			job_trigger VARCHAR(64) NOT NULL DEFAULT '',
			file_path TEXT NOT NULL,
			size VARCHAR(64) NOT NULL DEFAULT '',
			metadata LONGTEXT DEFAULT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			submitted_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			details LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_job (attachment_id, job_type, size),
			KEY idx_status (status),
			KEY idx_submitted (submitted_at)
		) {$charset};";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
		if ( $wpdb->last_error ) { error_log( '[MM_DB] meta table error: ' . $wpdb->last_error ); }
	}

	// ── Write: queue a job ──────────────────────────────────────────

	/**
	 * Insert or update a pending job in the appropriate table.
	 * This is the ONLY way jobs enter the system. No files.
	 */
	public static function log_pending_job( array $job ): bool {
		global $wpdb;
		$type = $job['job_type'] ?? 'metadata';
		$table = self::table_for( $type );

		$data = [
			'attachment_id' => absint( $job['attachment_id'] ?? 0 ),
			'image_name'    => sanitize_text_field( $job['image_name'] ?? '' ),
			'file_path'     => sanitize_text_field( $job['file_path'] ?? '' ),
			'size'          => sanitize_key( $job['size'] ?? '' ),
			'status'        => sanitize_text_field( $job['status'] ?? 'pending' ),
			'submitted_at'  => sanitize_text_field( $job['submitted_at'] ?? current_time( 'mysql' ) ),
		];

		// Table-specific columns.
		if ( $type === 'compression' ) {
			$data['dimensions']     = sanitize_text_field( $job['dimensions'] ?? '' );
			$data['optimize_level'] = absint( $job['optimize_level'] ?? 2 );
			if ( isset( $job['bytes_before'] ) ) { $data['bytes_before'] = absint( $job['bytes_before'] ); }
			if ( isset( $job['bytes_after'] ) )  { $data['bytes_after']  = absint( $job['bytes_after'] ); }
		}
		if ( $type === 'metadata' || $type === 'import' ) {
			$data['job_type']    = sanitize_key( $job['job_type'] ?? $type );
			$data['job_subtype'] = sanitize_key( $job['job_subtype'] ?? '' );
			$data['job_trigger'] = sanitize_key( $job['trigger'] ?? '' );
			$data['metadata']    = wp_json_encode( $job['metadata'] ?? [] );
		}
		if ( isset( $job['completed_at'] ) ) {
			$data['completed_at'] = sanitize_text_field( $job['completed_at'] );
		}

		$format = array_fill( 0, count( $data ), '%s' );
		$format[ array_search( 'attachment_id', array_keys( $data ), true ) ] = '%d';
		if ( $type === 'compression' ) {
			$oi = array_search( 'optimize_level', array_keys( $data ), true );
			if ( false !== $oi ) { $format[ $oi ] = '%d'; }
			foreach ( [ 'bytes_before', 'bytes_after' ] as $bk ) {
				$bi = array_search( $bk, array_keys( $data ), true );
				if ( false !== $bi ) { $format[ $bi ] = '%d'; }
			}
		}

		// Explicit upsert: same (attachment_id, size) replaces existing row.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE attachment_id = %d AND size = %s LIMIT 1",
			$data['attachment_id'], $data['size']
		) );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update( $table, $data, [ 'id' => (int) $existing ], $format, [ '%d' ] );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->insert( $table, $data, $format );
		}
		return false !== $result;
	}

	/**
	 * Insert a job without upsert — duplicates allowed (rule 18).
	 * Used by write_job() where no silent suppression is permitted.
	 */
	public static function insert_job( array $job ): bool {
		global $wpdb;
		$type  = $job['job_type'] ?? 'metadata';
		$table = self::table_for( $type );

		$data = [
			'attachment_id' => absint( $job['attachment_id'] ?? 0 ),
			'image_name'    => sanitize_text_field( $job['image_name'] ?? '' ),
			'file_path'     => sanitize_text_field( $job['file_path'] ?? '' ),
			'size'          => sanitize_key( $job['size'] ?? '' ),
			'status'        => sanitize_text_field( $job['status'] ?? 'pending' ),
			'submitted_at'  => sanitize_text_field( $job['submitted_at'] ?? current_time( 'mysql' ) ),
		];

		if ( $type === 'compression' ) {
			$data['dimensions']     = sanitize_text_field( $job['dimensions'] ?? '' );
			$data['optimize_level'] = absint( $job['optimize_level'] ?? 2 );
			if ( isset( $job['bytes_before'] ) ) { $data['bytes_before'] = absint( $job['bytes_before'] ); }
			if ( isset( $job['bytes_after'] ) )  { $data['bytes_after']  = absint( $job['bytes_after'] ); }
		}
		if ( $type === 'metadata' || $type === 'import' ) {
			$data['job_type']    = sanitize_key( $job['job_type'] ?? $type );
			$data['job_subtype'] = sanitize_key( $job['job_subtype'] ?? '' );
			$data['job_trigger'] = sanitize_key( $job['trigger'] ?? '' );
			$data['metadata']    = wp_json_encode( $job['metadata'] ?? [] );
		}

		$format = array_fill( 0, count( $data ), '%s' );
		$format[ array_search( 'attachment_id', array_keys( $data ), true ) ] = '%d';
		if ( $type === 'compression' ) {
			$oi = array_search( 'optimize_level', array_keys( $data ), true );
			if ( false !== $oi ) { $format[ $oi ] = '%d'; }
			foreach ( [ 'bytes_before', 'bytes_after' ] as $bk ) {
				$bi = array_search( $bk, array_keys( $data ), true );
				if ( false !== $bi ) { $format[ $bi ] = '%d'; }
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert( $table, $data, $format );
		return false !== $result;
	}

	// ── Read: claim next job ────────────────────────────────────────

	/**
	 * Atomically claim the next pending job for a queue type.
	 * Returns the job row or null if queue is empty.
	 */
	public static function claim_next_job( string $job_type ): ?array {
		global $wpdb;
		$table = self::table_for( $job_type );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM %i WHERE status = 'pending' ORDER BY submitted_at ASC, id ASC LIMIT 1",
			$table
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		// Atomic claim: only succeed if still pending.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET status = 'processing' WHERE id = %d AND status = 'pending'",
			$table,
			$row['id']
		) );

		if ( ! $claimed ) {
			return null; // Another daemon claimed it first.
		}

		$row['status'] = 'processing';
		return $row;
	}

	/**
	 * Mark a job as completed or failed.
	 */
	public static function finish_job( string $job_type, int $job_id, string $status, string $details = '', array $extra = [] ): void {
		global $wpdb;
		$table = self::table_for( $job_type );

		$data = [
			'status'       => $status,
			'completed_at' => current_time( 'mysql' ),
			'details'      => $details,
		];
		foreach ( $extra as $k => $v ) {
			$data[ $k ] = $v;
		}

		$format = array_fill( 0, count( $data ), '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, $data, [ 'id' => $job_id ], $format, [ '%d' ] );
	}

	// ── History queries ─────────────────────────────────────────────

	public static function count_by_status( string $job_type, string $status ): int {
		global $wpdb;
		$table = self::table_for( $job_type );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE status = %s", $table, $status
		) );
	}

	public static function has_any_completed_job( int $attachment_id ): bool {
		global $wpdb;
		foreach ( [ self::table_webp(), self::table_compress(), self::table_meta() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE attachment_id = %d AND status = 'completed'", $table, $attachment_id
			) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drop all three tables. Called during uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		foreach ( [ self::table_webp(), self::table_compress(), self::table_meta() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	// ── Compatibility methods for existing callers ──────────────────

	public static function table_name(): string {
		return self::table_meta();
	}

	public static function get_job( int $job_id, string $job_type = '' ): ?object {
		global $wpdb;
		$tables = $job_type ? [ self::table_for( $job_type ) ] : [ self::table_webp(), self::table_compress(), self::table_meta() ];
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $job_id ), OBJECT );
			if ( $row ) return $row;
		}
		return null;
	}

	public static function get_jobs( array $args = [] ): array {
		global $wpdb;
		$status   = $args['status'] ?? '';
		$type     = $args['job_type'] ?? '';
		$per_page = $args['per_page'] ?? ( $args['number'] ?? 50 );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;
		$search   = $args['search'] ?? '';
		$orderby  = $args['orderby'] ?? 'id';
		$order    = strtoupper( $args['order'] ?? 'DESC' );

		$allowed_cols = [ 'id', 'attachment_id', 'image_name', 'job_type', 'size', 'status', 'submitted_at', 'completed_at' ];
		if ( ! in_array( $orderby, $allowed_cols, true ) ) { $orderby = 'id'; }
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) { $order = 'DESC'; }

		$results = array();
		$total   = 0;
		$tables  = $type ? [ self::table_for( $type ) ] : [ self::table_webp(), self::table_compress(), self::table_meta() ];

		foreach ( $tables as $table ) {
			$where  = '1=1';
			$params = array();
			if ( 'not_pending' === $status ) {
				$where .= " AND status != 'pending'";
			} elseif ( $status ) {
				$where .= ' AND status = %s'; $params[] = $status;
			}
			if ( $search ) {
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				if ( $table === self::table_meta() ) {
					$where  .= ' AND (image_name LIKE %s OR job_type LIKE %s)';
					$params[] = $like;
					$params[] = $like;
				} else {
					$where  .= ' AND image_name LIKE %s';
					$params[] = $like;
				}
			}

			$count_params = $params;
			$count_row = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, array_merge( [ $table ], $count_params ) ) );
			$total += (int) $count_row;

			$params[] = $per_page;
			$params[] = $offset;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $where . " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", array_merge( [ $table ], $params ) ), OBJECT );
			$results = array_merge( $results, $rows );
		}
		return array( 'jobs' => $results, 'total' => $total );
	}

	public static function has_completed_compression( int $attachment_id, string $size ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE attachment_id = %d AND size = %s AND status = "completed"', self::table_compress(), $attachment_id, $size
		) );
	}

	public static function has_any_completed_metadata( int $attachment_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE attachment_id = %d AND status = "completed"', self::table_meta(), $attachment_id
		) );
	}

	public static function get_pending_job_types( int $attachment_id ): array {
		global $wpdb;
		$types = array();
		foreach ( [ 'webp' => self::table_webp(), 'compression' => self::table_compress(), 'metadata' => self::table_meta() ] as $type => $table ) {
			$c = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE attachment_id = %d AND status = "pending"', $table, $attachment_id ) );
			if ( $c ) $types[] = $type;
		}
		return $types;
	}

	public static function get_ids_with_completed_job( string $job_type ): array {
		global $wpdb;
		$table = self::table_for( $job_type );
		return $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT attachment_id FROM %i WHERE status = "completed"', $table ) );
	}

	public static function get_stats(): array {
		global $wpdb;
		$stats = array(
			'total_jobs' => 0,
			'pending' => 0,
			'completed' => 0,
			'failed' => 0,
			'bytes_saved' => 0,
			'bytes_original' => 0,
			'unique_attachments' => 0,
		);
		$all_ids = array();
		foreach ( [ 'webp', 'compression', 'metadata' ] as $type ) {
			$table = self::table_for( $type );
			$pending   = self::count_by_status( $type, 'pending' );
			$completed = self::count_by_status( $type, 'completed' );
			$failed    = self::count_by_status( $type, 'failed' );
			$stats['pending']   += $pending;
			$stats['completed'] += $completed;
			$stats['failed']    += $failed;
			$stats['total_jobs'] += $pending + $completed + $failed;
			$stats[$type] = array(
				'pending'   => $pending,
				'completed' => $completed,
				'failed'    => $failed,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table}" );
			$all_ids = array_merge( $all_ids, array_map( 'intval', $ids ) );
			// Byte counts (compression table only has these columns).
			if ( $type === 'compression' ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$row = $wpdb->get_row( "SELECT COALESCE(SUM(bytes_before),0) AS ob, COALESCE(SUM(bytes_after),0) AS oa FROM {$table} WHERE bytes_before IS NOT NULL" );
				if ( $row ) {
					$stats['bytes_original'] += (int) $row->ob;
					$stats['bytes_saved']    += (int) $row->ob - (int) $row->oa;
				}
			}
		}
		$stats['unique_attachments'] = count( array_unique( $all_ids ) );
		return $stats;
	}

	public static function delete_jobs_for_attachment( int $attachment_id ): void {
		global $wpdb;
		foreach ( [ self::table_webp(), self::table_compress(), self::table_meta() ] as $table ) {
			$wpdb->delete( $table, array( 'attachment_id' => $attachment_id ) );
		}
	}

	public static function log_job( array $job ): bool {
		self::log_pending_job( $job );
		$type   = $job['job_type'] ?? 'metadata';
		$status = $job['status'] ?? 'pending';
		if ( 'completed' === $status || 'failed' === $status ) {
			$id = (int) ( $job['id'] ?? 0 );
			if ( $id ) {
				self::finish_job( $type, $id, $status, $job['details'] ?? '', [
					'bytes_before' => $job['bytes_before'] ?? null,
					'bytes_after'  => $job['bytes_after'] ?? null,
				] );
			}
		}
		return true;
	}

	public static function drop_table(): void {
		self::drop_tables();
	}
}
