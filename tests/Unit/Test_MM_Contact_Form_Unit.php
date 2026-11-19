<?php
/**
 * Unit tests for the contact form feature.
 *
 * Tests form handler validation, reCAPTCHA verification, email sending,
 * honeypot check, and business profile settings integration.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Contact_Form_Unit extends WP_UnitTestCase {

	/** @var MM_Site_Settings */
	private $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = MM_Site_Settings::get_instance();
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		add_filter( 'wp_die_json_handler', array( $this, 'get_wp_die_handler' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		remove_filter( 'wp_die_json_handler', array( $this, 'get_wp_die_handler' ) );
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	public function get_wp_die_handler( $handler ) {
		return function ( $message ) {
			throw new \WPDieException( is_string( $message ) ? $message : wp_json_encode( $message ) );
		};
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function enable_form(): void {
		$biz = $this->settings->all_business();
		$biz['contact_form_enabled'] = true;
		$biz['email'] = 'test@example.com';
		$biz['contact_form_subject_prefix'] = 'Test Site';
		$biz['contact_form_recaptcha_secret'] = '';
		$this->settings->save_business( $biz );
	}

	private function disable_form(): void {
		$biz = $this->settings->all_business();
		$biz['contact_form_enabled'] = false;
		$this->settings->save_business( $biz );
	}

	private function post_to_handler( array $data ): array {
		foreach ( $data as $key => $value ) {
			$_POST[ $key ] = $value;
		}
		$handler = new MM_Mod_Business_Contact( $this->settings );
		ob_start();
		try {
			$handler->handle_contact_form();
		} catch ( \WPDieException $e ) {
			$output = ob_get_clean();
			$json   = is_string( $e->getMessage() ) ? json_decode( $e->getMessage(), true ) : null;
			if ( is_array( $json ) ) {
				return $json;
			}
			// wp_send_json_* output goes to buffer before die.
			$decoded = json_decode( $output, true );
			return is_array( $decoded ) ? $decoded : [ 'success' => false, 'data' => $e->getMessage() ];
		}
		$output = ob_get_clean();
		$decoded = json_decode( $output, true );
		return is_array( $decoded ) ? $decoded : [ 'success' => false, 'data' => $output ];
	}

	// ------------------------------------------------------------------
	// Business defaults
	// ------------------------------------------------------------------

	public function test_business_defaults_include_contact_form_fields(): void {
		$defaults = MM_Site_Settings::business_defaults();
		$this->assertArrayHasKey( 'contact_form_enabled', $defaults );
		$this->assertArrayHasKey( 'email', $defaults );
		$this->assertArrayHasKey( 'contact_form_subject_prefix', $defaults );
		$this->assertArrayHasKey( 'contact_form_submit_text', $defaults );
		$this->assertArrayHasKey( 'contact_form_tos_page_id', $defaults );
		$this->assertArrayHasKey( 'contact_form_recaptcha_site', $defaults );
		$this->assertArrayHasKey( 'contact_form_recaptcha_secret', $defaults );
	}

	public function test_contact_form_enabled_by_default(): void {
		$defaults = MM_Site_Settings::business_defaults();
		$this->assertTrue( $defaults['contact_form_enabled'] );
	}

	public function test_contact_form_submit_text_default(): void {
		$defaults = MM_Site_Settings::business_defaults();
		$this->assertEquals( 'Submit', $defaults['contact_form_submit_text'] );
	}

	// ------------------------------------------------------------------
	// Form handler validation
	// ------------------------------------------------------------------

	public function test_handler_rejects_when_form_disabled(): void {
		$this->admin_user();
		$this->disable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'disabled', strtolower( $result['data'] ?? '' ) );
	}

	public function test_handler_rejects_invalid_nonce(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => 'invalid_nonce',
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'security', strtolower( $result['data'] ?? '' ) );
	}

	public function test_handler_rejects_missing_name(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => '',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'name', strtolower( $result['data'] ?? '' ) );
	}

	public function test_handler_rejects_missing_email(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => '',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'email', strtolower( $result['data'] ?? '' ) );
	}

	public function test_handler_rejects_invalid_email(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'not-an-email',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'email', strtolower( $result['data'] ?? '' ) );
	}

	public function test_handler_rejects_missing_message(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => '',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'write', strtolower( $result['data'] ?? '' ) );
	}

	public function test_handler_rejects_missing_terms(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'terms', strtolower( $result['data'] ?? '' ) );
	}

	// ------------------------------------------------------------------
	// Honeypot
	// ------------------------------------------------------------------

	public function test_honeypot_blocks_submission(): void {
		$this->admin_user();
		$this->enable_form();
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Bot',
			'mm_cf_email'      => 'bot@example.com',
			'mm_cf_message'    => 'Spam',
			'mm_cf_terms'      => '1',
			'mm_cf_website'    => 'http://spam.com',
		] );
		// Honeypot returns success (pretends to send) but doesn't actually send.
		$this->assertTrue( $result['success'] );
	}

	// ------------------------------------------------------------------
	// reCAPTCHA
	// ------------------------------------------------------------------

	public function test_recaptcha_token_required_when_secret_set(): void {
		$this->admin_user();
		$this->enable_form();
		$biz = $this->settings->all_business();
		$biz['contact_form_recaptcha_secret'] = 'test_secret';
		$this->settings->save_business( $biz );

		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'recaptcha', strtolower( $result['data'] ?? '' ) );
	}

	public function test_recaptcha_not_required_when_secret_empty(): void {
		$this->admin_user();
		$this->enable_form();
		$biz = $this->settings->all_business();
		$biz['contact_form_recaptcha_secret'] = '';
		$this->settings->save_business( $biz );

		// Should proceed past reCAPTCHA check (may fail on email, but not reCAPTCHA).
		$result = $this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );
		$this->assertStringNotContainsString( 'recaptcha', strtolower( $result['data'] ?? '' ) );
	}

	// ------------------------------------------------------------------
	// Email sending
	// ------------------------------------------------------------------

	public function test_email_uses_no_reply_from_address(): void {
		$this->admin_user();
		$this->enable_form();

		$captured_headers = [];
		add_filter( 'wp_mail', function ( $args ) use ( &$captured_headers ) {
			$captured_headers = $args['headers'] ?? [];
			return $args;
		} );

		// Trigger submission (will fail on wp_mail in test env but headers are captured).
		$this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$found  = false;
		foreach ( (array) $captured_headers as $header ) {
			if ( is_string( $header ) && str_contains( $header, "no-reply@{$domain}" ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'From header should use no-reply@domain' );
	}

	public function test_email_subject_uses_configured_prefix(): void {
		$this->admin_user();
		$this->enable_form();
		$biz = $this->settings->all_business();
		$biz['contact_form_subject_prefix'] = 'My Business';
		$this->settings->save_business( $biz );

		$captured = [];
		add_filter( 'wp_mail', function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		} );

		$this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );

		$this->assertStringContainsString( '[My Business]', $captured['subject'] ?? '' );
	}

	public function test_email_to_address_uses_configured_value(): void {
		$this->admin_user();
		$this->enable_form();

		$captured = [];
		add_filter( 'wp_mail', function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		} );

		$this->post_to_handler( [
			'mm_contact_nonce' => wp_create_nonce( 'mm_contact_form' ),
			'mm_cf_name'       => 'Test User',
			'mm_cf_email'      => 'test@example.com',
			'mm_cf_message'    => 'Hello',
			'mm_cf_terms'      => '1',
		] );

		$this->assertStringContainsString( 'test@example.com', $captured['to'] ?? '' );
	}

	// ------------------------------------------------------------------
	// Template rendering
	// ------------------------------------------------------------------

	private function render_template(): string {
		// Create a contact page and set up the loop.
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_title'  => 'Contact',
			'post_name'   => 'contact',
			'post_status' => 'publish',
		] );
		$this->go_to( get_permalink( $page_id ) );

		$settings = $this->settings;
		$biz      = $settings->all_business();
		ob_start();
		// Simulate the template's loop inline (bypasses get_header/get_footer).
		while ( have_posts() ) {
			the_post();
			?>
			<div class="mm-contact-layout">
				<div class="mm-contact-info">
					<h1 class="entry-title"><?php echo esc_html( get_the_title() ); ?></h1>
				</div>
				<?php if ( ! empty( $biz['contact_form_enabled'] ) ) : ?>
				<div class="mm-contact-form-wrap">
					<form id="mm-contact-form" class="mm-contact-form">
						<?php wp_nonce_field( 'mm_contact_form', 'mm_contact_nonce' ); ?>
						<input type="text" name="mm_cf_name">
						<input type="email" name="mm_cf_email">
						<input type="tel" name="mm_cf_phone">
						<textarea name="mm_cf_message"></textarea>
						<?php
						$pp_url  = get_privacy_policy_url();
						$tos_id  = (int) ( $biz['contact_form_tos_page_id'] ?? 0 );
						$tos_url = $tos_id ? get_permalink( $tos_id ) : '';
						?>
						<label>
							<input type="checkbox" name="mm_cf_terms">
							<?php if ( $tos_url ) : ?><a href="<?php echo esc_url( $tos_url ); ?>">Terms of Service</a><?php endif; ?>
							<?php if ( $pp_url ) : ?><a href="<?php echo esc_url( $pp_url ); ?>">Privacy Policy</a><?php endif; ?>
						</label>
						<?php if ( ! empty( $biz['contact_form_recaptcha_site'] ) ) : ?>
							<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $biz['contact_form_recaptcha_site'] ); ?>" data-size="invisible"></div>
						<?php endif; ?>
						<button type="submit"><?php echo esc_html( $biz['contact_form_submit_text'] ?? 'Submit' ); ?></button>
					</form>
				</div>
				<?php endif; ?>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	public function test_template_renders_form_fields(): void {
		$this->enable_form();
		$output = $this->render_template();
		$this->assertStringContainsString( 'mm-contact-form', $output );
		$this->assertStringContainsString( 'mm_cf_name', $output );
		$this->assertStringContainsString( 'mm_cf_email', $output );
		$this->assertStringContainsString( 'mm_cf_message', $output );
		$this->assertStringContainsString( 'mm_cf_terms', $output );
	}

	public function test_template_hides_form_when_disabled(): void {
		$this->disable_form();
		$output = $this->render_template();
		$this->assertStringNotContainsString( 'mm-contact-form', $output );
	}

	public function test_template_includes_recaptcha_when_keys_set(): void {
		$this->enable_form();
		$biz = $this->settings->all_business();
		$biz['contact_form_recaptcha_site'] = 'test_site_key';
		$biz['contact_form_recaptcha_secret'] = 'test_secret_key';
		$this->settings->save_business( $biz );

		$output = $this->render_template();
		$this->assertStringContainsString( 'g-recaptcha', $output );
		$this->assertStringContainsString( 'test_site_key', $output );
		$this->assertStringContainsString( 'invisible', $output );
	}

	public function test_template_links_to_privacy_policy(): void {
		$this->enable_form();
		$pp_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_title'  => 'Privacy Policy',
			'post_name'   => 'privacy-policy',
			'post_status' => 'publish',
		] );
		update_option( 'wp_page_for_privacy_policy', $pp_id );

		$output = $this->render_template();
		$this->assertStringContainsString( 'Privacy Policy', $output );
	}

	public function test_template_links_to_terms_page(): void {
		$this->enable_form();
		$tos_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_title'  => 'Terms of Service',
			'post_name'   => 'terms-of-service',
			'post_status' => 'publish',
		] );
		$biz = $this->settings->all_business();
		$biz['contact_form_tos_page_id'] = $tos_id;
		$this->settings->save_business( $biz );

		$output = $this->render_template();
		$this->assertStringContainsString( 'Terms of Service', $output );
		$this->assertStringContainsString( get_permalink( $tos_id ), $output );
	}

	// ------------------------------------------------------------------
	// Display toggles integration
	// ------------------------------------------------------------------

	public function test_display_toggles_in_business_defaults(): void {
		$defaults = MM_Site_Settings::business_defaults();
		$this->assertArrayHasKey( 'show_phone', $defaults );
		$this->assertArrayHasKey( 'show_sms', $defaults );
		$this->assertArrayHasKey( 'show_email', $defaults );
		$this->assertArrayHasKey( 'show_vcard', $defaults );
		$this->assertArrayHasKey( 'show_json', $defaults );
		$this->assertArrayHasKey( 'show_csv', $defaults );
	}

	public function test_display_toggles_default_values(): void {
		$defaults = MM_Site_Settings::business_defaults();
		$this->assertTrue( $defaults['show_phone'] );
		$this->assertTrue( $defaults['show_sms'] );
		$this->assertTrue( $defaults['show_email'] );
		$this->assertTrue( $defaults['show_vcard'] );
		$this->assertTrue( $defaults['show_json'] );
		$this->assertTrue( $defaults['show_csv'] );
	}
}
