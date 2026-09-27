/*
 * The Living Draft — Load More / Infinite Scroll.
 *
 * Reads a <button.ld-load-more data-next-url="..." data-max-pages="N">
 * emitted by livingdraft_pagination() and, on click (or automatically if
 * data-auto="1"), fetches the next paginated page, extracts the .col-item
 * cards from it, and appends them to the current .cols container.
 *
 * WHY THIS EXISTS
 *
 * Two Customizer choices under Front page → Pagination style land here:
 *   - Load More  → user clicks the button
 *   - Infinite   → button auto-clicks itself when it scrolls into view
 *
 * Numbered pagination doesn't render this button, so this script no-ops
 * when the user leaves the default alone.
 *
 * TRADE-OFFS
 *
 * - URL doesn't update as pages load. That's a real cost — a reader can't
 *   share "page 4". We warned about it in the theme docs.
 * - Google may not index every page's stories via AJAX. The <noscript>
 *   fallback in the button HTML gives crawlers a plain <a href> next-page
 *   link, so at worst indexing behaves like a manual pagination click.
 * - Reduced motion / low-power devices: click-to-load respects the same
 *   fetch() as browsers already implement; nothing custom.
 */

( function () {
	'use strict';

	var button = document.querySelector( '.ld-load-more' );
	if ( ! button ) { return; }

	var currentPage = parseInt( button.dataset.currentPage || '1', 10 );
	var maxPages    = parseInt( button.dataset.maxPages || '1', 10 );
	var nextUrl     = button.dataset.nextUrl || '';
	var autoLoad    = button.dataset.auto === '1';
	var container   = document.querySelector( '.cols' );

	if ( ! container || ! nextUrl ) { return; }

	// The list of card selectors we know how to append. Kept in one place
	// so a template change only means adding a selector here.
	var CARD_SELECTOR = '.col-item';

	button.addEventListener( 'click', loadMore );

	// Infinite mode: click the button when it scrolls into view.
	if ( autoLoad && 'IntersectionObserver' in window ) {
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting && ! button.disabled && ! button.classList.contains( 'is-done' ) ) {
					loadMore();
				}
			} );
		}, {
			// Fire when the button is within one viewport height of appearing.
			// Gives the browser time to fetch before the user hits the actual
			// button — feels seamless.
			rootMargin: '0px 0px 400px 0px'
		} );
		io.observe( button );
	}

	function loadMore() {
		if ( button.disabled || button.classList.contains( 'is-done' ) ) { return; }

		button.disabled = true;
		var originalLabel = button.textContent;
		button.textContent = button.textContent.replace( /^./, function ( c ) { return c; } ) + '…';

		fetch( nextUrl, {
			headers: { 'Accept': 'text/html' },
			credentials: 'same-origin'
		} )
		.then( function ( res ) {
			if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
			return res.text();
		} )
		.then( function ( html ) {
			// Parse the fetched HTML in-place. We only need the cards, not the
			// full document — but the DOMParser handles both cleanly.
			var doc = new DOMParser().parseFromString( html, 'text/html' );
			var cards = doc.querySelectorAll( CARD_SELECTOR );

			if ( ! cards.length ) {
				// Empty response — treat as end of pagination.
				finish();
				return;
			}

			// Append each card to the current grid. Using appendChild directly
			// so browsers hydrate images/lazy-loading as they land.
			cards.forEach( function ( card ) {
				container.appendChild( card );
			} );

			// Bookkeeping: move to the next URL for the next click.
			currentPage++;
			button.dataset.currentPage = String( currentPage );

			if ( currentPage >= maxPages ) {
				finish();
				return;
			}

			// Build the next page URL. get_pagenum_link() on the server uses
			// /page/N/ or ?paged=N — either shape is handled here by regex.
			nextUrl = nextUrl
				.replace( /\/page\/\d+(\/?)/i, '/page/' + ( currentPage + 1 ) + '$1' )
				.replace( /([?&]paged=)\d+/, '$1' + ( currentPage + 1 ) );
			button.dataset.nextUrl = nextUrl;

			// Re-enable for the next click.
			button.disabled = false;
			button.textContent = originalLabel;
		} )
		.catch( function ( err ) {
			// Network error or 404 — don't leave the user stuck on "Loading…".
			// Restore the button label so they can retry manually.
			console.warn( '[The Living Draft] load-more failed:', err );
			button.disabled = false;
			button.textContent = originalLabel;
		} );
	}

	function finish() {
		button.classList.add( 'is-done' );
		button.disabled = true;
		button.textContent = '';
	}

} )();
