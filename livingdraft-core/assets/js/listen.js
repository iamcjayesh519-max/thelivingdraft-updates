/**
 * Listen — reads the article aloud using the browser's own speech synthesiser.
 *
 * === THE THREE BUGS THIS FILE IS MOSTLY ABOUT ===
 *
 * The Web Speech API looks like a four-line feature. It is not, and the
 * reason is that the three biggest engines each ship a long-standing defect
 * that only appears on exactly this use case — a long piece of text.
 *
 * 1. CHROME STOPS AFTER ~15 SECONDS.
 *    Chrome's synthesiser goes silent partway through any utterance longer
 *    than roughly fifteen seconds, with no error and no 'end' event. It has
 *    done this since 2016. The workaround is to split the text so no single
 *    utterance is long enough to hit it, and to ping pause()/resume() on a
 *    timer, which resets the internal watchdog. Both are done below.
 *
 * 2. THE VOICE LIST IS EMPTY ON FIRST READ.
 *    getVoices() returns [] until the engine has loaded them, and the
 *    'voiceschanged' event that announces this fires at different times per
 *    browser — and on some, more than once. So the list is populated
 *    reactively, not read once at startup.
 *
 * 3. iOS WILL NOT SPEAK WITHOUT A REAL TAP.
 *    Safari on iOS refuses speech that was not initiated inside a user
 *    gesture. Not "the page had a gesture at some point" — the speak() call
 *    must happen in the handler. This is why the first speak() fires directly
 *    inside the click listener, before anything async, and why resuming from
 *    a stored position never auto-plays.
 *
 * === WHY IT READS THE PAGE INSTEAD OF A STRING ===
 *
 * See the header of inc/listen/listen.php. Short version: the element being
 * highlighted is the element being spoken, so there is nothing to keep in
 * sync.
 *
 * @package LivingDraftCore
 * @since 4.2.0
 */

(function () {
	'use strict';

	var cfg = window.ldListen || {};
	var root = document.querySelector('[data-ld-listen]');

	if (!root) {
		return;
	}

	var synth = window.speechSynthesis;

	// No speech engine: leave the player hidden and stop. A visible control
	// that cannot work is worse than no control.
	if (!synth || typeof window.SpeechSynthesisUtterance === 'undefined') {
		return;
	}

	var article = document.querySelector(cfg.selector || '.entry-content');

	if (!article) {
		return;
	}

	var i18n = cfg.i18n || {};
	var postId = root.getAttribute('data-post') || '0';
	var storeKey = 'ld-listen-' + postId;
	var prefsKey = 'ld-listen-prefs';

	var playBtn = root.querySelector('.ld-listen__play');
	var labelEl = root.querySelector('.ld-listen__label');
	var fillEl = root.querySelector('.ld-listen__fill');
	var statusEl = root.querySelector('.ld-listen__status');
	var toggleBtn = root.querySelector('.ld-listen__toggle');
	var panel = root.querySelector('.ld-listen__panel');
	var voiceSel = root.querySelector('.ld-listen__voice');
	var rateSel = root.querySelector('.ld-listen__rate');

	var chunks = [];
	var index = 0;
	var playing = false;
	var paused = false;
	var keepAlive = null;

	/* ------------------------------------------------------------------
	 * 1. BUILDING THE CHUNKS
	 * ------------------------------------------------------------------ */

	/*
	 * What gets skipped, and why each one.
	 *
	 * pre / code      — source code read aloud character by character is
	 *                   noise. A listener wanting the code will look at it.
	 * figcaption      — a caption spoken between two paragraphs sounds like
	 *                   a sentence of the article, and reads as a non
	 *                   sequitur, because it belongs to something visual
	 *                   that the listener is not looking at.
	 * table           — a table read as a flat run of cells is unusable.
	 * .ld-toc         — a table of contents is a navigation aid. Speaking a
	 *                   list of headings before the article starts is the
	 *                   audio equivalent of reading out the index of a book.
	 * .ld-listen      — the player's own text.
	 * [aria-hidden]   — already declared decorative for assistive tech;
	 *                   speech is the same audience.
	 */
	var SKIP = 'pre, code, table, figcaption, .ld-toc, .ld-listen, .ld-newsletter, [aria-hidden="true"], [data-listen="skip"]';
	var TAKE = 'p, h2, h3, h4, li, blockquote';

	function shouldSkip(el) {
		return !!el.closest(SKIP);
	}

	function build() {
		chunks = [];

		var nodes = article.querySelectorAll(TAKE);

		Array.prototype.forEach.call(nodes, function (el) {
			if (shouldSkip(el)) {
				return;
			}

			// A list item inside a blockquote would otherwise be collected
			// twice — once as the li, once inside the blockquote's text.
			if (el.tagName === 'BLOCKQUOTE' && el.querySelector('p, li')) {
				return;
			}

			var text = (el.textContent || '').replace(/\s+/g, ' ').trim();

			if (text.length < 2) {
				return;
			}

			/*
			 * Split anything long enough to trip the Chrome watchdog. The
			 * split is on sentence ends so the pause lands where a reader
			 * would pause anyway; a paragraph with no sentence ends (rare,
			 * but a long list item can be one) falls through as a single
			 * piece and is capped by length instead.
			 */
			splitLong(text).forEach(function (part) {
				chunks.push({ el: el, text: part });
			});
		});

		return chunks.length > 0;
	}

	// ~220 characters is comfortably under fifteen seconds at any speed a
	// person would actually choose.
	var MAX = 220;

	function splitLong(text) {
		if (text.length <= MAX) {
			return [text];
		}

		var out = [];
		var sentences = text.match(/[^.!?]+[.!?]+["')\]]*\s*|[^.!?]+$/g) || [text];
		var buffer = '';

		sentences.forEach(function (sentence) {
			if ((buffer + sentence).length > MAX && buffer) {
				out.push(buffer.trim());
				buffer = '';
			}

			// A single sentence over the cap: break it on commas, then
			// hard-break whatever is left.
			while (sentence.length > MAX) {
				var cut = sentence.lastIndexOf(',', MAX);
				if (cut < MAX / 2) {
					cut = sentence.lastIndexOf(' ', MAX);
				}
				if (cut < 1) {
					cut = MAX;
				}
				out.push(sentence.slice(0, cut).trim());
				sentence = sentence.slice(cut);
			}

			buffer += sentence;
		});

		if (buffer.trim()) {
			out.push(buffer.trim());
		}

		return out;
	}

	/* ------------------------------------------------------------------
	 * 2. VOICES
	 * ------------------------------------------------------------------ */

	var voices = [];

	function loadVoices() {
		var all = synth.getVoices() || [];

		if (!all.length) {
			return;
		}

		// Prefer voices matching the site language, but never hide the
		// others — a reader may deliberately want a different accent, and
		// on some Linux builds nothing matches the page language at all.
		var lang = (cfg.lang || document.documentElement.lang || 'en').toLowerCase().slice(0, 2);

		voices = all.slice().sort(function (a, b) {
			var am = a.lang.toLowerCase().indexOf(lang) === 0 ? 0 : 1;
			var bm = b.lang.toLowerCase().indexOf(lang) === 0 ? 0 : 1;
			if (am !== bm) {
				return am - bm;
			}
			return a.name.localeCompare(b.name);
		});

		var saved = prefs().voice;
		voiceSel.innerHTML = '';

		voices.forEach(function (v, i) {
			var opt = document.createElement('option');
			opt.value = String(i);
			opt.textContent = v.name + ' (' + v.lang + ')';
			if (saved && v.name === saved) {
				opt.selected = true;
			}
			voiceSel.appendChild(opt);
		});
	}

	loadVoices();

	// Fires late, and on some browsers more than once. Both are fine —
	// loadVoices is idempotent.
	if (typeof synth.onvoiceschanged !== 'undefined') {
		synth.addEventListener('voiceschanged', loadVoices);
	}

	function currentVoice() {
		var i = parseInt(voiceSel.value, 10);
		return voices[i] || null;
	}

	/* ------------------------------------------------------------------
	 * 3. PREFERENCES AND POSITION
	 * ------------------------------------------------------------------ */

	/*
	 * localStorage is wrapped because it throws rather than returning null
	 * in Safari private mode and when a browser is configured to block
	 * site data. A reader with cookies locked down should still be able to
	 * press play; they just do not get their speed remembered.
	 */
	function prefs() {
		try {
			return JSON.parse(localStorage.getItem(prefsKey) || '{}') || {};
		} catch (e) {
			return {};
		}
	}

	function savePrefs(next) {
		try {
			localStorage.setItem(prefsKey, JSON.stringify(Object.assign(prefs(), next)));
		} catch (e) { /* Storage unavailable. Not worth telling anyone. */ }
	}

	function savePosition(i) {
		try {
			if (i > 0) {
				localStorage.setItem(storeKey, String(i));
			} else {
				localStorage.removeItem(storeKey);
			}
		} catch (e) { /* As above. */ }
	}

	function savedPosition() {
		try {
			return parseInt(localStorage.getItem(storeKey) || '0', 10) || 0;
		} catch (e) {
			return 0;
		}
	}

	rateSel.value = String(prefs().rate || cfg.rate || 1);

	/* ------------------------------------------------------------------
	 * 4. SPEAKING
	 * ------------------------------------------------------------------ */

	function highlight(el) {
		if (!cfg.highlight) {
			return;
		}

		var prev = article.querySelector('.ld-listen-current');

		if (prev) {
			prev.classList.remove('ld-listen-current');
		}

		if (el) {
			el.classList.add('ld-listen-current');
		}
	}

	function progress() {
		var pct = chunks.length ? Math.round((index / chunks.length) * 100) : 0;
		fillEl.style.transform = 'scaleX(' + (pct / 100) + ')';
	}

	function say(i) {
		if (i >= chunks.length) {
			finish();
			return;
		}

		index = i;

		var chunk = chunks[i];
		var utter = new window.SpeechSynthesisUtterance(chunk.text);
		var voice = currentVoice();

		if (voice) {
			utter.voice = voice;
			utter.lang = voice.lang;
		}

		utter.rate = parseFloat(rateSel.value) || 1;

		utter.onstart = function () {
			highlight(chunk.el);
			progress();
			savePosition(i);
		};

		utter.onend = function () {
			// A cancel() also fires onend. Only advance if we still think
			// we are playing, or stopping would immediately restart.
			if (playing && !paused) {
				say(i + 1);
			}
		};

		utter.onerror = function (event) {
			// 'interrupted' and 'canceled' are what a deliberate stop looks
			// like from in here. Anything else is a real failure and should
			// not silently freeze the player.
			if (event.error === 'interrupted' || event.error === 'canceled') {
				return;
			}
			stop();
		};

		synth.speak(utter);
	}

	/*
	 * The Chrome watchdog ping. pause() immediately followed by resume()
	 * resets the internal timer without any audible gap. Cheap, ugly, and
	 * the only thing that works.
	 */
	function startKeepAlive() {
		stopKeepAlive();
		keepAlive = window.setInterval(function () {
			if (playing && !paused && synth.speaking) {
				synth.pause();
				synth.resume();
			}
		}, 10000);
	}

	function stopKeepAlive() {
		if (keepAlive) {
			window.clearInterval(keepAlive);
			keepAlive = null;
		}
	}

	function setLabel(text) {
		labelEl.textContent = text;
	}

	function announce(text) {
		statusEl.textContent = text;
	}

	function play(from) {
		if (!chunks.length && !build()) {
			return;
		}

		playing = true;
		paused = false;

		root.classList.add('is-playing');
		root.classList.remove('is-paused');
		setLabel(i18n.pause || 'Pause');
		playBtn.setAttribute('aria-pressed', 'true');
		announce(i18n.pause ? '' : '');

		// synth.cancel() first: a queued utterance from a previous press
		// would otherwise play underneath this one.
		synth.cancel();
		say(typeof from === 'number' ? from : index);
		startKeepAlive();
	}

	function pause() {
		paused = true;
		playing = true;

		synth.pause();
		stopKeepAlive();

		root.classList.add('is-paused');
		root.classList.remove('is-playing');
		setLabel(i18n.resume || 'Resume');
		playBtn.setAttribute('aria-pressed', 'false');
	}

	function resume() {
		paused = false;

		root.classList.add('is-playing');
		root.classList.remove('is-paused');
		setLabel(i18n.pause || 'Pause');
		playBtn.setAttribute('aria-pressed', 'true');

		/*
		 * Safari's resume() after a long pause sometimes does nothing.
		 * Re-speaking the current chunk from the top is a fraction of a
		 * sentence of repetition, and always works.
		 */
		synth.cancel();
		say(index);
		startKeepAlive();
	}

	function stop() {
		playing = false;
		paused = false;

		synth.cancel();
		stopKeepAlive();
		highlight(null);

		root.classList.remove('is-playing', 'is-paused');
		setLabel(i18n.listen || 'Listen');
		playBtn.setAttribute('aria-pressed', 'false');
	}

	function finish() {
		stop();
		savePosition(0);
		index = 0;
		progress();
		setLabel(i18n.restart || 'Start again');
	}

	/* ------------------------------------------------------------------
	 * 5. WIRING
	 * ------------------------------------------------------------------ */

	playBtn.addEventListener('click', function () {
		if (playing && !paused) {
			pause();
			return;
		}

		if (paused) {
			resume();
			return;
		}

		/*
		 * First press. build() and speak() both happen synchronously inside
		 * this handler because iOS discards speech that starts outside a
		 * user gesture — see the file header.
		 */
		if (!chunks.length) {
			build();
		}

		var saved = savedPosition();

		play(saved > 0 && saved < chunks.length ? saved : 0);
	});

	toggleBtn.addEventListener('click', function () {
		var open = toggleBtn.getAttribute('aria-expanded') === 'true';

		toggleBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
		panel.hidden = open;
	});

	rateSel.addEventListener('change', function () {
		savePrefs({ rate: parseFloat(rateSel.value) });

		// Rate is fixed for the life of an utterance, so a change only
		// takes effect on the next one. Re-speaking the current chunk makes
		// it immediate, which is what someone dragging the speed expects.
		if (playing && !paused) {
			synth.cancel();
			say(index);
		}
	});

	voiceSel.addEventListener('change', function () {
		var v = currentVoice();

		savePrefs({ voice: v ? v.name : '' });

		if (playing && !paused) {
			synth.cancel();
			say(index);
		}
	});

	/*
	 * Leaving the page mid-sentence: cancel explicitly. Some browsers keep
	 * speaking after a navigation, which produces the memorable experience
	 * of a page you have already left continuing to read itself to you.
	 */
	window.addEventListener('pagehide', function () {
		synth.cancel();
	});

	// A background tab that keeps talking is the same problem, softer.
	document.addEventListener('visibilitychange', function () {
		if (document.hidden && playing && !paused) {
			pause();
		}
	});

	// Everything above is wired. Now the control can be shown.
	root.hidden = false;
}());
