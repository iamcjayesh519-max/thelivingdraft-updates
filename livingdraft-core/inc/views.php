<?php
/**
 * View counting.
 *
 * === WHAT CHANGED IN 4.0, AND WHY ===
 *
 * Until now the count was incremented in PHP while the page was being built,
 * on wp_head. That is the obvious way to do it and it is wrong, for a reason
 * that only shows up once a site gets fast: if any page cache is running --
 * LiteSpeed, WP Rocket, Cloudflare, a host-level cache -- PHP does not run at
 * all for most visitors. They are handed a saved copy of the page. The
 * counter never fires, and the numbers report a fraction of the truth.
 *
 * On thelivingdraft.com the effect was stark: articles reading 0, 2 and 4
 * lifetime views while Search Console reported dozens of impressions for the
 * same pieces over the same period.
 *
 * The count now happens in a separate request the reader's browser makes
 * after the page has loaded. That request is never cached, so it runs every
 * time, whatever sits in front of the site.
 *
 * A useful side effect: crawlers do not execute JavaScript, so the great
 * majority of bot traffic now excludes itself without being on any list. The
 * user-agent filter below is kept as a second line, not the first.
 *
 * === NOTHING IS EVER DELETED ===
 *
 * The old design kept a single "recent" float, multiplied it by 0.75 every
 * night, and deleted it once it fell below 0.5. That made Trending work, but
 * it destroyed the underlying data: a story's history was gone within weeks
 * and could never be recovered or re-examined.
 *
 * Four values are now stored per article, and the first three are permanent:
 *
 *   _ld_views         Lifetime total. Only ever increases. Never deleted.
 *   _ld_views_months  Every calendar month, for the life of the site.
 *   _ld_views_days    The last 120 days, day by day.
 *   _ld_views_trend   Derived: the last 7 days added up.
 *
 * Both the day and the month bucket are written on the same request, so when
 * a day older than 120 falls out of the daily record its views are already
 * counted in the month and nothing is lost. The month archive is never
 * trimmed. A story's whole readership history stays queryable for as long as
 * the site exists.
 *
 * Only _ld_views_trend is rewritten, and it is a cache of a sum that can
 * always be recomputed from the days.
 *
 * === WHY TRENDING STILL MEANS TRENDING ===
 *
 * Without some notion of recency, Trending and Popular are the same list and
 * one of the two tabs is pointless -- a big story from March would sit at the
 * top forever. The old decay achieved that destructively. Summing the last
 * seven days achieves the same thing by reading real data: a story that stops
 * being read drops out of Trending on its own, because the days it was read
 * in slide out of the window. No information is discarded to make that happen.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIVINGDRAFT_VIEWS_TOTAL  = '_ld_views';
const LIVINGDRAFT_VIEWS_DAYS   = '_ld_views_days';
const LIVINGDRAFT_VIEWS_MONTHS = '_ld_views_months';
const LIVINGDRAFT_VIEWS_TREND  = '_ld_views_trend';

/**
 * Retired in 4.0. Still declared because a child theme or snippet may refer
 * to it, and still READ as a fallback while a site accumulates its first week
 * of daily data. Never written to, and never deleted.
 */
const LIVINGDRAFT_VIEWS_RECENT = '_ld_views_recent';

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * How many days of day-by-day detail to keep.
 *
 * Older days are folded away only after their views are already safe in the
 * month archive, so changing this alters the resolution of the recent
 * record, never the totals.
 *
 * @since 4.0.0
 * @return int
 */
function livingdraft_views_daily_window() {
	return max( 14, (int) apply_filters( 'livingdraft_views_daily_window', 120 ) );
}

/**
 * How many days count as "trending".
 *
 * @since 4.0.0
 * @return int
 */
function livingdraft_views_trend_window() {
	return max( 1, (int) apply_filters( 'livingdraft_views_trend_window', 7 ) );
}

/**
 * How long before the same reader counts again on the same article.
 *
 * @since 4.0.0
 * @return int Seconds.
 */
function livingdraft_views_repeat_window() {
	return max( 60, (int) apply_filters( 'livingdraft_views_repeat_window', 6 * HOUR_IN_SECONDS ) );
}

/* ==================================================================
 * 2. THE BEACON
 * ================================================================== */

/**
 * Load the counting script on single articles only.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_views_enqueue_beacon() {
	if ( ! is_singular( 'post' ) || is_preview() || is_customize_preview() ) {
		return;
	}

	// 4.9.0: the site-wide tracker counts article reads on the same request.
	if ( function_exists( 'livingdraft_analytics_enabled' ) && livingdraft_analytics_enabled() && livingdraft_an_ready() ) {
		return;
	}

	// The newsroom reading its own work is not readership. Checked here as
	// well as in the endpoint, so the request is never even made.
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return;
	}

	$path = LIVINGDRAFT_CORE_DIR . 'assets/js/views-beacon.js';

	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-views',
		LIVINGDRAFT_CORE_URL . 'assets/js/views-beacon.js',
		array(),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_add_inline_script(
		'livingdraft-views',
		'window.livingdraftViews=' . wp_json_encode(
			array(
				'endpoint' => rest_url( 'livingdraft/v1/view' ),
				'post'     => (int) get_the_ID(),
				'window'   => (int) livingdraft_views_repeat_window(),
			)
		) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_views_enqueue_beacon' );

/**
 * Register the endpoint the beacon posts to.
 *
 * === ON THE ABSENCE OF A NONCE ===
 *
 * A nonce would be printed into the page HTML, and the page HTML is cached.
 * Every visitor served from cache would receive the same nonce, minted
 * whenever the cache was written, and it would start failing for everyone
 * once it aged out. A nonce is the right tool for an action taken by a known
 * user; this is an anonymous count on a cached page, where it cannot work and
 * would only produce silent undercounting -- the exact bug being fixed here.
 *
 * What guards the endpoint instead: it accepts nothing but a post id, it
 * verifies that id is a published post, it writes no user-supplied data, it
 * returns nothing, and repeat views are guarded per reader. The worst a
 * determined abuser achieves is inflating a number on the site owner's own
 * dashboard.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_views_register_route() {
	register_rest_route(
		'livingdraft/v1',
		'/view',
		array(
			'methods'             => 'POST',
			'callback'            => 'livingdraft_views_record',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $value ) {
						return absint( $value ) > 0;
					},
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'livingdraft_views_register_route' );

/**
 * Substrings identifying a non-human visitor.
 *
 * A second line of defence only. Crawlers do not run the beacon, so almost
 * nothing reaches this list any more. Kept for the handful of headless and
 * auditing agents that do execute JavaScript.
 *
 * @since 4.0.0
 * @return string[]
 */
function livingdraft_views_bot_signatures() {
	return (array) apply_filters(
		'livingdraft_bot_signatures',
		array(
			'bot', 'crawl', 'spider', 'slurp', 'preview', 'monitor', 'headless',
			'facebookexternalhit', 'twitterbot', 'linkedinbot', 'discordbot',
			'whatsapp', 'telegram', 'slackbot', 'skype',
			'gptbot', 'chatgpt', 'oai-searchbot', 'claude', 'claudebot',
			'anthropic', 'perplexity', 'ccbot', 'google-extended', 'bytespider',
			'ahrefs', 'semrush', 'mj12', 'dotbot', 'petalbot', 'yandex', 'baidu',
			'applebot', 'duckduckbot', 'bingpreview', 'lighthouse', 'pagespeed',
		)
	);
}

/**
 * Record one view.
 *
 * Always answers 204 No Content, whether or not the view was counted. A
 * counter has no business telling an anonymous caller why it declined, and
 * the browser has nothing to do with the answer either way.
 *
 * @since 4.0.0
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function livingdraft_views_record( $request ) {
	$empty = new WP_REST_Response( null, 204 );

	$post_id = absint( $request->get_param( 'post' ) );
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return $empty;
	}

	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return $empty;
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
		? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) )
		: '';

	if ( '' === $agent ) {
		return $empty; // A real browser always sends one.
	}

	foreach ( livingdraft_views_bot_signatures() as $needle ) {
		if ( false !== strpos( $agent, $needle ) ) {
			return $empty;
		}
	}

	if ( ! livingdraft_views_repeat_ok( $post_id, $agent ) ) {
		return $empty;
	}

	livingdraft_views_increment( $post_id );

	return $empty;
}

/**
 * Server-side repeat guard.
 *
 * The browser already refuses to send a second beacon for the same article
 * within the repeat window, which handles every honest reader at no cost to
 * the database. This is the backstop for the rest.
 *
 * === WHY IT IS SKIPPED WITHOUT AN OBJECT CACHE ===
 *
 * Without a persistent object cache, a transient is a row in wp_options.
 * Writing one per reader per article, as the 3.x counter did, churns
 * thousands of rows an hour on a busy news site -- a real cost paid on every
 * view to prevent something that barely matters. With Redis or Memcached
 * present the same check is free, so it runs. A site can force it on
 * regardless with the filter, at that price.
 *
 * @since 4.0.0
 * @param int    $post_id Post.
 * @param string $agent   Lower-cased user agent.
 * @return bool True when this view should count.
 */
function livingdraft_views_repeat_ok( $post_id, $agent ) {
	$use_guard = (bool) apply_filters( 'livingdraft_views_server_guard', wp_using_ext_object_cache() );

	if ( ! $use_guard ) {
		return true;
	}

	$raw = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: '';

	// Anonymised before use, and only ever stored as a hash.
	$who = function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $raw ) : $raw;
	$key = 'ld_seen_' . md5( $who . '|' . $agent . '|' . $post_id );

	if ( get_transient( $key ) ) {
		return false;
	}

	set_transient( $key, 1, livingdraft_views_repeat_window() );

	return true;
}

/* ==================================================================
 * 3. WRITING THE NUMBERS
 * ================================================================== */

/**
 * Add one view to an article, everywhere it is recorded.
 *
 * The day and the month are written together, which is what makes trimming
 * the daily record lossless later.
 *
 * @since 4.0.0
 * @param int $post_id Post.
 * @return void
 */
function livingdraft_views_increment( $post_id ) {
	$post_id = (int) $post_id;
	$today   = current_time( 'Y-m-d' );
	$month   = substr( $today, 0, 7 );

	// --- Lifetime. Only ever goes up, and is never removed. ---
	$total = (int) get_post_meta( $post_id, LIVINGDRAFT_VIEWS_TOTAL, true );
	update_post_meta( $post_id, LIVINGDRAFT_VIEWS_TOTAL, $total + 1 );

	// --- The permanent month archive. ---
	$months = get_post_meta( $post_id, LIVINGDRAFT_VIEWS_MONTHS, true );
	$months = is_array( $months ) ? $months : array();

	$months[ $month ] = (int) ( isset( $months[ $month ] ) ? $months[ $month ] : 0 ) + 1;
	krsort( $months );

	update_post_meta( $post_id, LIVINGDRAFT_VIEWS_MONTHS, $months );

	// --- The rolling daily record. ---
	$days = get_post_meta( $post_id, LIVINGDRAFT_VIEWS_DAYS, true );
	$days = is_array( $days ) ? $days : array();

	$days[ $today ] = (int) ( isset( $days[ $today ] ) ? $days[ $today ] : 0 ) + 1;
	$days           = livingdraft_views_trim_days( $days );

	update_post_meta( $post_id, LIVINGDRAFT_VIEWS_DAYS, $days );

	// --- The derived trending figure. ---
	update_post_meta( $post_id, LIVINGDRAFT_VIEWS_TREND, livingdraft_views_sum_days( $days ) );

	/**
	 * Fires after a view has been counted.
	 *
	 * @since 4.0.0
	 * @param int $post_id Post that was read.
	 */
	do_action( 'livingdraft_view_counted', $post_id );
}

/**
 * Drop days older than the window.
 *
 * Safe because every one of those views is already in the month archive,
 * written by the same call that wrote the day.
 *
 * @since 4.0.0
 * @param array $days Day map, 'Y-m-d' => int.
 * @return array
 */
function livingdraft_views_trim_days( $days ) {
	if ( ! is_array( $days ) ) {
		return array();
	}

	$cutoff = gmdate( 'Y-m-d', time() - ( livingdraft_views_daily_window() * DAY_IN_SECONDS ) );

	foreach ( array_keys( $days ) as $date ) {
		if ( (string) $date < $cutoff ) {
			unset( $days[ $date ] );
		}
	}

	krsort( $days );

	return $days;
}

/**
 * Add up the last N days.
 *
 * @since 4.0.0
 * @param array    $days   Day map.
 * @param int|null $window Days, or the trending default.
 * @return int
 */
function livingdraft_views_sum_days( $days, $window = null ) {
	if ( ! is_array( $days ) ) {
		return 0;
	}

	$window = $window ? (int) $window : livingdraft_views_trend_window();
	$cutoff = gmdate( 'Y-m-d', time() - ( $window * DAY_IN_SECONDS ) );
	$sum    = 0;

	foreach ( $days as $date => $count ) {
		if ( (string) $date >= $cutoff ) {
			$sum += (int) $count;
		}
	}

	return $sum;
}

/* ==================================================================
 * 4. READING THE NUMBERS
 * ================================================================== */

/**
 * Everything known about how one article has been read.
 *
 * @since 4.0.0
 * @param int|null $post_id Post, or the current one.
 * @return array
 */
function livingdraft_views_stats( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();

	$days   = get_post_meta( $post_id, LIVINGDRAFT_VIEWS_DAYS, true );
	$months = get_post_meta( $post_id, LIVINGDRAFT_VIEWS_MONTHS, true );

	return array(
		'total'  => (int) get_post_meta( $post_id, LIVINGDRAFT_VIEWS_TOTAL, true ),
		'trend'  => (int) get_post_meta( $post_id, LIVINGDRAFT_VIEWS_TREND, true ),
		'days'   => is_array( $days ) ? $days : array(),
		'months' => is_array( $months ) ? $months : array(),
	);
}

/**
 * The lifetime total for one article.
 *
 * The function the theme should call to print a read count.
 *
 * @since 4.0.0
 * @param int|null $post_id Post, or the current one.
 * @return int
 */
function livingdraft_views_total( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();

	return (int) get_post_meta( $post_id, LIVINGDRAFT_VIEWS_TOTAL, true );
}

/**
 * The most-read stories.
 *
 * Signature unchanged from 3.x, because the theme's Latest / Popular /
 * Trending strip calls it and has to keep working across the upgrade. Only
 * the meta key behind "trending" has changed.
 *
 * @param string $mode  'popular' for all time, 'trending' for the last week.
 * @param int    $limit How many.
 * @return WP_Post[]
 */
function livingdraft_get_popular( $mode = 'popular', $limit = 5 ) {
	$limit = max( 1, (int) $limit );
	$meta  = ( 'trending' === $mode ) ? LIVINGDRAFT_VIEWS_TREND : LIVINGDRAFT_VIEWS_TOTAL;
	$key   = 'ld_pop_' . $mode . '_' . $limit;

	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return array_filter( array_map( 'get_post', $cached ) );
	}

	$ids = get_posts(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'meta_key'            => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);

	/*
	 * A site that has just upgraded has no trending data yet -- the seven-day
	 * window fills over the first week. Rather than show a thin tab, top up
	 * from the retired 3.x decayed score if it is still in the database. It is
	 * only ever read, never written and never removed.
	 */
	if ( 'trending' === $mode && count( $ids ) < $limit ) {
		$legacy = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $limit - count( $ids ),
				'post__not_in'        => $ids ? $ids : array( 0 ),
				'meta_key'            => LIVINGDRAFT_VIEWS_RECENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'             => 'meta_value_num',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		$ids    = array_merge( $ids, $legacy );
	}

	// A new site has no view data at all. Fall back to newest, so the tab is
	// never empty on day one.
	if ( count( $ids ) < $limit ) {
		$fill = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $limit - count( $ids ),
				'post__not_in'        => $ids ? $ids : array( 0 ),
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		$ids  = array_merge( $ids, $fill );
	}

	set_transient( $key, $ids, 30 * MINUTE_IN_SECONDS );

	return array_filter( array_map( 'get_post', $ids ) );
}

/* ==================================================================
 * 5. NIGHTLY HOUSEKEEPING
 * ================================================================== */

/**
 * Recompute the trending figure for every article that has one.
 *
 * Needed because trending has to fall on its own. An article read heavily
 * last week and not at all this week is never written to again, so without
 * this its stale seven-day sum would hold it at the top of Trending forever.
 *
 * Only the derived figure is rewritten. The lifetime total, the months and
 * the days are not touched.
 *
 * @since 4.0.0
 * @return int Number of articles refreshed.
 */
function livingdraft_views_refresh_trend() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => (int) apply_filters( 'livingdraft_views_refresh_limit', 5000 ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => LIVINGDRAFT_VIEWS_DAYS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);

	$done = 0;

	foreach ( $ids as $id ) {
		$id   = (int) $id;
		$days = get_post_meta( $id, LIVINGDRAFT_VIEWS_DAYS, true );

		if ( ! is_array( $days ) ) {
			continue;
		}

		$trimmed = livingdraft_views_trim_days( $days );

		if ( $trimmed !== $days ) {
			update_post_meta( $id, LIVINGDRAFT_VIEWS_DAYS, $trimmed );
		}

		update_post_meta( $id, LIVINGDRAFT_VIEWS_TREND, livingdraft_views_sum_days( $trimmed ) );
		$done++;
	}

	// The Popular and Trending lists are cached for half an hour; clear them
	// so the front page reflects the refreshed order at once.
	foreach ( array( 'popular', 'trending' ) as $mode ) {
		for ( $n = 1; $n <= 20; $n++ ) {
			delete_transient( 'ld_pop_' . $mode . '_' . $n );
		}
	}

	return $done;
}
add_action( 'livingdraft_views_nightly', 'livingdraft_views_refresh_trend' );

/**
 * Schedule the nightly refresh, and make sure the retired 3.x decay job --
 * which deleted data -- is not still running alongside it.
 *
 * Hooked to init rather than activation alone, so a site that upgrades by
 * overwriting the plugin folder still gets the change.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_views_schedule() {
	if ( ! wp_next_scheduled( 'livingdraft_views_nightly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'livingdraft_views_nightly' );
	}

	// The 3.x decay job multiplied the recent score by 0.75 every night and
	// deleted it below 0.5. Nothing should be destroying counts any more.
	if ( wp_next_scheduled( 'livingdraft_daily_decay' ) ) {
		wp_clear_scheduled_hook( 'livingdraft_daily_decay' );
	}
}
add_action( 'init', 'livingdraft_views_schedule' );

/**
 * Clear our own schedule on deactivation. The counts stay exactly where they
 * are -- deactivating a plugin is not permission to destroy a site's history.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_views_unschedule() {
	wp_clear_scheduled_hook( 'livingdraft_views_nightly' );
	wp_clear_scheduled_hook( 'livingdraft_daily_decay' );
}
register_deactivation_hook( LIVINGDRAFT_CORE_FILE, 'livingdraft_views_unschedule' );

/* ==================================================================
 * 6. THE ADMIN COLUMN
 * ================================================================== */

/**
 * Add the Views column to the Posts list.
 *
 * @param array $cols Columns.
 * @return array
 */
function livingdraft_core_views_column( $cols ) {
	$cols['ld_views'] = __( 'Views', 'livingdraft-core' );

	return $cols;
}
add_filter( 'manage_post_posts_columns', 'livingdraft_core_views_column' );

/**
 * Sortable, by lifetime total.
 *
 * @since 4.0.0
 * @param array $cols Sortable columns.
 * @return array
 */
function livingdraft_core_views_sortable( $cols ) {
	$cols['ld_views'] = 'ld_views';

	return $cols;
}
add_filter( 'manage_edit-post_sortable_columns', 'livingdraft_core_views_sortable' );

/**
 * Translate the column sort into a numeric meta sort.
 *
 * @since 4.0.0
 * @param WP_Query $query Query.
 * @return void
 */
function livingdraft_core_views_sort( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'ld_views' !== $query->get( 'orderby' ) ) {
		return;
	}

	$query->set( 'meta_key', LIVINGDRAFT_VIEWS_TOTAL );
	$query->set( 'orderby', 'meta_value_num' );
}
add_action( 'pre_get_posts', 'livingdraft_core_views_sort' );

/**
 * Render one cell: lifetime total, with the last seven days beside it.
 *
 * @param string $col     Column name.
 * @param int    $post_id Post ID.
 * @return void
 */
function livingdraft_core_views_cell( $col, $post_id ) {
	if ( 'ld_views' !== $col ) {
		return;
	}

	$stats = livingdraft_views_stats( $post_id );

	printf(
		'<strong style="font-variant-numeric:tabular-nums">%s</strong>'
			. ' <span style="color:#888;font-variant-numeric:tabular-nums" title="%s">(%s)</span>',
		esc_html( number_format_i18n( $stats['total'] ) ),
		esc_attr(
			sprintf(
				/* translators: %d: number of days in the trending window. */
				__( 'Reads in the last %d days', 'livingdraft-core' ),
				livingdraft_views_trend_window()
			)
		),
		esc_html( number_format_i18n( $stats['trend'] ) )
	);
}
add_action( 'manage_post_posts_custom_column', 'livingdraft_core_views_cell', 10, 2 );
