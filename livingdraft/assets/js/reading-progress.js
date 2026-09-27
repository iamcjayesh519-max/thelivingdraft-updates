/**
 * Reading progress bar.
 *
 * === WHAT IT MEASURES ===
 *
 * Progress through the ARTICLE, not through the page. Those are different
 * numbers and the difference matters: a story followed by an author box, a
 * timeline, Read Next, and forty comments is maybe 45% article by page
 * height. A bar measuring page scroll sits at 45% when the reader has just
 * finished the last paragraph, which tells them the opposite of the truth at
 * the exact moment they most want to know it.
 *
 * So the bar is anchored to the content element. It reads 0% when the top of
 * the article reaches the top of the viewport and 100% when the bottom of the
 * article does. Everything after the article is 100%; everything before it —
 * headline, standfirst, lede image — is 0%, because a reader looking at the
 * headline has not read anything yet.
 *
 * === WHY IT IS NOT A SCROLL LISTENER ===
 *
 * Reading getBoundingClientRect() inside a scroll event forces the browser to
 * settle layout on every frame it fires, which on a long page is the classic
 * way to make a smooth page feel sticky. The scroll handler here only sets a
 * flag; the measuring happens once per frame in requestAnimationFrame, which
 * is the fastest the bar could usefully update anyway.
 *
 * @package LivingDraft
 * @since 4.1.0
 */

(function () {
	'use strict';

	var bar = document.querySelector('.reading-progress__bar');

	if (!bar) {
		return;
	}

	var article = document.querySelector('.entry-content');

	// No article on this page — the bar is markup with nothing to measure.
	// Remove it rather than leave an empty fixed strip at the top.
	if (!article) {
		var shell = document.querySelector('.reading-progress');
		if (shell && shell.parentNode) {
			shell.parentNode.removeChild(shell);
		}
		return;
	}

	var ticking = false;
	var last = -1;

	function measure() {
		ticking = false;

		var box = article.getBoundingClientRect();

		/*
		 * The distance the reader travels between "article top is at the top
		 * of the screen" and "article bottom is at the top of the screen".
		 * For an article shorter than the viewport this is a small number,
		 * and for a very short one it can be zero — hence the guard, which
		 * would otherwise divide by it.
		 */
		var travel = box.height;

		if (travel <= 0) {
			return;
		}

		// How far past the top of the article we have scrolled.
		var passed = -box.top;
		var ratio = passed / travel;

		if (ratio < 0) {
			ratio = 0;
		} else if (ratio > 1) {
			ratio = 1;
		}

		// Round to whole percent. Sub-pixel updates are invisible, and
		// skipping them avoids touching the DOM on most frames of a slow
		// scroll.
		var pct = Math.round(ratio * 100);

		if (pct === last) {
			return;
		}

		last = pct;

		bar.style.transform = 'scaleX(' + (pct / 100) + ')';
		bar.parentNode.setAttribute('aria-valuenow', String(pct));
	}

	function request() {
		if (ticking) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame(measure);
	}

	window.addEventListener('scroll', request, { passive: true });
	window.addEventListener('resize', request, { passive: true });

	/*
	 * Late-loading images and embeds change the article's height after the
	 * first measurement, which would leave the bar reading against a stale
	 * total. ResizeObserver catches every one of those without polling.
	 */
	if ('ResizeObserver' in window) {
		new ResizeObserver(request).observe(article);
	} else {
		window.addEventListener('load', request);
	}

	measure();
}());
