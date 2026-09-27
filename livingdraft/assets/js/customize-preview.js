/**
 * Living Draft — Customizer live preview.
 *
 * The colour settings are registered with postMessage transport. That tells
 * WordPress not to reload the preview frame, which means nothing at all
 * happens unless something in the frame listens. This is that listener: it
 * writes each colour straight onto the :root custom property the stylesheet
 * already reads, so one binding repaints the whole page, blocks included.
 */
( function ( api ) {
	'use strict';

	if ( ! api ) {
		return;
	}

	var COLOURS = {
		livingdraft_color_paper: '--paper',
		livingdraft_color_ink: '--ink',
		livingdraft_color_soft: '--ink-2',
		livingdraft_color_mark: '--mark',
		livingdraft_color_link: '--link',
		livingdraft_color_rule: '--rule'
	};

	Object.keys( COLOURS ).forEach( function ( setting ) {
		api( setting, function ( value ) {
			value.bind( function ( to ) {
				var root = document.documentElement;

				if ( to ) {
					root.style.setProperty( COLOURS[ setting ], to );
				} else {
					// Cleared control: hand the variable back to the stylesheet.
					root.style.removeProperty( COLOURS[ setting ] );
				}
			} );
		} );
	} );
}( window.wp && window.wp.customize ) );
