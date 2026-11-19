<?php
/**
 * Admin tab — Contact Form settings.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;
?>
<div class="mm-meta-panel" id="page-contact-form">

	<h2>Form Settings</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Contact Form</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[contact_form][enabled]" value="1"
						<?php checked( $s->get('contact_form.enabled', true) ); ?>>
					Display the contact form on the contact page
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_cf_to_email">To Email Address</label></th>
			<td>
				<input type="email" id="gcm_cf_to_email"
					name="<?php echo esc_attr($opt); ?>[contact_form][to_email]"
					value="<?php echo esc_attr( $s->get('contact_form.to_email','') ); ?>"
					class="regular-text" placeholder="admin@domain.com">
				<p class="description">Where contact form submissions are delivered. Defaults to the site admin email if blank.</p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_cf_subject_prefix">Subject Prefix</label></th>
			<td>
				<input type="text" id="gcm_cf_subject_prefix"
					name="<?php echo esc_attr($opt); ?>[contact_form][subject_prefix]"
					value="<?php echo esc_attr( $s->get('contact_form.subject_prefix','') ); ?>"
					class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo('name') ); ?>">
				<p class="description">Prefix added to all incoming email subjects. Defaults to the site name if blank.</p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_cf_submit_text">Submit Button Text</label></th>
			<td>
				<input type="text" id="gcm_cf_submit_text"
					name="<?php echo esc_attr($opt); ?>[contact_form][submit_text]"
					value="<?php echo esc_attr( $s->get('contact_form.submit_text','Submit') ); ?>"
					class="regular-text">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_cf_tos_page">Terms of Service Page</label></th>
			<td>
				<?php
				wp_dropdown_pages( [
					'name'             => $opt . '[contact_form][tos_page_id]',
					'id'               => 'gcm_cf_tos_page',
					'selected'         => (int) $s->get( 'contact_form.tos_page_id', 0 ),
					'show_option_none' => '— Select Page —',
					'option_none_value' => 0,
				] );
				?>
				<p class="description">The page containing your Terms of Service. Linked from the form's agreement checkbox. Privacy Policy link uses WordPress core settings automatically.</p>
			</td>
		</tr>
	</table>

	<h2>Google reCAPTCHA v2 Invisible</h2>
	<p class="description" style="margin-bottom:12px">
		Keys from <a href="https://www.google.com/recaptcha" target="_blank" rel="noopener">Google reCAPTCHA</a>.
		Choose <strong>reCAPTCHA v2 → Invisible reCAPTCHA</strong> when creating the keys.
		Leave blank to disable reCAPTCHA (honeypot + browser check still active).
	</p>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_cf_rc_site">Site Key</label></th>
			<td>
				<input type="text" id="gcm_cf_rc_site"
					name="<?php echo esc_attr($opt); ?>[contact_form][recaptcha_site]"
					value="<?php echo esc_attr( $s->get('contact_form.recaptcha_site','') ); ?>"
					class="regular-text" placeholder="6L...">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_cf_rc_secret">Secret Key</label></th>
			<td>
				<input type="password" id="gcm_cf_rc_secret"
					name="<?php echo esc_attr($opt); ?>[contact_form][recaptcha_secret]"
					value="<?php echo esc_attr( $s->get('contact_form.recaptcha_secret','') ); ?>"
					class="regular-text" placeholder="6L...">
			</td>
		</tr>
	</table>

</div>
