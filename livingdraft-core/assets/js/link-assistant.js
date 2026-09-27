/**
 * Link assistant — the panel on the post edit screen.
 *
 * Reads the article as it is in the editor right now (unsaved changes
 * included), asks the server for checked suggestions, and shows each one as
 * Before / After with Accept and Skip. Accept edits the paragraph in the
 * editor; nothing is saved until the editor presses Update.
 *
 * Works in the block editor and the classic editor. Plain JavaScript, no
 * build step.
 *
 * @package LivingDraftCore
 * @since 4.7.0
 */
(function () {
	'use strict';

	var cfg = window.ldLinkAssistant || { i18n: {} };
	var t = cfg.i18n;

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text !== undefined && text !== null) { node.textContent = text; }
		return node;
	}

	/* ---- The editor, whichever one is in use ------------------------ */

	function blockEditor() {
		var wp = window.wp;
		if (wp && wp.data && wp.data.select('core/block-editor') && wp.data.select('core/editor')) {
			return wp.data;
		}
		return null;
	}

	function classicEditor() {
		var mce = window.tinymce && window.tinymce.get('content');
		if (mce && !mce.isHidden()) { return { mce: mce }; }
		var area = document.getElementById('content');
		return area ? { area: area } : null;
	}

	function currentContent() {
		var data = blockEditor();
		if (data) { return data.select('core/editor').getEditedPostContent(); }
		var classic = classicEditor();
		if (classic && classic.mce) { return classic.mce.getContent(); }
		if (classic && classic.area) { return classic.area.value; }
		return '';
	}

	function htmlOf(value) {
		if (typeof value === 'string') { return value; }
		if (value && typeof value.toHTMLString === 'function') { return value.toHTMLString(); }
		return value ? String(value) : '';
	}

	// The same sentence may be escaped slightly differently in a block's
	// attribute than in the saved HTML. Try the sensible forms.
	function variants(s) {
		var out = [s];
		out.push(s.replace(/&amp;/g, '&'));
		out.push(s.replace(/&(?!amp;|lt;|gt;|#|[a-z]+;)/g, '&amp;'));
		return out.filter(function (v, i) { return out.indexOf(v) === i; });
	}

	function replaceOnce(html, needle, replacement) {
		var found = null;
		variants(needle).some(function (v) {
			if (html.indexOf(v) !== -1) { found = v; return true; }
			return false;
		});
		if (!found) { return null; }
		return html.replace(found, function () { return replacement; });
	}

	function applyInBlocks(data, needle, replacement) {
		var blocks = data.select('core/block-editor').getBlocks();
		var stack = blocks.slice();
		while (stack.length) {
			var block = stack.shift();
			if (block.name === 'core/paragraph') {
				var html = htmlOf(block.attributes.content);
				var next = replaceOnce(html, needle, replacement);
				if (next !== null) {
					data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { content: next });
					return true;
				}
			}
			if (block.innerBlocks && block.innerBlocks.length) {
				stack = stack.concat(block.innerBlocks);
			}
		}
		return false;
	}

	function apply(suggestion) {
		var data = blockEditor();
		if (data) { return applyInBlocks(data, suggestion.needle, suggestion.replacement); }

		var classic = classicEditor();
		if (!classic) { return false; }

		var html = classic.mce ? classic.mce.getContent() : classic.area.value;
		var next = replaceOnce(html, suggestion.needle, suggestion.replacement);
		if (next === null) { return false; }

		if (classic.mce) {
			classic.mce.setContent(next);
			classic.mce.setDirty(true);
		} else {
			classic.area.value = next;
		}
		return true;
	}

	/* ---- Rendering --------------------------------------------------- */

	// Show the rewritten sentence with the anchor highlighted.
	function afterNode(s) {
		var p = el('p', 'ld-la-after');
		var i = s.rewritten.indexOf(s.anchor);
		if (i === -1) {
			p.textContent = s.rewritten;
			return p;
		}
		p.appendChild(document.createTextNode(s.rewritten.slice(0, i)));
		var mark = el('mark', 'ld-la-anchor', s.anchor);
		p.appendChild(mark);
		p.appendChild(document.createTextNode(s.rewritten.slice(i + s.anchor.length)));
		return p;
	}

	function card(s) {
		var box = el('div', 'ld-la-card');

		var target = el('div', 'ld-la-target');
		target.appendChild(el('span', 'ld-la-label', t.linksTo));
		var link = el('a', null, s.target.title);
		link.href = s.target.url;
		link.target = '_blank';
		link.rel = 'noopener';
		target.appendChild(link);
		var score = el('span', 'ld-la-score', t.reliability + ' ' + s.target.score + '/100');
		target.appendChild(score);
		box.appendChild(target);

		if (s.target.reasons && s.target.reasons.length) {
			box.appendChild(el('p', 'ld-la-reasons', s.target.reasons.join(' · ')));
		}
		if (s.why) {
			box.appendChild(el('p', 'ld-la-why', s.why));
		}

		if (s.changed) {
			box.appendChild(el('span', 'ld-la-label', t.before));
			box.appendChild(el('p', 'ld-la-before', s.original));
			box.appendChild(el('span', 'ld-la-label', t.after));
		} else {
			box.appendChild(el('p', 'ld-la-note', t.onlyLink));
		}
		box.appendChild(afterNode(s));

		var row = el('div', 'ld-la-actions');
		var yes = el('button', 'button button-primary', t.accept);
		var no = el('button', 'button', t.skip);
		yes.type = no.type = 'button';
		var result = el('span', 'ld-la-result');

		yes.addEventListener('click', function () {
			if (apply(s)) {
				box.classList.add('is-accepted');
				result.textContent = t.accepted;
			} else {
				result.textContent = t.notFound;
				box.classList.add('is-failed');
			}
			yes.disabled = no.disabled = true;
		});
		no.addEventListener('click', function () {
			box.classList.add('is-skipped');
			result.textContent = t.skipped;
			yes.disabled = no.disabled = true;
		});

		row.appendChild(yes);
		row.appendChild(no);
		row.appendChild(result);
		box.appendChild(row);
		return box;
	}

	function render(panel, data) {
		var out = panel.querySelector('[data-ld-la-results]');
		var status = panel.querySelector('[data-ld-la-status]');
		var b = data.budget;
		out.textContent = '';

		if (b && status) {
			status.textContent = b.words + ' words · ' + b.links + ' links · up to ' + b.allowed +
				' allowed (1 per ' + b.per + ' words) · ' + b.remaining + ' more possible';
		}

		if (data.same_keyword && data.same_keyword.length) {
			var warn = el('div', 'ld-la-warn');
			warn.appendChild(el('p', null, t.sameKeyword));
			var list = el('ul');
			data.same_keyword.forEach(function (item) {
				var li = el('li');
				var a = el('a', null, item.title);
				a.href = item.url;
				a.target = '_blank';
				li.appendChild(a);
				list.appendChild(li);
			});
			warn.appendChild(list);
			out.appendChild(warn);
		}

		if (data.intent) {
			var intent = el('div', 'ld-la-intent');
			intent.appendChild(el('span', 'ld-la-label', t.intent));
			intent.appendChild(el('p', null, data.intent));
			out.appendChild(intent);
		}

		if (data.message) {
			out.appendChild(el('p', 'ld-la-note', data.message));
		}

		(data.suggestions || []).forEach(function (s) {
			out.appendChild(card(s));
		});
	}

	/* ---- Running ----------------------------------------------------- */

	function run(panel, button) {
		var out = panel.querySelector('[data-ld-la-results]');
		out.textContent = '';
		out.appendChild(el('p', 'ld-la-note', t.working));
		button.disabled = true;

		var body = new FormData();
		body.append('action', 'ld_link_assistant');
		body.append('post_id', panel.getAttribute('data-post-id'));
		body.append('nonce', panel.getAttribute('data-nonce'));
		body.append('content', currentContent());

		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success) {
					render(panel, json.data);
				} else {
					out.textContent = '';
					out.appendChild(el('p', 'ld-la-error', (json && json.data && json.data.message) || t.error));
				}
			})
			.catch(function () {
				out.textContent = '';
				out.appendChild(el('p', 'ld-la-error', t.error));
			})
			.then(function () { button.disabled = false; });
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ld-la-run]');
		if (!button) { return; }
		var panel = button.closest('[data-ld-la]');
		if (panel) { run(panel, button); }
	});
})();
