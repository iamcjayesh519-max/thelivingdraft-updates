<?php
/**
 * GSC hooks: analytics cache, auto-indexing on publish, metabox strip.
 *
 * The two capabilities run on different schedules because they map to
 * different real-world needs.
 *
 * ANALYTICS: refresh once a day (cron), read from cache on metabox
 * render. Search Console data has a 2-3 day latency anyway, so nightly
 * is fresh enough. Live queries per metabox render would burn quota.
 *
 * INDEXING: fire immediately on publish. That's the whole point —
 * tell Google about a new URL within seconds of it being live. Uses
 * `wp_after_insert_post` so meta is fully written before we submit.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. ANALYTICS CACHE
 *
 * The nightly cron pulls the top 1000 pages for the last 28 days
 * (ending 3 days ago, per GSC's latency), stores as a URL-indexed
 * array in an option so per-URL lookup at metabox render is O(1).
 * ------------------------------------------------------------------ */

const LD_GSC_CACHE_OPTION   = 'livingdraft_gsc_analytics_cache';
const LD_GSC_CACHE_META_OPT = 'livingdraft_gsc_analytics_cache_meta';
const LD_GSC_INDEXING_LOG   = 'livingdraft_gsc_indexing_log';

/**
 * Fetch fresh analytics data and store as a URL-keyed array.
 * Returns true on success, WP_Error on failure.
 */
function livingdraft_gsc_refresh_analytics_cache() {
	$s = livingdraft_gsc_get_settings();
	if ( ! $s['analytics_enabled'] || ! livingdraft_gsc_configured() ) {
		return new WP_Error( 'ld_gsc_disabled', __( 'GSC analytics not configured or enabled.', 'livingdraft-core' ) );
	}

	$result = livingdraft_gsc_query_analytics( '', 1000 );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$cache = array();
	if ( isset( $result['rows'] ) && is_array( $result['rows'] ) ) {
		foreach ( $result['rows'] as $row ) {
			$url = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $url ) {
				continue;
			}
			$cache[ $url ] = array(
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => (float) ( $row['ctr'] ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}
	}

	update_option( LD_GSC_CACHE_OPTION, $cache, false );
	update_option( LD_GSC_CACHE_META_OPT, array(
		'refreshed' => time(),
		'count'     => count( $cache ),
	), false );

	// v3.6.0: denormalize per-post metrics into postmeta so the queue
	// list can sort/filter by clicks and impressions via meta_value_num.
	// Without this, sorting requires loading the whole cache option
	// into PHP on every query and sorting there. Postmeta gives us
	// SQL-side ORDER BY on 10K+ post sites for free.
	livingdraft_gsc_denormalize_metrics_to_postmeta( $cache );

	return true;
}

/**
 * Post types that carry denormalised GSC metrics.
 *
 * Matches the post types the SEO list column is added to, so the two can
 * never disagree about which screens should show numbers.
 *
 * @since 4.0.0
 * @return string[]
 */
function livingdraft_gsc_metric_post_types() {
	if ( function_exists( 'livingdraft_seo_column_post_types' ) ) {
		return (array) livingdraft_seo_column_post_types();
	}

	return array( 'post', 'page' );
}

/**
 * Walk the URL-keyed analytics cache and stamp each match onto the
 * corresponding post's postmeta. Iterating cache-first (typically
 * 1000 URLs) rather than posts-first (which could be 100k on a
 * newsroom) is much cheaper.
 *
 * The URL variant resolver handles the http/https/www/slash quirks
 * so a cache key of "https://example.com/foo/" still resolves to
 * the post whose permalink is "https://www.example.com/foo".
 *
 * Missing entries (a post that used to have data and no longer
 * does) get their meta cleared, so old numbers don't linger and
 * pollute the sort order.
 */
function livingdraft_gsc_denormalize_metrics_to_postmeta( $cache = null ) {
	if ( null === $cache ) {
		$cache = get_option( LD_GSC_CACHE_OPTION, array() );
	}
	if ( ! is_array( $cache ) ) {
		return 0;
	}

	// Build post_id → data map from the cache.
	$post_data = array();
	foreach ( $cache as $url => $data ) {
		$post_id = url_to_postid( (string) $url );
		if ( ! $post_id ) {
			// Try variants — the cache may hold a URL under a scheme /
			// host / slash form that doesn't match get_permalink().
			foreach ( livingdraft_gsc_url_variants( (string) $url ) as $variant ) {
				$post_id = url_to_postid( $variant );
				if ( $post_id ) {
					break;
				}
			}
		}
		if ( $post_id && ! isset( $post_data[ $post_id ] ) ) {
			$post_data[ $post_id ] = $data;
		}
	}

	// Clear stale metrics from posts that no longer appear in cache.
	// We only clear posts that PREVIOUSLY had a stamp, to avoid a
	// full-table postmeta scan on every refresh.
	global $wpdb;
	$previously_stamped = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ld_gsc_impressions'"
	);
	foreach ( (array) $previously_stamped as $pid ) {
		$pid = (int) $pid;
		if ( ! isset( $post_data[ $pid ] ) ) {
			delete_post_meta( $pid, '_ld_gsc_clicks' );
			delete_post_meta( $pid, '_ld_gsc_impressions' );
			delete_post_meta( $pid, '_ld_gsc_position' );
		}
	}

	// Stamp fresh values.
	$updated = 0;
	foreach ( $post_data as $pid => $data ) {
		update_post_meta( $pid, '_ld_gsc_clicks', (int) ( $data['clicks'] ?? 0 ) );
		update_post_meta( $pid, '_ld_gsc_impressions', (int) ( $data['impressions'] ?? 0 ) );
		update_post_meta( $pid, '_ld_gsc_position', (float) ( $data['position'] ?? 0 ) );
		$updated++;
	}

	/*
	 * === SECOND PASS, ADDED IN 4.0 ===
	 *
	 * The loop above walks the cache and asks url_to_postid() to find the
	 * matching post. That is the cheap direction, but url_to_postid() is
	 * unreliable: it resolves a URL by running it back through the rewrite
	 * rules, and it commonly returns 0 for permalink structures built on a
	 * nested category (/states/west-bengal/slug), for URLs without a
	 * trailing slash, and for percent-encoded paths. Google reports all
	 * three.
	 *
	 * The result was a site where the editor sidebar showed impressions and
	 * clicks perfectly while every row of the Posts list showed a dash —
	 * because the sidebar goes the OTHER way. It takes the post's own
	 * permalink and looks it up in the cache, which never has to guess.
	 *
	 * So this pass does exactly what the sidebar does, for every published
	 * post that pass one failed to stamp. Slower, but bounded and only over
	 * the posts still missing, so a site where pass one worked does almost
	 * no extra work here.
	 */
	$unresolved = get_posts(
		array(
			'post_type'      => livingdraft_gsc_metric_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => (int) apply_filters( 'livingdraft_gsc_backfill_limit', 2000 ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_ld_gsc_impressions',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	foreach ( $unresolved as $pid ) {
		$pid  = (int) $pid;
		$data = livingdraft_gsc_analytics_for( get_permalink( $pid ) );

		if ( ! is_array( $data ) ) {
			continue; // Google genuinely has nothing for this URL.
		}

		update_post_meta( $pid, '_ld_gsc_clicks', (int) ( $data['clicks'] ?? 0 ) );
		update_post_meta( $pid, '_ld_gsc_impressions', (int) ( $data['impressions'] ?? 0 ) );
		update_post_meta( $pid, '_ld_gsc_position', (float) ( $data['position'] ?? 0 ) );
		$updated++;
	}

	// v3.7.0: also stamp termmeta so category/tag list screens can
	// show clicks + impressions the same way posts do. We iterate
	// every term in every public taxonomy (bounded by term count,
	// usually a few hundred on a newsroom) and match its archive URL
	// against the cache.
	livingdraft_gsc_denormalize_term_metrics( $cache );

	return $updated;
}

/**
 * v3.7.0: Denormalize GSC data into termmeta.
 * Same pattern as the post version but for terms: walk every term
 * in every public taxonomy, resolve its archive URL, match against
 * the cache using the variant resolver. Cheap because the term
 * count on a typical newsroom is well under 1000.
 */
function livingdraft_gsc_denormalize_term_metrics( $cache = null ) {
	if ( null === $cache ) {
		$cache = get_option( LD_GSC_CACHE_OPTION, array() );
	}
	if ( ! is_array( $cache ) ) {
		return 0;
	}

	// Which taxonomies get GSC data? Public ones, excluding a couple
	// that never have real archive pages worth tracking.
	$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
	$excluded   = array( 'nav_menu', 'link_category', 'post_format' );
	$taxonomies = array_values( array_diff( $taxonomies, $excluded ) );

	if ( empty( $taxonomies ) ) {
		return 0;
	}

	$terms = get_terms( array(
		'taxonomy'   => $taxonomies,
		'hide_empty' => false,
		'fields'     => 'ids',
		'number'     => 5000, // Cap for very-large sites.
	) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return 0;
	}

	// Clear stale termmeta first — same idea as posts, only touch terms
	// that previously had a stamp.
	global $wpdb;
	$previously_stamped = $wpdb->get_col(
		"SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_ld_gsc_impressions'"
	);
	$stamp_targets = array();
	foreach ( $terms as $tid ) {
		$link = get_term_link( (int) $tid );
		if ( is_wp_error( $link ) || ! $link ) {
			continue;
		}
		$hit = null;
		foreach ( livingdraft_gsc_url_variants( $link ) as $variant ) {
			if ( isset( $cache[ $variant ] ) ) {
				$hit = $cache[ $variant ];
				break;
			}
		}
		if ( $hit ) {
			$stamp_targets[ (int) $tid ] = $hit;
		}
	}
	// Clear stamps on terms that no longer match cache.
	foreach ( (array) $previously_stamped as $tid ) {
		$tid = (int) $tid;
		if ( ! isset( $stamp_targets[ $tid ] ) ) {
			delete_term_meta( $tid, '_ld_gsc_clicks' );
			delete_term_meta( $tid, '_ld_gsc_impressions' );
			delete_term_meta( $tid, '_ld_gsc_position' );
		}
	}
	// Write fresh values.
	$updated = 0;
	foreach ( $stamp_targets as $tid => $data ) {
		update_term_meta( $tid, '_ld_gsc_clicks', (int) ( $data['clicks'] ?? 0 ) );
		update_term_meta( $tid, '_ld_gsc_impressions', (int) ( $data['impressions'] ?? 0 ) );
		update_term_meta( $tid, '_ld_gsc_position', (float) ( $data['position'] ?? 0 ) );
		$updated++;
	}
	return $updated;
}

/**
 * v3.6.0: Queue list query. Powers the new Indexing Queue table
 * on the GSC admin tab. Returns a page of posts plus totals.
 *
 * Filters (each translates to a meta_query clause):
 *   all          — no filter, every published post
 *   never        — never submitted to Indexing API
 *   stale_30     — never submitted OR last submitted 30+ days ago
 *   stale_90     — never submitted OR last submitted 90+ days ago
 *   recent_7     — submitted in the last 7 days
 *
 * Sorts:
 *   newest, oldest             — post_date DESC / ASC
 *   never_submitted            — post_date DESC among NOT EXISTS on stamp
 *   longest_ago                — _ld_gsc_indexed_at ASC (oldest submission first)
 *   top_impressions            — _ld_gsc_impressions DESC
 *   top_clicks                 — _ld_gsc_clicks DESC
 *   best_position              — _ld_gsc_position ASC (excluding 0/no-data)
 */
function livingdraft_gsc_queue_query( $args = array() ) {
	$defaults = array(
		'sort'      => 'longest_ago',
		'filter'    => 'stale_30',
		'post_type' => array( 'post', 'page' ),
		'per_page'  => 50,
		'page'      => 1,
	);
	$args = wp_parse_args( $args, $defaults );
	$now  = time();

	$wp_args = array(
		'post_type'      => $args['post_type'],
		'post_status'    => 'publish',
		'posts_per_page' => (int) $args['per_page'],
		'paged'          => max( 1, (int) $args['page'] ),
		'no_found_rows'  => false, // Need total for pagination.
		'meta_query'     => array(),
	);

	switch ( $args['filter'] ) {
		case 'never':
			$wp_args['meta_query'][] = array(
				'key'     => '_ld_gsc_indexed_at',
				'compare' => 'NOT EXISTS',
			);
			break;
		case 'stale_30':
			$wp_args['meta_query'][] = array(
				'relation' => 'OR',
				array( 'key' => '_ld_gsc_indexed_at', 'compare' => 'NOT EXISTS' ),
				array(
					'key'     => '_ld_gsc_indexed_at',
					'value'   => $now - 30 * DAY_IN_SECONDS,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			);
			break;
		case 'stale_90':
			$wp_args['meta_query'][] = array(
				'relation' => 'OR',
				array( 'key' => '_ld_gsc_indexed_at', 'compare' => 'NOT EXISTS' ),
				array(
					'key'     => '_ld_gsc_indexed_at',
					'value'   => $now - 90 * DAY_IN_SECONDS,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			);
			break;
		case 'recent_7':
			$wp_args['meta_query'][] = array(
				'key'     => '_ld_gsc_indexed_at',
				'value'   => $now - 7 * DAY_IN_SECONDS,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
			break;
	}

	switch ( $args['sort'] ) {
		case 'newest':
			$wp_args['orderby'] = 'date';
			$wp_args['order']   = 'DESC';
			break;
		case 'oldest':
			$wp_args['orderby'] = 'date';
			$wp_args['order']   = 'ASC';
			break;
		case 'never_submitted':
			$wp_args['orderby']       = 'date';
			$wp_args['order']         = 'DESC';
			$wp_args['meta_query'][]  = array(
				'key'     => '_ld_gsc_indexed_at',
				'compare' => 'NOT EXISTS',
			);
			break;
		case 'longest_ago':
			// Sort by indexed_at ASC. Posts with no stamp sort as 0
			// (MySQL NULL treatment via meta_value_num), which puts
			// them at the top — which is intuitive: "the oldest /
			// most-forgotten first" naturally includes never-submitted.
			$wp_args['meta_key'] = '_ld_gsc_indexed_at';
			$wp_args['orderby']  = 'meta_value_num';
			$wp_args['order']    = 'ASC';
			break;
		case 'top_impressions':
			$wp_args['meta_key'] = '_ld_gsc_impressions';
			$wp_args['orderby']  = 'meta_value_num';
			$wp_args['order']    = 'DESC';
			break;
		case 'top_clicks':
			$wp_args['meta_key'] = '_ld_gsc_clicks';
			$wp_args['orderby']  = 'meta_value_num';
			$wp_args['order']    = 'DESC';
			break;
		case 'best_position':
			$wp_args['meta_key']     = '_ld_gsc_position';
			$wp_args['orderby']      = 'meta_value_num';
			$wp_args['order']        = 'ASC';
			// Exclude 0 (no data) so position 1.2 wins over position 0.
			$wp_args['meta_query'][] = array(
				'key'     => '_ld_gsc_position',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
			break;
	}

	// Empty meta_query trips WP_Query. Drop it if we didn't add any clauses.
	if ( empty( $wp_args['meta_query'] ) ) {
		unset( $wp_args['meta_query'] );
	}

	$q = new WP_Query( $wp_args );
	return array(
		'posts' => $q->posts,
		'total' => (int) $q->found_posts,
		'pages' => (int) $q->max_num_pages,
	);
}

/**
 * Look up analytics for a single URL in the cache. Returns null on
 * cache miss — the metabox distinguishes "no data yet" from "zero clicks".
 *
 * v3.4.0: expanded URL normalization. Previously we tried only one
 * trailing-slash variant. GSC keys its data by the exact URL Google
 * indexed, which may differ from get_permalink() in four dimensions:
 *   - trailing slash / no trailing slash
 *   - https / http
 *   - www / non-www
 * We now try every combination so a site whose canonical is
 * https://example.com/ but whose GSC data is indexed under
 * http://www.example.com/ still gets a cache hit.
 */
function livingdraft_gsc_analytics_for( $url ) {
	$cache = get_option( LD_GSC_CACHE_OPTION, array() );
	if ( ! is_array( $cache ) || empty( $cache ) ) {
		return null;
	}

	foreach ( livingdraft_gsc_url_variants( $url ) as $variant ) {
		if ( isset( $cache[ $variant ] ) ) {
			return $cache[ $variant ];
		}
	}
	return null;
}

/**
 * Generate every plausible URL variant Google might have indexed the
 * page under. Order matters — the caller returns on first hit — so
 * the passed-in canonical form is tried first, then progressively
 * more distant variants.
 *
 * @param string $url The canonical URL of the post/page.
 * @return string[]   Unique list, canonical first.
 */
function livingdraft_gsc_url_variants( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return array();
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return array( $url );
	}

	$host   = $parts['host'];
	$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
	$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
	// Only fabricate scheme/www variants for the URL itself, not the
	// query string — a URL with query params probably means a listing
	// page, but we still want to match all four host variants.

	$hosts   = array( $host );
	$stripped = preg_replace( '/^www\./i', '', $host );
	if ( $stripped !== $host ) {
		$hosts[] = $stripped;
	} else {
		$hosts[] = 'www.' . $host;
	}

	$schemes = array( 'https', 'http' );
	// Put the passed-in scheme first if given.
	if ( isset( $parts['scheme'] ) && 'http' === $parts['scheme'] ) {
		$schemes = array( 'http', 'https' );
	}

	// Both trailing-slash forms of the path.
	$paths = array();
	$paths[] = $path;
	if ( '/' === substr( $path, -1 ) ) {
		$paths[] = rtrim( $path, '/' );
	} else {
		$paths[] = $path . '/';
	}

	$out = array();
	foreach ( $schemes as $s ) {
		foreach ( $hosts as $h ) {
			foreach ( $paths as $p ) {
				$out[] = $s . '://' . $h . $p . $query;
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Register nightly cron. Fires once daily at whatever "hour after
 * midnight UTC" WP-Cron picks. GSC data doesn't change hourly so this
 * is deliberately not a fine-grained schedule.
 */
function livingdraft_gsc_schedule_cron() {
	if ( ! wp_next_scheduled( 'livingdraft_gsc_daily_refresh' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'livingdraft_gsc_daily_refresh' );
	}
}
add_action( 'init', 'livingdraft_gsc_schedule_cron' );

add_action( 'livingdraft_gsc_daily_refresh', 'livingdraft_gsc_refresh_analytics_cache' );

/* ------------------------------------------------------------------
 * 2. AUTO-SUBMIT TO INDEXING API ON PUBLISH
 * ------------------------------------------------------------------ */

function livingdraft_gsc_maybe_submit_indexing( $post_id, $post, $update, $post_before ) {
	$s = livingdraft_gsc_get_settings();
	if ( ! $s['indexing_enabled'] || ! livingdraft_gsc_configured() ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// Only for publish, only for post types that make sense to index.
	$types = apply_filters( 'livingdraft_gsc_indexing_post_types', array( 'post', 'page' ) );
	if ( ! in_array( $post->post_type, $types, true ) ) {
		return;
	}

	if ( 'publish' !== $post->post_status ) {
		return;
	}

	// Debounce rapid re-saves. wp_after_insert_post fires on every
	// wp_update_post, including tiny edits — a writer pressing Save
	// twice within a minute shouldn't burn two quota units. Skip if
	// this exact URL was submitted successfully in the last 5 minutes.
	//
	// (Note: comparing $post_before->post_modified_gmt to $post's is
	// useless here — wp_update_post updates modified_gmt on every
	// save regardless, so the two values always differ. Hence this
	// log-based approach instead.)
	$recent_cutoff = time() - 300;
	$url_for_check = get_permalink( $post );
	$log_check     = get_option( LD_GSC_INDEXING_LOG, array() );
	if ( is_array( $log_check ) ) {
		foreach ( $log_check as $row ) {
			if ( 200 === (int) ( $row['code'] ?? 0 )
				&& ( $row['url'] ?? '' ) === $url_for_check
				&& (int) ( $row['time'] ?? 0 ) >= $recent_cutoff ) {
				return;
			}
		}
	}

	// Same exclusion logic as sitemap/IndexNow — never submit URLs we
	// don't want indexed in the first place.
	if ( function_exists( 'livingdraft_sitemap_should_include_post' ) && ! livingdraft_sitemap_should_include_post( $post ) ) {
		return;
	}

	// Guard against burning the daily 200-URL quota. If we've already
	// hit 200 successful submissions today, don't even attempt — the
	// call would fail with 429 and just noise up the log.
	if ( livingdraft_gsc_indexing_submissions_today() >= 200 ) {
		livingdraft_gsc_log_submission( array(
			'code'    => 0,
			'message' => __( 'Skipped — daily quota reached.', 'livingdraft-core' ),
			'url'     => get_permalink( $post ),
			'time'    => time(),
			'source'  => 'auto',
		) );
		return;
	}

	$url    = get_permalink( $post );
	$result = livingdraft_gsc_indexing_submit( $url );

	livingdraft_gsc_log_submission( array(
		'code'    => (int) $result['code'],
		'message' => $result['message'],
		'url'     => $url,
		'time'    => time(),
		'source'  => 'auto',
	) );
}
add_action( 'wp_after_insert_post', 'livingdraft_gsc_maybe_submit_indexing', 25, 4 );

/**
 * Append a submission to the log, keeping the last 200 entries so the
 * admin screen can show a meaningful history without unbounded growth.
 *
 * When the submission succeeded (code=200), we ALSO stamp the target
 * post with a permanent `_ld_gsc_indexed_at` postmeta. This is the
 * persistent record — the log is capped and rotates, so we can't rely
 * on it alone to know "has this post ever been submitted successfully?"
 * for bulk-backfill deduplication.
 */
function livingdraft_gsc_log_submission( $entry ) {
	$log = get_option( LD_GSC_INDEXING_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, $entry );
	$log = array_slice( $log, 0, 200 );
	update_option( LD_GSC_INDEXING_LOG, $log, false );

	// Persistent per-post stamp for successful submissions.
	if ( 200 === (int) ( $entry['code'] ?? 0 ) && ! empty( $entry['url'] ) ) {
		$post_id = url_to_postid( $entry['url'] );
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, '_ld_gsc_indexed_at', (int) ( $entry['time'] ?? time() ) );
		}
	}
}

/* ------------------------------------------------------------------
 * 3. BULK BACKFILL (AJAX)
 *
 * Manual "index next 25 URLs" for backfilling existing posts. Sequential
 * with a small delay so we don't slam Google in one burst. Respects the
 * 200/day quota.
 * ------------------------------------------------------------------ */

/**
 * Return N post IDs that haven't been successfully submitted yet, using
 * the persistent `_ld_gsc_indexed_at` postmeta (set by log_submission
 * on every 200 response) rather than the log itself — the log is
 * capped at 200 entries and rotates, so a large backfill would start
 * re-submitting older URLs as their log entries fell off.
 */
function livingdraft_gsc_pending_post_ids( $limit = 25 ) {
	global $wpdb;

	// Newest first so recent content gets the priority push.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('post','page')
			   AND p.post_password = ''
			   AND pm.meta_id IS NULL
			 ORDER BY p.post_date DESC
			 LIMIT %d",
			'_ld_gsc_indexed_at',
			(int) $limit * 3
		)
	);

	$out = array();
	foreach ( $rows as $row ) {
		$p = get_post( (int) $row->ID );
		if ( ! $p ) {
			continue;
		}
		if ( function_exists( 'livingdraft_sitemap_should_include_post' ) && ! livingdraft_sitemap_should_include_post( $p ) ) {
			continue;
		}
		$out[] = (int) $p->ID;
		if ( count( $out ) >= (int) $limit ) {
			break;
		}
	}
	return $out;
}

function livingdraft_gsc_ajax_bulk_index() {
	check_ajax_referer( 'ld_gsc_bulk', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	if ( ! livingdraft_gsc_configured() ) {
		wp_send_json_error( array( 'message' => __( 'GSC not configured.', 'livingdraft-core' ) ) );
	}

	$batch    = min( 25, max( 1, isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 10 ) );
	$today    = livingdraft_gsc_indexing_submissions_today();
	$headroom = max( 0, 200 - $today );
	$batch    = min( $batch, $headroom );

	if ( 0 === $batch ) {
		wp_send_json_success( array(
			'submitted'  => 0,
			'quota_left' => 0,
			'done'       => true,
			'message'    => __( 'Daily quota already used — resets at UTC midnight.', 'livingdraft-core' ),
		) );
	}

	$ids = livingdraft_gsc_pending_post_ids( $batch );
	if ( empty( $ids ) ) {
		wp_send_json_success( array(
			'submitted'  => 0,
			'quota_left' => 200 - $today,
			'done'       => true,
			'message'    => __( 'No more posts to submit.', 'livingdraft-core' ),
		) );
	}

	$ok   = 0;
	$fail = 0;
	foreach ( $ids as $id ) {
		$url    = get_permalink( $id );
		$result = livingdraft_gsc_indexing_submit( $url );
		livingdraft_gsc_log_submission( array(
			'code'    => (int) $result['code'],
			'message' => $result['message'],
			'url'     => $url,
			'time'    => time(),
			'source'  => 'bulk',
		) );
		if ( 200 === (int) $result['code'] ) {
			$ok++;
		} else {
			$fail++;
		}
		// Tiny stagger to be polite — Google's per-minute quota is
		// generous but we don't need to burst.
		usleep( 100000 );
	}

	wp_send_json_success( array(
		'submitted'  => $ok,
		'failed'     => $fail,
		'quota_left' => max( 0, 200 - livingdraft_gsc_indexing_submissions_today() ),
		'done'       => false,
	) );
}
/*
 * === THIS ENDPOINT IS CURRENTLY UNREACHABLE ===
 *
 * It verifies a nonce for the action 'ld_gsc_bulk', and nothing anywhere in
 * this plugin calls wp_create_nonce( 'ld_gsc_bulk' ). No admin screen or
 * script posts action=ld_gsc_bulk_index either. Any request that did reach it
 * would be rejected at the nonce check.
 *
 * It appears to have been superseded by ld_gsc_index_batch, which does the
 * same job from the Search Console screen and has a working button behind it.
 * The handler is left in place rather than deleted because it is correctly
 * guarded by both a nonce and a capability check, so it costs nothing and
 * removing a registered endpoint can break callers that are not visible from
 * here.
 *
 * Noted so that nobody spends an afternoon working out why the bulk index
 * button does nothing: there is no bulk index button.
 */
add_action( 'wp_ajax_ld_gsc_bulk_index', 'livingdraft_gsc_ajax_bulk_index' );

/**
 * Manual "sync analytics now" trigger.
 */
function livingdraft_gsc_ajax_sync_analytics() {
	check_ajax_referer( 'ld_gsc_sync', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	$result = livingdraft_gsc_refresh_analytics_cache();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	$meta = get_option( LD_GSC_CACHE_META_OPT, array() );
	wp_send_json_success( array(
		'refreshed_at' => time(),
		'count'        => isset( $meta['count'] ) ? (int) $meta['count'] : 0,
	) );
}
add_action( 'wp_ajax_ld_gsc_sync_analytics', 'livingdraft_gsc_ajax_sync_analytics' );

/**
 * v3.4.0: Live per-URL analytics query. Used by the metabox strip's
 * "Fetch live" button when the nightly cache misses this URL —
 * either because the URL is outside the top 1000 or because Google
 * indexed it under a different variant than any we know to try.
 *
 * Rate-limited to one call per minute per user so a stuck spinner
 * on a slow response doesn't let a writer hammer the API. The
 * result is written into the cache under the exact permalink so
 * subsequent renders hit the cache path.
 */
function livingdraft_gsc_ajax_live_metrics() {
	check_ajax_referer( 'ld_gsc_live', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	if ( ! livingdraft_gsc_configured() ) {
		wp_send_json_error( array( 'message' => __( 'GSC not configured.', 'livingdraft-core' ) ) );
	}
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'livingdraft-core' ) ) );
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'livingdraft-core' ) ) );
	}

	// Simple per-user rate limit — one live query per minute is
	// generous for legit interactive use and blocks accidental spam.
	$rate_key = 'ld_gsc_live_' . get_current_user_id();
	if ( get_transient( $rate_key ) ) {
		wp_send_json_error( array( 'message' => __( 'Please wait a moment before fetching again.', 'livingdraft-core' ) ) );
	}
	set_transient( $rate_key, 1, 60 );

	$url = get_permalink( $post );

	// Query GSC filtered to just this URL. Reuses the same 28-day
	// window the nightly cache uses.
	$result = livingdraft_gsc_query_analytics( $url, 1 );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$row = isset( $result['rows'][0] ) && is_array( $result['rows'][0] ) ? $result['rows'][0] : null;
	if ( ! $row ) {
		wp_send_json_success( array(
			'found' => false,
			'message' => __( 'Google returned no rows for this URL — it may genuinely have zero impressions in the last 28 days, or Google indexed it under a URL we didn\'t match.', 'livingdraft-core' ),
		) );
	}

	$data = array(
		'clicks'      => (int) ( $row['clicks'] ?? 0 ),
		'impressions' => (int) ( $row['impressions'] ?? 0 ),
		'ctr'         => (float) ( $row['ctr'] ?? 0 ),
		'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
	);

	// Warm the cache under the permalink so subsequent renders skip
	// the round-trip. If the cache doesn't exist yet, initialize it.
	$cache = get_option( LD_GSC_CACHE_OPTION, array() );
	if ( ! is_array( $cache ) ) {
		$cache = array();
	}
	$cache[ $url ] = $data;
	update_option( LD_GSC_CACHE_OPTION, $cache, false );

	wp_send_json_success( array(
		'found' => true,
		'data'  => $data,
	) );
}
add_action( 'wp_ajax_ld_gsc_live_metrics', 'livingdraft_gsc_ajax_live_metrics' );

/**
 * v3.4.0: Manual single-URL indexing submission. Used by the "Submit
 * now" / "Retry" button on the metabox strip. Respects the same
 * 200/day quota as the auto-submit path.
 */
function livingdraft_gsc_ajax_index_now() {
	check_ajax_referer( 'ld_gsc_index_now', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	if ( ! livingdraft_gsc_configured() ) {
		wp_send_json_error( array( 'message' => __( 'GSC not configured.', 'livingdraft-core' ) ) );
	}
	$s = livingdraft_gsc_get_settings();
	if ( ! $s['indexing_enabled'] ) {
		wp_send_json_error( array( 'message' => __( 'Auto-indexing is off; turn it on in SEO → Search Console.', 'livingdraft-core' ) ) );
	}
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'livingdraft-core' ) ) );
	}
	if ( 'publish' !== $post->post_status ) {
		wp_send_json_error( array( 'message' => __( 'Post is not published.', 'livingdraft-core' ) ) );
	}
	if ( livingdraft_gsc_indexing_submissions_today() >= 200 ) {
		wp_send_json_error( array( 'message' => __( 'Daily quota reached (200/day). Resets at UTC midnight.', 'livingdraft-core' ) ) );
	}

	$url    = get_permalink( $post );
	$result = livingdraft_gsc_indexing_submit( $url );
	livingdraft_gsc_log_submission( array(
		'code'    => (int) $result['code'],
		'message' => $result['message'],
		'url'     => $url,
		'time'    => time(),
		'source'  => 'manual',
	) );

	if ( 200 === (int) $result['code'] ) {
		wp_send_json_success( array( 'message' => __( 'Submitted — reload the page to see the confirmation.', 'livingdraft-core' ) ) );
	}
	wp_send_json_error( array( 'message' => $result['message'] ) );
}
add_action( 'wp_ajax_ld_gsc_index_now', 'livingdraft_gsc_ajax_index_now' );

/**
 * v3.6.0: Submit an arbitrary list of post IDs to the Indexing API.
 * Different from `ld_gsc_bulk_index` (which picks the next N never-
 * submitted posts) — this takes IDs from the queue table's checkbox
 * selection or from a "submit all matching filter" flow.
 *
 * Capped at 20 IDs per request; the JS chunks a larger selection
 * into sequential batches, updating a progress bar between each.
 * Respects the 200/day quota — trims the batch to whatever's left.
 */
function livingdraft_gsc_ajax_index_batch() {
	check_ajax_referer( 'ld_gsc_index_batch', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	if ( ! livingdraft_gsc_configured() ) {
		wp_send_json_error( array( 'message' => __( 'GSC not configured.', 'livingdraft-core' ) ) );
	}
	$s = livingdraft_gsc_get_settings();
	if ( ! $s['indexing_enabled'] ) {
		wp_send_json_error( array( 'message' => __( 'Indexing is disabled — enable it in Setup.', 'livingdraft-core' ) ) );
	}

	$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
	$ids = array_values( array_filter( array_unique( array_slice( $ids, 0, 20 ) ) ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'No post IDs provided.', 'livingdraft-core' ) ) );
	}

	$today    = livingdraft_gsc_indexing_submissions_today();
	$headroom = max( 0, 200 - $today );
	if ( 0 === $headroom ) {
		wp_send_json_success( array(
			'submitted'  => 0,
			'failed'     => 0,
			'skipped'    => count( $ids ),
			'quota_left' => 0,
			'quota_hit'  => true,
			'message'    => __( 'Daily quota reached — resets at UTC midnight.', 'livingdraft-core' ),
		) );
	}
	// Trim to whatever quota remains so we don't burn a submission on
	// a call we already know will fail.
	$ids = array_slice( $ids, 0, $headroom );

	$ok      = 0;
	$fail    = 0;
	$skipped = 0;
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			$skipped++;
			continue;
		}
		if ( function_exists( 'livingdraft_sitemap_should_include_post' ) && ! livingdraft_sitemap_should_include_post( $post ) ) {
			// Same exclusion rule the sitemap and auto-submit paths use —
			// don't waste quota on URLs we've marked noindex.
			$skipped++;
			continue;
		}
		$url    = get_permalink( $id );
		$result = livingdraft_gsc_indexing_submit( $url );
		livingdraft_gsc_log_submission( array(
			'code'    => (int) $result['code'],
			'message' => $result['message'],
			'url'     => $url,
			'time'    => time(),
			'source'  => 'queue',
		) );
		if ( 200 === (int) $result['code'] ) {
			$ok++;
		} else {
			$fail++;
		}
		// Small stagger to be polite; matches the bulk path.
		usleep( 100000 );
	}

	wp_send_json_success( array(
		'submitted'  => $ok,
		'failed'     => $fail,
		'skipped'    => $skipped,
		'quota_left' => max( 0, 200 - livingdraft_gsc_indexing_submissions_today() ),
	) );
}
add_action( 'wp_ajax_ld_gsc_index_batch', 'livingdraft_gsc_ajax_index_batch' );

/**
 * v3.7.1: Rebuild the per-post + per-term index from whatever's in the
 * existing analytics cache. Doesn't hit Google — just walks the cache
 * option and stamps postmeta / termmeta.
 *
 * The full sync path (`ld_gsc_sync_analytics`) also runs denormalization
 * as its final step, but that requires a Google API round-trip. This
 * endpoint exists for the common upgrade case where cache is already
 * populated from before the denormalization feature existed — users
 * shouldn't have to burn a fresh API call just to backfill their
 * postmeta stamps.
 */
function livingdraft_gsc_ajax_rebuild_index() {
	check_ajax_referer( 'ld_gsc_rebuild', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	$cache = get_option( LD_GSC_CACHE_OPTION, array() );
	if ( ! is_array( $cache ) || empty( $cache ) ) {
		wp_send_json_error( array( 'message' => __( 'Analytics cache is empty. Run "Sync now" first to fetch data from Google.', 'livingdraft-core' ) ) );
	}
	$posts = livingdraft_gsc_denormalize_metrics_to_postmeta( $cache );
	$terms = livingdraft_gsc_denormalize_term_metrics( $cache );
	wp_send_json_success( array(
		'posts_stamped' => (int) $posts,
		'terms_stamped' => (int) $terms,
		'cache_size'    => count( $cache ),
	) );
}
add_action( 'wp_ajax_ld_gsc_rebuild_index', 'livingdraft_gsc_ajax_rebuild_index' );

/**
 * v3.7.1: Version-triggered one-shot upgrade. Runs the first time a
 * user loads the admin after upgrading to 3.7.1 or later. Idempotent
 * — the flag key includes the version we last ran for, so downgrades
 * won't accidentally re-trigger.
 *
 * Currently does one thing: backfill the postmeta + termmeta stamps
 * from the existing analytics cache so the new Impr./Clicks columns
 * on Posts / Pages / Terms show data immediately. Without this, users
 * upgrading from 3.6.x see empty columns until their next nightly
 * cron fires or they manually sync.
 */
function livingdraft_gsc_maybe_run_upgrade_backfill() {
	if ( ! is_admin() ) {
		return;
	}
	// Only run for admins — the backfill is a write operation.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$flag = get_option( 'livingdraft_gsc_backfill_version', '' );
	if ( '3.7.1' === $flag || version_compare( $flag, '3.7.1', '>=' ) ) {
		return;
	}
	// Only run when a cache actually exists and no denormalization has
	// happened yet — a fresh install has nothing to backfill.
	$cache = get_option( LD_GSC_CACHE_OPTION, array() );
	if ( ! is_array( $cache ) || empty( $cache ) ) {
		// Nothing to backfill; still update the flag so we don't check
		// on every admin page load.
		update_option( 'livingdraft_gsc_backfill_version', '3.7.1', false );
		return;
	}
	livingdraft_gsc_denormalize_metrics_to_postmeta( $cache );
	livingdraft_gsc_denormalize_term_metrics( $cache );
	update_option( 'livingdraft_gsc_backfill_version', '3.7.1', false );
}
add_action( 'admin_init', 'livingdraft_gsc_maybe_run_upgrade_backfill', 20 );

/* ------------------------------------------------------------------
 * 4. METABOX STRIP: PERFORMANCE FOR THIS POST
 * ------------------------------------------------------------------ */

function livingdraft_gsc_metabox_strip( $post ) {
	$s = livingdraft_gsc_get_settings();
	if ( ! $s['analytics_enabled'] && ! $s['indexing_enabled'] ) {
		return;
	}
	if ( ! livingdraft_gsc_configured() ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	$url    = get_permalink( $post );
	$data   = $s['analytics_enabled'] ? livingdraft_gsc_analytics_for( $url ) : null;
	$meta   = get_option( LD_GSC_CACHE_META_OPT, array() );
	$synced = isset( $meta['refreshed'] ) ? (int) $meta['refreshed'] : 0;

	// v3.4.0: per-post last indexing submission. Read from the
	// persistent postmeta stamp (set on every 200) plus the last log
	// entry that mentions this URL, so we can show success even if
	// the log has rotated.
	$last_indexed_ts   = (int) get_post_meta( $post->ID, '_ld_gsc_indexed_at', true );
	$last_attempt_row  = null;
	if ( $s['indexing_enabled'] ) {
		$log = get_option( LD_GSC_INDEXING_LOG, array() );
		if ( is_array( $log ) ) {
			foreach ( $log as $row ) {
				if ( ( $row['url'] ?? '' ) === $url ) {
					$last_attempt_row = $row;
					break;
				}
			}
		}
	}
	?>
	<div class="ld-gsc-strip">
		<div class="ld-gsc-head">
			<div>
				<div class="ld-gsc-eyebrow"><?php esc_html_e( 'Last 28 days · Search Console', 'livingdraft-core' ); ?></div>
				<div class="ld-gsc-title"><?php esc_html_e( 'Performance', 'livingdraft-core' ); ?></div>
			</div>
			<?php if ( $synced && $s['analytics_enabled'] ) : ?>
				<span class="ld-gsc-synced">
					<?php
					printf(
						/* translators: %s: human-readable time ago */
						esc_html__( 'synced %s ago', 'livingdraft-core' ),
						esc_html( human_time_diff( $synced, time() ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! $s['analytics_enabled'] ) : ?>
			<div class="ld-gsc-empty">
				<?php esc_html_e( 'Analytics sync is off — turn it on in SEO → Search Console.', 'livingdraft-core' ); ?>
			</div>
		<?php elseif ( null === $data ) : ?>
			<div class="ld-gsc-empty">
				<?php if ( 0 === $synced ) : ?>
					<?php esc_html_e( 'No cache yet. Run a manual sync in SEO → Search Console to populate.', 'livingdraft-core' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No data in the cache for this URL. Try fetching it live — this queries Google directly for just this URL and works for the long tail beyond the top 1000.', 'livingdraft-core' ); ?>
					<div style="margin-top:8px">
						<button type="button" class="ld-gsc-live-btn button button-small"
							data-post-id="<?php echo (int) $post->ID; ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_live' ) ); ?>">
							<?php esc_html_e( 'Fetch live from GSC', 'livingdraft-core' ); ?>
						</button>
						<span class="ld-gsc-live-status" style="margin-left:8px;font-size:11px;color:#666"></span>
					</div>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="ld-gsc-metrics">
				<div class="ld-gsc-metric">
					<div class="ld-gsc-metric-num"><?php echo esc_html( number_format_i18n( $data['clicks'] ) ); ?></div>
					<div class="ld-gsc-metric-label"><?php esc_html_e( 'clicks', 'livingdraft-core' ); ?></div>
				</div>
				<div class="ld-gsc-metric">
					<div class="ld-gsc-metric-num"><?php echo esc_html( number_format_i18n( $data['impressions'] ) ); ?></div>
					<div class="ld-gsc-metric-label"><?php esc_html_e( 'impressions', 'livingdraft-core' ); ?></div>
				</div>
				<div class="ld-gsc-metric">
					<div class="ld-gsc-metric-num"><?php echo esc_html( sprintf( '%.1f%%', $data['ctr'] * 100 ) ); ?></div>
					<div class="ld-gsc-metric-label"><?php esc_html_e( 'CTR', 'livingdraft-core' ); ?></div>
				</div>
				<div class="ld-gsc-metric">
					<div class="ld-gsc-metric-num"><?php echo esc_html( $data['position'] ); ?></div>
					<div class="ld-gsc-metric-label"><?php esc_html_e( 'avg. position', 'livingdraft-core' ); ?></div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $s['indexing_enabled'] ) :
			// v3.4.0: per-post indexing status line. Three states:
			//  - never attempted → say so, offer to submit now
			//  - last attempt succeeded → show when
			//  - last attempt failed  → show when + specific reason
			$ok = $last_attempt_row && 200 === (int) ( $last_attempt_row['code'] ?? 0 );
			?>
			<div class="ld-gsc-indexing-status" style="margin-top:10px;padding-top:10px;border-top:1px solid #eee;font-size:12px">
				<div class="ld-gsc-eyebrow" style="margin-bottom:4px"><?php esc_html_e( 'Indexing API', 'livingdraft-core' ); ?></div>
				<?php if ( ! $last_attempt_row && ! $last_indexed_ts ) : ?>
					<div style="color:#a37a1f">
						<?php esc_html_e( 'Never submitted for this URL.', 'livingdraft-core' ); ?>
						<button type="button" class="ld-gsc-index-btn button button-small" style="margin-left:6px"
							data-post-id="<?php echo (int) $post->ID; ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_index_now' ) ); ?>">
							<?php esc_html_e( 'Submit now', 'livingdraft-core' ); ?>
						</button>
						<span class="ld-gsc-index-status" style="margin-left:6px;color:#666"></span>
					</div>
				<?php elseif ( $ok ) : ?>
					<div style="color:#2f7a3a">
						✓ <?php
						printf(
							/* translators: %s: human-readable time ago */
							esc_html__( 'Submitted %s ago (200 OK — Google will fetch soon).', 'livingdraft-core' ),
							esc_html( human_time_diff( (int) ( $last_attempt_row['time'] ?? $last_indexed_ts ), time() ) )
						);
						?>
					</div>
				<?php else : ?>
					<div style="color:#a32e2e">
						✗ <?php
						printf(
							/* translators: 1: HTTP status code, 2: error message */
							esc_html__( 'Last attempt failed (%1$s): %2$s', 'livingdraft-core' ),
							esc_html( (string) ( $last_attempt_row['code'] ?? '?' ) ),
							esc_html( (string) ( $last_attempt_row['message'] ?? '' ) )
						);
						?>
						<button type="button" class="ld-gsc-index-btn button button-small" style="margin-left:6px"
							data-post-id="<?php echo (int) $post->ID; ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_index_now' ) ); ?>">
							<?php esc_html_e( 'Retry', 'livingdraft-core' ); ?>
						</button>
						<span class="ld-gsc-index-status" style="margin-left:6px;color:#666"></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<script>
		(function(){
			// Live-fetch button.
			document.querySelectorAll('.ld-gsc-live-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					var status = btn.parentElement.querySelector('.ld-gsc-live-status');
					var fd = new FormData();
					fd.append('action', 'ld_gsc_live_metrics');
					fd.append('nonce', btn.getAttribute('data-nonce'));
					fd.append('post_id', btn.getAttribute('data-post-id'));
					btn.disabled = true;
					status.textContent = '<?php echo esc_js( __( 'Querying Google…', 'livingdraft-core' ) ); ?>';
					fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
						.then(function(r){return r.json();})
						.then(function(res){
							if (res && res.success && res.data && res.data.found) {
								status.textContent = '<?php echo esc_js( __( 'Got it — reload to see the numbers.', 'livingdraft-core' ) ); ?>';
								status.style.color = '#2f7a3a';
							} else {
								status.textContent = (res && res.data && res.data.message) || '<?php echo esc_js( __( 'Failed.', 'livingdraft-core' ) ); ?>';
								status.style.color = '#a32e2e';
								btn.disabled = false;
							}
						})
						.catch(function(){
							status.textContent = '<?php echo esc_js( __( 'Network error.', 'livingdraft-core' ) ); ?>';
							status.style.color = '#a32e2e';
							btn.disabled = false;
						});
				});
			});
			// Manual-index button.
			document.querySelectorAll('.ld-gsc-index-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					var status = btn.parentElement.querySelector('.ld-gsc-index-status');
					var fd = new FormData();
					fd.append('action', 'ld_gsc_index_now');
					fd.append('nonce', btn.getAttribute('data-nonce'));
					fd.append('post_id', btn.getAttribute('data-post-id'));
					btn.disabled = true;
					status.textContent = '<?php echo esc_js( __( 'Submitting…', 'livingdraft-core' ) ); ?>';
					fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
						.then(function(r){return r.json();})
						.then(function(res){
							if (res && res.success) {
								status.textContent = res.data.message || '<?php echo esc_js( __( 'Submitted.', 'livingdraft-core' ) ); ?>';
								status.style.color = '#2f7a3a';
							} else {
								status.textContent = (res && res.data && res.data.message) || '<?php echo esc_js( __( 'Failed.', 'livingdraft-core' ) ); ?>';
								status.style.color = '#a32e2e';
								btn.disabled = false;
							}
						})
						.catch(function(){
							status.textContent = '<?php echo esc_js( __( 'Network error.', 'livingdraft-core' ) ); ?>';
							status.style.color = '#a32e2e';
							btn.disabled = false;
						});
				});
			});
		})();
		</script>
	</div>
	<?php
}
add_action( 'livingdraft_seo_metabox_after', 'livingdraft_gsc_metabox_strip' );

/**
 * Inline styling for the metabox strip — kept next to its markup so
 * changes stay together.
 */
add_action( 'admin_head-post.php', 'livingdraft_gsc_metabox_css' );
add_action( 'admin_head-post-new.php', 'livingdraft_gsc_metabox_css' );
function livingdraft_gsc_metabox_css() {
	$s = livingdraft_gsc_get_settings();
	if ( ! $s['analytics_enabled'] || ! livingdraft_gsc_configured() ) {
		return;
	}
	?>
	<style>
		.ld-gsc-strip {
			margin-top: 16px;
			padding: 14px 16px;
			background: #fff;
			border: 1px solid #e5e5e5;
		}
		.ld-gsc-head {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			margin-bottom: 10px;
		}
		.ld-gsc-eyebrow {
			font-family: var(--tld-mono, "IBM Plex Mono", monospace);
			font-size: 10px;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: #999;
		}
		.ld-gsc-title {
			font-family: var(--tld-serif, Georgia, serif);
			font-size: 15px;
			color: #1a1a1a;
			margin-top: 2px;
		}
		.ld-gsc-synced {
			font-family: var(--tld-mono, monospace);
			font-size: 10px;
			color: #999;
		}
		.ld-gsc-metrics {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 12px;
			padding-top: 4px;
		}
		.ld-gsc-metric {
			text-align: center;
			padding: 10px 4px;
			background: #fafaf7;
			border: 1px solid #eee;
		}
		.ld-gsc-metric-num {
			font-family: var(--tld-serif, Georgia, serif);
			font-size: 24px;
			color: #1a1a1a;
			line-height: 1.1;
		}
		.ld-gsc-metric-label {
			margin-top: 4px;
			font-family: var(--tld-mono, monospace);
			font-size: 9px;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: #666;
		}
		.ld-gsc-empty {
			color: #666;
			font-size: 12px;
			padding: 8px 0;
		}
	</style>
	<?php
}
