/**
 * Vision AI for images.
 *
 * Two behaviours in one file:
 *   1. Per-attachment button in the media modal / library grid detail.
 *      Uses event delegation on document so it works no matter when
 *      WordPress's Backbone re-renders the form.
 *   2. Batch runner in the SEO metabox. Iterates missing-alt images
 *      one at a time, updating the counter and progress line as each
 *      completes.
 */

( function () {
	'use strict';

	var cfg = window.livingdraftAiImageAlt;
	if ( ! cfg ) return;

	/* -------------------------------------------------------------
	 * Per-attachment: media modal + library detail
	 * ------------------------------------------------------------- */

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.ld-ai-alt-btn' ) : null;
		if ( ! btn ) return;

		var attachmentId = btn.getAttribute( 'data-ld-attachment' );
		var nonce        = btn.getAttribute( 'data-ld-nonce' );
		if ( ! attachmentId || ! nonce ) return;

		ev.preventDefault();

		var status = document.querySelector( '[data-ld-status-for="' + attachmentId + '"]' );

		btn.disabled = true;
		var originalHtml = btn.innerHTML;
		btn.innerHTML = '<span class="ld-ai-alt-glyph">✦</span> ' + cfg.strings.working;
		if ( status ) { status.textContent = ''; }

		// If we're on the post editor, hand the post title along for
		// language + tone matching.
		var articleTitle = '';
		var titleEl = document.getElementById( 'title' ) || document.querySelector( '.editor-post-title__input' );
		if ( titleEl ) {
			articleTitle = titleEl.value || titleEl.textContent || '';
		}

		var body = new FormData();
		body.append( 'action', 'ld_ai_image_alt' );
		body.append( 'attachment_id', attachmentId );
		body.append( 'nonce', nonce );
		if ( articleTitle ) body.append( 'article_title', articleTitle );
		// Per-invocation model override. When rendered in the SEO
		// metabox context, the metabox picker exposes its selection
		// via window.livingdraftAiCurrentModel. In the Media Library
		// modal there's usually no picker, so it falls back to the
		// localStorage-persisted choice for the same storage key.
		try {
			if ( typeof window.livingdraftAiCurrentModel === 'function' ) {
				var m = window.livingdraftAiCurrentModel( 'livingdraft.ai.model.metabox' );
				if ( m ) body.append( 'ai_model', m );
			}
		} catch ( e ) {}

		fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					var msg = res && res.data && res.data.message ? res.data.message : 'Failed';
					if ( status ) {
						status.textContent = cfg.strings.failed + ' ' + msg;
						status.style.color = '#a32e2e';
					}
					return;
				}
				fillAltFieldsFor( attachmentId, res.data.alt );
				if ( status ) {
					status.textContent = '✓ ' + cfg.strings.saved;
					status.style.color = '#2f7a3a';
				}
			} )
			.catch( function ( err ) {
				if ( status ) {
					status.textContent = cfg.strings.failed + ' ' + err.message;
					status.style.color = '#a32e2e';
				}
			} )
			.finally( function () {
				btn.disabled = false;
				btn.innerHTML = originalHtml;
			} );
	} );

	/**
	 * Fill any visible alt-text input WordPress renders for this
	 * attachment. Covers all three surfaces:
	 *   - Media modal:  attachments[N][alt]  (with post ID prefix)
	 *   - Grid detail:  attachments[N][alt]
	 *   - List detail:  attachments-N-alt
	 */
	function fillAltFieldsFor( attachmentId, alt ) {
		var selectors = [
			'input[name="attachments[' + attachmentId + '][alt]"]',
			'textarea[name="attachments[' + attachmentId + '][alt]"]',
			'#attachments-' + attachmentId + '-alt',
			// Media modal alt textarea has a different name attribute
			// in some WP versions:
			'textarea[data-setting="alt"]',
		];
		selectors.forEach( function ( sel ) {
			var els = document.querySelectorAll( sel );
			els.forEach( function ( el ) {
				el.value = alt;
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		} );
	}

	/* -------------------------------------------------------------
	 * Batch runner in the SEO metabox
	 * ------------------------------------------------------------- */

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '[data-ld-run]' ) : null;
		if ( ! btn ) return;

		var strip = btn.closest( '[data-ld-image-alt-strip]' );
		if ( ! strip ) return;

		ev.preventDefault();

		var ids, nonces;
		try {
			ids    = JSON.parse( strip.getAttribute( 'data-ld-ids' ) );
			nonces = JSON.parse( strip.getAttribute( 'data-ld-nonces' ) );
		} catch ( e ) {
			return;
		}
		if ( ! Array.isArray( ids ) || ids.length === 0 ) return;

		var articleTitle = strip.getAttribute( 'data-ld-post-title' ) || '';
		var counter      = strip.querySelector( '[data-ld-count]' );
		var progress     = strip.querySelector( '[data-ld-progress]' );

		btn.disabled = true;
		if ( progress ) { progress.hidden = false; }

		var done      = 0;
		var succeeded = 0;
		var failed    = 0;
		var total     = ids.length;

		function next() {
			if ( done >= total ) {
				btn.textContent = '✓ Done';
				setTimeout( function () { window.location.reload(); }, 1200 );
				return;
			}

			var id    = ids[ done ];
			var nonce = nonces[ id ];
			done++;

			if ( progress ) {
				progress.textContent = 'Working on image ' + done + ' of ' + total + '…';
			}

			var body = new FormData();
			body.append( 'action', 'ld_ai_image_alt' );
			body.append( 'attachment_id', id );
			body.append( 'nonce', nonce );
			body.append( 'article_title', articleTitle );
			try {
				if ( typeof window.livingdraftAiCurrentModel === 'function' ) {
					var m2 = window.livingdraftAiCurrentModel( 'livingdraft.ai.model.metabox' );
					if ( m2 ) body.append( 'ai_model', m2 );
				}
			} catch ( e ) {}

			fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						succeeded++;
						if ( counter ) counter.textContent = String( total - succeeded );
					} else {
						failed++;
					}
				} )
				.catch( function () { failed++; } )
				.finally( function () {
					// Small stagger between calls so the rate limiter
					// doesn't fire on a batch of 10+.
					setTimeout( next, 250 );
				} );
		}

		next();
	} );

}() );
