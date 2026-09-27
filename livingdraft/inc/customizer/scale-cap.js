/*
 * Living Draft — Scale cap.
 *
 * When the browser CSS viewport becomes wider than the configured "max layout
 * width" (Customizer → Page layout → Maximum layout width), apply CSS zoom to
 * the <html> element so the whole page uniformly shrinks to fit within that
 * cap — rather than letting the layout spread out at extreme zoom-out.
 *
 * The result: at 100% browser zoom, the site looks normal. Zoom out to 50%
 * and the site scales down proportionally, keeping the same layout ratios
 * (like scaling down an image), instead of "responding" to the wider CSS
 * viewport by making everything wider.
 *
 * Reads the cap from <html data-ld-scale-cap="N">, injected by the theme's
 * wp_head hook when the setting is > 0. When set to 0 (disabled), the theme
 * omits the attribute and this script no-ops.
 *
 * CSS `zoom` on <html> is supported in Chrome, Safari, Edge, and Firefox 126+.
 * On older browsers this feature simply doesn't activate — the page renders
 * normally without scaling. No layout breakage.
 */

( function () {
	'use strict';

	var html = document.documentElement;
	var cap  = parseInt( html.getAttribute( 'data-ld-scale-cap' ), 10 );

	// No cap set, or invalid — feature disabled.
	if ( ! cap || cap <= 0 ) {
		return;
	}

	// Guard against browsers without CSS `zoom` support. Feature-detect by
	// setting zoom and checking whether it stuck; skip if not.
	html.style.zoom = 1;
	if ( getComputedStyle( html ).zoom !== '1' && html.style.zoom !== '1' ) {
		// Zoom didn't stick — browser doesn't support it. Bail out cleanly.
		html.style.zoom = '';
		return;
	}
	html.style.zoom = '';

	function apply() {
		// CRITICAL: reset zoom to 1 BEFORE measuring. Otherwise on browsers
		// where CSS `zoom` on <html> affects clientWidth returns, we'd read
		// a post-zoom width and compute an ever-smaller scale factor on each
		// resize — a feedback spiral. Reading with zoom=1 gives us the true
		// CSS viewport width every time.
		html.style.zoom = '';
		var viewport = html.clientWidth;

		if ( viewport > cap ) {
			// Scale factor = how much smaller we need to make everything so
			// the layout, drawn at its natural viewport, fits the actual
			// window. E.g. viewport 3200, cap 2000 → scale 0.625.
			html.style.zoom = ( cap / viewport ).toFixed( 4 );
		}
		// (When viewport <= cap, zoom stays reset to '' from above.)
	}

	apply();

	// Debounce resize to avoid thrash while a user drags the window edge.
	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( apply, 100 );
	} );

	// Also re-check on orientationchange (mobile rotation).
	window.addEventListener( 'orientationchange', apply );

} )();
