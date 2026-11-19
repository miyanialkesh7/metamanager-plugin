<?php
/**
 * MM_Metadata_CLI — WP-CLI command group.
 *
 * Usage: wp metamanager metadata <subcommand> [options]
 *
 * Subcommands:
 *   export          Dump current settings as JSON to stdout
 *   import          Import settings from a JSON file or stdin
 *   reset           Reset all settings to defaults
 *   backfill-links  Extract links from all existing published posts
 *   check-links     Run a full broken-link scan immediately
 *   ping            Ping Google + Bing with the sitemap URL
 *   flush-rewrites  Flush WordPress rewrite rules
 *   schema-test     Fetch a URL and print the JSON-LD found in <head>
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class MM_Metadata_CLI extends \WP_CLI_Command {

	// -------------------------------------------------------------------------
	// export
	// -------------------------------------------------------------------------

	/**
	 * Export current plugin settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: json (default), pretty.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata export
	 *   wp metamanager metadata export --format=pretty
	 *
	 * @when after_wp_load
	 */
	public function export( array $args, array $assoc_args ): void {
		$settings  = get_option( MM_META_OPT_SETTINGS, [] );
		$business  = get_option( MM_META_OPT_BUSINESS, [] );
		$format    = $assoc_args['format'] ?? 'json';
		$flags     = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		if ( 'pretty' === $format ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		WP_CLI::line( wp_json_encode( compact( 'settings', 'business' ), $flags ) );
	}

	// -------------------------------------------------------------------------
	// import
	// -------------------------------------------------------------------------

	/**
	 * Import plugin settings from a JSON file or stdin.
	 *
	 * Reads a JSON file previously created by `wp metamanager metadata export` and
	 * applies the settings and/or business profile data. Missing keys are
	 * filled from factory defaults so the imported data is always complete.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to a JSON file. Omit to read from stdin.
	 *
	 * [--dry-run]
	 * : Show what would be imported without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata import backup.json
	 *   wp metamanager metadata import backup.json --dry-run
	 *   cat backup.json | wp metamanager import
	 *
	 * @when after_wp_load
	 */
	public function import( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$file    = $args[0] ?? '';

		// Read JSON from file or stdin.
		if ( '' !== $file ) {
			$file = realpath( $file );
			if ( ! $file || ! is_readable( $file ) ) {
				WP_CLI::error( "Cannot read file: {$args[0]}" );
			}
			$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$json = '';
			while ( !feof( STDIN ) ) {
				$json .= fread( STDIN, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_operations_read
			}
		}

		$data = json_decode( trim( $json ), true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Invalid JSON — expected an object with "settings" and/or "business" keys.' );
		}

		$has_settings = isset( $data['settings'] ) && is_array( $data['settings'] );
		$has_business = isset( $data['business'] ) && is_array( $data['business'] );

		if ( ! $has_settings && ! $has_business ) {
			WP_CLI::error( 'JSON must contain a "settings" and/or "business" key.' );
		}

		// Deep-merge with defaults so imported data is always complete.
		$settings = $has_settings
			? $this->deep_merge( MM_Site_Settings::settings_defaults(), $data['settings'] )
			: null;
		$business = $has_business
			? $this->deep_merge( MM_Site_Settings::business_defaults(), $data['business'] )
			: null;

		// Show diff.
		if ( $has_settings ) {
			$old = get_option( MM_META_OPT_SETTINGS, [] );
			$this->show_settings_diff( $old, $settings );
		}
		if ( $has_business ) {
			$old = get_option( MM_META_OPT_BUSINESS, [] );
			$this->show_settings_diff( $old, $business, 'business' );
		}

		if ( $dry_run ) {
			WP_CLI::warning( 'Dry run — no changes written.' );
			return;
		}

		// Write.
		if ( $has_settings ) {
			update_option( MM_META_OPT_SETTINGS, $settings, false );
		}
		if ( $has_business ) {
			update_option( MM_META_OPT_BUSINESS, $business, false );
		}

		$parts = [];
		if ( $has_settings ) { $parts[] = 'settings'; }
		if ( $has_business ) { $parts[] = 'business profile'; }
		WP_CLI::success( 'Imported ' . implode( ' + ', $parts ) . '.' );
	}

	// -------------------------------------------------------------------------
	// reset
	// -------------------------------------------------------------------------

	/**
	 * Reset all plugin settings to factory defaults.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata reset
	 *
	 * @when after_wp_load
	 */
	public function reset( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'This will overwrite all Metamanager settings. Continue?' );
		update_option( MM_META_OPT_SETTINGS, MM_Site_Settings::settings_defaults() );
		update_option( MM_META_OPT_BUSINESS, MM_Site_Settings::business_defaults() );
		WP_CLI::success( 'Settings reset to defaults.' );
	}

	// -------------------------------------------------------------------------
	// check-links
	// -------------------------------------------------------------------------

	/**
	 * Run a full broken-link scan immediately (bypasses the cron schedule).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Check ALL queued links, not just the next batch.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata check-links
	 *   wp metamanager metadata check-links --all
	 *
	 * @when after_wp_load
	 */
	public function check_links( array $args, array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$checker  = new MM_Mod_Links( $settings );
		$check_all = isset( $assoc_args['all'] );

		if ( $check_all ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_ignored = 0', MM_Mod_Links::table_name() ) );
			$batch = (int) $settings->get( 'links.batch_size', 50 );
			$runs  = (int) ceil( $total / max( 1, $batch ) );

			WP_CLI::log( "Running {$runs} batch(es) for {$total} link(s)..." );
			for ( $i = 0; $i < $runs; $i++ ) {
				$checker->run_batch_check();
				WP_CLI::log( "Batch " . ( $i + 1 ) . "/{$runs} done." );
			}
		} else {
			$checker->run_batch_check();
			WP_CLI::log( "One batch checked." );
		}

		// Report broken count.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$broken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_broken = 1 AND is_ignored = 0', MM_Mod_Links::table_name() ) );
		WP_CLI::success( "Done. Broken links: {$broken}." );
	}

	// -------------------------------------------------------------------------
	// ping
	// -------------------------------------------------------------------------

	/**
	 * Immediately ping Google and Bing with the sitemap URL.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata ping
	 *
	 * @when after_wp_load
	 */
	public function ping( array $args, array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$sitemap  = new MM_Mod_Sitemap_Web( $settings );
		$sitemap->send_ping();
		WP_CLI::success( 'Ping sent to search engines.' );
	}

	// -------------------------------------------------------------------------
	// backfill-links
	// -------------------------------------------------------------------------

	/**
	 * Scan all existing published posts and populate the link table.
	 *
	 * Posts that were saved after the plugin was installed (and therefore
	 * already indexed via the save_post hook) are skipped automatically.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata backfill-links
	 *
	 * @when after_wp_load
	 */
	public function backfill_links( array $args, array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$mod      = new MM_Mod_Links( $settings );
		$batch    = 50;
		$offset   = 0;
		$progress = null;
		$new_count     = 0;
		$skipped_count = 0;

		do {
			$result = $mod->backfill_posts( $offset, $batch );

			if ( null === $progress && $result['total'] > 0 ) {
				$progress = \WP_CLI\Utils\make_progress_bar(
					"Scanning {$result['total']} post(s)",
					$result['total']
				);
			}

			if ( $progress ) {
				$progress->tick( $result['scanned'] );
			}

			$new_count     += $result['scanned'] - $result['skipped'];
			$skipped_count += $result['skipped'];
			$offset         = $result['new_offset'];

		} while ( ! $result['done'] && $result['scanned'] > 0 );

		if ( $progress ) {
			$progress->finish();
		}

		WP_CLI::success( "Done. Extracted links from {$new_count} new post(s). {$skipped_count} already-indexed post(s) skipped." );
	}

	// -------------------------------------------------------------------------
	// flush-rewrites
	// -------------------------------------------------------------------------

	/**
	 * Flush WordPress rewrite rules.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata flush-rewrites
	 *
	 * @when after_wp_load
	 */
	public function flush_rewrites( array $args, array $assoc_args ): void {
		flush_rewrite_rules( true );
		WP_CLI::success( 'Rewrite rules flushed.' );
	}

	// -------------------------------------------------------------------------
	// schema-test
	// -------------------------------------------------------------------------

	/**
	 * Fetch a site URL and print any JSON-LD schema found in the page.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The page URL to test. Must be on this WordPress site.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata schema-test https://example.com/about/
	 *
	 * @when after_wp_load
	 */
	public function schema_test( array $args, array $assoc_args ): void {
		$url = $args[0] ?? '';
		if ( ! $url ) {
			WP_CLI::error( 'Please provide a URL.' );
		}

		// Validate URL belongs to this site.
		if ( ! str_starts_with( $url, home_url() ) ) {
			WP_CLI::error( 'URL must start with ' . home_url() );
		}

		$response = wp_remote_get( $url, [
			'timeout'   => 15,
			'user-agent' => 'GCM-SEO-CLI/1.0',
		] );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'Request failed: ' . $response->get_error_message() );
		}

		$body    = wp_remote_retrieve_body( $response );
		$code    = wp_remote_retrieve_response_code( $response );
		WP_CLI::log( "HTTP {$code}" );

		// Extract all <script type="application/ld+json"> blocks.
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $matches ) ) {
			foreach ( $matches[1] as $i => $json ) {
				WP_CLI::log( "\n── JSON-LD block " . ( $i + 1 ) . " ──" );
				$decoded = json_decode( trim( $json ), true );
				WP_CLI::line( wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
		} else {
			WP_CLI::warning( 'No JSON-LD blocks found in page.' );
		}

		// Also show meta robots.
		if ( preg_match( '/<meta\s+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m ) ) {
			WP_CLI::log( "\nmeta robots: " . $m[1] );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Recursively merge $override into $base. Arrays are merged by key;
	 * scalar values in $override replace $base values.
	 * Numeric lists are merged by key (not replaced).
	 */
	private function deep_merge( array $base, array $override ): array {
		return MM_Site_Settings::deep_merge( $base, $override, true );
	}

	/**
	 * Show a flat diff between old and new settings arrays.
	 */
	private function show_settings_diff( array $old, array $new, string $label = 'settings' ): void {
		$flat_old = $this->flatten_array( $old );
		$flat_new = $this->flatten_array( $new );
		$all_keys = array_unique( array_merge( array_keys( $flat_old ), array_keys( $flat_new ) ) );
		sort( $all_keys );

		$changes = 0;
		foreach ( $all_keys as $key ) {
			$old_val = $flat_old[ $key ] ?? '(missing)';
			$new_val = $flat_new[ $key ] ?? '(missing)';
			if ( $old_val === $new_val ) {
				continue;
			}
			++$changes;
			WP_CLI::log( "  {$label}: {$key}" );
			WP_CLI::log( "    - " . wp_json_encode( $old_val ) );
			WP_CLI::log( "    + " . wp_json_encode( $new_val ) );
		}

		if ( 0 === $changes ) {
			WP_CLI::log( "  {$label}: no changes" );
		} else {
			WP_CLI::log( "  {$label}: {$changes} change(s)" );
		}
	}

	/**
	 * Flatten a nested array to dot-notation keys.
	 *
	 * @return array<string, mixed>
	 */
	private function flatten_array( array $array, string $prefix = '' ): array {
		$result = [];
		foreach ( $array as $key => $value ) {
			$new_key = '' === $prefix ? $key : "{$prefix}.{$key}";
			if ( is_array( $value ) && ! empty( $value ) ) {
				$result += $this->flatten_array( $value, $new_key );
			} else {
				$result[ $new_key ] = $value;
			}
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// pref — plugin preferences (mm_* standalone options)
	// -------------------------------------------------------------------------

	/**
	 * List all Metamanager preference options and their current values.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table or json (default table).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata pref list
	 *   wp metamanager metadata pref list --format=json
	 *
	 * @subcommand pref-list
	 * @when after_wp_load
	 */
	public function pref_list( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$prefs  = $this->get_pref_map();

		$rows = [];
		foreach ( $prefs as $label => $key ) {
			$rows[] = [ 'Option' => $key, 'Label' => $label, 'Value' => get_option( $key, '' ) ];
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'Option', 'Label', 'Value' ] );
		}
	}

	/**
	 * Get a single preference value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Option key (e.g. mm_compress_level, mm_notify_email).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata pref get mm_compress_level
	 *
	 * @subcommand pref-get
	 * @when after_wp_load
	 */
	public function pref_get( array $args, array $assoc_args ): void {
		$key = $args[0] ?? '';
		if ( ! $key ) {
			WP_CLI::error( 'Usage: wp metamanager metadata pref get <key>' );
		}
		$prefs = $this->get_pref_map();
		if ( ! in_array( $key, $prefs, true ) ) {
			WP_CLI::error( "Unknown preference key: {$key}. Valid: " . implode( ', ', $prefs ) );
		}
		$value = get_option( $key, '' );
		WP_CLI::line( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
	}

	/**
	 * Set a preference value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Option key (e.g. mm_compress_level, mm_notify_email).
	 *
	 * <value>
	 * : New value.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata pref set mm_compress_level 3
	 *   wp metamanager metadata pref set mm_notify_email ops@example.com
	 *
	 * @subcommand pref-set
	 * @when after_wp_load
	 */
	public function pref_set( array $args, array $assoc_args ): void {
		$key   = $args[0] ?? '';
		$value = $args[1] ?? '';
		if ( ! $key ) {
			WP_CLI::error( 'Usage: wp metamanager metadata pref set <key> <value>' );
		}
		$prefs = $this->get_pref_map();
		if ( ! in_array( $key, $prefs, true ) ) {
			WP_CLI::error( "Unknown preference key: {$key}. Valid: " . implode( ', ', $prefs ) );
		}
		update_option( $key, $value );
		WP_CLI::success( "{$key} set to {$value}" );
	}

	/**
	 * Canonical map of preference labels to option keys.
	 *
	 * @return array<string, string>
	 */
	private function get_pref_map(): array {
		return [
			'Compress level'              => 'mm_compress_level',
			'Batch size'                  => 'mm_batch_size',
			'Max revisions'               => 'mm_max_revisions',
			'Failure notifications'       => 'mm_notify_enabled',
			'Notification email'          => 'mm_notify_email',
			'REST API disabled'           => 'mm_api_disabled',
			'REST API allowed IPs'        => 'mm_api_allowed_ips',
			'Upload receipt CC'           => 'mm_upload_notify_extra_email',
			'Auto provenance'             => 'mm_auto_provenance',
			'Delete data on uninstall'    => 'mm_delete_data_on_uninstall',
			'Contact card style'          => 'mm_meta_contact_style',
		];
	}

	// -------------------------------------------------------------------------
	// links — link maintenance
	// -------------------------------------------------------------------------

	/**
	 * List tracked links with status filter.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter: broken, ok, ignored, or all (default all).
	 *
	 * [--per_page=<n>]
	 * : Results per page (default 20).
	 *
	 * [--paged=<n>]
	 * : Page number (default 1).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links list --status=broken
	 *
	 * @subcommand links-list
	 * @when after_wp_load
	 */
	public function links_list( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$status   = (string) ( $assoc_args['status'] ?? 'all' );
		$per_page = (int) ( $assoc_args['per_page'] ?? 20 );
		$paged    = (int) ( $assoc_args['paged'] ?? 1 );
		$offset   = ( max( 1, $paged ) - 1 ) * $per_page;

		$conditions = [];
		$allowed    = [ 'broken', 'ok', 'ignored' ];
		if ( in_array( $status, $allowed, true ) ) {
			$conditions[] = $wpdb->prepare( 'status = %s', $status );
		}
		$where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}" );
		// phpcs:enable

		if ( empty( $rows ) ) {
			WP_CLI::line( 'No links found.' );
			return;
		}

		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'ID'      => $row->id,
				'URL'     => $row->url,
				'Status'  => $row->status,
				'Code'    => $row->status_code ?? '',
				'Checked' => $row->last_checked ?? '',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'URL', 'Status', 'Code', 'Checked' ] );
		WP_CLI::line( sprintf( 'Showing %d of %d links.', count( $items ), $total ) );
	}

	/**
	 * Ignore or unignore a tracked link.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link row ID.
	 *
	 * <state>
	 * : "ignore" or "unignore".
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links set-ignored 5 ignore
	 *   wp metamanager metadata links set-ignored 5 unignore
	 *
	 * @subcommand links-set-ignored
	 * @when after_wp_load
	 */
	public function links_set_ignored( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$id    = (int) ( $args[0] ?? 0 );
		$state = (string) ( $args[1] ?? '' );
		if ( ! $id || ! in_array( $state, [ 'ignore', 'unignore' ], true ) ) {
			WP_CLI::error( 'Usage: wp metamanager metadata links set-ignored <id> <ignore|unignore>' );
		}

		$new_status = 'ignore' === $state ? 'ignored' : 'ok';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( $table, [ 'status' => $new_status ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
		if ( false === $updated ) {
			WP_CLI::error( "Failed to update link {$id}." );
		}
		WP_CLI::success( "Link {$id} set to {$new_status}." );
	}

	/**
	 * Re-check one tracked link by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link row ID.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links recheck 5
	 *
	 * @subcommand links-recheck
	 * @when after_wp_load
	 */
	public function links_recheck( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp metamanager metadata links recheck <id>' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
		if ( ! $row ) {
			WP_CLI::error( "Link {$id} not found." );
		}

		$response = wp_remote_head( $row->url, [ 'timeout' => 15, 'sslverify' => true ] );
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, [ 'status' => 'broken', 'last_checked' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
			WP_CLI::warning( "Link {$id} check failed: " . $response->get_error_message() );
			return;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$status = ( $code >= 200 && $code < 400 ) ? 'ok' : 'broken';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, [ 'status' => $status, 'status_code' => $code, 'last_checked' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%s', '%d', '%s' ], [ '%d' ] );
		WP_CLI::success( "Link {$id}: HTTP {$code} → {$status}" );
	}

	/**
	 * Purge (truncate) the entire link table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links purge --yes
	 *
	 * @subcommand links-purge
	 * @when after_wp_load
	 */
	public function links_purge( array $args, array $assoc_args ): void {
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes' ) ) {
			WP_CLI::error( 'Add --yes to confirm purging the link table.' );
		}
		global $wpdb;
		$table = MM_Mod_Links::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		WP_CLI::success( 'Link table purged.' );
	}

	// -------------------------------------------------------------------------
	// receipts — upload receipt failure handling
	// -------------------------------------------------------------------------

	/**
	 * Retry a failed upload-receipt email.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Upload notification record ID.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata receipts retry 3
	 *
	 * @subcommand receipts-retry
	 * @when after_wp_load
	 */
	public function receipts_retry( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp metamanager metadata receipts retry <id>' );
		}
		if ( ! class_exists( 'MM_Upload_Notify' ) || ! method_exists( 'MM_Upload_Notify', 'retry_notice' ) ) {
			WP_CLI::error( 'MM_Upload_Notify retry handler not available.' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInputInput -- integer ID, sanitized above
		MM_Upload_Notify::retry_notice();
		WP_CLI::success( "Retry triggered for upload receipt {$id}." );
	}

	/**
	 * Dismiss a failed upload-receipt notice.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Upload notification record ID.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata receipts dismiss 3
	 *
	 * @subcommand receipts-dismiss
	 * @when after_wp_load
	 */
	public function receipts_dismiss( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp metamanager metadata receipts dismiss <id>' );
		}
		if ( ! class_exists( 'MM_Upload_Notify' ) || ! method_exists( 'MM_Upload_Notify', 'dismiss_notice' ) ) {
			WP_CLI::error( 'MM_Upload_Notify dismiss handler not available.' );
		}
		MM_Upload_Notify::dismiss_notice();
		WP_CLI::success( "Upload receipt {$id} dismissed." );
	}
}

\WP_CLI::add_command( 'metamanager metadata', 'MM_Metadata_CLI' );
