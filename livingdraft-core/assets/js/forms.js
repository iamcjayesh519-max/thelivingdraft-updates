/**
 * Forms — submit handling for the contact and subscribe forms.
 *
 * === WHAT THIS BINDS TO ===
 *
 * Two selectors, for two reasons:
 *
 *   [data-ld-form]    the plugin's own shortcodes.
 *   .ld-signup-form   the theme's newsletter bar, which has carried this
 *                     class and a correct nonce for several versions with
 *                     no handler attached to it. See the header of
 *                     inc/forms/forms-subscribe.php.
 *
 * Binding to the theme's class means the existing signup bar starts working
 * on activation, with no theme edit and no markup change.
 *
 * === WHY IT DOES NOT USE fetch's DEFAULT ERROR HANDLING ===
 *
 * fetch only rejects on a network failure. A 500 from the server resolves
 * normally with ok === false, so `.then(r => r.json())` on an error page
 * throws a parse error that reads as "Unexpected token <" — which then gets
 * shown to a reader as if it meant something. Every branch below produces a
 * sentence a person can act on instead.
 *
 * @package LivingDraftCore
 * @since 4.5.0
 */

(function () {
	'use strict';

	var cfg = window.ldForms || {};
	var i18n = cfg.i18n || {};

	var forms = document.querySelectorAll('[data-ld-form], .ld-signup-form');

	if (!forms.length || !cfg.ajax) {
		return;
	}

	/**
	 * Which endpoint a form talks to.
	 *
	 * The theme's bar has no data-ld-form attribute, so anything without one
	 * is treated as a subscribe form — which is what it is.
	 */
	function actionFor(form) {
		var kind = form.getAttribute('data-ld-form') || 'subscribe';
		return kind === 'contact' ? 'livingdraft_contact' : 'livingdraft_subscribe';
	}

	function statusEl(form) {
		// The plugin's forms use .ld-form__status; the theme's bar uses
		// .ld-signup-note. Both are role="status" already.
		return form.querySelector('.ld-form__status, .ld-signup-note');
	}

	function say(form, text, kind) {
		var el = statusEl(form);

		if (!el) {
			return;
		}

		el.textContent = text;
		el.classList.remove('is-error', 'is-done', 'is-working');

		if (kind) {
			el.classList.add(kind);
		}
	}

	function submitting(form, on) {
		var button = form.querySelector('button[type="submit"], button:not([type])');

		if (button) {
			button.disabled = on;
		}

		form.classList.toggle('is-submitting', on);
	}

	function handle(event) {
		event.preventDefault();

		var form = event.currentTarget;

		if (form.classList.contains('is-submitting')) {
			return;
		}

		/*
		 * Validate before sending. novalidate is on the markup so the
		 * browser's own bubbles do not fire — they are unstyleable and
		 * appear in the wrong place on a footer bar — but the constraints
		 * are still there to be checked.
		 */
		if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
			var firstBad = form.querySelector(':invalid');

			say(form, firstBad && firstBad.validationMessage
				? firstBad.validationMessage
				: 'Please check the form.', 'is-error');

			if (firstBad) {
				firstBad.focus();
			}

			return;
		}

		submitting(form, true);
		say(form, i18n.sending || 'Sending…', 'is-working');

		var body = new FormData(form);
		body.append('action', actionFor(form));

		fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			// See the file header: a non-2xx still resolves here.
			if (!response.ok) {
				throw new Error('http ' + response.status);
			}
			return response.json();
		}).then(function (res) {
			submitting(form, false);

			if (res && res.success) {
				say(form, (res.data && res.data.message) || 'Thank you.', 'is-done');

				/*
				 * Clear the fields but leave the form in place. Replacing it
				 * with a thank-you block loses the status region that was
				 * just announced, so a screen reader user hears nothing.
				 */
				form.reset();
				return;
			}

			say(form, (res && res.data && res.data.message) || i18n.failed, 'is-error');
		}).catch(function () {
			submitting(form, false);
			say(form, i18n.offline || i18n.failed, 'is-error');
		});
	}

	Array.prototype.forEach.call(forms, function (form) {
		form.addEventListener('submit', handle);
	});
}());
