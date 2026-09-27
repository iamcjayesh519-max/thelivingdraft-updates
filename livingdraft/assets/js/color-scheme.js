/**
 * Living Draft - the reader's light/dark control.
 *
 * The head script in inc/color-scheme.php has already applied the saved
 * choice before paint. This file only handles what happens afterwards:
 * reacting to the reader pressing one of the three options, saving it,
 * and keeping other open tabs of the same site in step.
 *
 * Deferred, because none of that has to happen before the page is drawn.
 */
(function () {
	'use strict';

	var KEY = 'livingdraft-scheme';
	var VALID = ['light', 'dark', 'auto'];

	function isValid(value) {
		return VALID.indexOf(value) !== -1;
	}

	/**
	 * localStorage throws rather than returning null in Safari private
	 * browsing and inside some app webviews. Every access is wrapped so a
	 * reader in one of those never loses the control entirely - it simply
	 * stops remembering between visits, which is a far smaller failure.
	 */
	function read() {
		try {
			var stored = window.localStorage.getItem(KEY);
			return isValid(stored) ? stored : null;
		} catch (e) {
			return null;
		}
	}

	function write(value) {
		try {
			window.localStorage.setItem(KEY, value);
		} catch (e) {
			/* Choice still applies for this page view, just not the next. */
		}
	}

	function apply(value) {
		if (!isValid(value)) {
			return;
		}
		document.documentElement.setAttribute('data-scheme', value);
	}

	function init() {
		var control = document.querySelector('[data-scheme-control]');
		if (!control) {
			return;
		}

		var inputs = control.querySelectorAll('input[name="livingdraft-scheme"]');
		if (!inputs.length) {
			return;
		}

		/*
		 * The radio marked checked in the HTML is the SITE default, because
		 * PHP cannot know what this reader chose. If they have a saved
		 * choice, move the tick to it so the control tells the truth about
		 * what is currently applied.
		 */
		var saved = read();
		if (saved) {
			for (var i = 0; i < inputs.length; i++) {
				inputs[i].checked = (inputs[i].value === saved);
			}
		}

		control.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || target.name !== 'livingdraft-scheme') {
				return;
			}
			apply(target.value);
			write(target.value);
		});

		/*
		 * Someone reading in two tabs and changing the setting in one
		 * should not find the other still contradicting it. The storage
		 * event only fires in OTHER tabs, never the one that made the
		 * change, so there is no loop here.
		 */
		window.addEventListener('storage', function (event) {
			if (event.key !== KEY || !isValid(event.newValue)) {
				return;
			}
			apply(event.newValue);
			for (var j = 0; j < inputs.length; j++) {
				inputs[j].checked = (inputs[j].value === event.newValue);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
