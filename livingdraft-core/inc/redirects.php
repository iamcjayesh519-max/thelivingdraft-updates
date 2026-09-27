<?php
/**
 * Redirections — send old URLs to their new home.
 *
 * === WHY THIS EXISTS ===
 *
 * When you edit a headline, WordPress changes the URL. Every inbound link
 * to the old URL now returns 404. Same when you unpublish a story, change
 * a category slug, or restructure your permalink pattern. Broken links
 * hurt SEO (Google penalises 404s), lose readers (they hit dead ends), and
 * break bookmarks / social shares.
 *
 * This module does three things:
 *   1. Auto-redirects when a post's slug changes. No thinking required.
 *   2. Manual redirects — a table in wp-admin where you paste old URL →
 *      new URL. Serves 301 or 302 status.
 *   3. 404 monitor — logs URLs that returned 404 in the last 30 days, so
 *      you can convert real reader mistakes into working redirects.
 *
 * === WHY NOT RANK MATH ===
 *
 * Rank Math does redirects. But its interface has 40 other features around
 * it. This is a focused tool for editorial workflow — you spend more of
 * your day changing headlines than tuning schema, so redirects should be
 * one click away, not five.
 *
 * === STORAGE ===
 *
 * Uses one custom database table (wp_livingdraft_redirects) instead of
 * WordPress options, so lookups are fast even with thousands of redirects.
 * 404 log is a separate table (wp_livingdraft_404s) with a size cap.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. DATABASE — table creation and version tracking
 * ------------------------------------------------------------------ */

define( 'LIVINGDRAFT_REDIRECTS_DB_VERSION', '1.0' );

/**
 * Create the redirects tables on activation, or upgrade them if the
 * schema has changed. Called at plugin load, checks a stored version
 * number so dbDelta only runs when needed.
 */
function livingdraft_redirects_maybe_install() {
	$installed = get_option( 'livingdraft_redirects_db_version' );

	if ( $installed === LIVINGDRAFT_REDIRECTS_DB_VERSION ) {
		return; // Already at current version, no work to do.
	}

	global $wpdb;
	$charset = $wpdb->get_charset_collate();

	$redirects_table = $wpdb->prefix . 'livingdraft_redirects';
	$log_table       = $wpdb->prefix . 'livingdraft_404s';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// Redirects table — source → target with type and hit counter.
	// Source path is indexed for O(log n) lookups on every request.
	$sql1 = "CREATE TABLE $redirects_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		source_path VARCHAR(2048) NOT NULL,
		target_url VARCHAR(2048) NOT NULL,
		redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
		hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
		last_hit DATETIME DEFAULT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		notes VARCHAR(255) DEFAULT NULL,
		auto_generated TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		KEY source_path_idx (source_path(191))
	) $charset;";

	// 404 log — capped at ~5000 rows via periodic cleanup. Tracks the URL,
	// referrer (where the user came from), and how many times it happened.
	$sql2 = "CREATE TABLE $log_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		url_path VARCHAR(2048) NOT NULL,
		referrer VARCHAR(2048) DEFAULT NULL,
		user_agent VARCHAR(500) DEFAULT NULL,
		hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
		last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		resolved TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		KEY url_path_idx (url_path(191)),
		KEY last_seen_idx (last_seen)
	) $charset;";

	dbDelta( $sql1 );
	dbDelta( $sql2 );

	update_option( 'livingdraft_redirects_db_version', LIVINGDRAFT_REDIRECTS_DB_VERSION );
}
add_action( 'plugins_loaded', 'livingdraft_redirects_maybe_install' );

/* ------------------------------------------------------------------
 * 2. THE REDIRECT ITSELF — runs early on every request
 * ------------------------------------------------------------------ */

/**
 * Intercept the current request, look for a matching redirect, and if
 * found, send the browser to the new URL.
 *
 * Hooked to 'template_redirect' at priority 1 so it runs before WordPress
 * fully renders the page. Only fires on the front end, never in admin.
 */
function livingdraft_redirects_run() {
	if ( is_admin() ) {
		return;
	}

	/*
	 * The current request path, without query string, decoded.
	 * e.g. /old-story-headline/  or  /category/misspelled-thing
	 *
	 * wp_unslash() matters here. WordPress runs add_magic_quotes() over
	 * $_SERVER as well as $_GET and $_POST, so a path containing a quote or
	 * a backslash arrives with an extra backslash in front of it. Matched
	 * against the stored source_path it would never hit, and the redirect
	 * would fail silently for exactly the URLs most likely to have been
	 * mistyped in the first place.
	 */
	$request  = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	$request  = urldecode( (string) $request );
	$request  = trailingslashit( $request );

	if ( '' === $request || '/' === $request ) {
		return; // Nothing to redirect on the home page.
	}

	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_redirects';

	// Look up exact path match. Kept simple by design — no regex, no
	// wildcard, no query-string matching. If you need those, use .htaccess.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, target_url, redirect_type FROM $table WHERE source_path = %s LIMIT 1",
			$request
		)
	);
	// phpcs:enable

	if ( ! $row ) {
		return; // No redirect configured for this path. Let WordPress handle it (may 404).
	}

	// Update hit counter without blocking the redirect. Fire-and-forget.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE $table SET hits = hits + 1, last_hit = %s WHERE id = %d",
			current_time( 'mysql' ),
			(int) $row->id
		)
	);

	// Type: 301 (permanent) is default and best for SEO.
	// 302 (temporary) is for A/B tests or seasonal redirects.
	$type = in_array( (int) $row->redirect_type, array( 301, 302, 307 ), true ) ? (int) $row->redirect_type : 301;

	// wp_safe_redirect() would refuse external hosts. Some targets ARE
	// external (moved to Medium, retired stories linking to archive.org).
	// We already require the admin to have manage_options to save these,
	// so trust is established at write time, not at read time.
	wp_redirect( esc_url_raw( $row->target_url ), $type );
	exit;
}
add_action( 'template_redirect', 'livingdraft_redirects_run', 1 );

/* ------------------------------------------------------------------
 * 3. AUTO-REDIRECT ON SLUG CHANGE — the killer feature for editors
 * ------------------------------------------------------------------ */

/**
 * When a post's slug changes, create a 301 from the old URL to the new
 * one automatically. Runs on 'post_updated', which fires AFTER the post
 * is saved but with both old and new data available.
 *
 * This is what saves you from breaking every inbound link when you edit
 * a published headline. WordPress does not do this on its own — old
 * slug is thrown away, all incoming links to it start 404-ing.
 *
 * @param int     $post_id
 * @param WP_Post $post_after
 * @param WP_Post $post_before
 */
function livingdraft_redirects_track_slug_change( $post_id, $post_after, $post_before ) {
	// Only for published posts and pages — drafts don't have public URLs.
	if ( 'publish' !== $post_after->post_status ) {
		return;
	}

	if ( ! in_array( $post_after->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	// Slug didn't change → nothing to redirect.
	if ( $post_before->post_name === $post_after->post_name ) {
		return;
	}

	// Setting turned off in admin → skip.
	if ( ! (bool) get_option( 'livingdraft_redirects_auto_slug', true ) ) {
		return;
	}

	// Build the old and new URLs. We reconstruct the old URL by temporarily
	// swapping the post's slug back to what it was.
	$new_url = get_permalink( $post_after );

	$original_name       = $post_after->post_name;
	$post_after->post_name = $post_before->post_name;
	$old_url             = get_permalink( $post_after );
	$post_after->post_name = $original_name;

	// Reduce to path-only (redirect source is stored as path, not full URL).
	$old_path = wp_parse_url( $old_url, PHP_URL_PATH );
	if ( ! $old_path ) {
		return;
	}
	$old_path = trailingslashit( $old_path );

	// If the new URL is the same as the old (rare, but happens when the
	// permalink structure includes post_id not slug), don't loop.
	if ( $old_url === $new_url ) {
		return;
	}

	livingdraft_redirects_add(
		$old_path,
		$new_url,
		301,
		sprintf( 'Auto: slug changed on post #%d', $post_id ),
		true
	);
}
add_action( 'post_updated', 'livingdraft_redirects_track_slug_change', 10, 3 );

/* ------------------------------------------------------------------
 * 4. 404 MONITOR — capture broken URLs so you can fix them
 * ------------------------------------------------------------------ */

/**
 * When WordPress serves a 404 page, log the URL. Editors can review the
 * log periodically and turn frequent 404s into working redirects.
 *
 * Hooked to '404_template' so it only fires on actual 404 responses —
 * not on redirects, not on real pages.
 */
function livingdraft_redirects_log_404( $template ) {
	// Setting turned off? Skip logging entirely.
	if ( ! (bool) get_option( 'livingdraft_redirects_log_404', true ) ) {
		return $template;
	}

	// Skip logging bot-generated 404s. Real editors care about human traffic.
	// Uses the theme's bot-detection filter if available (added earlier in Phase 1).
	$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	$bot_signatures = apply_filters(
		'livingdraft_bot_signatures',
		array( 'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'headless' )
	);
	foreach ( $bot_signatures as $sig ) {
		if ( stripos( $ua, $sig ) !== false ) {
			return $template;
		}
	}

	// Unslashed for the same reason as the redirect lookup above.
	$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	if ( ! $path || '/' === $path ) {
		return $template;
	}
	$path = trailingslashit( urldecode( $path ) );

	// Skip WordPress admin-y things.
	if ( 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '/wp-json' ) || 0 === strpos( $path, '/wp-login' ) ) {
		return $template;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_404s';

	// Truncate very long UAs — no reason to store a 2KB browser fingerprint.
	$ua_trimmed = mb_substr( $ua, 0, 500 );
	$referrer   = mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), 0, 2000 );

	// Upsert pattern — if this URL was already 404-ing, increment hits.
	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM $table WHERE url_path = %s AND resolved = 0 LIMIT 1",
			$path
		)
	);

	if ( $existing ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET hits = hits + 1, last_seen = %s WHERE id = %d",
				current_time( 'mysql' ),
				(int) $existing
			)
		);
	} else {
		$wpdb->insert(
			$table,
			array(
				'url_path'   => $path,
				'referrer'   => $referrer,
				'user_agent' => $ua_trimmed,
				'last_seen'  => current_time( 'mysql' ),
				'first_seen' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	// Occasional cleanup — cap at 5000 rows to prevent the table growing forever.
	if ( wp_rand( 1, 100 ) === 1 ) {
		$wpdb->query(
			"DELETE FROM $table WHERE id NOT IN (SELECT id FROM (SELECT id FROM $table ORDER BY last_seen DESC LIMIT 5000) tmp)"
		);
	}

	return $template;
}
add_filter( '404_template', 'livingdraft_redirects_log_404' );

/* ------------------------------------------------------------------
 * 5. PUBLIC API — helpers other code can call
 * ------------------------------------------------------------------ */

/**
 * Add or update a redirect. Idempotent — if source already exists, it's
 * overwritten with the new target. Returns the row ID or false on failure.
 *
 * @param string $source_path e.g. '/old-headline/'
 * @param string $target_url  Full URL, may be external.
 * @param int    $type        301|302|307
 * @param string $notes       Freeform admin note, optional.
 * @param bool   $auto        Whether this was auto-generated (vs manual).
 * @return int|false Row ID, or false.
 */
function livingdraft_redirects_add( $source_path, $target_url, $type = 301, $notes = '', $auto = false ) {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_redirects';

	$source_path = trailingslashit( trim( (string) $source_path ) );
	$target_url  = esc_url_raw( (string) $target_url );

	if ( '' === $source_path || '/' === $source_path || '' === $target_url ) {
		return false;
	}

	// Never create a redirect that loops back to itself.
	$target_path = wp_parse_url( $target_url, PHP_URL_PATH );
	if ( trailingslashit( (string) $target_path ) === $source_path ) {
		return false;
	}

	$existing = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM $table WHERE source_path = %s LIMIT 1", $source_path )
	);

	$data = array(
		'source_path'    => $source_path,
		'target_url'     => $target_url,
		'redirect_type'  => in_array( (int) $type, array( 301, 302, 307 ), true ) ? (int) $type : 301,
		'notes'          => mb_substr( (string) $notes, 0, 255 ),
		'auto_generated' => $auto ? 1 : 0,
	);
	$format = array( '%s', '%s', '%d', '%s', '%d' );

	if ( $existing ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $existing ), $format, array( '%d' ) );
		return (int) $existing;
	}

	$data['created_at'] = current_time( 'mysql' );
	$format[] = '%s';

	$wpdb->insert( $table, $data, $format );
	return (int) $wpdb->insert_id;
}

/**
 * Delete a redirect by ID.
 */
function livingdraft_redirects_delete( $id ) {
	global $wpdb;
	return (bool) $wpdb->delete( $wpdb->prefix . 'livingdraft_redirects', array( 'id' => (int) $id ), array( '%d' ) );
}

/**
 * Fetch a page of redirects for the admin list table.
 */
function livingdraft_redirects_list( $args = array() ) {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_redirects';

	$args = wp_parse_args( $args, array(
		'per_page' => 20,
		'page'     => 1,
		'orderby'  => 'created_at',
		'order'    => 'DESC',
		'search'   => '',
	) );

	$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
	$orderby = in_array( $args['orderby'], array( 'created_at', 'hits', 'last_hit', 'source_path' ), true ) ? $args['orderby'] : 'created_at';
	$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

	$where     = '1=1';
	$where_args = array();

	if ( ! empty( $args['search'] ) ) {
		$where       .= ' AND (source_path LIKE %s OR target_url LIKE %s)';
		$like         = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$where_args[] = $like;
		$where_args[] = $like;
	}

	$sql = "SELECT * FROM $table WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
	$where_args[] = (int) $args['per_page'];
	$where_args[] = (int) $offset;

	return $wpdb->get_results( $wpdb->prepare( $sql, $where_args ) );
}

function livingdraft_redirects_count( $search = '' ) {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_redirects';

	if ( '' === $search ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}
	$like = '%' . $wpdb->esc_like( $search ) . '%';
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE source_path LIKE %s OR target_url LIKE %s", $like, $like )
	);
}

function livingdraft_404s_list( $args = array() ) {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_404s';

	$args = wp_parse_args( $args, array(
		'per_page' => 30,
		'page'     => 1,
		'resolved' => 0,
	) );

	$offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table WHERE resolved = %d ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d",
			(int) $args['resolved'],
			(int) $args['per_page'],
			(int) $offset
		)
	);
}

function livingdraft_404s_count( $resolved = 0 ) {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_404s';
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE resolved = %d", (int) $resolved ) );
}

function livingdraft_404_mark_resolved( $id ) {
	global $wpdb;
	return (bool) $wpdb->update(
		$wpdb->prefix . 'livingdraft_404s',
		array( 'resolved' => 1 ),
		array( 'id' => (int) $id ),
		array( '%d' ),
		array( '%d' )
	);
}

/* ------------------------------------------------------------------
 * 6. ADMIN — the actual page where editors work
 * ------------------------------------------------------------------ */

require_once __DIR__ . '/redirects-admin.php';
require_once __DIR__ . '/redirects-suggest.php';
