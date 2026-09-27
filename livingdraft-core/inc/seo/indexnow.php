<?php
/**
 * IndexNow — instant indexing for Bing and Yandex.
 *
 * When a post is published or meaningfully updated, we POST its URL
 * to api.indexnow.org, which then propagates to the participating
 * search engines (Bing, Yandex, Seznam, Naver). For a news site this
 * gets articles into Bing's index within minutes rather than hours.
 *
 * Google is not part of IndexNow — they killed their own ping endpoint
 * back in 2023. Google discovery relies on the sitemap.
 *
 * === HOW THE KEY WORKS ===
 *
 * IndexNow requires the site to prove ownership by hosting a text file
 * at /<key>.txt whose contents are the key itself. We auto-generate a
 * 32-char hex key on first enable, serve the file via a rewrite rule,
 * and pass it in every ping.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. TOGGLE + KEY
 * ------------------------------------------------------------------ */

function livingdraft_indexnow_enabled() {
	return (bool) apply_filters( 'livingdraft_indexnow_enabled', (bool) get_option( 'livingdraft_indexnow_enabled', false ) );
}

/**
 * Get the site's IndexNow key, generating one on first access.
 * 32 hex chars keeps it inside the spec's 8-128 char range while
 * being trivial to eyeball.
 */
function livingdraft_indexnow_key() {
	$k = get_option( 'livingdraft_indexnow_key' );
	if ( is_string( $k ) && preg_match( '/^[a-f0-9]{16,128}$/', $k ) ) {
		return $k;
	}
	$k = bin2hex( random_bytes( 16 ) );
	update_option( 'livingdraft_indexnow_key', $k, false );
	return $k;
}

/* ------------------------------------------------------------------
 * 2. KEY FILE ENDPOINT
 *
 * Served at /<key>.txt. We intercept in `parse_request` — cheaper
 * than a rewrite rule and doesn't need a flush.
 * ------------------------------------------------------------------ */

function livingdraft_indexnow_serve_key_file() {
	if ( ! livingdraft_indexnow_enabled() ) {
		return;
	}

	$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path = strtok( $path, '?' ); // Drop query string.

	if ( ! preg_match( '#^/([a-f0-9]{16,128})\.txt$#', $path, $m ) ) {
		return;
	}

	$key = livingdraft_indexnow_key();
	if ( ! hash_equals( $key, $m[1] ) ) {
		return; // Not our key file.
	}

	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo esc_html( $key );
	exit;
}
add_action( 'parse_request', 'livingdraft_indexnow_serve_key_file', 1 );

/* ------------------------------------------------------------------
 * 3. PING ON PUBLISH
 *
 * `wp_after_insert_post` runs after all meta boxes have saved, which
 * is exactly the moment we want — earlier hooks fire before update-log
 * meta is written, and we'd end up pinging a stale version.
 * ------------------------------------------------------------------ */

function livingdraft_indexnow_maybe_ping( $post_id, $post, $update, $post_before ) {
	if ( ! livingdraft_indexnow_enabled() ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, apply_filters( 'livingdraft_indexnow_post_types', array( 'post', 'page' ) ), true ) ) {
		return;
	}
	if ( ! livingdraft_sitemap_should_include_post( $post ) ) {
		return; // Same exclusion logic as the sitemap — no point pinging noindexed URLs.
	}

	// Skip when the transition is publish → publish and the modified
	// time didn't move (which happens with programmatic saves that
	// don't actually change content).
	if ( $update && $post_before && 'publish' === $post_before->post_status
		&& $post_before->post_modified_gmt === $post->post_modified_gmt ) {
		return;
	}

	livingdraft_indexnow_submit( array( get_permalink( $post_id ) ) );
}
add_action( 'wp_after_insert_post', 'livingdraft_indexnow_maybe_ping', 20, 4 );

/**
 * Submit URLs to IndexNow. Fire-and-forget (5 second timeout, non-blocking
 * where possible). Result cached for the admin log so users can see what
 * happened without opening browser dev tools.
 *
 * @param string[] $urls
 * @return array { url, code, message, time }
 */
function livingdraft_indexnow_submit( $urls ) {
	$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );
	if ( empty( $urls ) ) {
		return array( 'code' => 0, 'message' => 'No URLs.' );
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$key  = livingdraft_indexnow_key();

	$body = array(
		'host'        => $host,
		'key'         => $key,
		'keyLocation' => home_url( '/' . $key . '.txt' ),
		'urlList'     => $urls,
	);

	$response = wp_remote_post(
		'https://api.indexnow.org/indexnow',
		array(
			'timeout' => 5,
			'blocking' => true,
			'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'     => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		$result = array(
			'code'    => 0,
			'message' => $response->get_error_message(),
			'urls'    => $urls,
			'time'    => time(),
		);
	} else {
		$code = (int) wp_remote_retrieve_response_code( $response );
		// 200 = accepted; 202 = accepted, taken; 400 = bad request; 403 = key mismatch;
		// 422 = URLs invalid or don't belong to the host; 429 = rate limited.
		$result = array(
			'code'    => $code,
			'message' => livingdraft_indexnow_code_message( $code ),
			'urls'    => $urls,
			'time'    => time(),
		);
	}

	livingdraft_indexnow_log( $result );
	return $result;
}

function livingdraft_indexnow_code_message( $code ) {
	$map = array(
		200 => __( 'Accepted', 'livingdraft-core' ),
		202 => __( 'Accepted (queued)', 'livingdraft-core' ),
		400 => __( 'Bad request', 'livingdraft-core' ),
		403 => __( 'Key not verified — check the key file at /{key}.txt', 'livingdraft-core' ),
		422 => __( 'Invalid URLs or wrong host', 'livingdraft-core' ),
		429 => __( 'Rate limited', 'livingdraft-core' ),
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : sprintf( 'HTTP %d', $code );
}

/* ------------------------------------------------------------------
 * 4. SMALL SUBMISSION LOG
 *
 * Last 50 pings kept in an option so the admin screen can show them.
 * More than enough for troubleshooting; anyone doing volume analysis
 * should go to Bing Webmaster Tools directly.
 * ------------------------------------------------------------------ */

function livingdraft_indexnow_log( $result ) {
	$log = get_option( 'livingdraft_indexnow_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, $result );
	$log = array_slice( $log, 0, 50 );
	update_option( 'livingdraft_indexnow_log', $log, false );
}

function livingdraft_indexnow_get_log() {
	$log = get_option( 'livingdraft_indexnow_log', array() );
	return is_array( $log ) ? $log : array();
}
