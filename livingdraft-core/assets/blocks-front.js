/**
 * Front-end behaviour for the article blocks.
 * No library, no build step, runs deferred.
 */
(function () {
	'use strict';

	/* ---- Cookie consent ---------------------------------------------
	 *
	 * The banner markup is always in the page, hidden. The decision about
	 * whether to SHOW it happens here, in the reader's own browser, by
	 * reading their cookie.
	 *
	 * This is deliberate. If PHP decided, a page cache would freeze that
	 * decision into the saved HTML and serve it to everybody — so the banner
	 * would keep reappearing after someone accepted it.
	 * ------------------------------------------------------------------ */

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

	var banner = document.querySelector('[data-ld-consent-banner]');

	if (banner) {
		var answered = readConsent();

		if (answered) {
			// Already decided. Take it out of the page entirely.
			banner.remove();
		} else {
			banner.hidden = false;

			banner.addEventListener('click', function (event) {
				var button = event.target.closest('[data-ld-consent]');
				if (!button) {
					return;
				}

				var choice = button.getAttribute('data-ld-consent');
				writeConsent(choice);

				// Did it actually stick? Some privacy modes block cookies
				// outright. Better to hide the banner than to nag forever.
				var stored = readConsent();
				banner.remove();

				/*
				 * Accepting needs one reload, because the analytics script was
				 * never put on the page. The flag stops that becoming a loop
				 * if a cache serves the same copy back.
				 */
				if (choice === 'yes' && stored === 'yes') {
					try {
						if (!window.sessionStorage.getItem('ldConsentReload')) {
							window.sessionStorage.setItem('ldConsentReload', '1');
							window.location.reload();
						}
					} catch (e) {
						// sessionStorage unavailable; skip the reload rather
						// than risk reloading over and over.
					}
				}
			});
		}
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
