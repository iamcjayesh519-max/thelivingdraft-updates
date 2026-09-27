<?php
/**
 * Your own CSS and your own code.
 *
 * This replaces WP Add Custom CSS and WPCode Lite. It also fixes a problem
 * neither of them solves and the WordPress Customizer shares: Appearance >
 * Customize > Additional CSS is stored PER THEME. Switch theme and your CSS
 * disappears. Kept here, it does not.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load assets/custom.css on the front of the site, after the theme's own
 * stylesheet so your rules win without needing !important.
 */
function livingdraft_core_custom_css() {
	$path = LIVINGDRAFT_CORE_DIR . 'assets/custom.css';

	if ( ! file_exists( $path ) || filesize( $path ) < 10 ) {
		return;
	}

	wp_enqueue_style(
		'livingdraft-custom',
		LIVINGDRAFT_CORE_URL . 'assets/custom.css',
		array(),
		filemtime( $path )
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_core_custom_css', 999 );

/* ==================================================================
 *
 *   YOUR CODE SNIPPETS GO BELOW THIS LINE
 *
 *   Move each snippet out of WPCode Lite and paste it here, one after
 *   another. Put a comment above each one saying what it does, so that
 *   in a year you still know.
 *
 *   THREE RULES:
 *   1. Never paste anything starting with <?php — there is already one
 *      at the top of this file. A second one breaks the site.
 *   2. Change one thing, then load your site and check it still works.
 *      Do not paste five snippets at once.
 *   3. Keep a copy of this file somewhere safe before you edit it.
 *
 *   Some snippets may no longer be needed. The Living Draft theme
 *   already does all of these, so delete them if you find them:
 *     - removing emoji scripts
 *     - removing jQuery Migrate
 *     - removing the WordPress version number from the head
 *     - disabling oEmbed
 *     - setting image sizes or thumbnail crops
 *     - adding Open Graph tags (Rank Math does this)
 *
 * ================================================================== */


// --- Snippet 1: (describe what it does here) ------------------------



// --- Snippet 2: ----------------------------------------------------



// --- Snippet 3: ----------------------------------------------------
