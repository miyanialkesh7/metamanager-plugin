<?php
/**
 * Integration tests for the MM Archives Customizer section.
 *
 * Regression guard: the per-CPT layout controls originally passed their option
 * setting id under the singular 'setting' arg key. WP_Customize_Control only
 * reads 'settings' (plural), so each control bound to a nonexistent setting
 * named after the control id. check_capabilities() then found a null setting
 * and silently dropped every control from the Customizer pane — the section
 * title rendered in the menu but the section itself was empty.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Customizer extends WP_UnitTestCase {

	/**
	 * Customizer manager under test.
	 *
	 * @var WP_Customize_Manager|null
	 */
	private $wp_customize;

	/**
	 * Build a fresh manager and register the MM Archives section against it.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_Customize_Manager' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		}

		$this->wp_customize = new WP_Customize_Manager();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		MM_Customizer::customize_register( $this->wp_customize );
	}

	/**
	 * Drop the manager between tests.
	 */
	public function tear_down() {
		$this->wp_customize = null;
		parent::tear_down();
	}

	/**
	 * The root-level section exists with the user-facing title.
	 */
	public function test_mm_archives_section_registered(): void {
		$section = $this->wp_customize->get_section( 'mm_archives' );
		$this->assertNotNull( $section, 'mm_archives section must be registered' );
		$this->assertSame( 'MM Archives', $section->title );
	}

	/**
	 * One select control per CPT, bound to the archive_layouts option setting.
	 */
	public function test_every_cpt_has_a_layout_control(): void {
		foreach ( array_keys( MM_Schema_Post_Types::TYPES ) as $slug ) {
			$control = $this->wp_customize->get_control( "mm_archives_layout_{$slug}" );
			$this->assertNotNull( $control, "control for {$slug} must be registered" );
			$this->assertSame( 'select', $control->type );
			$this->assertSame( 'mm_archives', $control->section );
			$this->assertSame(
				"mm_meta_settings[schema][archive_layouts][{$slug}]",
				$control->settings['default']->id ?? null,
				"control for {$slug} must bind to its archive_layouts setting"
			);
		}
	}

	/**
	 * Controls must survive check_capabilities() — the drop that emptied the
	 * section when settings resolved to null.
	 */
	public function test_controls_pass_capability_gate(): void {
		foreach ( array_keys( MM_Schema_Post_Types::TYPES ) as $slug ) {
			$control = $this->wp_customize->get_control( "mm_archives_layout_{$slug}" );
			$this->assertNotNull( $control );
			$this->assertTrue(
				$control->check_capabilities(),
				"control for {$slug} must pass check_capabilities() so it renders in the pane"
			);
		}
	}

	/**
	 * The bound settings are option-backed with menu as the default.
	 */
	public function test_layout_settings_are_option_settings(): void {
		foreach ( array_keys( MM_Schema_Post_Types::TYPES ) as $slug ) {
			$setting = $this->wp_customize->get_setting( "mm_meta_settings[schema][archive_layouts][{$slug}]" );
			$this->assertNotNull( $setting, "setting for {$slug} must be registered" );
			$this->assertSame( 'option', $setting->type );
			$this->assertSame( 'menu', $setting->default );
		}
	}

	/**
	 * The section and every control must survive prepare_controls(), which is
	 * the filter step that ran between registration and pane export.
	 */
	public function test_prepare_controls_keeps_mm_archives(): void {
		$this->wp_customize->prepare_controls();

		$this->assertNotNull(
			$this->wp_customize->get_section( 'mm_archives' ),
			'section must survive prepare_controls()'
		);

		foreach ( array_keys( MM_Schema_Post_Types::TYPES ) as $slug ) {
			$this->assertNotNull(
				$this->wp_customize->get_control( "mm_archives_layout_{$slug}" ),
				"control for {$slug} must survive prepare_controls()"
			);
		}
	}
}
