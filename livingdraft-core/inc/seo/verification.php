<?php
/**
 * Site verification meta tags.
 *
 * Google Search Console, Bing Webmaster Tools, Yandex, Pinterest, Baidu,
 * and Facebook Domain Verification all give you a small string to paste
 * into a meta tag in your site's <head>. This module gives them a home
 * in Settings instead of forcing site owners to hack the theme header
 * (which breaks on every theme update).
 *
 * All six are optional. An empty field emits nothing.
 *
 * @package LivingDraftCore
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The verification providers this module handles. Kept in one place
 * so the admin form and the front-end printer stay in lock step.
 *
 * Keys are the option slug we store under; values describe how the
 * provider expects to see the value on the page.
 */
function livingdraft_seo_verification_providers() {
	return array(
		'google' => array(
			'label'       => __( 'Google Search Console', 'livingdraft-core' ),
			'meta_name'   => 'google-site-verification',
			'placeholder' => 'e.g. AbCdEfGhIjKlMnOpQrStUvWxYz0123456789',
			'help'        => __( 'Search Console → Settings → Ownership verification → HTML tag. Paste only the content="…" value.', 'livingdraft-core' ),
		),
		'bing' => array(
			'label'       => __( 'Bing Webmaster Tools', 'livingdraft-core' ),
			'meta_name'   => 'msvalidate.01',
			'placeholder' => 'e.g. 1A2B3C4D5E6F7G8H9I0J',
			'help'        => __( 'Bing Webmaster Tools → Site → Add & Verify → Meta tag. Paste only the content="…" value.', 'livingdraft-core' ),
		),
		'yandex' => array(
			'label'       => __( 'Yandex Webmaster', 'livingdraft-core' ),
			'meta_name'   => 'yandex-verification',
			'placeholder' => 'e.g. 1234567890abcdef',
			'help'        => __( 'Yandex → Add site → Meta tag. Paste only the content="…" value.', 'livingdraft-core' ),
		),
		'pinterest' => array(
			'label'       => __( 'Pinterest', 'livingdraft-core' ),
			'meta_name'   => 'p:domain_verify',
			'placeholder' => 'e.g. abcdef1234567890',
			'help'        => __( 'Pinterest → Business → Claim your website. Paste only the content="…" value.', 'livingdraft-core' ),
		),
		'baidu' => array(
			'label'       => __( 'Baidu (百度)', 'livingdraft-core' ),
			'meta_name'   => 'baidu-site-verification',
			'placeholder' => 'e.g. code-1234abcd',
			'help'        => __( 'Baidu 站长平台 → Site verification → HTML meta tag.', 'livingdraft-core' ),
		),
		'facebook' => array(
			'label'       => __( 'Facebook Domain Verification', 'livingdraft-core' ),
			'meta_name'   => 'facebook-domain-verification',
			'placeholder' => 'e.g. 1a2b3c4d5e6f7g8h9i0j',
			'help'        => __( 'Facebook Business Settings → Brand Safety → Domains → your domain → Meta-tag verification.', 'livingdraft-core' ),
		),
	);
}

/**
 * Read a single stored value. Empty when unset.
 */
function livingdraft_seo_verification_get( $provider ) {
	$all = get_option( 'livingdraft_seo_verification', array() );
	return is_array( $all ) && isset( $all[ $provider ] ) ? (string) $all[ $provider ] : '';
}

/* ------------------------------------------------------------------
 * 1. FRONT-END: emit the meta tags
 * ------------------------------------------------------------------ */

/**
 * Print each configured verification tag in <head>. Site verification
 * tags are conventionally emitted on every page, not just the home
 * page — Google actually recommends this so any page can be used as
 * an ownership proof. So we hook wp_head unconditionally.
 *
 * We use `esc_attr` on the value even though the providers all give
 * short alphanumeric strings — belt and braces. And we skip tags
 * whose value looks like an obviously wrong paste (whole HTML tag
 * pasted instead of just the value), instead emitting an HTML
 * comment on the front-end so an admin viewing source can see why
 * their tag isn't being emitted.
 */
function livingdraft_seo_verification_print_head() {
	$all = get_option( 'livingdraft_seo_verification', array() );
	if ( ! is_array( $all ) || empty( $all ) ) {
		return;
	}

	foreach ( livingdraft_seo_verification_providers() as $slug => $spec ) {
		$val = isset( $all[ $slug ] ) ? trim( (string) $all[ $slug ] ) : '';
		if ( '' === $val ) {
			continue;
		}

		// If the user pasted the whole tag, extract the content attribute
		// rather than emitting broken HTML. This is by far the most
		// common paste mistake.
		if ( false !== stripos( $val, 'content=' ) && preg_match( '/content=(?:"|\')([^"\']+)/i', $val, $m ) ) {
			$val = $m[1];
		}

		// Same safety net for a wholesale <meta …> paste that didn't
		// match the pattern above.
		if ( '<' === $val[0] ) {
			echo "<!-- livingdraft verification for {$slug}: paste only the token, not the whole tag -->\n";
			continue;
		}

		printf(
			'<meta name="%s" content="%s">' . "\n",
			esc_attr( $spec['meta_name'] ),
			esc_attr( $val )
		);
	}
}
add_action( 'wp_head', 'livingdraft_seo_verification_print_head', 1 );

/* ------------------------------------------------------------------
 * 2. ADMIN: save handler
 * ------------------------------------------------------------------ */

/**
 * Handle a POST from the Webmasters settings tab. Redirects back to
 * the tab with ?saved=1 so the existing "settings saved" notice
 * shows.
 */
function livingdraft_seo_verification_handle_save() {
	if ( ! isset( $_POST['ld_seo_verification_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_verification' );

	$providers = livingdraft_seo_verification_providers();
	$in        = isset( $_POST['verification'] ) && is_array( $_POST['verification'] ) ? wp_unslash( $_POST['verification'] ) : array();

	$out = array();
	foreach ( $providers as $slug => $spec ) {
		$val = isset( $in[ $slug ] ) ? trim( (string) $in[ $slug ] ) : '';
		// Store verbatim — the front-end printer sanitizes on output
		// and understands the "user pasted the whole tag" case.
		// The stored value is length-capped at 500 chars so a bad paste
		// of a huge script tag can't blow up the option row.
		if ( '' !== $val ) {
			$out[ $slug ] = substr( sanitize_text_field( $val ), 0, 500 );
		}
	}

	update_option( 'livingdraft_seo_verification', $out, false );
	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'webmasters', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_verification_handle_save' );
