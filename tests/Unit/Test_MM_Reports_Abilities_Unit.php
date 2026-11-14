<?php
/**
 * Unit tests for MetaManager's own status reporting.
 *
 * MetaManager manages metadata (post meta, term meta, schema). It reads
 * only its own status file (metamanager-status.json). GCM server status
 * lives in the GCM Coworker (gcm/*). MXRoute mail status lives in
 * mxroute/queue-status. Three repos, three status files, three scopes.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Reports_Abilities_Unit extends WP_UnitTestCase {

	/** @var array<string,string> */
	private $fixtures = array();

	public function set_up(): void {
		parent::set_up();
		$this->fixtures = array();
		add_filter( 'mm_report_path', array( $this, 'filter_report_path' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'mm_report_path', array( $this, 'filter_report_path' ), 10 );
		foreach ( $this->fixtures as $path ) {
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}
	}

	public function filter_report_path( string $path, string $which ): string {
		return $this->fixtures[ $which ] ?? $path;
	}

	private function fixture( string $which, string $content ): void {
		$path = wp_tempnam( "mm_report_$which" );
		file_put_contents( $path, $content );
		$this->fixtures[ $which ] = $path;
	}

	// ------------------------------------------------------------------
	// Registration contract — MetaManager only registers its own
	// ------------------------------------------------------------------

	public function test_metamanager_registers_only_its_own_abilities(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Abilities are registered by register_hooks() during WP init
		// (metamanager.php:125). Do NOT re-fire wp_abilities_api_init —
		// that would attempt duplicate registration.

		// MetaManager owns this one — reads metamanager-status.json.
		$ability = wp_get_ability( 'metamanager/get-daemon-status' );
		$this->assertNotNull( $ability, 'metamanager/get-daemon-status must be registered' );
		$this->assertSame( 'metamanager/get-daemon-status', $ability->get_name() );

		// MetaManager must register only metamanager/* — nothing outside
		// its own namespace. Cross-repo reports (GCM server status,
		// MXRoute mail status) live in their owning repos.
		foreach ( wp_get_abilities() as $name => $a ) {
			$ns = ( false !== strpos( $name, '/' ) ) ? explode( '/', $name )[0] : '';
			if ( 'metamanager' === $ns ) {
				$this->assertStringStartsWith( 'metamanager/', $name, "MetaManager-registered ability $name must be in metamanager/ namespace" );
			}
		}
	}

	// ------------------------------------------------------------------
	// Daemon status — reads metamanager-status.json (MetaManager's own file)
	// ------------------------------------------------------------------

	public function test_get_daemon_status_reads_writer_contract(): void {
		$data = array(
			'ts'             => '2026-11-11T12:00:00Z',
			'plugin_version' => '2.3.198',
			'daemon_version' => '2.4.86',
			'daemons'        => array(
				'compress' => array( 'running' => true, 'pid' => 123 ),
				'meta'     => array( 'running' => false, 'pid' => null ),
			),
			'queues'         => array( 'compress' => 2, 'meta' => 0, 'completed' => 9, 'failed' => 1 ),
			'tools'          => array( 'exiftool' => true, 'jpegtran' => true, 'optipng' => true, 'cwebp' => true, 'ffmpeg' => true ),
			'cron'           => array(),
		);
		$this->fixture( 'daemon', wp_json_encode( $data ) );
		$result = ( new MM_Abilities() )->ability_get_daemon_status();
		$this->assertSame( '2.4.86', $result['daemon_version'] );
		$this->assertTrue( $result['daemons']['compress']['running'] );
		$this->assertSame( 1, $result['queues']['failed'] );
	}

	// ------------------------------------------------------------------
	// Error semantics — absence of evidence is never a state
	// ------------------------------------------------------------------

	public function test_missing_report_is_an_explicit_error(): void {
		$this->fixtures['daemon'] = '/nonexistent/metamanager-status.json';
		$result = ( new MM_Abilities() )->ability_get_daemon_status();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'report_unavailable', $result->get_error_code() );
	}

	public function test_corrupt_report_is_an_explicit_error(): void {
		$this->fixture( 'daemon', '{not json' );
		$result = ( new MM_Abilities() )->ability_get_daemon_status();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'report_unreadable', $result->get_error_code() );
	}
}
