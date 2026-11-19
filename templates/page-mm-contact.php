<?php
/**
 * Template Name: Metamanager — Contact Page
 *
 * Auto-generated contact page from business profile settings.
 * Two-column layout on desktop (info left, form right).
 * Single column on tablet/mobile (info top, form bottom).
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

$settings = MM_Site_Settings::get_instance();
$biz      = $settings->all_business();

while ( have_posts() ) {
	the_post();
	?>
	<div class="mm-contact-layout">
		<div class="mm-contact-info">
			<h1 class="entry-title"><?php echo esc_html( get_the_title() ); ?></h1>
			<?php
			remove_filter( 'the_content', 'wpautop' );
			the_content();
			add_filter( 'the_content', 'wpautop' );
			?>
		</div>

		<?php if ( ! empty( $biz["contact_form_enabled"] ) ) : ?>
		<div class="mm-contact-form-wrap">
			<h2 class="mm-contact-form-heading"><?php esc_html_e( 'Send Us a Message', 'metamanager' ); ?></h2>
			<form id="mm-contact-form" class="mm-contact-form" method="post">
				<?php wp_nonce_field( 'mm_contact_form', 'mm_contact_nonce' ); ?>

				<div class="mm-form-field">
					<label for="mm_cf_name"><?php esc_html_e( 'Your Name', 'metamanager' ); ?> <span class="mm-required">*</span></label>
					<input type="text" id="mm_cf_name" name="mm_cf_name" required aria-required="true">
					<span class="mm-field-error" data-field="name"></span>
				</div>

				<div class="mm-form-field">
					<label for="mm_cf_email"><?php esc_html_e( 'Your Email', 'metamanager' ); ?> <span class="mm-required">*</span></label>
					<input type="email" id="mm_cf_email" name="mm_cf_email" required aria-required="true">
					<span class="mm-field-error" data-field="email"></span>
				</div>

				<div class="mm-form-field">
					<label for="mm_cf_phone"><?php esc_html_e( 'Phone Number', 'metamanager' ); ?></label>
					<input type="tel" id="mm_cf_phone" name="mm_cf_phone">
				</div>

				<div class="mm-form-field">
					<label for="mm_cf_message"><?php esc_html_e( 'Message', 'metamanager' ); ?> <span class="mm-required">*</span></label>
					<textarea id="mm_cf_message" name="mm_cf_message" rows="5" required aria-required="true"></textarea>
					<span class="mm-field-error" data-field="message"></span>
				</div>

				<?php
				// Terms of Service & Privacy Policy agreement.
				$pp_url = get_privacy_policy_url();
				$tos_id = (int) ( $biz["contact_form_tos_page_id"] ?? 0 );
				$tos_url = $tos_id ? get_permalink( $tos_id ) : '';
				?>
				<div class="mm-form-field mm-form-checkbox">
					<label>
						<input type="checkbox" name="mm_cf_terms" required aria-required="true">
						<?php esc_html_e( 'I accept the', 'metamanager' ); ?>
						<?php if ( $tos_url ) : ?>
							<a href="<?php echo esc_url( $tos_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Terms of Service', 'metamanager' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Terms of Service', 'metamanager' ); ?>
						<?php endif; ?>
						<?php esc_html_e( 'and', 'metamanager' ); ?>
						<?php if ( $pp_url ) : ?>
							<a href="<?php echo esc_url( $pp_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'metamanager' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Privacy Policy', 'metamanager' ); ?>
						<?php endif; ?>
						<?php esc_html_e( 'for this website', 'metamanager' ); ?>
					</label>
					<span class="mm-field-error" data-field="terms"></span>
				</div>

				<?php
				// reCAPTCHA v2 invisible.
				$rc_site = (string) ( $biz["contact_form_recaptcha_site"] ?? '' );
				if ( $rc_site ) :
					?>
					<div class="mm-form-field mm-form-recaptcha">
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $rc_site ); ?>" data-size="invisible" data-callback="mmContactRecaptchaCallback"></div>
					</div>
					<script src="https://www.google.com/recaptcha/api.js" async defer></script>
				<?php endif; ?>

				<div class="mm-form-field mm-form-submit">
					<button type="submit" id="mm-contact-submit" class="mm-contact-submit-btn">
						<?php echo esc_html( $biz["contact_form_submit_text"] ?? __( 'Submit', 'metamanager' ) ); ?>
					</button>
				</div>

				<div class="mm-form-status" id="mm-contact-status"></div>
			</form>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

get_footer();
