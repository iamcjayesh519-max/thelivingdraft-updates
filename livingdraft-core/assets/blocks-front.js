/**
 * Front-end behaviour for the article blocks.
 * No library, no build step, runs deferred.
 */
(function () {
	'use strict';

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
