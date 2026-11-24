/**
 * MetaManager Contact Form — submission handling + reCAPTCHA v2 invisible.
 */
(function () {
	'use strict';

	var form = document.getElementById('mm-contact-form');
	if (!form) return;

	var statusEl = document.getElementById('mm-contact-status');
	var submitBtn = document.getElementById('mm-contact-submit');
	var recaptchaReady = false;

	// reCAPTCHA v2 invisible callback.
	window.mmContactRecaptchaCallback = function (token) {
		submitForm(token);
	};

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		clearErrors();

		// Validate required fields.
		var valid = true;
		var name = form.querySelector('[name="mm_cf_name"]');
		var email = form.querySelector('[name="mm_cf_email"]');
		var message = form.querySelector('[name="mm_cf_message"]');
		var terms = form.querySelector('[name="mm_cf_terms"]');

		if (!name.value.trim()) { showError('name', 'Please enter your name'); valid = false; }
		if (!email.value.trim() || !email.validity.valid) { showError('email', 'Please enter a valid email address'); valid = false; }
		if (!message.value.trim()) { showError('message', 'Please write something'); valid = false; }
		if (!terms.checked) { showError('terms', 'Please accept the Terms of Service and Privacy Policy'); valid = false; }

		if (!valid) return;

		// If reCAPTCHA is present, execute it (triggers callback).
		var rc = form.querySelector('.g-recaptcha');
		if (rc && typeof grecaptcha !== 'undefined') {
			submitBtn.disabled = true;
			grecaptcha.execute();
		} else {
			submitForm('');
		}
	});

	function submitForm(token) {
		submitBtn.disabled = true;
		statusEl.className = 'mm-form-status';
		statusEl.style.display = 'none';

		var formData = new FormData(form);
		formData.append('action', 'mm_contact_submit');
		formData.append('mm_recaptcha_token', token || '');

		fetch(form.action || (window.ajaxurl || '/wp-admin/admin-ajax.php'), {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			submitBtn.disabled = false;
			if (data.success) {
				statusEl.className = 'mm-form-status success';
				statusEl.textContent = data.data || 'Thank you. Your message has been sent.';
				statusEl.style.display = 'block';
				form.reset();
				if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
			} else {
				statusEl.className = 'mm-form-status error';
				statusEl.textContent = data.data || 'Something went wrong. Please try again.';
				statusEl.style.display = 'block';
				if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
			}
		})
		.catch(function () {
			submitBtn.disabled = false;
			statusEl.className = 'mm-form-status error';
			statusEl.textContent = 'Network error. Please try again.';
			statusEl.style.display = 'block';
		});
	}

	function showError(field, msg) {
		var el = form.querySelector('[data-field="' + field + '"]');
		if (el) el.textContent = msg;
	}

	function clearErrors() {
		var els = form.querySelectorAll('.mm-field-error');
		for (var i = 0; i < els.length; i++) els[i].textContent = '';
	}
})();
