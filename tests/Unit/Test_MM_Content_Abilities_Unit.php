<?php
/**
 * Unit tests for ability separation of concerns.
 *
 * content/* and comments/* abilities moved to GCM Coworker (gcm repo).
 * MetaManager owns only metamanager/* abilities (including daemon status).
 * General WordPress operations belong in the mu-plugin on every site.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Content_Abilities_Unit extends WP_UnitTestCase {

	/** @var MM_Abilities */
	private $mm;

	public function set_up(): void {
		parent::set_up();
		$this->mm = new MM_Abilities();
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	// ------------------------------------------------------------------
	// Separation of concerns: content/* and comments/* moved to Coworker.
	// ------------------------------------------------------------------

	public function test_content_and_comment_abilities_are_not_registered_by_metamanager(): void {
		$this->admin_user();
		$moved = array(
			'content/list-posts', 'content/get-post', 'content/create-draft',
			'content/update-post', 'content/schedule-publish', 'content/publish',
			'content/unpublish', 'content/trash', 'content/restore',
			'content/list-media', 'content/upload-media',
			'content/set-featured-image', 'content/list-terms', 'content/assign-term',
			'comments/list', 'comments/moderate',
		);
		foreach ( $moved as $name ) {
			$this->assertFalse( wp_has_ability( $name ), "$name should NOT be registered by MetaManager (moved to Coworker)" );
		}
	}

	public function test_metamanager_and_reports_abilities_still_register(): void {
		$this->admin_user();
		// Abilities are registered by register_hooks() during WP init
		// (metamanager.php:125). Do NOT re-fire wp_abilities_api_init —
		// that would attempt duplicate registration and emit _doing_it_wrong.
		$own = array(
			'metamanager/get-post-meta', 'metamanager/update-post-meta',
			'metamanager/get-schema', 'metamanager/get-business-profile',
			'metamanager/get-navigation', 'metamanager/get-term-meta',
			'metamanager/get-daemon-status',
		);
		foreach ( $own as $name ) {
			$this->assertNotNull( wp_get_ability( $name ), "$name must be registered by MetaManager" );
		}
	}
}
