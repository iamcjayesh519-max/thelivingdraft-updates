/**
 * SEO metabox interactive layer.
 *
 * Three responsibilities:
 *   1. Live SERP preview and character counters — pure DOM.
 *   2. Debounced call to admin-ajax for the analyzer, then re-render
 *      the score circle and checklist.
 *   3. Wire the "Generate with AI" buttons on title / description /
 *      focus keyword, taking current form state and pasting the
 *      response back into the field.
 *
 * No jQuery, no build step — Living Draft house style.
 */

( function () {
	'use strict';

	var ctx = window.livingdraftSeoContext;
	if ( ! ctx ) return;

	var $ = function ( sel, root ) {
		return ( root || document ).querySelector( sel );
	};
	var $$ = function ( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	};

	var titleEl   = $( '#ld_seo_title' );
	var descEl    = $( '#ld_seo_description' );
	var kwEl      = $( '#ld_seo_focus_keyword' );

	if ( ! titleEl || ! descEl ) return;

	/* -------- SERP preview + counters ---------------------------- */

	var serpTitle = $( '[data-ld-serp-title]' );
	var serpDesc  = $( '[data-ld-serp-desc]' );

	function updatePreview() {
		var t = titleEl.value.trim() || ctx.postTitle;
		var d = descEl.value.trim();
		if ( serpTitle ) {
			serpTitle.textContent = t + ' | ' + ctx.siteName;
		}
		if ( serpDesc ) {
			serpDesc.textContent = d || '(Google will pick a snippet from your article.)';
		}
	}

	function updateCounters() {
		$$( '[data-ld-counter]' ).forEach( function ( span ) {
			var targetId = span.getAttribute( 'data-ld-counter' );
			var el       = document.getElementById( targetId );
			if ( ! el ) return;
			var min = parseInt( span.getAttribute( 'data-ld-min' ), 10 ) || 0;
			var max = parseInt( span.getAttribute( 'data-ld-max' ), 10 ) || 999;
			var len = el.value.length;
			span.textContent = len + ' / ' + max;
			span.classList.remove( 'is-good', 'is-warn', 'is-bad' );
			if ( len === 0 ) {
				// leave neutral
			} else if ( len >= min && len <= max ) {
				span.classList.add( 'is-good' );
			} else if ( len < min && len >= min * 0.6 ) {
				span.classList.add( 'is-warn' );
			} else if ( len > max && len <= max * 1.15 ) {
				span.classList.add( 'is-warn' );
			} else {
				span.classList.add( 'is-bad' );
			}
		} );
	}

	/* -------- Analysis ------------------------------------------- */

	var analyzeTimer = null;
	function scheduleAnalyze() {
		if ( analyzeTimer ) clearTimeout( analyzeTimer );
		analyzeTimer = setTimeout( runAnalyze, 800 );
	}

	function runAnalyze() {
		var body = new FormData();
		body.append( 'action', 'ld_seo_analyze' );
		body.append( 'nonce', ctx.analyzeNonce );
		body.append( 'post_id', String( ctx.postId ) );
		body.append( 'title', titleEl.value );
		body.append( 'description', descEl.value );
		body.append( 'keyword', kwEl ? kwEl.value : '' );

		fetch( ctx.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					renderAnalysis( res.data );
				}
			} )
			.catch( function () { /* silent — the checklist just won't update this time */ } );
	}

	function renderAnalysis( data ) {
		var scoreEl = $( '[data-ld-seo-score]' );
		var hintEl  = $( '[data-ld-seo-hint]' );
		var list    = $( '[data-ld-seo-checks]' );
		if ( ! scoreEl || ! list ) return;

		scoreEl.textContent = data.score;
		scoreEl.classList.remove( 'is-good', 'is-warn', 'is-bad' );
		if ( data.grade === 'good' ) scoreEl.classList.add( 'is-good' );
		if ( data.grade === 'warn' ) scoreEl.classList.add( 'is-warn' );
		if ( data.grade === 'bad' )  scoreEl.classList.add( 'is-bad' );

		if ( hintEl ) {
			if ( ! data.has_keyword ) {
				hintEl.textContent = 'Enter a focus keyword to unlock the full analysis.';
			} else {
				var failed = ( data.checks || [] ).filter( function ( c ) { return c.status === 'fail'; } );
				hintEl.textContent = failed.length
					? failed.length + ' check' + ( failed.length === 1 ? '' : 's' ) + ' need attention.'
					: 'All checks look good.';
			}
		}

		// Preserve any open suggestion panels across a re-render — the AI
		// call takes a couple of seconds and the user shouldn't lose their
		// suggestion just because a debounced re-analysis fired.
		var openPanels = {};
		$$( '.ld-seo-check-panel', list ).forEach( function ( p ) {
			var checkId = p.getAttribute( 'data-ld-check' );
			if ( checkId ) openPanels[ checkId ] = p.innerHTML;
		} );

		list.innerHTML = '';
		( data.checks || [] ).forEach( function ( c ) {
			var li = document.createElement( 'li' );
			li.setAttribute( 'data-ld-check-row', c.id );

			var head = document.createElement( 'div' );
			head.className = 'ld-seo-check-head';

			var dot = document.createElement( 'span' );
			dot.className = 'ld-seo-check-dot is-' + c.status;
			head.appendChild( dot );

			var body = document.createElement( 'span' );
			body.className = 'ld-seo-check-body';
			var strong = document.createElement( 'strong' );
			strong.textContent = c.label;
			var hint = document.createElement( 'span' );
			hint.className = 'ld-seo-check-hint';
			hint.textContent = c.hint;
			body.appendChild( strong );
			body.appendChild( hint );
			head.appendChild( body );

			// "Fix with AI" button — only when we have a strategy AND
			// a working provider AND the check isn't already passing.
			if ( c.fix && ctx.aiReady ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'ld-seo-check-fix-btn';
				btn.setAttribute( 'data-ld-fix-check', c.id );
				btn.setAttribute( 'data-ld-fix-task', c.fix.task );
				btn.setAttribute( 'data-ld-fix-mode', c.fix.mode );
				if ( c.fix.target ) {
					btn.setAttribute( 'data-ld-fix-target', c.fix.target );
				}
				btn.innerHTML = '<span class="ld-seo-ai-glyph">✦</span> ' + escapeHtml( c.fix.label );
				btn.addEventListener( 'click', onFixClick );
				head.appendChild( btn );
			}

			li.appendChild( head );

			// Re-attach an in-flight suggestion panel if the check row
			// still exists after the re-render.
			if ( openPanels[ c.id ] ) {
				var panel = document.createElement( 'div' );
				panel.className = 'ld-seo-check-panel';
				panel.setAttribute( 'data-ld-check', c.id );
				panel.innerHTML = openPanels[ c.id ];
				wireCopyButtons( panel );
				wireDismissButtons( panel );
				li.appendChild( panel );
			}

			list.appendChild( li );
		} );
	}

	/* -------- Fix-with-AI: autofix and suggest flows -------------- */

	function onFixClick( ev ) {
		var btn      = ev.currentTarget;
		var mode     = btn.getAttribute( 'data-ld-fix-mode' );
		var task     = btn.getAttribute( 'data-ld-fix-task' );
		var checkId  = btn.getAttribute( 'data-ld-fix-check' );
		var targetId = btn.getAttribute( 'data-ld-fix-target' );

		if ( ! task ) return;

		var originalHtml = btn.innerHTML;
		btn.disabled = true;
		btn.innerHTML = '<span class="ld-seo-ai-glyph">✦</span> ' + ctx.strings.aiWorking;

		var body = new FormData();
		body.append( 'action', 'ld_seo_ai' );
		body.append( 'nonce', ctx.aiNonce );
		body.append( 'post_id', String( ctx.postId ) );
		body.append( 'task', task );
		body.append( 'title', titleEl.value );
		body.append( 'keyword', kwEl ? kwEl.value : '' );
		if ( targetId ) {
			var t = document.getElementById( targetId );
			if ( t ) body.append( 'existing', t.value );
		}

		var m = window.livingdraftAiCurrentModel && window.livingdraftAiCurrentModel( ctx.modelStorageKey );
		if ( m ) body.append( 'ai_model', m );

		fetch( ctx.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! res.data.text ) {
					var msg = res && res.data && res.data.message ? res.data.message : ctx.strings.aiFailed;
					alert( msg );
					return;
				}
				if ( mode === 'autofix' && targetId ) {
					var target = document.getElementById( targetId );
					if ( target ) {
						target.value = res.data.text;
						target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						// Briefly highlight the field so the writer sees what changed.
						target.classList.add( 'is-ld-ai-updated' );
						setTimeout( function () { target.classList.remove( 'is-ld-ai-updated' ); }, 1600 );
					}
				} else {
					// Suggest mode: show the response inline under the check.
					showSuggestionPanel( checkId, res.data.text );
				}
			} )
			.catch( function ( err ) {
				alert( ctx.strings.aiFailed + ' ' + err.message );
			} )
			.finally( function () {
				btn.disabled = false;
				btn.innerHTML = originalHtml;
			} );
	}

	function showSuggestionPanel( checkId, text ) {
		var row = document.querySelector( '[data-ld-check-row="' + cssEscape( checkId ) + '"]' );
		if ( ! row ) return;

		// Replace any existing panel on this row.
		var existing = row.querySelector( '.ld-seo-check-panel' );
		if ( existing ) existing.remove();

		var panel = document.createElement( 'div' );
		panel.className = 'ld-seo-check-panel';
		panel.setAttribute( 'data-ld-check', checkId );

		var suggText = document.createElement( 'div' );
		suggText.className = 'ld-seo-check-panel-text';
		suggText.textContent = text;

		var actions = document.createElement( 'div' );
		actions.className = 'ld-seo-check-panel-actions';

		var copyBtn = document.createElement( 'button' );
		copyBtn.type = 'button';
		copyBtn.className = 'ld-seo-check-panel-btn is-primary';
		copyBtn.setAttribute( 'data-ld-copy', '' );
		copyBtn.textContent = 'Copy';

		var dismissBtn = document.createElement( 'button' );
		dismissBtn.type = 'button';
		dismissBtn.className = 'ld-seo-check-panel-btn';
		dismissBtn.setAttribute( 'data-ld-dismiss', '' );
		dismissBtn.textContent = 'Dismiss';

		actions.appendChild( copyBtn );
		actions.appendChild( dismissBtn );

		panel.appendChild( suggText );
		panel.appendChild( actions );
		row.appendChild( panel );

		wireCopyButtons( panel );
		wireDismissButtons( panel );
	}

	function wireCopyButtons( scope ) {
		$$( '[data-ld-copy]', scope ).forEach( function ( btn ) {
			if ( btn._ldWired ) return;
			btn._ldWired = true;
			btn.addEventListener( 'click', function () {
				var panel = btn.closest( '.ld-seo-check-panel' );
				if ( ! panel ) return;
				var text = panel.querySelector( '.ld-seo-check-panel-text' );
				if ( ! text ) return;
				var content = text.textContent;
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( content ).then( flashCopied.bind( null, btn ) );
				} else {
					// Legacy fallback: temporary textarea + execCommand.
					var ta = document.createElement( 'textarea' );
					ta.value = content;
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild( ta );
					ta.select();
					try { document.execCommand( 'copy' ); } catch ( e ) {}
					document.body.removeChild( ta );
					flashCopied( btn );
				}
			} );
		} );
	}

	function wireDismissButtons( scope ) {
		$$( '[data-ld-dismiss]', scope ).forEach( function ( btn ) {
			if ( btn._ldWired ) return;
			btn._ldWired = true;
			btn.addEventListener( 'click', function () {
				var panel = btn.closest( '.ld-seo-check-panel' );
				if ( panel ) panel.remove();
			} );
		} );
	}

	function flashCopied( btn ) {
		var original = btn.textContent;
		btn.textContent = 'Copied';
		btn.classList.add( 'is-copied' );
		setTimeout( function () {
			btn.textContent = original;
			btn.classList.remove( 'is-copied' );
		}, 1400 );
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	// CSS.escape isn't in every legacy browser; small fallback covers
	// the alphanumeric check IDs we actually generate.
	function cssEscape( s ) {
		if ( window.CSS && CSS.escape ) return CSS.escape( s );
		return String( s ).replace( /[^a-zA-Z0-9_-]/g, '\\$&' );
	}

	/* -------- AI generate buttons -------------------------------- */

	$$( '.ld-seo-ai-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var task     = btn.getAttribute( 'data-ld-ai-task' );
			var targetId = btn.getAttribute( 'data-ld-ai-target' );
			var target   = document.getElementById( targetId );
			if ( ! task || ! target ) return;

			var originalHtml = btn.innerHTML;
			btn.disabled = true;
			btn.innerHTML = '<span class="ld-seo-ai-glyph">✦</span> ' + ctx.strings.aiWorking;

			var body = new FormData();
			body.append( 'action', 'ld_seo_ai' );
			body.append( 'nonce', ctx.aiNonce );
			body.append( 'post_id', String( ctx.postId ) );
			body.append( 'task', task );
			body.append( 'title', titleEl.value );
			body.append( 'keyword', kwEl ? kwEl.value : '' );
			body.append( 'existing', target.value );

			var m = window.livingdraftAiCurrentModel && window.livingdraftAiCurrentModel( ctx.modelStorageKey );
			if ( m ) body.append( 'ai_model', m );

			fetch( ctx.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success && res.data && res.data.text ) {
						target.value = res.data.text;
						// Trigger the input handlers so preview/counters/analysis update.
						target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					} else {
						var msg = res && res.data && res.data.message ? res.data.message : ctx.strings.aiFailed;
						alert( msg );
					}
				} )
				.catch( function ( err ) {
					alert( ctx.strings.aiFailed + ' ' + err.message );
				} )
				.finally( function () {
					btn.disabled = false;
					btn.innerHTML = originalHtml;
				} );
		} );
	} );

	/* -------- Wire it up ----------------------------------------- */

	[ titleEl, descEl, kwEl ].filter( Boolean ).forEach( function ( el ) {
		el.addEventListener( 'input', function () {
			updatePreview();
			updateCounters();
			scheduleAnalyze();
		} );
	} );

	/* -------- Slug field live preview ---------------------------- */
	// The slug preview span (data-ld-slug-mirror) reflects whatever's
	// in the slug input. sanitize_title() runs server-side on save;
	// we do a lightweight client-side version here just for the mirror
	// so writers see roughly what the final slug will be. Not a full
	// reimplementation — WP's sanitize_title strips accents, transliterates
	// unicode, etc. We just handle the common cases (lowercase, spaces
	// to hyphens, strip anything that isn't alphanumeric or hyphen).
	var slugEl    = document.getElementById( 'ld_seo_slug' );
	var slugMirror = document.querySelector( '[data-ld-slug-mirror]' );
	if ( slugEl && slugMirror ) {
		slugEl.addEventListener( 'input', function () {
			var raw = slugEl.value || slugEl.placeholder || '';
			var cleaned = raw
				.toLowerCase()
				.replace( /\s+/g, '-' )
				.replace( /[^a-z0-9-]/g, '' )
				.replace( /-+/g, '-' )
				.replace( /^-|-$/g, '' );
			slugMirror.textContent = cleaned || slugEl.placeholder;
		} );
	}

	// Initial render.
	updatePreview();
	updateCounters();
	runAnalyze();
}() );
