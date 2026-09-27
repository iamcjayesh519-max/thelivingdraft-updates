/**
 * Mail admin: the drafting buttons.
 *
 * Every button here writes into a field. None of them sends anything —
 * sending is a form post with a confirmation, handled server-side.
 *
 * @package LivingDraftCore
 * @since 4.3.0
 */

(function () {
	'use strict';

	var cfg = window.ldMail || {};
	var i18n = cfg.i18n || {};

	function post(action, data) {
		var body = new URLSearchParams();

		body.append('action', action);
		body.append('nonce', cfg.nonce);

		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});

		return fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) {
			return r.json();
		});
	}

	function busy(button, status, on) {
		button.disabled = on;

		if (status) {
			status.textContent = on ? (i18n.working || 'Working…') : '';
		}
	}

	function fail(status, message) {
		if (status) {
			status.textContent = message || i18n.failed || 'That did not work.';
			status.style.color = '#a3271f';
		}
	}

	/* --------------------------------------------------------------
	 * Credential checks
	 *
	 * Each button reports next to its own field rather than into one
	 * shared status line. Three keys on one screen and a single output
	 * area means you cannot tell which one the answer is about.
	 * -------------------------------------------------------------- */

	Array.prototype.forEach.call(document.querySelectorAll('.ld-mail-verify'), function (button) {
		var out = button.parentNode.querySelector('.ld-mail-verify-out');

		button.addEventListener('click', function () {
			button.disabled = true;
			out.className = 'tld-inline-status ld-mail-verify-out';
			out.textContent = i18n.working || 'Checking…';

			var body = new URLSearchParams();
			body.append('action', 'ld_mail_verify');
			body.append('nonce', cfg.verifyNonce);
			body.append('which', button.getAttribute('data-which'));

			fetch(cfg.ajax, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (r) {
				return r.json();
			}).then(function (res) {
				button.disabled = false;

				if (!res.success) {
					out.className = 'tld-inline-status ld-mail-verify-out is-bad';
					out.textContent = (res.data && res.data.message) || i18n.failed;
					return;
				}

				// Findings can include a warning alongside a pass, so the
				// text is joined rather than reduced to a tick.
				var lines = res.data.findings || [];
				var warned = lines.join(' ').indexOf('Warning') !== -1;

				out.className = 'tld-inline-status ld-mail-verify-out' + (warned ? ' is-warn' : ' is-good');
				out.textContent = lines.join(' ');
			}).catch(function () {
				button.disabled = false;
				out.className = 'tld-inline-status ld-mail-verify-out is-bad';
				out.textContent = i18n.failed || 'That did not work.';
			});
		});
	});

	/* --------------------------------------------------------------
	 * Draft a newsletter
	 * -------------------------------------------------------------- */

	var draftBtn = document.getElementById('ld-ai-newsletter');
	var bodyField = document.getElementById('ld-body');
	var aiStatus = document.getElementById('ld-ai-status');

	if (draftBtn && bodyField) {
		draftBtn.addEventListener('click', function () {
			// Overwriting work someone has already done is the one mistake
			// a draft button can make that cannot be undone with Ctrl-Z
			// after a page reload. Ask first, but only when there is
			// actually something to lose.
			if (bodyField.value.trim() && !window.confirm(i18n.replace)) {
				return;
			}

			busy(draftBtn, aiStatus, true);

			post('ld_mail_ai_newsletter', { days: 7 }).then(function (res) {
				busy(draftBtn, aiStatus, false);

				if (!res.success) {
					fail(aiStatus, res.data && res.data.message);
					return;
				}

				bodyField.value = res.data.html;
				aiStatus.style.color = '#63635c';
				aiStatus.textContent = res.data.posts + ' stories used. Read it before you send it.';
			}).catch(function () {
				busy(draftBtn, aiStatus, false);
				fail(aiStatus);
			});
		});
	}

	/* --------------------------------------------------------------
	 * Subject lines
	 * -------------------------------------------------------------- */

	var subjBtn = document.getElementById('ld-ai-subjects');
	var subjList = document.getElementById('ld-ai-subject-list');
	var subjField = document.getElementById('ld-subject');

	if (subjBtn && subjList && bodyField && subjField) {
		subjBtn.addEventListener('click', function () {
			busy(subjBtn, aiStatus, true);
			subjList.innerHTML = '';

			post('ld_mail_ai_subjects', { body: bodyField.value }).then(function (res) {
				busy(subjBtn, aiStatus, false);

				if (!res.success) {
					fail(aiStatus, res.data && res.data.message);
					return;
				}

				res.data.suggestions.forEach(function (item) {
					var row = document.createElement('div');
					row.style.cssText = 'padding:8px 0;border-top:1px solid #e6e3da;';

					var text = document.createElement('div');
					var strong = document.createElement('strong');
					strong.textContent = item.subject;
					text.appendChild(strong);

					if (item.preview) {
						var small = document.createElement('div');
						small.style.cssText = 'color:#63635c;font-size:12px;';
						small.textContent = item.preview;
						text.appendChild(small);
					}

					var use = document.createElement('button');
					use.type = 'button';
					use.className = 'button';
					use.textContent = i18n.use || 'Use this';
					use.style.marginTop = '6px';

					use.addEventListener('click', function () {
						subjField.value = item.subject;
						subjField.focus();
					});

					row.appendChild(text);
					row.appendChild(use);
					subjList.appendChild(row);
				});
			}).catch(function () {
				busy(subjBtn, aiStatus, false);
				fail(aiStatus);
			});
		});
	}

	/* --------------------------------------------------------------
	 * Reply drafts
	 * -------------------------------------------------------------- */

	var replyBtn = document.getElementById('ld-ai-reply');
	var replyMsg = document.getElementById('ld-reply-message');
	var replyIntent = document.getElementById('ld-reply-intent');
	var replyOut = document.getElementById('ld-reply-output');
	var replyStatus = document.getElementById('ld-reply-status');

	if (replyBtn && replyMsg && replyOut) {
		replyBtn.addEventListener('click', function () {
			busy(replyBtn, replyStatus, true);
			replyOut.innerHTML = '';

			post('ld_mail_ai_reply', {
				message: replyMsg.value,
				intent: replyIntent ? replyIntent.value : ''
			}).then(function (res) {
				busy(replyBtn, replyStatus, false);

				if (!res.success) {
					fail(replyStatus, res.data && res.data.message);
					return;
				}

				res.data.drafts.forEach(function (draft) {
					var card = document.createElement('div');
					card.style.cssText = 'margin-top:14px;padding:14px;border:1px solid #d8d5cc;background:#fdfdfb;';

					var head = document.createElement('p');
					head.style.cssText = 'margin:0 0 8px;font-weight:600;';
					head.textContent = draft.label;

					var area = document.createElement('textarea');
					area.rows = 6;
					area.className = 'large-text';
					area.value = draft.body;

					var copy = document.createElement('button');
					copy.type = 'button';
					copy.className = 'button';
					copy.textContent = i18n.copy || 'Copy';
					copy.style.marginTop = '6px';

					copy.addEventListener('click', function () {
						area.select();

						// Clipboard API needs a secure context; execCommand
						// still works on a plain-HTTP staging site, which is
						// where this is most likely to be used.
						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(area.value);
						} else {
							document.execCommand('copy');
						}

						copy.textContent = i18n.copied || 'Copied';
						window.setTimeout(function () {
							copy.textContent = i18n.copy || 'Copy';
						}, 1500);
					});

					card.appendChild(head);
					card.appendChild(area);
					card.appendChild(copy);
					replyOut.appendChild(card);
				});
			}).catch(function () {
				busy(replyBtn, replyStatus, false);
				fail(replyStatus);
			});
		});
	}
}());
