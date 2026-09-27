/**
 * Living Draft - the view counter.
 *
 * The page this runs on is almost certainly cached. That is the point: the
 * page is static and fast, and this one small request is the only dynamic
 * thing about the visit. It runs on every reader, whatever cache sits in
 * front of the site, which is exactly what the old server-side counter
 * could not do.
 *
 * Deliberately tiny and deliberately last. Nothing here has to happen before
 * the reader can read.
 */
(function () {
	'use strict';

	var config = window.livingdraftViews;

	if (!config || !config.endpoint || !config.post) {
		return;
	}

	var KEY = 'ld-read-' + config.post;

	/*
	 * The repeat guard lives here rather than on the server.
	 *
	 * The old counter wrote a database row for every reader of every article
	 * to remember it had seen them. On a busy news site that is thousands of
	 * rows an hour, written on the visitor's own page load, purely to avoid
	 * double-counting somebody who pressed refresh.
	 *
	 * The browser already knows whether it has been here. Asking it costs
	 * nothing and touches no database. The server keeps its own guard as a
	 * backstop, but only where a persistent object cache makes it free.
	 */
	function seenRecently() {
		try {
			var last = window.localStorage.getItem(KEY);

			if (!last) {
				return false;
			}

			var age = Date.now() - parseInt(last, 10);

			// A stored value from a different era, or corrupt. Treat as unseen.
			if (isNaN(age) || age < 0) {
				return false;
			}

			return age < (config.window * 1000);
		} catch (e) {
			// Safari private mode and some app webviews throw on any access.
			// Counting the view is the lesser error, so carry on.
			return false;
		}
	}

	function remember() {
		try {
			window.localStorage.setItem(KEY, String(Date.now()));
		} catch (e) {
			/* Nothing to do. The server guard may still catch a repeat. */
		}
	}

	function send() {
		if (seenRecently()) {
			return;
		}

		remember();

		var body = JSON.stringify({ post: config.post });

		/*
		 * sendBeacon is the right tool: the browser takes the request and
		 * guarantees to deliver it even if the reader navigates away in the
		 * same instant, and it never blocks the page. fetch with keepalive
		 * is the fallback for the handful of browsers without it.
		 */
		if (navigator.sendBeacon) {
			try {
				var ok = navigator.sendBeacon(
					config.endpoint,
					new Blob([body], { type: 'application/json' })
				);
				if (ok) {
					return;
				}
			} catch (e) {
				/* Fall through to fetch. */
			}
		}

		if (window.fetch) {
			fetch(config.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function () {
				/* A missed count is not worth an error in the console. */
			});
		}
	}

	/*
	 * Wait for the page to settle before counting. Two reasons: the request
	 * must never compete with the article's own images for bandwidth, and a
	 * reader who lands and leaves inside a second did not read anything.
	 */
	function schedule() {
		window.setTimeout(send, 1500);
	}

	if (document.readyState === 'complete') {
		schedule();
	} else {
		window.addEventListener('load', schedule);
	}
})();
