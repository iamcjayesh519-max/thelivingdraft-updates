/**
 * Front-end behaviour for the article blocks.
 * No library, no build step, runs deferred.
 */
(function () {
	'use strict';

	/* ---- Cookie consent ---------------------------------------------
	 *
	 * Everything is decided here, in the reader's own browser, because the
	 * page itself is usually a cached copy that is identical for everyone.
	 *
	 * The head script printed by inc/consent.php has already told Google
	 * Consent Mode what this reader chose last time. This part shows the
	 * banner to readers who have not answered, applies a new answer at once
	 * (no reload), and lets anyone reopen the banner through a link to
	 * #cookie-settings or [data-ld-consent-open].
	 * ------------------------------------------------------------------ */

	var consentConfig = window.ldConsentConfig || {};

	function readConsent() {
		var match = document.cookie.match(/(?:^|;\s*)ld_consent=(yes|no)/);
		return match ? match[1] : null;
	}

	function writeConsent(value) {
		var days = 365;
		var expires = new Date(Date.now() + days * 864e5).toUTCString();
		var secure = location.protocol === 'https:' ? ';Secure' : '';
		// max-age and expires both, so older browsers keep it too.
		document.cookie = 'ld_consent=' + value +
			';path=/;max-age=' + (days * 86400) +
			';expires=' + expires +
			';SameSite=Lax' + secure;
	}

	// Strict mode ships Google's tag as inert text/plain. Make it real.
	function activateGatedScripts() {
		var inert = document.querySelectorAll('script[type="text/plain"][data-ld-consent-src]');
		Array.prototype.forEach.call(inert, function (old) {
			var live = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (attr) {
				if (attr.name !== 'type' && attr.name !== 'data-ld-consent-src') {
					live.setAttribute(attr.name, attr.value);
				}
			});
			live.async = true;
			live.src = old.getAttribute('data-ld-consent-src');
			old.parentNode.replaceChild(live, old);
		});
	}

	// Withdrawing consent should also remove what was stored under it.
	function clearAnalyticsCookies() {
		var host = location.hostname;
		var parts = host.split('.');
		var domains = [''];
		for (var i = 0; i < parts.length - 1; i++) {
			domains.push(';domain=.' + parts.slice(i).join('.'));
		}
		document.cookie.split(';').forEach(function (pair) {
			var name = pair.split('=')[0].trim();
			if (/^(_ga|_gid|_gat|_gcl)/.test(name)) {
				domains.forEach(function (d) {
					document.cookie = name + '=;path=/;max-age=0;expires=Thu, 01 Jan 1970 00:00:00 GMT' + d;
				});
			}
		});
	}

	function applyConsent(choice, isNewAnswer) {
		var yes = choice === 'yes';
		var ads = yes && !!consentConfig.ads;

		if (isNewAnswer && typeof window.gtag === 'function') {
			window.gtag('consent', 'update', {
				analytics_storage: yes ? 'granted' : 'denied',
				ad_storage: ads ? 'granted' : 'denied',
				ad_user_data: ads ? 'granted' : 'denied',
				ad_personalization: ads ? 'granted' : 'denied'
			});
		}

		// Tell the WP Consent API too, if that plugin is installed, so
		// Site Kit's own consent handling agrees with this banner.
		if (isNewAnswer && typeof window.wp_set_consent === 'function') {
			window.wp_set_consent('statistics', yes ? 'allow' : 'deny');
			window.wp_set_consent('marketing', ads ? 'allow' : 'deny');
		}

		if (yes) {
			activateGatedScripts();
		} else if (isNewAnswer) {
			clearAnalyticsCookies();
		}
	}

	var banner = document.querySelector('[data-ld-consent-banner]');

	if (banner) {
		var answered = readConsent();

		if (answered) {
			// Consent Mode already knows (the head script read the cookie).
			// Only strict mode still has a script to switch on.
			applyConsent(answered, false);
		} else {
			banner.hidden = false;
		}

		banner.addEventListener('click', function (event) {
			var button = event.target.closest('[data-ld-consent]');
			if (!button) {
				return;
			}

			var choice = button.getAttribute('data-ld-consent');
			writeConsent(choice);
			banner.hidden = true;
			applyConsent(choice, true);
		});

		// "Cookie settings" links: a menu item pointing at #cookie-settings,
		// or anything carrying data-ld-consent-open.
		document.addEventListener('click', function (event) {
			var opener = event.target.closest('[data-ld-consent-open], a[href$="#cookie-settings"]');
			if (!opener) {
				return;
			}
			event.preventDefault();
			banner.hidden = false;
			var first = banner.querySelector('[data-ld-consent]');
			if (first) {
				first.focus();
			}
		});
	}

	/* ---- Table of contents ------------------------------------------ */

	var toc = document.querySelector('[data-ld-toc]');
	if (toc) {
		var scope = toc.closest('.entry-content') || document.body;
		var heads = scope.querySelectorAll('h2:not(.ld-toc-title):not(.ld-faq-title), h3');
		var list = document.createElement('ol');
		var made = 0;

		Array.prototype.forEach.call(heads, function (head, index) {
			var text = (head.textContent || '').trim();
			if (!text) {
				return;
			}
			if (!head.id) {
				head.id = 'sec-' + index + '-' + text.toLowerCase()
					.replace(/[^a-z0-9\s-]/g, '')
					.trim().replace(/\s+/g, '-').slice(0, 40);
			}
			var li = document.createElement('li');
			if (head.tagName === 'H3') {
				li.className = 'is-sub';
			}
			var a = document.createElement('a');
			a.href = '#' + head.id;
			a.textContent = text;
			li.appendChild(a);
			list.appendChild(li);
			made++;
		});

		var placeholder = toc.querySelector('.ld-toc-placeholder');
		if (made > 1) {
			if (placeholder) {
				placeholder.remove();
			}
			toc.appendChild(list);
		} else {
			// Not enough headings to be worth a contents list.
			toc.style.display = 'none';
		}
	}

	/* ---- Newsletter form -------------------------------------------- */

	Array.prototype.forEach.call(document.querySelectorAll('.ld-signup-form'), function (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var note = form.querySelector('.ld-signup-note');
			var button = form.querySelector('button');
			var data = new FormData(form);
			data.append('action', 'livingdraft_subscribe');

			if (button) {
				button.disabled = true;
			}
			if (note) {
				note.textContent = '';
			}

			fetch(form.getAttribute('data-endpoint'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (note) {
						note.textContent = (res.data && res.data.message) || '';
						// classList, not className: the element also carries
						// signup-note, which is what the theme actually styles.
						note.classList.remove('is-good', 'is-bad');
						note.classList.add(res.success ? 'is-good' : 'is-bad');
					}
					if (res.success) {
						form.reset();
					}
				})
				.catch(function () {
					if (note) {
						note.textContent = (window.livingdraftL10n && window.livingdraftL10n.networkError)
							|| 'Could not reach the server. Please try again.';
						note.classList.remove('is-good');
						note.classList.add('is-bad');
					}
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
					}
				});
		});
	});
}());
