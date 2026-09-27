/**
 * Living Draft - the fact-check panel.
 *
 * One button, one request, one rendered result. The server does all the
 * thinking; this only manages the wait, which can be twenty or thirty
 * seconds because a check is one AI call plus up to eight lookups plus a
 * page fetch or two.
 */
(function () {
	'use strict';

	var config = window.livingdraftFactCheck;

	if (!config) {
		return;
	}

	function init() {
		var panel = document.querySelector('.ld-fc');

		if (!panel) {
			return;
		}

		var button  = panel.querySelector('.ld-fc-run');
		var status  = panel.querySelector('.ld-fc-status');
		var results = panel.querySelector('.ld-fc-results');
		var postId  = panel.getAttribute('data-post');

		if (!button || !postId) {
			return;
		}

		button.addEventListener('click', function () {
			if (button.disabled) {
				return;
			}

			button.disabled = true;
			status.textContent = config.working;

			/*
			 * The check reads the SAVED content, not what is on screen. A
			 * writer who has typed a paragraph without saving would otherwise
			 * get a result for the previous draft and have no way to tell.
			 * Saving first is the only honest option, and wp.data is
			 * available in the block editor without adding a dependency.
			 */
			var save = Promise.resolve();

			if (window.wp && wp.data && wp.data.dispatch('core/editor')) {
				var editor = wp.data.select('core/editor');

				if (editor && editor.isEditedPostDirty && editor.isEditedPostDirty()) {
					status.textContent = 'Saving the draft first…';
					save = wp.data.dispatch('core/editor').savePost();
				}
			}

			save.then(function () {
				status.textContent = config.working;
				return run(postId);
			}).then(function (data) {
				results.innerHTML = data.html;
				status.textContent = '';
			}).catch(function (error) {
				status.textContent = (error && error.message) ? error.message : config.failed;
			}).then(function () {
				button.disabled = false;
			});
		});
	}

	function run(postId) {
		var body = new URLSearchParams();
		body.append('action', 'ld_factcheck_run');
		body.append('nonce', config.nonce);
		body.append('post_id', postId);

		return fetch(config.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				var message = (payload && payload.data && payload.data.message) ? payload.data.message : config.failed;
				throw new Error(message);
			}
			return payload.data;
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
